<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Trickplay;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Media\TrickplayCache;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Image\DiskCache;

/**
 * R-CONSOLE-09 [review riders W9]: trickplay sprite/timeline host-trust.
 *
 * The server chooses these URLs. Before the cure the DTO stored whatever host
 * the wire supplied — an SSRF-adjacent transport-trust gap (M-CONSOLE-33 r1
 * minors_forward). The cure applies the estate media-authority law
 * ({@see \Phlix\Console\Api\ServerAuthority}, M-CONSOLE-55 lineage) at the
 * single parse choke: absolute URLs must sit on the configured server's host
 * authority (exact host or subdomain), relative URLs are same-origin by
 * construction and pass, everything else folds to null — the DTO's existing
 * "unavailable" signal. Pins below drive the REAL ingest paths: the
 * ApiClient::trickplay() wire resolver and the TrickplayCache disk-hit read.
 */
final class TrickplayHostTrustTest extends TestCase
{
    private const BASE = 'https://srv.example';

    private ?string $cacheDir = null;

    protected function tearDown(): void
    {
        if ($this->cacheDir !== null && is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->cacheDir);
        }
        $this->cacheDir = null;
    }

    // ---- DTO-level clamp -------------------------------------------------

    public function testAbsoluteForeignHostFoldsToNull(): void
    {
        $tp = Trickplay::fromArray([
            'sprite_url' => 'https://evil.example/sprite.jpg',
            'timeline_url' => 'https://169.254.169.254/latest/meta-data',
        ], self::BASE);

        self::assertNull($tp->spriteUrl, 'foreign host must not survive parse');
        self::assertNull($tp->timelineUrl, 'link-local metadata host must not survive parse');
    }

    public function testProtocolRelativeForeignHostFoldsToNull(): void
    {
        $tp = Trickplay::fromArray([
            'sprite_url' => '//evil.example/sprite.jpg',
            'timeline_url' => null,
        ], self::BASE);

        self::assertNull($tp->spriteUrl, '//host parses to a host — the clamp must judge it');
    }

    public function testRelativeUrlsPassUnchanged(): void
    {
        $tp = Trickplay::fromArray([
            'sprite_url' => '/trickplay/m1/sprite.jpg',
            'timeline_url' => '/trickplay/m1/timeline.json',
        ], self::BASE);

        self::assertSame('/trickplay/m1/sprite.jpg', $tp->spriteUrl);
        self::assertSame('/trickplay/m1/timeline.json', $tp->timelineUrl);
    }

    public function testExactHostAndSubdomainSurviveCaseInsensitively(): void
    {
        $tp = Trickplay::fromArray([
            'sprite_url' => 'https://SRV.EXAMPLE/sprite.jpg',
            'timeline_url' => 'https://cdn.srv.example/t.json',
        ], self::BASE);

        self::assertSame(
            'https://SRV.EXAMPLE/sprite.jpg',
            $tp->spriteUrl,
            'exact host (case-insensitive) is the server itself',
        );
        self::assertSame(
            'https://cdn.srv.example/t.json',
            $tp->timelineUrl,
            'signed-CDN subdomain offload is the documented deployment (ServerAuthority law)',
        );
    }

    public function testEmptyBaseFailClosesAbsoluteUrlsAndKeepsRelativeOnes(): void
    {
        $tp = Trickplay::fromArray([
            'sprite_url' => 'https://srv.example/sprite.jpg',
            'timeline_url' => '/tl.json',
        ]);

        self::assertNull($tp->spriteUrl, 'no configured authority — an absolute URL is unattributable, fold it');
        self::assertSame('/tl.json', $tp->timelineUrl);
    }

    // ---- live ingest paths -----------------------------------------------

    public function testApiClientWirePathClampsForeignHosts(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'sprite_url' => 'https://attacker.internal/sprite.jpg',
            'timeline_url' => '/trickplay/m1/timeline.json',
        ]);
        $client = new ApiClient(self::BASE, $transport);
        $client->setToken(new TokenBundle('t', 'r'));

        $tp = $this->await($client->trickplay('m1'));
        self::assertInstanceOf(Trickplay::class, $tp);

        self::assertNull($tp->spriteUrl, 'the wire resolver must fold the foreign sprite host');
        self::assertSame(
            '/trickplay/m1/timeline.json',
            $tp->timelineUrl,
            'the relative timeline rides through the same call',
        );
    }

    public function testWirePathKeepsServerAuthorityUrls(): void
    {
        $transport = (new FakeTransport())->json(200, [
            'sprite_url' => self::BASE . '/sprite.jpg',
            'timeline_url' => 'https://cdn.srv.example/t.json',
        ]);
        $client = new ApiClient(self::BASE, $transport);
        $client->setToken(new TokenBundle('t', 'r'));

        $tp = $this->await($client->trickplay('m1'));
        self::assertInstanceOf(Trickplay::class, $tp);

        self::assertSame(self::BASE . '/sprite.jpg', $tp->spriteUrl);
        self::assertSame('https://cdn.srv.example/t.json', $tp->timelineUrl);
    }

    public function testTamperedDiskBlobReentersThroughTheSameClamp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/phlix_trickplay_hosttrust_' . bin2hex(random_bytes(4));
        $disk = new DiskCache($this->cacheDir);
        $disk->put('trickplay:m1', json_encode([
            'sprite_url' => 'https://evil.example/sprite.jpg',
            'timeline_url' => '/tl.json',
        ]) ?: '');

        // The transport must NEVER be consulted — the disk hit resolves first.
        $transport = new FakeTransport();
        $api = new ApiClient(self::BASE, $transport);
        $api->setToken(new TokenBundle('t', 'r'));
        $cache = new TrickplayCache($api, $disk);

        $tp = $this->await($cache->load('m1'));
        self::assertInstanceOf(Trickplay::class, $tp);

        self::assertNull($tp->spriteUrl, 'a pre-cure or tampered cache file cannot smuggle a foreign host back in');
        self::assertSame('/tl.json', $tp->timelineUrl);
        self::assertSame(0, $transport->requestCount(), 'the disk hit must resolve without an API fetch');
    }

    /** Same loop-driven await as {@see \Phlix\Console\Tests\Media\TrickplayCacheTest}. */
    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}
