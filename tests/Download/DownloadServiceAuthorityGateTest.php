<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Download;

use Phlix\Console\Download\DownloadException;
use Phlix\Console\Download\DownloadService;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

/**
 * The download sink's media-authority gate [review console__secaudit m4,
 * M-CONSOLE-55]: a signed URL pointing outside the configured server's host
 * authority is refused BEFORE any filesystem or network work.
 */
final class DownloadServiceAuthorityGateTest extends TestCase
{
    /** Scratch download root (the gate must not create it for refused URLs). */
    private const SCRATCH_DIR = '/tmp/opencode/fix-con2021/dl-authority-test';

    /** @var string|false */
    private $previousEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = getenv('PHLIX_DOWNLOAD_DIR');
        putenv(self::SCRATCH_DIR);
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === false) {
            putenv('PHLIX_DOWNLOAD_DIR');
        } else {
            putenv('PHLIX_DOWNLOAD_DIR=' . $this->previousEnv);
        }
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

    public function testAForeignHostSignedUrlIsRefusedWithoutTouchingDisk(): void
    {
        $service = new DownloadService(serverBase: 'https://phlix.example.com');

        $reason = $this->rejectionReason($service->download('https://evil.tld/payload.mp4', 'm1'));

        self::assertNotNull($reason, 'the foreign URL must reject, not proceed');
        self::assertStringContainsString('refused', (string) $reason);
        self::assertStringContainsString('outside the configured server', (string) $reason);
        self::assertDirectoryDoesNotExist(self::SCRATCH_DIR, 'a refused URL must not even create the download directory');
    }

    public function testTheRefusalCarriesADownloadException(): void
    {
        $service = new DownloadService(serverBase: 'https://phlix.example.com');

        $thrown = null;
        $service->download('https://evil.tld/payload.mp4', 'm1')
            ->then(null, static function (\Throwable $e) use (&$thrown): void {
                $thrown = $e;
            });

        self::assertInstanceOf(DownloadException::class, $thrown, 'the existing download-failure UX arm must consume it');
    }

    public function testAnUnconstrainedServiceHasNoGate(): void
    {
        // serverBase = null keeps the legacy permissive behaviour for any
        // construction without a server context; the production wiring (the
        // only caller) always passes one — pinned by the wiring guard.
        $service = new DownloadService();

        $reason = $this->rejectionReason($service->download('https://evil.tld/payload.mp4', 'm1'));

        self::assertNull($reason, 'no authority configured → the gate does not fire (failure would only come from the real fetch)');
    }
}
