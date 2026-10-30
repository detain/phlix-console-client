<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Download;

use Phlix\Console\Download\DownloadException;
use Phlix\Console\Download\DownloadResult;
use Phlix\Console\Download\DownloadService;
use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ThroughStream;

use function React\Promise\resolve;

/**
 * The download sink's local hardening laws [review console__secaudit m5,
 * M-CONSOLE-54]: the download directory is created private, downloaded files
 * land 0600, a byte ceiling is enforced on both the declared and the streamed
 * size, and server-supplied filenames must survive a conservative shape law —
 * otherwise the media id (itself validated) or a constant names the file.
 * R-CONSOLE-08 (riders W8) adds the traversal-node property law on top:
 * whatever the guard config, the extracted name is a single inert path
 * component that can never escape the download root.
 *
 * The signed-URL host-authority pin from the same review lives in
 * {@see DownloadServiceAuthorityGateTest} (shipped under W-min21).
 */
final class DownloadServiceHardeningTest extends TestCase
{
    /** Scratch download root this suite owns. */
    private const SCRATCH_DIR = '/tmp/opencode/fix-cons2223/dl-hardening';

    /** @var string|false */
    private $previousEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = getenv('PHLIX_DOWNLOAD_DIR');
        putenv('PHLIX_DOWNLOAD_DIR=' . self::SCRATCH_DIR);
        $this->clearScratch();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === false) {
            putenv('PHLIX_DOWNLOAD_DIR');
        } else {
            putenv('PHLIX_DOWNLOAD_DIR=' . $this->previousEnv);
        }
        $this->clearScratch();
    }

    /** Remove this suite's scratch directory (never recursive, files first). */
    private function clearScratch(): void
    {
        if (!is_dir(self::SCRATCH_DIR)) {
            return;
        }
        foreach (scandir(self::SCRATCH_DIR) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink(self::SCRATCH_DIR . '/' . $entry);
            }
        }
        @rmdir(self::SCRATCH_DIR);
    }

    /**
     * A stub browser whose streaming request answers with a canned promise —
     * `requestStreaming` is the single seam the service uses.
     */
    private function stubBrowser(PromiseInterface $promise): Browser
    {
        // PHPUnit-generated: the class is only phpdoc-@final, and extending it
        // would violate the repo's single-source/analysis law (S448: no
        // ignoreErrors, no baseline) — the mock is the sanctioned boundary.
        $browser = $this->createMock(Browser::class);
        $browser->method('requestStreaming')->willReturn($promise);

        return $browser;
    }

    /**
     * Duck-typed streaming response: the service only ever asks the response
     * for `getHeaderLine` and a React-stream `getBody`.
     *
     * @param array<string, string> $headers
     */
    private function response(array $headers, ThroughStream $body): object
    {
        return new class ($headers, $body) {
            /** @param array<string, string> $headers */
            public function __construct(
                private readonly array $headers,
                private readonly ThroughStream $body,
            ) {
            }

            public function getHeaderLine(string $name): string
            {
                return $this->headers[$name] ?? '';
            }

            public function getBody(): ThroughStream
            {
                return $this->body;
            }
        };
    }

    /** Resolve a (synchronously rejected) promise to its failure message. */
    private function rejectionReason(PromiseInterface $promise): ?string
    {
        $error = null;
        $promise->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e->getMessage();
        });

        return $error;
    }

    public function testTheDownloadDirectoryIsCreatedPrivate(): void
    {
        // The request hangs forever on purpose: the directory law is checked
        // before any byte moves, exactly like the authority gate above.
        $service = new DownloadService(browser: $this->stubBrowser((new Deferred())->promise()));

        $service->download('https://srv.example/media/m1/show.mp4', 'm1');

        self::assertDirectoryExists(self::SCRATCH_DIR);
        $mode = fileperms(self::SCRATCH_DIR) & 0o777;
        self::assertSame(0o700, $mode, 'the client-created download root is owner-only [M-CONSOLE-54]');
    }

    public function testDownloadedFilesLandPrivateAndResolveCleanly(): void
    {
        $body = new ThroughStream();
        $service = new DownloadService(
            browser: $this->stubBrowser(resolve($this->response(
                ['Content-Length' => '5', 'Content-Type' => 'video/mp4'],
                $body,
            ))),
        );

        $result = null;
        $service->download('https://srv.example/media/m1/show.mp4', 'm1')
            ->then(static function (DownloadResult $r) use (&$result): void {
                $result = $r;
            });

        $body->write('hello');
        $body->end();

        self::assertInstanceOf(DownloadResult::class, $result);
        /** @var string $path */
        $path = (string) $result->url;
        $mode = fileperms($path) & 0o777;
        self::assertSame(0o600, $mode, 'the final file is owner-rw only [M-CONSOLE-54]');
        self::assertSame('hello', file_get_contents($path));
        self::assertSame([$path], glob(self::SCRATCH_DIR . '/*') ?: [], 'no .tmp leftovers after rename');
    }

    public function testADeclaredSizeAboveTheCeilingIsRefusedBeforeOpeningFiles(): void
    {
        $body = new ThroughStream();
        $service = new DownloadService(
            browser: $this->stubBrowser(resolve($this->response(
                ['Content-Length' => '4000', 'Content-Type' => 'video/mp4'],
                $body,
            ))),
            maxBytes: 10,
        );

        $reason = $this->rejectionReason($service->download('https://srv.example/media/m1/show.mp4', 'm1'));

        self::assertNotNull($reason);
        self::assertStringContainsString('declared 4000 bytes', (string) $reason);
        self::assertStringContainsString('limit', (string) $reason);
        self::assertSame([], glob(self::SCRATCH_DIR . '/*') ?: [], 'the refusal opens no file');
    }

    public function testAStreamedBodyPastTheCeilingIsRejectedAndCleanedUp(): void
    {
        $body = new ThroughStream();
        $service = new DownloadService(
            // No Content-Length at all: only the written-bytes guard applies.
            browser: $this->stubBrowser(resolve($this->response(['Content-Type' => 'video/mp4'], $body))),
            maxBytes: 8,
        );

        $thrown = null;
        $service->download('https://srv.example/media/m1/show.mp4', 'm1')
            ->then(null, static function (\Throwable $e) use (&$thrown): void {
                $thrown = $e;
            });

        $body->write('1234567'); // under the cap
        self::assertNull($thrown, 'below the ceiling the download still lives');
        $body->write('89'); // crosses it
        self::assertInstanceOf(DownloadException::class, $thrown, 'the cap rejects the moment it is crossed');
        self::assertStringContainsString('streamed past the 8 byte limit', (string) $thrown->getMessage());

        $body->write('12345678'); // further chunks are discarded, one rejection stands
        $body->end();
        self::assertSame([], glob(self::SCRATCH_DIR . '/*') ?: [], 'the tmp file is unlinked on rejection');
    }

    public function testTheWrittenByteCountIsCappedEvenWhenNoEndFollows(): void
    {
        // A hostile server can stream forever; the guard must stop writing at
        // the ceiling: bytes on disk are bounded by maxBytes + one chunk.
        $body = new ThroughStream();
        $service = new DownloadService(
            browser: $this->stubBrowser(resolve($this->response(['Content-Type' => 'video/mp4'], $body))),
            maxBytes: 16,
        );
        $promise = $service->download('https://srv.example/media/m1/show.mp4', 'm1');
        $rejected = false;
        $promise->then(null, static function () use (&$rejected): void {
            $rejected = true;
        });

        for ($i = 0; $i < 50; $i++) {
            $body->write(str_repeat('x', 8));
        }

        self::assertTrue($rejected, 'the download settles at the breach, not at stream end');
        self::assertSame([], glob(self::SCRATCH_DIR . '/*') ?: [], 'nothing is left on disk');
    }

    /**
     * The filename shape law, exercised through the service's own extractor.
     *
     * Note the layering measured here: parse_url itself rewrites control bytes
     * in the path to `_`, so a smuggled control char arrives sanitized and the
     * shape law then judges the surviving text; the law's teeth are against
     * dotfiles, overlong names, percent fragments, and non-ASCII.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function provideFilenameShapes(): array
    {
        return [
            'conservative name keeps' => ['https://srv.example/media/My.Show.2024[x].mp4', 'm1', 'My.Show.2024[x].mp4'],
            'dotfile falls back' => ['https://srv.example/.hidden.mp4', 'm1', 'phlix_m1.mp4'],
            'overlong falls back' => ['https://srv.example/' . str_repeat('a', 200) . '.mp4', 'm1', 'phlix_m1.mp4'],
            'percent-fragment falls back' => ['https://srv.example/evil%2Ftrav.mp4', 'm1', 'phlix_m1.mp4'],
            'non-ascii falls back (conservative ASCII class)' => ['https://srv.example/Мираж.mp4', 'm1', 'phlix_m1.mp4'],
            'dot-only candidate falls back' => ['https://srv.example/...mp4', 'm1', 'phlix_m1.mp4'],
            // R-CONSOLE-08 (riders W8, from M-CONSOLE-33__r1 minors_forward):
            // the class is the PATH traversal node, not the dotfile —
            // basename('/x/..') returns '..' itself.
            'dot-dot basename falls back' => ['https://srv.example/media/..', 'm1', 'phlix_m1.mp4'],
            'dot basename falls back' => ['https://srv.example/media/.', 'm1', 'phlix_m1.mp4'],
            'hostile id cannot escape the constant' => ['https://srv.example/.hidden.mp4', '../evil', 'phlix-download.mp4'],
        ];
    }

    /**
     * The filename shape law, one row per hostile-or-boundary candidate.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideFilenameShapes')]
    public function testTheFilenameShapeLaw(string $url, string $mediaId, string $expected): void
    {
        $service = new DownloadService();
        $method = new \ReflectionMethod(DownloadService::class, 'extractFilename');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke($service, $url, $mediaId));
    }

    /**
     * R-CONSOLE-08 (riders W8): the forward from M-CONSOLE-33__r1 named the
     * PATH class specifically — `extractFilename` basename('..') dot-dot →
     * download-path traversal. The M-CONSOLE-54 shape law answers it (a
     * dot-prefixed candidate, which includes '.' and '..', forfeits the
     * server name), but a dotfile-shaped row does not pin the traversal
     * NODE. This law pins the sink-side property directly, whatever internal
     * guard config stands: the extracted name is ALWAYS a single inert path
     * component, so `$downloadDir . '/' . $name` can never escape the
     * download root — on POSIX (no '/') and on the backslash-as-separator
     * portability reading (no '\\') — never '.' or '..', printable-only,
     * control-byte-free, and length-bounded.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideTraversalCandidates(): array
    {
        return [
            'dot-dot tail' => ['https://srv.example/media/..', 'm1'],
            'bare dot-dot' => ['https://srv.example/..', 'm1'],
            'leading traversal chain' => ['https://srv.example/../../etc/passwd.mp4', 'm1'],
            'dot tail' => ['https://srv.example/media/.', 'm1'],
            'current-dir chain' => ['https://srv.example/a/./b.mp4', 'm1'],
            'encoded traversal prefix' => ['https://srv.example/%2e%2e/show.mp4', 'm1'],
            'null-byte tail' => ['https://srv.example/show.mp4%00', 'm1'],
            'hostile dot-dot id' => ['https://srv.example/media/..', '../evil'],
            'both sides hostile' => ['https://srv.example/..%2f..%2fx', '..'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideTraversalCandidates')]
    public function testTheExtractedNameIsAlwaysASingleInertComponent(string $url, string $mediaId): void
    {
        $service = new DownloadService();
        $method = new \ReflectionMethod(DownloadService::class, 'extractFilename');
        $method->setAccessible(true);
        /** @var string $name */
        $name = $method->invoke($service, $url, $mediaId);

        self::assertNotSame('', $name, 'the extractor always names the file');
        self::assertNotContains($name, ['.', '..'], 'the name is never a traversal node');
        self::assertSame(basename($name), $name, 'the name never carries a POSIX path component');
        self::assertStringNotContainsString('\\', $name, 'the name never carries a backslash separator');
        self::assertStringNotContainsString(chr(0), $name, 'the name carries no control bytes');
        self::assertTrue(ctype_print($name), 'the name is fully printable');
        self::assertLessThanOrEqual(128, strlen($name), 'the name is length-bounded');
    }
}
