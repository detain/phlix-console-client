<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Api;

use Phlix\Console\Api\ServerAuthority;
use PHPUnit\Framework\TestCase;

/**
 * The media-authority predicate [review console__secaudit m4, M-CONSOLE-55]:
 * a server-supplied absolute URL is honoured by playback/download sinks only
 * when hosted at — or beneath — the configured server's own host.
 */
final class ServerAuthorityTest extends TestCase
{
    public function testExactHostIsTrusted(): void
    {
        self::assertTrue(ServerAuthority::trusted('https://phlix.example.com', 'https://phlix.example.com/api/v1/stream/x?sig=1'));
    }

    public function testSubdomainIsTrustedForSignedCdnOffloads(): void
    {
        self::assertTrue(ServerAuthority::trusted('https://phlix.example.com', 'https://cdn.phlix.example.com/movie.mp4'));
        self::assertTrue(ServerAuthority::trusted('https://phlix.example.com', 'https://a.b.phlix.example.com/movie.mp4'));
    }

    public function testForeignHostIsRefused(): void
    {
        self::assertFalse(ServerAuthority::trusted('https://phlix.example.com', 'https://evil.tld/pwned.mp4'));
    }

    public function testSuffixLookAlikeIsNotASubdomain(): void
    {
        // Classic trust bug: 'evil-phlix.example.com' ENDS WITH the base host
        // text but is a sibling, not a child — only '.<base>' may be appended.
        self::assertFalse(ServerAuthority::trusted('https://phlix.example.com', 'https://evil-phlix.example.com/x.mp4'));
        self::assertFalse(ServerAuthority::trusted('https://example.com', 'https://notexample.com/x.mp4'));
    }

    public function testPortsAreIgnoredHostComparison(): void
    {
        self::assertTrue(ServerAuthority::trusted('https://phlix.example.com:8096', 'https://phlix.example.com:9443/stream'));
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        self::assertTrue(ServerAuthority::trusted('https://Phlix.Example.COM', 'https://CDN.phlix.EXAMPLE.com/x'));
    }

    public function testHostlessOrMalformedCandidatesFailClosed(): void
    {
        self::assertFalse(ServerAuthority::trusted('https://phlix.example.com', 'nonsense'));
        self::assertFalse(ServerAuthority::trusted('https://phlix.example.com', ''));
        self::assertFalse(ServerAuthority::trusted('', 'https://phlix.example.com/x'));
        self::assertFalse(ServerAuthority::trusted('not a url', 'https://phlix.example.com/x'));
    }
}
