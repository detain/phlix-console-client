<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Cast;

use Phlix\Console\Api\Cast\CastBackend;
use Phlix\Console\Api\Dto\Cast\CastDevice;
use PHPUnit\Framework\TestCase;

final class CastDeviceTest extends TestCase
{
    public function testFromChromecastReadsTheFullRow(): void
    {
        $device = CastDevice::fromChromecast([
            'device_id' => 'cc-1',
            'name' => 'Living Room TV',
            'model' => 'Chromecast Ultra',
            'host' => '10.0.0.5',
            'address' => '10.0.0.5:8009',
        ]);

        self::assertSame(CastBackend::Chromecast, $device->backend);
        self::assertSame('cc-1', $device->id);
        self::assertSame('Living Room TV', $device->name);
        self::assertSame('Chromecast Ultra', $device->model);
        self::assertSame('10.0.0.5:8009', $device->detail, 'address is preferred over host');
        self::assertNull($device->supportsVideo);
    }

    public function testFromChromecastFallsBackToHostWhenNoAddress(): void
    {
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'TV', 'host' => '10.0.0.9']);

        self::assertSame('10.0.0.9', $device->detail);
    }

    public function testFromChromecastTolerantDefaults(): void
    {
        $device = CastDevice::fromChromecast([]);

        self::assertSame(CastBackend::Chromecast, $device->backend);
        self::assertSame('', $device->id);
        self::assertSame('', $device->name);
        self::assertNull($device->model);
        self::assertNull($device->detail);
        self::assertNull($device->supportsVideo);
    }

    public function testFromRokuReadsTheRow(): void
    {
        $device = CastDevice::fromRoku([
            'device_id' => 'roku-1',
            'name' => 'Bedroom Roku',
            'model' => 'Roku Ultra',
            'address' => '10.0.0.7',
        ]);

        self::assertSame(CastBackend::Roku, $device->backend);
        self::assertSame('roku-1', $device->id);
        self::assertSame('Bedroom Roku', $device->name);
        self::assertSame('Roku Ultra', $device->model);
        self::assertSame('10.0.0.7', $device->detail);
        self::assertNull($device->supportsVideo);
    }

    public function testFromRokuFallsBackToHost(): void
    {
        $device = CastDevice::fromRoku(['device_id' => 'roku-1', 'name' => 'Roku', 'host' => '10.0.0.8']);

        self::assertSame('10.0.0.8', $device->detail);
    }

    public function testFromRokuTolerantDefaults(): void
    {
        $device = CastDevice::fromRoku([]);

        self::assertSame('', $device->id);
        self::assertSame('', $device->name);
        self::assertNull($device->detail);
    }

    public function testFromAirPlayReadsTheRowWithSupportsVideoTrue(): void
    {
        $device = CastDevice::fromAirPlay([
            'device_id' => 'ap-1',
            'name' => 'Apple TV',
            'model' => 'AppleTV6,2',
            'address' => '10.0.0.3',
            'supports_video' => true,
        ]);

        self::assertSame(CastBackend::AirPlay, $device->backend);
        self::assertSame('ap-1', $device->id);
        self::assertSame('Apple TV', $device->name);
        self::assertSame('AppleTV6,2', $device->model);
        self::assertSame('10.0.0.3', $device->detail);
        self::assertTrue($device->supportsVideo);
    }

    public function testFromAirPlayCoercesSupportsVideoFalse(): void
    {
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-2', 'name' => 'HomePod', 'supports_video' => false]);

        self::assertFalse($device->supportsVideo);
    }

    public function testFromAirPlayCoercesSupportsVideoFromTinyint(): void
    {
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-3', 'name' => 'Speaker', 'supports_video' => 1]);

        self::assertTrue($device->supportsVideo);
    }

    public function testFromAirPlayLeavesSupportsVideoNullWhenAbsent(): void
    {
        $device = CastDevice::fromAirPlay(['device_id' => 'ap-4', 'name' => 'Old AirPort']);

        self::assertNull($device->supportsVideo, 'absent supports_video stays null (unknown)');
    }

    public function testFromAirPlayTolerantDefaults(): void
    {
        $device = CastDevice::fromAirPlay([]);

        self::assertSame('', $device->id);
        self::assertSame('', $device->name);
        self::assertNull($device->detail);
        self::assertNull($device->supportsVideo);
    }

    public function testFromDlnaReadsUdnAndFriendlyName(): void
    {
        $device = CastDevice::fromDlna([
            'udn' => 'uuid:abc-123',
            'friendly_name' => 'Samsung TV',
            'model_name' => 'UE55',
            'manufacturer' => 'Samsung',
        ]);

        self::assertSame(CastBackend::Dlna, $device->backend);
        self::assertSame('uuid:abc-123', $device->id, 'id reads udn for DLNA');
        self::assertSame('Samsung TV', $device->name, 'name reads friendly_name for DLNA');
        self::assertSame('UE55', $device->model);
        self::assertSame('Samsung', $device->detail, 'detail is the manufacturer for DLNA');
        self::assertNull($device->supportsVideo);
    }

    public function testFromDlnaTolerantDefaults(): void
    {
        $device = CastDevice::fromDlna([]);

        self::assertSame('', $device->id);
        self::assertSame('', $device->name);
        self::assertNull($device->model);
        self::assertNull($device->detail);
    }

    public function testLabelCombinesNameAndBackend(): void
    {
        $device = CastDevice::fromChromecast(['device_id' => 'cc-1', 'name' => 'Living Room TV']);

        self::assertSame('Living Room TV · Chromecast', $device->label());
    }

    public function testDlnaLabelUsesFriendlyName(): void
    {
        $device = CastDevice::fromDlna(['udn' => 'uuid:1', 'friendly_name' => 'Samsung TV']);

        self::assertSame('Samsung TV · DLNA', $device->label());
    }
}
