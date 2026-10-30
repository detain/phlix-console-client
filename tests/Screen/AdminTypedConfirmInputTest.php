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

        // 'r' arms the typed-confirm mode ("Type \"restart\" and press Enter to confirm").
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

        // 'u' arms the pending-apply typed-confirm ("Type \"update\" and press Enter to confirm").
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

    // [review console__copywriter m7, M-CONSOLE-14] The double-quoted typed-
    // confirm family (the two admin screens) said "Type \"x\" to confirm" in
    // one place and "Type \"x\" and press Enter to confirm" in another — the
    // same screen contradicted itself. Law: one template for the family. The
    // single-quote pending-action prompts (PlaylistPendingAction et al.) are a
    // separate, internally consistent family and stay as they are.

    public function testTheDoubleQuotedConfirmPromptsFollowOneTemplate(): void
    {
        $offenders = [];
        $paths = glob(dirname(__DIR__, 2) . '/src/Screen/*.php');
        self::assertIsArray($paths);

        foreach ($paths as $path) {
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                if (preg_match('/Type "/', $token[1]) !== 1) {
                    continue;
                }

                if (preg_match('/^Type "\w+" and press Enter to confirm[:.]?\s*$/', trim(trim($token[1], "'\""))) !== 1) {
                    $offenders[] = basename($path) . ': ' . $token[1];
                }
            }
        }

        self::assertSame([], $offenders, 'typed-confirm wording drifted off the single template [M-CONSOLE-14]');
    }

    public function testTheConfirmTemplateIsActuallyLiveInTheAdminScreens(): void
    {
        $restart = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Screen/AdminServerRestartScreen.php');
        $update = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Screen/AdminPluginUpdateScreen.php');

        self::assertStringContainsString('Type "restart" and press Enter to confirm', $restart);
        self::assertStringContainsString('Type "update" and press Enter to confirm', $update);
    }
}
