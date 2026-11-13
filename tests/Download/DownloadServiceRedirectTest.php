<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Download;

use Phlix\Console\Download\DownloadException;
use Phlix\Console\Download\DownloadService;
use PHPUnit\Framework\TestCase;
use React\Http\Browser;

use function React\Promise\resolve;

/**
 * Per-hop media authority [review CON-4]: a redirect Location is re-checked
 * with ServerAuthority::trusted() before it is requested.
 */
final class DownloadServiceRedirectTest extends TestCase
{
    private function redirect(string $location): object
    {
        return new class ($location) {
            public function __construct(private readonly string $location)
            {
            }

            public function getStatusCode(): int
            {
                return 302;
            }

            public function getHeaderLine(string $name): string
            {
                return $name === 'Location' ? $this->location : '';
            }

            public function getBody(): object
            {
                return new class () {
                    public function close(): void
                    {
                    }
                };
            }
        };
    }

    public function testHopToAForeignHostIsRefusedAndNeverRequested(): void
    {
        $requested = [];
        $browser = $this->createMock(Browser::class);
        $browser->method('requestStreaming')->willReturnCallback(
            function (string $method, string $url) use (&$requested) {
                $requested[] = $url;

                return resolve($this->redirect('https://evil.tld/payload.mp4'));
            },
        );
        $service = new DownloadService(browser: $browser, serverBase: 'https://phlix.example.com');

        $error = null;
        $service->download('https://cdn.phlix.example.com/f.mp4', 'm1')
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        self::assertSame(['https://cdn.phlix.example.com/f.mp4'], $requested);
        self::assertInstanceOf(DownloadException::class, $error);
        self::assertStringContainsString('redirected outside', $error->getMessage());
    }

    public function testSecondHopIsAlsoRechecked(): void
    {
        $requested = [];
        $browser = $this->createMock(Browser::class);
        $browser->method('requestStreaming')->willReturnCallback(
            function (string $method, string $url) use (&$requested) {
                $requested[] = $url;

                return resolve($this->redirect(
                    count($requested) === 1 ? 'https://phlix.example.com/b' : 'https://evil.tld/c',
                ));
            },
        );
        $service = new DownloadService(browser: $browser, serverBase: 'https://phlix.example.com');

        $error = null;
        $service->download('https://phlix.example.com/a', 'm1')
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        self::assertSame(['https://phlix.example.com/a', 'https://phlix.example.com/b'], $requested);
        self::assertInstanceOf(DownloadException::class, $error);
    }
}
