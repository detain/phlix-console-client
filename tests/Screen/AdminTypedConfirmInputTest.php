<?php

/**
 * M-CONSOLE-67 (review console__tuispec): the two typed-confirm input caps
 * must count CODEPOINTS, not bytes, so multibyte users get the same field
 * length Latin users get — the law the sibling *PendingAction helpers already
 * ship (`mb_strlen($next, 'UTF-8')`).
 *
 * CU-030 (review console__writer): all typed-confirm prompts quote the magic
 * word with single quotes; Enter-prose only appears where Enter is required.
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

        // 'r' arms the typed-confirm mode ("Type 'restart' and press Enter to confirm").
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

        // 'u' arms the pending-apply typed-confirm ("Type 'update' and press Enter to confirm").
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

    // [review console__writer #4/#5, CU-030 — supersedes the M-CONSOLE-14 two-
    // family law recorded below] Every typed-confirm prompt now quotes the
    // magic word with single quotes — the form the *PendingAction family always
    // used; the admin screens joined it. Enter-prose tracks the real gate: the
    // two admin screens demand Enter (their handleConfirmKey submits on it) and
    // say so; the pending actions fire the instant the buffer matches and say
    // nothing about Enter.
    //
    // [original M-CONSOLE-14 law, historical] The double-quoted typed-confirm
    // family (the two admin screens) said "Type \"x\" to confirm" in one place
    // and "Type \"x\" and press Enter to confirm" in another — the same screen
    // contradicted itself; that round unified within the family only, and
    // CU-030 unified across both.

    public function testTheTypedConfirmPromptsQuoteTheMagicWordWithSingleQuotes(): void
    {
        $offenders = [];
        $found = 0;
        $paths = glob(dirname(__DIR__, 2) . '/src/Screen/*.php');
        self::assertIsArray($paths);

        foreach ($paths as $path) {
            $code = (string) file_get_contents($path);
            if (preg_match_all('/Type (.{0,30}?)to confirm/u', $code, $hits) < 1) {
                continue;
            }

            foreach ($hits[1] as $word) {
                $found++;

                if (preg_match("/^'[^'\\n]{0,24}'( and press Enter)?$/u", trim($word)) !== 1) {
                    $offenders[] = basename($path) . ': Type ' . $word . 'to confirm';
                }
            }
        }

        // Anti-vacuous floor: the eight shipped prompt literals must stay found.
        self::assertGreaterThanOrEqual(8, $found, 'the typed-confirm scan stopped seeing the family it guards [CU-030]');
        self::assertSame([], $offenders, 'typed-confirm prompts must quote the magic word with single quotes [CU-030]');
    }

    public function testTheConfirmTemplateIsActuallyLiveInTheAdminScreens(): void
    {
        $restart = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Screen/AdminServerRestartScreen.php');
        $update = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Screen/AdminPluginUpdateScreen.php');

        self::assertStringContainsString("Type 'restart' and press Enter to confirm", $restart);
        self::assertStringContainsString("Type 'update' and press Enter to confirm", $update);

        // [review console__writer #5] The restart screen serves both its render
        // and its wrong-word toast from the single const — no duplicated literal.
        self::assertStringContainsString('ShowToastMsg::error(self::RESTART_CONFIRM', $restart);
    }

    // [CONSOLIDATED M16] The InputReader decodes Ctrl+letter as a Char carrying
    // the letter's rune plus ctrl:true. Unguarded append arms turned such chords
    // (and alt chords) into confirmation text — a destructive command one
    // accidental keystroke-combination away. The buffers must stay empty.

    public function testCtrlChordsNeverTypeIntoTheRestartConfirmation(): void
    {
        $screen = new AdminServerRestartScreen($this->adminClient());
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        foreach (str_split('restart') as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune, ctrl: true));
        }

        self::assertSame('', $this->typed($screen), 'ctrl+letter chords must not fill the restart confirm buffer');
    }

    public function testAltChordsNeverTypeIntoTheUpdateConfirmation(): void
    {
        $screen = new AdminPluginUpdateScreen($this->adminClient());
        [$screen] = $screen->update(new KeyMsg(KeyType::Char, 'u'));

        foreach (str_split('update') as $rune) {
            [$screen] = $screen->update(new KeyMsg(KeyType::Char, $rune, alt: true));
        }

        self::assertSame('', $this->typed($screen), 'alt+letter chords must not fill the update confirm buffer');
    }
}
