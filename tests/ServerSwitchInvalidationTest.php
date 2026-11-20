<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\App;
use Phlix\Console\Config\Config;
use Phlix\Console\Config\ServerEntry;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Msg\SwitchServerMsg;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Support\DeferredTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Mosaic;

/**
 * A server switch invalidates the library and media stores the App holds, and a
 * response that was in flight against the old server must not repopulate them.
 */
final class ServerSwitchInvalidationTest extends TestCase
{
    private string $dir;
    private string|false $prevXdg;

    protected function setUp(): void
    {
        parent::setUp();
        // The switch persists the active server id, so keep config out of the real home.
        $this->dir = sys_get_temp_dir() . '/phlix-switch-' . bin2hex(random_bytes(6));
        $this->prevXdg = getenv('XDG_CONFIG_HOME');
        putenv('XDG_CONFIG_HOME=' . $this->dir);
    }

    protected function tearDown(): void
    {
        $this->prevXdg === false ? putenv('XDG_CONFIG_HOME') : putenv('XDG_CONFIG_HOME=' . $this->prevXdg);
        foreach (glob($this->dir . '/phlix/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/phlix');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{App, LibrariesStore, MediaStore, DeferredTransport} */
    private function bootTwoServers(): array
    {
        $transport = new DeferredTransport();
        $api = new ApiClient('https://one.example', $transport);
        $libraries = new LibrariesStore($api, 60.0, static fn (): float => 1000.0);
        $media = new MediaStore($api, 60.0, static fn (): float => 1000.0);
        $config = new Config(
            servers: [
                new ServerEntry(id: 's1', label: 'One', url: 'https://one.example'),
                new ServerEntry(id: 's2', label: 'Two', url: 'https://two.example'),
            ],
            activeServerId: 's1',
        );
        $app = App::boot($config, new AuthStore($api, TokenStore::default()), $api, $libraries, $media, new PosterLoader(Mosaic::halfBlock()));

        return [$app, $libraries, $media, $transport];
    }

    public function testServerSwitchInvalidatesTheLibraryAndMediaStores(): void
    {
        [$app, $libraries, $media, $transport] = $this->bootTwoServers();
        $query = MediaQuery::forLibrary('lib-1', limit: 18);
        $base = $transport->requestCount();

        $libraries->all();
        $transport->respondJson($base, ['libraries' => [['id' => 'l1', 'name' => 'Movies', 'type' => 'movie']]]);
        $media->page($query);
        $transport->respondJson($base + 1, ['items' => [['id' => 'm1', 'name' => 'Old', 'type' => 'movie']], 'total' => 1, 'offset' => 0, 'limit' => 18]);
        self::assertNotNull($libraries->cached(), 'precondition: the library list is cached');

        $app->update(new SwitchServerMsg('s2'));

        self::assertNull($libraries->cached(), 'the switch drops the cached library list');
        $media->page($query);
        self::assertSame($base + 3, $transport->requestCount(), 'the switch drops the cached media page, so it refetches');
    }

    public function testResponseFromTheOldServerSettlingAfterTheSwitchIsNotCached(): void
    {
        [$app, $libraries, , $transport] = $this->bootTwoServers();
        $base = $transport->requestCount();

        $libraries->all();
        $app->update(new SwitchServerMsg('s2'));
        $transport->respondJson($base, ['libraries' => [['id' => 'old', 'name' => 'Stale', 'type' => 'movie']]]);

        self::assertNull($libraries->cached(), 'a library list from the previous server is not cached after the switch');
    }
}
