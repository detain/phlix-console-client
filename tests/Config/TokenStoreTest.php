<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Config;

use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Config\TokenStore;
use PHPUnit\Framework\TestCase;

final class TokenStoreTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-token-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/token.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->dir)) {
            @unlink($this->dir);
        } else {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testLoadReturnsNullWhenAbsent(): void
    {
        $store = new TokenStore($this->path);

        self::assertFalse($store->exists());
        self::assertNull($store->load());
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        $store = new TokenStore($this->path);
        $bundle = new TokenBundle('access', 'refresh', 'Bearer', 1_700_000_000);

        $store->save($bundle);

        self::assertTrue($store->exists());
        self::assertEquals($bundle, $store->load());
    }

    public function testSaveCreatesDirectoryAndOwnerOnlyPermissions(): void
    {
        $store = new TokenStore($this->path);
        $store->save(new TokenBundle('a', 'r'));

        self::assertDirectoryExists($this->dir);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->path)), -4));
    }

    public function testSaveLeavesNoTempFilesBehind(): void
    {
        $store = new TokenStore($this->path);
        $store->save(new TokenBundle('a', 'r'));

        self::assertSame(['token.json'], array_map('basename', glob($this->dir . '/*') ?: []));
    }

    public function testLoadReturnsNullForInvalidJson(): void
    {
        @mkdir($this->dir, 0o700, true);
        file_put_contents($this->path, 'not json');

        self::assertNull((new TokenStore($this->path))->load());
    }

    public function testLoadReturnsNullForTokenWithoutAccessToken(): void
    {
        @mkdir($this->dir, 0o700, true);
        file_put_contents($this->path, json_encode(['refresh_token' => 'r']));

        self::assertNull((new TokenStore($this->path))->load(), 'a bundle with no access token is invalid');
    }

    public function testClearRemovesToken(): void
    {
        $store = new TokenStore($this->path);
        $store->save(new TokenBundle('a', 'r'));

        $store->clear();

        self::assertFalse($store->exists());
        $store->clear(); // idempotent, must not error
    }

    public function testSaveThrowsWhenDirectoryCannotBeCreated(): void
    {
        // A regular file where the directory should be makes mkdir() fail.
        file_put_contents($this->dir, 'i am a file');
        $store = new TokenStore($this->dir . '/token.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create token directory');

        $store->save(new TokenBundle('a', 'r'));
    }

    public function testDefaultStoreUsesConfigDir(): void
    {
        $prev = getenv('XDG_CONFIG_HOME');
        putenv('XDG_CONFIG_HOME=' . $this->dir);
        try {
            self::assertSame($this->dir . '/phlix/token.json', TokenStore::default()->path());
        } finally {
            $prev === false ? putenv('XDG_CONFIG_HOME') : putenv('XDG_CONFIG_HOME=' . $prev);
        }
    }
}
