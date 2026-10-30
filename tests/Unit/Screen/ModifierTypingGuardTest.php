<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use PHPUnit\Framework\TestCase;

/**
 * [review console__tuispec, M-CONSOLE-65] text-append key handlers must ignore
 * `ctrl`/`alt`: the terminal still reports the printable rune under a modifier,
 * and an unguarded append turns e.g. alt+s into a literal "s" in the field.
 *
 * The four typing surfaces named by the review fold their guard into the same
 * line shape; three are exercised behaviorally in their own suites
 * (AppTest / SearchScreenTest / FilterBarTest), while the fourth — the
 * SyncPlay room-name append inside PlayerScreen's deep modal state machine —
 * is pinned here at the source level, the law applying to every site equally.
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
