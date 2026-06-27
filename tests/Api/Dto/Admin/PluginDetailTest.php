<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\PluginDetail;
use PHPUnit\Framework\TestCase;

final class PluginDetailTest extends TestCase
{
    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'name' => 'trakt',
            'version' => '1.2.3',
            'type' => 'scrobbler',
            'enabled' => true,
            'installed_at' => '2026-06-26T12:00:00-04:00',
            'settings_schema' => [
                'api_key' => ['type' => 'string', 'required' => true, 'secret' => true, 'label' => 'API Key', 'description' => 'Your key'],
                'enabled' => ['type' => 'bool', 'required' => false, 'secret' => false, 'label' => 'On'],
                'limit' => ['type' => 'int', 'label' => 'Limit'],
            ],
            'settings' => [
                'api_key' => '••••',
                'enabled' => true,
                'limit' => 50,
            ],
        ];
    }

    public function testMapsTheHeaderAndBuildsFieldsFromTheSchema(): void
    {
        $detail = PluginDetail::fromArray($this->payload());

        self::assertSame('trakt', $detail->name);
        self::assertSame('1.2.3', $detail->version);
        self::assertSame('scrobbler', $detail->type);
        self::assertTrue($detail->enabled);
        self::assertSame('2026-06-26T12:00:00-04:00', $detail->installedAt);
        self::assertCount(3, $detail->fields);
    }

    public function testFieldsAreKeyedBySchemaInOrderWithValuesFromSettings(): void
    {
        $detail = PluginDetail::fromArray($this->payload());

        self::assertSame('api_key', $detail->fields[0]->key);
        self::assertSame('string', $detail->fields[0]->type);
        self::assertSame('API Key', $detail->fields[0]->label);
        self::assertSame('Your key', $detail->fields[0]->description);
        self::assertTrue($detail->fields[0]->required);
        self::assertTrue($detail->fields[0]->secret);
        self::assertSame('••••', $detail->fields[0]->value, 'a secret value comes through masked');

        self::assertSame('enabled', $detail->fields[1]->key);
        self::assertSame('bool', $detail->fields[1]->type);
        self::assertSame('true', $detail->fields[1]->value);

        self::assertSame('limit', $detail->fields[2]->key);
        self::assertSame('int', $detail->fields[2]->type);
        self::assertSame('50', $detail->fields[2]->value);
    }

    public function testTypeDefaultsToStringWhenTheSchemaOmitsIt(): void
    {
        $detail = PluginDetail::fromArray([
            'settings_schema' => ['note' => ['label' => 'Note']],
            'settings' => ['note' => 'hi'],
        ]);

        self::assertSame('string', $detail->fields[0]->type);
        self::assertSame('hi', $detail->fields[0]->value);
    }

    public function testAFieldWithNoSettingsValueRendersBlank(): void
    {
        $detail = PluginDetail::fromArray([
            'settings_schema' => ['k' => ['type' => 'string']],
            'settings' => [],
        ]);

        self::assertSame('', $detail->fields[0]->value);
    }

    public function testSkipsNonArraySchemaEntries(): void
    {
        $detail = PluginDetail::fromArray([
            'settings_schema' => ['good' => ['type' => 'string'], 'bad' => 'nope'],
            'settings' => [],
        ]);

        self::assertCount(1, $detail->fields);
        self::assertSame('good', $detail->fields[0]->key);
    }

    public function testTolerantOfAnEmptyOrThinBody(): void
    {
        $detail = PluginDetail::fromArray([]);

        self::assertSame('', $detail->name);
        self::assertSame('', $detail->version);
        self::assertSame('', $detail->type);
        self::assertFalse($detail->enabled);
        self::assertNull($detail->installedAt);
        self::assertSame([], $detail->fields);
    }

    public function testTolerantOfANonArraySchemaMap(): void
    {
        $detail = PluginDetail::fromArray(['name' => 'x', 'settings_schema' => 'nope', 'settings' => 'nope']);

        self::assertSame('x', $detail->name);
        self::assertSame([], $detail->fields);
    }
}
