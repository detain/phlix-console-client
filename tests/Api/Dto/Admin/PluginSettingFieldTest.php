<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\PluginSettingField;
use PHPUnit\Framework\TestCase;

final class PluginSettingFieldTest extends TestCase
{
    public function testHoldsTheSchemaMetadataAndRenderedValue(): void
    {
        $field = PluginSettingField::fromParts('api_key', 'string', 'API Key', 'Your key', true, false, 'abc');

        self::assertSame('api_key', $field->key);
        self::assertSame('string', $field->type);
        self::assertSame('API Key', $field->label);
        self::assertSame('Your key', $field->description);
        self::assertTrue($field->required);
        self::assertFalse($field->secret);
        self::assertSame('abc', $field->value);
    }

    public function testRendersABoolValueAsTrueOrFalse(): void
    {
        self::assertSame('true', PluginSettingField::fromParts('k', 'bool', '', '', false, false, true)->value);
        self::assertSame('false', PluginSettingField::fromParts('k', 'bool', '', '', false, false, false)->value);
    }

    public function testRendersAnArrayValueAsCompactJson(): void
    {
        $field = PluginSettingField::fromParts('k', 'json', '', '', false, false, ['a', 'b']);

        self::assertSame('["a","b"]', $field->value);
    }

    public function testRendersAScalarValueAsAString(): void
    {
        self::assertSame('8096', PluginSettingField::fromParts('k', 'int', '', '', false, false, 8096)->value);
        self::assertSame('1.5', PluginSettingField::fromParts('k', 'float', '', '', false, false, 1.5)->value);
    }

    public function testRendersNullAndNonScalarAsEmptyString(): void
    {
        self::assertSame('', PluginSettingField::fromParts('k', 'string', '', '', false, false, null)->value);
    }

    public function testCarriesAMaskedSecretValueVerbatim(): void
    {
        // A secret's value arrives already MASKED from the server; the field keeps
        // it as-is (rendered as a scalar string).
        $field = PluginSettingField::fromParts('token', 'string', 'Token', '', false, true, '••••');

        self::assertTrue($field->secret);
        self::assertSame('••••', $field->value);
    }

    public function testDisplayLabelFallsBackToTheKey(): void
    {
        self::assertSame('API Key', PluginSettingField::fromParts('api_key', 'string', 'API Key', '', false, false, '')->displayLabel());
        self::assertSame('api_key', PluginSettingField::fromParts('api_key', 'string', '', '', false, false, '')->displayLabel());
    }

    public function testIsBoolReflectsTheType(): void
    {
        self::assertTrue(PluginSettingField::fromParts('k', 'bool', '', '', false, false, true)->isBool());
        self::assertFalse(PluginSettingField::fromParts('k', 'string', '', '', false, false, 'x')->isBool());
    }

    public function testKindNormalizesTheShortFormTypes(): void
    {
        self::assertSame('bool', PluginSettingField::fromParts('k', 'bool', '', '', false, false, true)->kind());
        self::assertSame('int', PluginSettingField::fromParts('k', 'int', '', '', false, false, 1)->kind());
        self::assertSame('float', PluginSettingField::fromParts('k', 'float', '', '', false, false, 1.0)->kind());
        self::assertSame('json', PluginSettingField::fromParts('k', 'json', '', '', false, false, [])->kind());
        self::assertSame('string', PluginSettingField::fromParts('k', 'string', '', '', false, false, '')->kind());
    }

    public function testKindNormalizesTheJsonSchemaLongFormTypes(): void
    {
        // Third-party manifests (e.g. phlix-plugin-trakt) use JSON-Schema names.
        self::assertSame('bool', PluginSettingField::fromParts('k', 'boolean', '', '', false, false, true)->kind());
        self::assertSame('int', PluginSettingField::fromParts('k', 'integer', '', '', false, false, 1)->kind());
        self::assertSame('float', PluginSettingField::fromParts('k', 'number', '', '', false, false, 1.0)->kind());
        self::assertSame('json', PluginSettingField::fromParts('k', 'array', '', '', false, false, [])->kind());
        self::assertSame('json', PluginSettingField::fromParts('k', 'object', '', '', false, false, [])->kind());
    }

    public function testKindFallsBackToStringForAnUnknownType(): void
    {
        self::assertSame('string', PluginSettingField::fromParts('k', 'mystery', '', '', false, false, 'x')->kind());
        self::assertSame('string', PluginSettingField::fromParts('k', '', '', '', false, false, 'x')->kind());
    }

    public function testIsBoolRecognizesTheJsonSchemaBooleanLongForm(): void
    {
        self::assertTrue(PluginSettingField::fromParts('k', 'boolean', '', '', false, false, true)->isBool());
        self::assertFalse(PluginSettingField::fromParts('k', 'integer', '', '', false, false, 1)->isBool());
    }

    public function testBoolValueReadsTheDisplayString(): void
    {
        self::assertTrue(PluginSettingField::fromParts('k', 'bool', '', '', false, false, true)->boolValue());
        self::assertFalse(PluginSettingField::fromParts('k', 'bool', '', '', false, false, false)->boolValue());
        // any non-'true' string reads false.
        self::assertFalse(PluginSettingField::fromParts('k', 'bool', '', '', false, false, 'nope')->boolValue());
    }
}
