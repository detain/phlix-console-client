<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\ServerSetting;
use Phlix\Console\Api\Dto\Admin\ServerSettings;
use PHPUnit\Framework\TestCase;

final class ServerSettingsTest extends TestCase
{
    private function dataPayload(): array
    {
        return [
            'settings' => [
                'zeta' => true,
                'alpha' => 8096,
                'gamma' => 1.5,
                'beta' => 'Phlix',
                'delta' => ['a', 'b'],
            ],
            'types' => [
                'zeta' => 'bool',
                'alpha' => 'int',
                'gamma' => 'float',
                'beta' => 'string',
                'delta' => 'json',
            ],
            'overridden' => ['alpha', 'delta'],
        ];
    }

    public function testMapsEveryTypeAndKey(): void
    {
        $settings = ServerSettings::fromArray($this->dataPayload());

        self::assertContainsOnlyInstancesOf(ServerSetting::class, $settings->settings);
        self::assertCount(5, $settings->settings);

        $byKey = [];
        foreach ($settings->settings as $setting) {
            $byKey[$setting->key] = $setting;
        }

        self::assertSame('true', $byKey['zeta']->displayValue);
        self::assertSame('bool', $byKey['zeta']->type);
        self::assertSame('8096', $byKey['alpha']->displayValue);
        self::assertSame('int', $byKey['alpha']->type);
        self::assertSame('1.5', $byKey['gamma']->displayValue);
        self::assertSame('Phlix', $byKey['beta']->displayValue);
        self::assertSame('["a","b"]', $byKey['delta']->displayValue);
    }

    public function testUsesTheTypesMapAsTheAuthoritativeKeySet(): void
    {
        // A key present in `settings` but ABSENT from `types` is not surfaced.
        $settings = ServerSettings::fromArray([
            'settings' => ['known' => 1, 'orphan' => 2],
            'types' => ['known' => 'int'],
            'overridden' => [],
        ]);

        self::assertCount(1, $settings->settings);
        self::assertSame('known', $settings->settings[0]->key);
    }

    public function testFlagsOverriddenKeys(): void
    {
        $settings = ServerSettings::fromArray($this->dataPayload());

        $byKey = [];
        foreach ($settings->settings as $setting) {
            $byKey[$setting->key] = $setting->overridden;
        }

        self::assertTrue($byKey['alpha']);
        self::assertTrue($byKey['delta']);
        self::assertFalse($byKey['beta']);
        self::assertFalse($byKey['zeta']);
    }

    public function testSortsKeysForAStableDisplay(): void
    {
        $settings = ServerSettings::fromArray($this->dataPayload());

        $keys = array_map(static fn (ServerSetting $s): string => $s->key, $settings->settings);
        self::assertSame(['alpha', 'beta', 'delta', 'gamma', 'zeta'], $keys);
    }

    public function testPullsAMissingSettingsValueAsNull(): void
    {
        // A key in `types` but not in `settings` renders as empty.
        $settings = ServerSettings::fromArray([
            'types' => ['only' => 'string'],
        ]);

        self::assertCount(1, $settings->settings);
        self::assertSame('', $settings->settings[0]->displayValue);
    }

    public function testToleratesAllMapsMissing(): void
    {
        $settings = ServerSettings::fromArray([]);

        self::assertSame([], $settings->settings);
    }

    public function testToleratesNonArrayMaps(): void
    {
        $settings = ServerSettings::fromArray([
            'settings' => 'nope',
            'types' => 7,
            'overridden' => false,
        ]);

        self::assertSame([], $settings->settings);
    }
}
