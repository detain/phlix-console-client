<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Api;

use Phlix\Console\Api\ApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that all four SyncPlay HTTP calls target the correct routes.
 *
 * Route contract (confirmed against SyncPlayController.php:68):
 *   POST   /api/v1/syncplay/groups           → create
 *   GET    /api/v1/syncplay/groups           → list   (response: { groups: [...] })
 *   POST   /api/v1/syncplay/groups/{id}/join → join
 *   POST   /api/v1/syncplay/groups/{id}/leave→ leave  (NOT DELETE)
 */
final class SyncPlayRequestLineTest extends TestCase
{
    public function testNoRoomsPathRemains(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        $this->assertStringNotContainsString(
            '/api/v1/syncplay/rooms',
            $source,
            'No /syncplay/rooms path should remain in ApiClient.php'
        );
    }

    public function testNoDeleteForSyncplay(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        // No DELETE method call should contain 'syncplay' anywhere on that same logical call line.
        // We search for the string 'DELETE' and confirm no 'syncplay' appears between
        // the opening quote and the closing quote of that first argument.
        $this->assertStringNotContainsString(
            "'DELETE', '/api/v1/syncplay/",
            $source,
            'DELETE must not be used for any syncplay route — use POST instead'
        );
    }

    public function testListResponseKeyIsGroups(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        $this->assertStringContainsString(
            "\$data['groups']",
            $source,
            'listSyncPlayGroups() must parse $data[\'groups\']'
        );
        $this->assertStringNotContainsString(
            "\$data['rooms']",
            $source,
            '$data[\'rooms\'] must not appear in ApiClient.php'
        );
    }

    public function testCreateGroupRoute(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        // POST /api/v1/syncplay/groups
        $this->assertStringContainsString(
            "'POST', '/api/v1/syncplay/groups', [], [",
            $source,
            'createSyncPlayGroup() must POST to /api/v1/syncplay/groups'
        );
    }

    public function testListGroupsRoute(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        // GET /api/v1/syncplay/groups
        $this->assertStringContainsString(
            "'GET', '/api/v1/syncplay/groups'",
            $source,
            'listSyncPlayGroups() must GET /api/v1/syncplay/groups'
        );
    }

    public function testJoinGroupRoute(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        // POST /api/v1/syncplay/groups/{id}/join
        $this->assertStringContainsString(
            "'POST', '/api/v1/syncplay/groups/' . rawurlencode(\$roomId) . '/join'",
            $source,
            'joinSyncPlayGroup() must POST to /api/v1/syncplay/groups/{id}/join'
        );
    }

    public function testLeaveGroupRouteUsesPost(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../src/Api/ApiClient.php');

        // POST /api/v1/syncplay/groups/{id}/leave (NOT DELETE)
        $this->assertStringContainsString(
            "'POST', '/api/v1/syncplay/groups/' . rawurlencode(\$roomId) . '/leave'",
            $source,
            'leaveSyncPlayGroup() must POST to /api/v1/syncplay/groups/{id}/leave'
        );
    }

    public function testSyncPlayGroupDtoExists(): void
    {
        $dtoPath = __DIR__ . '/../../../src/Api/Dto/SyncPlayGroup.php';
        $this->assertFileExists($dtoPath, 'SyncPlayGroup DTO must exist');
        $this->assertStringNotContainsString(
            'class SyncPlayRoom',
            file_get_contents($dtoPath),
            'SyncPlayRoom class must be renamed to SyncPlayGroup'
        );
    }

    public function testOldSyncPlayRoomDtoDoesNotExist(): void
    {
        $oldPath = __DIR__ . '/../../../src/Api/Dto/SyncPlayRoom.php';
        $this->assertFileDoesNotExist($oldPath, 'Old SyncPlayRoom.php must be deleted');
    }
}
