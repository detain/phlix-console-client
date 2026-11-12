<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Download;

use Phlix\Console\Download\DownloadException;
use Phlix\Console\Download\DownloadResult;
use Phlix\Console\Download\DownloadService;
use Phlix\Console\Tests\Api\FakeClockLoop;
use PHPUnit\Framework\TestCase;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Stream\ThroughStream;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * The download sink fails CLOSED [CON-S9 / CON-9, CON-38]: a failed or short
 * write (disk full), a body that ends off its declared Content-Length, a
 * stream that closes without 'end', and a body that stalls past the idle
 * timeout each reject the download exactly once and leave nothing on disk.
 * Only a complete, fully written body is renamed into place.
 *
 * Disk-full is simulated with {@see DiskFullStreamWrapper}, a userspace
 * stream wrapper the download root is pointed at: its writes fail outright
 * (`false`) or stop short after a byte budget, exactly the two shapes a full
 * disk produces through fwrite().
 */
final class DownloadServiceFailClosedTest extends TestCase
{
    /** Signed URL every case downloads (no serverBase: authority gate off). */
    private const URL = 'https://srv.example/media/m1/show.mp4';

    private string $scratchDir;

    /** @var string|false */
    private $previousEnv = false;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/phlix-console-dl-failclosed-' . getmypid();
        $this->previousEnv = getenv('PHLIX_DOWNLOAD_DIR');
        putenv('PHLIX_DOWNLOAD_DIR=' . $this->scratchDir);
        $this->clearScratch();
        DiskFullStreamWrapper::reset();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === false) {
            putenv('PHLIX_DOWNLOAD_DIR');
        } else {
            putenv('PHLIX_DOWNLOAD_DIR=' . $this->previousEnv);
        }
        $this->clearScratch();
        if (in_array(DiskFullStreamWrapper::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(DiskFullStreamWrapper::SCHEME);
        }
        DiskFullStreamWrapper::reset();
    }

    /** Remove this suite's scratch directory (never recursive, files first). */
    private function clearScratch(): void
    {
        if (!is_dir($this->scratchDir)) {
            return;
        }
        foreach (scandir($this->scratchDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->scratchDir . '/' . $entry);
            }
        }
        @rmdir($this->scratchDir);
    }

    /** @return list<string> */
    private function scratchFiles(): array
    {
        return glob($this->scratchDir . '/*') ?: [];
    }

    /** Point the download root at the disk-full wrapper. */
    private function useDiskFullRoot(): void
    {
        stream_wrapper_register(DiskFullStreamWrapper::SCHEME, DiskFullStreamWrapper::class);
        putenv('PHLIX_DOWNLOAD_DIR=' . DiskFullStreamWrapper::SCHEME . '://dl');
    }

    private function stubBrowser(PromiseInterface $promise): Browser
    {
        // PHPUnit-generated: Browser is phpdoc-@final (see the hardening suite).
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

    /**
     * Start a download and capture every settlement.
     *
     * @return array{0: \ArrayObject<int, mixed>, 1: \ArrayObject<int, mixed>}
     */
    private function start(DownloadService $service): array
    {
        $resolved = new \ArrayObject();
        $rejected = new \ArrayObject();
        $service->download(self::URL, 'm1')->then(
            static function (DownloadResult $r) use ($resolved): void {
                $resolved->append($r);
            },
            static function (\Throwable $e) use ($rejected): void {
                $rejected->append($e);
            },
        );

        return [$resolved, $rejected];
    }

    /**
     * @param array<string, string> $headers
     */
    private function service(
        ThroughStream $body,
        array $headers,
        ?FakeClockLoop $loop = null,
        float $idle = 60.0,
    ): DownloadService {
        return new DownloadService(
            browser: $this->stubBrowser(resolve($this->response($headers, $body))),
            loop: $loop ?? new FakeClockLoop(),
            idleTimeout: $idle,
        );
    }

    /**
     * @param \ArrayObject<int, mixed> $rejected
     */
    private function assertSingleRejection(\ArrayObject $rejected, string $needle): void
    {
        self::assertCount(1, $rejected, 'the download settles exactly once');
        $error = $rejected[0];
        self::assertInstanceOf(DownloadException::class, $error);
        self::assertStringContainsString($needle, $error->getMessage());
    }

    public function testAFailedWriteRejectsAndUnlinksTheTmpFile(): void
    {
        $this->useDiskFullRoot();
        DiskFullStreamWrapper::$writeReturnsFalse = true;
        $body = new ThroughStream();
        [$resolved, $rejected] = $this->start($this->service($body, ['Content-Type' => 'video/mp4']));

        $body->write('hello');
        $body->end(); // must not resurrect a resolve

        self::assertCount(0, $resolved, 'a write that failed never resolves');
        $this->assertSingleRejection($rejected, 'could not be written to disk');
        self::assertSame([], DiskFullStreamWrapper::$files, 'the tmp file is unlinked');
        self::assertFalse($body->isReadable(), 'the body is closed on failure');
    }

    public function testAShortWriteRejectsAndUnlinksTheTmpFile(): void
    {
        $this->useDiskFullRoot();
        DiskFullStreamWrapper::$byteBudget = 7; // the disk fills mid-chunk
        $body = new ThroughStream();
        [$resolved, $rejected] = $this->start($this->service($body, ['Content-Type' => 'video/mp4']));

        $body->write('12345');
        self::assertCount(0, $rejected, 'within the budget the download lives');
        $body->write('67890'); // only 2 of 5 bytes land
        $body->end();

        self::assertCount(0, $resolved, 'a truncated file never resolves');
        $this->assertSingleRejection($rejected, 'could not be written to disk');
        self::assertSame([], DiskFullStreamWrapper::$files, 'the tmp file is unlinked');
    }

    public function testABodyEndingShortOfItsDeclaredLengthIsRejected(): void
    {
        $body = new ThroughStream();
        $service = $this->service($body, ['Content-Length' => '10', 'Content-Type' => 'video/mp4']);
        [$resolved, $rejected] = $this->start($service);

        $body->write('hello');
        $body->end();

        self::assertCount(0, $resolved);
        $this->assertSingleRejection($rejected, 'received 5 of 10 bytes');
        self::assertSame([], $this->scratchFiles(), 'the partial file is unlinked');
    }

    public function testABodyRunningPastItsDeclaredLengthIsRejected(): void
    {
        $body = new ThroughStream();
        $service = $this->service($body, ['Content-Length' => '3', 'Content-Type' => 'video/mp4']);
        [$resolved, $rejected] = $this->start($service);

        $body->write('hello');
        $body->end();

        self::assertCount(0, $resolved);
        $this->assertSingleRejection($rejected, 'received 5 of 3 bytes');
        self::assertSame([], $this->scratchFiles());
    }

    public function testAMatchingDeclaredLengthResolvesAndDisarmsTheIdleTimer(): void
    {
        $loop = new FakeClockLoop();
        $body = new ThroughStream();
        $service = $this->service($body, ['Content-Length' => '5', 'Content-Type' => 'video/mp4'], $loop);
        [$resolved, $rejected] = $this->start($service);

        $body->write('hello');
        $body->end();

        self::assertCount(0, $rejected);
        self::assertCount(1, $resolved);
        $result = $resolved[0];
        self::assertInstanceOf(DownloadResult::class, $result);
        self::assertSame(5, $result->size);
        self::assertSame('hello', file_get_contents($result->url));
        self::assertSame(0, $loop->pendingOneShotCount(), 'success disarms the idle timer');
    }

    public function testAnUndeclaredLengthStillResolves(): void
    {
        $body = new ThroughStream();
        [$resolved, $rejected] = $this->start($this->service($body, ['Content-Type' => 'video/mp4']));

        $body->write('hello');
        $body->end();

        self::assertCount(0, $rejected, 'no declaration, nothing to keep (chunked transfer)');
        self::assertCount(1, $resolved);
    }

    public function testACloseWithoutEndSettlesTheDownload(): void
    {
        $loop = new FakeClockLoop();
        $body = new ThroughStream();
        [$resolved, $rejected] = $this->start($this->service($body, ['Content-Type' => 'video/mp4'], $loop));

        $body->write('hel');
        $body->close(); // connection dropped: no 'end', no 'error'

        self::assertCount(0, $resolved);
        $this->assertSingleRejection($rejected, 'connection closed before the file finished');
        self::assertSame([], $this->scratchFiles(), 'the partial file is unlinked');
        self::assertSame(0, $loop->pendingOneShotCount(), 'the idle timer is disarmed');
    }

    public function testAnErrorThenCloseSettlesOnceWithTheStreamError(): void
    {
        $body = new ThroughStream();
        [$resolved, $rejected] = $this->start($this->service($body, ['Content-Type' => 'video/mp4']));

        $body->write('hel');
        $body->emit('error', [new \RuntimeException('reset by peer')]);
        $body->close();

        self::assertCount(0, $resolved);
        $this->assertSingleRejection($rejected, 'Download stream error: reset by peer');
        self::assertSame([], $this->scratchFiles());
    }

    public function testAStalledBodyTimesOutAndCleansUp(): void
    {
        $loop = new FakeClockLoop();
        $body = new ThroughStream();
        [$resolved, $rejected] = $this->start($this->service($body, ['Content-Type' => 'video/mp4'], $loop, 5.0));

        self::assertSame([5.0], $loop->oneShotDelays, 'the idle timer arms once the file is open');
        $body->write('hel');
        self::assertSame(1, $loop->pendingOneShotCount(), 'each chunk re-arms (cancel + one fresh timer)');
        self::assertCount(0, $rejected);

        $loop->fireNextOneShot(); // the server went silent

        self::assertCount(0, $resolved);
        $this->assertSingleRejection($rejected, 'stalled');
        self::assertSame([], $this->scratchFiles(), 'the partial file is unlinked');
        self::assertFalse($body->isReadable(), 'the stalled body is closed');
    }

    public function testTheDefaultIdleTimeoutIsArmed(): void
    {
        $loop = new FakeClockLoop();
        $body = new ThroughStream();
        $service = new DownloadService(
            browser: $this->stubBrowser(resolve($this->response(['Content-Type' => 'video/mp4'], $body))),
            loop: $loop,
        );
        $this->start($service);

        self::assertSame([DownloadService::STREAM_IDLE_TIMEOUT_SECONDS], $loop->oneShotDelays);
        $body->end();
        self::assertSame(0, $loop->pendingOneShotCount());
    }

    public function testARequestFailureRejectsWithoutTouchingDisk(): void
    {
        $service = new DownloadService(
            browser: $this->stubBrowser(reject(new \RuntimeException('connection refused'))),
            loop: new FakeClockLoop(),
        );
        [$resolved, $rejected] = $this->start($service);

        self::assertCount(0, $resolved);
        $this->assertSingleRejection($rejected, 'Download request failed: connection refused');
        self::assertSame([], $this->scratchFiles(), 'no tmp file was ever minted');
    }

    public function testACapBreachClosesTheBodyAndWritesNothingPastTheCeiling(): void
    {
        $this->useDiskFullRoot();
        $body = new ThroughStream();
        $service = new DownloadService(
            browser: $this->stubBrowser(resolve($this->response(['Content-Type' => 'video/mp4'], $body))),
            maxBytes: 8,
            loop: new FakeClockLoop(),
        );
        [, $rejected] = $this->start($service);

        $body->write('1234567');
        $body->write('89');

        $this->assertSingleRejection($rejected, 'streamed past the 8 byte limit');
        self::assertSame(7, DiskFullStreamWrapper::$bytesWritten, 'the breaching chunk is never written');
        self::assertSame([], DiskFullStreamWrapper::$files);
        self::assertFalse($body->isReadable(), 'the body is closed at the breach');
    }
}

/**
 * In-memory userspace stream wrapper standing in for a filesystem that fills
 * up: writes fail outright ({@see $writeReturnsFalse}) or stop short once
 * {@see $byteBudget} bytes have landed — the two shapes fwrite() reports for a
 * full disk. Supports exactly the calls DownloadService makes on its root.
 */
final class DiskFullStreamWrapper
{
    public const SCHEME = 'phlixdiskfull';

    /** @var array<string, string> Live files: path => contents. */
    public static array $files = [];

    public static bool $writeReturnsFalse = false;

    public static ?int $byteBudget = null;

    public static int $bytesWritten = 0;

    /** @var resource|null */
    public $context;

    private string $path = '';

    public static function reset(): void
    {
        self::$files = [];
        self::$writeReturnsFalse = false;
        self::$byteBudget = null;
        self::$bytesWritten = 0;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool // phpcs:ignore
    {
        $this->path = $path;
        self::$files[$path] = '';

        return true;
    }

    public function stream_write(string $data): int|false // phpcs:ignore
    {
        if (self::$writeReturnsFalse) {
            return false;
        }
        $room = self::$byteBudget === null ? strlen($data) : max(0, self::$byteBudget - self::$bytesWritten);
        $n = min(strlen($data), $room);
        if (isset(self::$files[$this->path])) {
            self::$files[$this->path] .= substr($data, 0, $n);
        }
        self::$bytesWritten += $n;

        return $n;
    }

    public function stream_close(): void // phpcs:ignore
    {
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool // phpcs:ignore
    {
        return true;
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[$path]);

        return true;
    }

    public function rename(string $from, string $to): bool
    {
        if (!isset(self::$files[$from])) {
            return false;
        }
        self::$files[$to] = self::$files[$from];
        unset(self::$files[$from]);

        return true;
    }

    /** @return array<string, int>|false */
    public function url_stat(string $path, int $flags): array|false // phpcs:ignore
    {
        if ($path === self::SCHEME . '://dl') {
            return ['mode' => 0o040700];
        }
        if (isset(self::$files[$path])) {
            return ['mode' => 0o100600, 'size' => strlen(self::$files[$path])];
        }

        return false;
    }
}
