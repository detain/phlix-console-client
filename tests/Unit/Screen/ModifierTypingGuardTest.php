<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use PHPUnit\Framework\TestCase;

/**
 * [review console__tuispec, M-CONSOLE-65] text-append key handlers must ignore
 * `ctrl`/`alt`: the terminal still reports the printable rune under a modifier,
 * and an unguarded append turns e.g. alt+s into a literal "s" in the field.
 *
 * The four original typing surfaces named by the review fold their guard into
 * the same line shape; three are exercised behaviorally in their own suites
 * (AppTest / SearchScreenTest / FilterBarTest), while the fourth — the
 * SyncPlay room-name append inside PlayerScreen's deep modal state machine —
 * is pinned here at the source level, the law applying to every site equally.
 *
 * [CONSOLIDATED M16] widens the census to the screens the follow-up review
 * caught still appending modifier-tainted runes: the four word-confirmations
 * guarding destructive actions (playlist delete, collection delete, duplicate
 * merge, library delete-all), the library create-form field, the restart and
 * update confirmations, and the modifier-blind plain-letter command arms on
 * Admin Settings (q/r/e) and Settings (l/s). A confirmation buffer that
 * accepts Ctrl+d is a destructive action one accidental chord away.
 */
final class ModifierTypingGuardTest extends TestCase
{
    /** The guarded Char-append line shared by the palette, Search and FilterBar. */
    private const GUARDED_CHAR_APPEND =
        '$msg->type === KeyType::Char && $msg->rune !== \'\' && !$msg->ctrl && !$msg->alt';

    /** The guarded Space-append line shared by the palette and Search/FilterBar. */
    private const GUARDED_SPACE_APPEND =
        '$msg->type === KeyType::Space && !$msg->ctrl && !$msg->alt';

    /** The guarded room-name append inside PlayerScreen's creation state. */
    private const GUARDED_MODAL_APPEND =
        '$msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && $menu->state() === 1';

    /** The guarded restart-confirmation append (codepoint cap — M-CONSOLE-67). */
    private const GUARDED_RESTART_APPEND =
        '$msg->type === KeyType::Char && mb_strlen($this->typed, \'UTF-8\') < 10 && !$msg->ctrl && !$msg->alt';

    /** The guarded update-confirmation bail shape (fall-through, not append). */
    private const GUARDED_UPDATE_BAIL =
        '$msg->type !== KeyType::Char || $msg->ctrl || $msg->alt || $msg->rune === \'\'';

    /** The shared modifier-free precondition behind Admin Settings' letter commands. */
    private const GUARDED_PLAIN_CHAR =
        '$plainChar = $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt';

    /**
     * @return array<string, array{0: string, 1: string}> file => guarded line
     */
    private static function sites(): array
    {
        return [
            'palette (App)' => ['App.php', self::GUARDED_CHAR_APPEND],
            'search (SearchScreen)' => ['Screen/SearchScreen.php', self::GUARDED_CHAR_APPEND],
            'filter (FilterBar)' => ['Ui/FilterBar.php', self::GUARDED_CHAR_APPEND],
            'room name (PlayerScreen)' => ['Screen/PlayerScreen.php', self::GUARDED_MODAL_APPEND],
            'playlist delete confirm' => ['Screen/PlaylistsScreen.php', self::GUARDED_CHAR_APPEND],
            'collection delete confirm' => ['Screen/CollectionsScreen.php', self::GUARDED_CHAR_APPEND],
            'duplicate merge confirm' => ['Screen/AdminDuplicatesScreen.php', self::GUARDED_CHAR_APPEND],
            'library delete-all confirm' => ['Screen/AdminLibrariesScreen.php', self::GUARDED_CHAR_APPEND],
            'restart confirm' => ['Screen/AdminServerRestartScreen.php', self::GUARDED_RESTART_APPEND],
            'update confirm' => ['Screen/AdminPluginUpdateScreen.php', self::GUARDED_UPDATE_BAIL],
            'admin settings letter commands' => ['Screen/AdminSettingsScreen.php', self::GUARDED_PLAIN_CHAR],
        ];
    }

    public function testEveryNamedTypingSurfaceGuardsItsCharAppend(): void
    {
        foreach (self::sites() as $label => [$rel, $needle]) {
            $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/' . $rel);

            self::assertStringContainsString(
                $needle,
                $contents,
                "the {$label} Char-append must carry the M-CONSOLE-65 ctrl/alt guard",
            );
        }
    }

    public function testTheSpaceArmsCarryTheSameGuard(): void
    {
        // The Space key reaches the same buffers as a printable rune; the review
        // scoped "Char-typing guards" but a ctrl/alt+Space injection is the very
        // same defect class, so the guard covers it too.
        foreach (['App.php', 'Screen/SearchScreen.php', 'Ui/FilterBar.php'] as $rel) {
            $contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/' . $rel);

            self::assertStringContainsString(
                self::GUARDED_SPACE_APPEND,
                $contents,
                "the {$rel} Space-append must carry the M-CONSOLE-65 guard",
            );
        }
    }
}
