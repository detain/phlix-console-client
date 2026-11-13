<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Config;

use Phlix\Console\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigWsPortBoundaryTest extends TestCase
{
    public function testOutOfRangeWsPortFallsBackToDefault(): void
    {
        $path = sys_get_temp_dir() . '/phlix-ws-port-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode([
            'servers' => [
                ['id' => 'a', 'url' => 'https://a.tld', 'ws_port' => 70000],
                ['id' => 'b', 'url' => 'https://b.tld', 'ws_port' => 8097],
            ],
        ]));
        $config = Config::load($path);
        @unlink($path);

        self::assertNull($config->servers[0]->wsPort);
        self::assertSame(8097, $config->servers[1]->wsPort);
    }
}
