<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Config;

use Phlix\Console\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/phlix-config-' . bin2hex(random_bytes(6));
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

    public function testDefaultsToNoServer(): void
    {
        $config = new Config();

        self::assertNull($config->serverUrl);
        self::assertFalse($config->hasServer());
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        $path = $this->dir . '/config.json';
        (new Config('https://srv.example'))->save($path);

        $loaded = Config::load($path);

        self::assertSame('https://srv.example', $loaded->serverUrl);
        self::assertTrue($loaded->hasServer());
    }

    public function testSaveWritesOwnerOnlyPermissions(): void
    {
        $path = $this->dir . '/config.json';
        (new Config('https://srv'))->save($path);

        self::assertFileExists($path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testLoadMissingFileReturnsDefaults(): void
    {
        $config = Config::load($this->dir . '/does-not-exist.json');

        self::assertNull($config->serverUrl);
    }

    public function testLoadInvalidJsonReturnsDefaults(): void
    {
        @mkdir($this->dir, 0o700, true);
        $path = $this->dir . '/config.json';
        file_put_contents($path, '{not valid json');

        self::assertNull(Config::load($path)->serverUrl);
    }

    public function testWithServerUrlNormalises(): void
    {
        self::assertSame('https://host.tld', (new Config())->withServerUrl('  host.tld/  ')->serverUrl);
        self::assertSame('http://host.tld', (new Config())->withServerUrl('http://host.tld')->serverUrl);
        self::assertSame('https://h.tld:8096', (new Config())->withServerUrl('h.tld:8096/')->serverUrl);
    }

    // ---- theme -----------------------------------------------------------

    public function testThemeDefaultsToNull(): void
    {
        self::assertNull((new Config())->theme);
    }

    public function testThemeRoundTripsThroughSaveAndLoad(): void
    {
        $path = $this->dir . '/config.json';
        (new Config('https://srv', 'Midnight'))->save($path);

        $loaded = Config::load($path);

        self::assertSame('Midnight', $loaded->theme);
        self::assertSame('https://srv', $loaded->serverUrl);
    }

    public function testAbsentThemeLoadsAsNull(): void
    {
        // A legacy config file with only server_url (no theme key) → theme is null.
        @mkdir($this->dir, 0o700, true);
        $path = $this->dir . '/config.json';
        file_put_contents($path, json_encode(['server_url' => 'https://srv']));

        $loaded = Config::load($path);

        self::assertSame('https://srv', $loaded->serverUrl);
        self::assertNull($loaded->theme);
    }

    public function testEmptyThemeStringLoadsAsNull(): void
    {
        @mkdir($this->dir, 0o700, true);
        $path = $this->dir . '/config.json';
        file_put_contents($path, json_encode(['server_url' => 'https://srv', 'theme' => '']));

        self::assertNull(Config::load($path)->theme);
    }

    public function testWithThemePreservesServerUrl(): void
    {
        $config = (new Config('https://srv'))->withTheme('Daylight');

        self::assertSame('Daylight', $config->theme);
        self::assertSame('https://srv', $config->serverUrl, 'switching theme keeps the server');
    }

    public function testWithServerUrlPreservesTheme(): void
    {
        $config = (new Config('https://old', 'Midnight'))->withServerUrl('new.tld');

        self::assertSame('https://new.tld', $config->serverUrl);
        self::assertSame('Midnight', $config->theme, 'changing server keeps the theme');
    }

    public function testNormalizeUrl(): void
    {
        self::assertSame('https://a.tld', Config::normalizeUrl('a.tld'));
        self::assertSame('https://a.tld', Config::normalizeUrl('https://a.tld/'));
        self::assertSame('http://a.tld', Config::normalizeUrl('  http://a.tld  '));
        self::assertSame('', Config::normalizeUrl('   '));
    }

    public function testDirHonoursXdgConfigHome(): void
    {
        $prev = getenv('XDG_CONFIG_HOME');
        putenv('XDG_CONFIG_HOME=' . $this->dir);
        try {
            self::assertSame($this->dir . '/phlix', Config::dir());
            self::assertSame($this->dir . '/phlix/config.json', Config::path());
        } finally {
            $prev === false ? putenv('XDG_CONFIG_HOME') : putenv('XDG_CONFIG_HOME=' . $prev);
        }
    }

    public function testDirFallsBackToHomeDotConfig(): void
    {
        $prevXdg = getenv('XDG_CONFIG_HOME');
        $prevHome = getenv('HOME');
        putenv('XDG_CONFIG_HOME');
        putenv('HOME=' . $this->dir);
        try {
            self::assertSame($this->dir . '/.config/phlix', Config::dir());
        } finally {
            $prevXdg === false ? putenv('XDG_CONFIG_HOME') : putenv('XDG_CONFIG_HOME=' . $prevXdg);
            $prevHome === false ? putenv('HOME') : putenv('HOME=' . $prevHome);
        }
    }

    public function testDirFallsBackToTempWhenNoHome(): void
    {
        $prevXdg = getenv('XDG_CONFIG_HOME');
        $prevHome = getenv('HOME');
        $prevProfile = getenv('USERPROFILE');
        putenv('XDG_CONFIG_HOME');
        putenv('HOME');
        putenv('USERPROFILE');
        try {
            self::assertSame(rtrim(sys_get_temp_dir(), '/') . '/.config/phlix', Config::dir());
        } finally {
            $prevXdg === false ? putenv('XDG_CONFIG_HOME') : putenv('XDG_CONFIG_HOME=' . $prevXdg);
            $prevHome === false ? putenv('HOME') : putenv('HOME=' . $prevHome);
            $prevProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE=' . $prevProfile);
        }
    }

    public function testSaveThrowsWhenDirectoryCannotBeCreated(): void
    {
        // Occupy the directory path with a regular file so mkdir() fails.
        file_put_contents($this->dir, 'i am a file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create config directory');

        (new Config('https://x'))->save($this->dir . '/config.json');
    }

    public function testDeviceIdIsStableNonEmptyAndPrefixed(): void
    {
        $id = Config::deviceId();

        self::assertStringStartsWith('phlix-console-', $id);
        self::assertNotSame('phlix-console-', $id, 'a host/dir-derived suffix is appended');
        self::assertSame($id, Config::deviceId(), 'stable across calls');
    }
}
