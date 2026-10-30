<?php

/**
 * M-CONSOLE-67 (review console__tuispec): the two typed-confirm input caps
 * must count CODEPOINTS, not bytes, so multibyte users get the same field
 * length Latin users get — the law the sibling *PendingAction helpers already
 * ship (`mb_strlen($next, 'UTF-8')`).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Screen\AdminPluginUpdateScreen;
use Phlix\Console\Screen\AdminServerRestartScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

final class AdminTypedConfirmInputTest extends TestCase
{
    /** Six 3-byte CJK runes: bytes would cap the fields long before codepoints do. */
    private const RUNES = ['上', '海', '猫', '空', '茶', '水'];

    private function adminClient(): AdminClient
    {
        return new AdminClient(new ApiClient('https://srv', new FakeTransport()));
    }

    /** Read the private typed buffer of a screen (same reflection idiom as the metrics pin). */
    private function typed(object $screen): string
    {
        return (function (): string {
            return $this->typed;
        })->call($screen);
    }

    public function testTheRestartConfirmFieldCountsCodepointsNotBytes(): void
    {
        $screen = new AdminServerRestartScreen($this->adminClient());

        // 'r' arms the typed-confirm mode ("Type \"restart\" to confirm").
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        foreach (self::RUNES as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune));
        }

        // Cap is 10: byte math (strlen) stopped at 4 runes (12 bytes ≥ 10);
        // the codepoint law keeps all six.
        self::assertSame('上海猫空茶水', $this->typed($screen));
    }

    public function testTheUpdateConfirmFieldCountsCodepointsNotBytes(): void
    {
        $screen = new AdminPluginUpdateScreen($this->adminClient());

        // 'u' arms the pending-apply typed-confirm ("Type \"update\" to confirm").
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'u'));

        $firstFour = array_slice(self::RUNES, 0, 4);
        foreach ($firstFour as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune));
        }

        // Cap is 6: byte math stopped at 2 runes (6 bytes ≥ 6);
        // the codepoint law keeps all four.
        self::assertSame('上海猫空', $this->typed($screen));
    }

    public function testLatinTypingStillRespectsTheCodepointCap(): void
    {
        $screen = new AdminServerRestartScreen($this->adminClient());
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        foreach (['r', 'e', 's', 't', 'a', 'r', 't'] as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune));
        }

        // 7 Latin chars — well inside the 10-codepoint cap; the word survives.
        self::assertSame('restart', $this->typed($screen));
    }
}
