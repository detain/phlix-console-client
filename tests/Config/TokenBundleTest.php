<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Config;

use Phlix\Console\Config\TokenBundle;
use PHPUnit\Framework\TestCase;

final class TokenBundleTest extends TestCase
{
    public function testFromAuthResponseComputesExpiryFromNow(): void
    {
        $bundle = TokenBundle::fromAuthResponse([
            'access_token' => 'access-xyz',
            'refresh_token' => 'refresh-xyz',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => ['id' => 'u1'],
        ], now: 1_000_000);

        self::assertSame('access-xyz', $bundle->accessToken);
        self::assertSame('refresh-xyz', $bundle->refreshToken);
        self::assertSame('Bearer', $bundle->tokenType);
        self::assertSame(1_003_600, $bundle->expiresAt);
        self::assertTrue($bundle->isValid());
        self::assertTrue($bundle->hasRefreshToken());
    }

    public function testFromAuthResponseWithoutExpiresInHasNullExpiry(): void
    {
        $bundle = TokenBundle::fromAuthResponse([
            'access_token' => 'a',
            'refresh_token' => 'r',
        ], now: 1_000_000);

        self::assertNull($bundle->expiresAt);
        self::assertSame('Bearer', $bundle->tokenType, 'defaults to Bearer');
        self::assertFalse($bundle->isExpired(2_000_000), 'unknown expiry is never expired');
    }

    public function testAuthorizationHeader(): void
    {
        $bundle = new TokenBundle('tok', 'ref', 'Bearer', null);

        self::assertSame('Bearer tok', $bundle->authorizationHeader());
    }

    public function testIsExpiredHonoursSkew(): void
    {
        $bundle = new TokenBundle('a', 'r', 'Bearer', expiresAt: 1_000_000);

        self::assertFalse($bundle->isExpired(now: 1_000_000 - 100, skew: 30));
        // Within the 30s skew window counts as expired.
        self::assertTrue($bundle->isExpired(now: 1_000_000 - 10, skew: 30));
        self::assertTrue($bundle->isExpired(now: 1_000_001, skew: 30));
    }

    public function testInvalidWhenNoAccessToken(): void
    {
        $bundle = new TokenBundle('', 'r');

        self::assertFalse($bundle->isValid());
    }

    public function testArrayRoundTrip(): void
    {
        $bundle = new TokenBundle('a', 'r', 'Bearer', 123);
        $restored = TokenBundle::fromArray($bundle->toArray());

        self::assertEquals($bundle, $restored);
    }

    public function testFromArrayToleratesMissingFields(): void
    {
        $bundle = TokenBundle::fromArray([]);

        self::assertSame('', $bundle->accessToken);
        self::assertSame('Bearer', $bundle->tokenType);
        self::assertNull($bundle->expiresAt);
        self::assertFalse($bundle->isValid());
    }
}
