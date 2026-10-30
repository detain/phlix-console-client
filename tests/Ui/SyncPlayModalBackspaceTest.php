<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Ui\SyncPlayModal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression pin for M-CONSOLE-43: backspace() in the room-name input must cut
 * one Unicode CODEPOINT, never one byte. The pre-fix strlen()/substr() version
 * sliced a single byte off UTF-8 names, leaving a dangling partial sequence on
 * screen (mojibake tail) for every multi-byte character.
 *
 * Granularity law (deliberate, documented in SyncPlayModal::backspace()):
 * removal is per codepoint, not per grapheme cluster. A base character plus
 * combining marks takes one backspace per mark; grapheme clustering would need
 * ext-intl, which composer.json does not require and the CI matrix does not
 * install. mb_substr(..., 'UTF-8') is the house idiom (see FilterBar and
 * SearchScreen backspace paths).
 *
 * All multi-byte fixtures below are written as \u{} escapes, never literals,
 * so source-file normalization can never silently change what is tested.
 */
final class SyncPlayModalBackspaceTest extends TestCase
{
    /**
     * Open the modal and enter the room-creation state (state 1, empty name).
     */
    private function createMode(): SyncPlayModal
    {
        [$modal, $roomId] = SyncPlayModal::open([], 80, 24)->select();

        self::assertNull($roomId, 'cursor 0 enters creation, never joins');
        self::assertSame(1, $modal->state());

        return $modal;
    }

    public function testPlainAsciiStillCutsOneCharAtATime(): void
    {
        $modal = $this->createMode()->appendChar('abc');

        self::assertSame('ab', $modal->backspace()->roomName());
        self::assertSame('a', $modal->backspace()->backspace()->roomName());
        self::assertSame('', $modal->backspace()->backspace()->backspace()->roomName());
    }

    public function testBackspaceOnEmptyNameStaysEmpty(): void
    {
        self::assertSame('', $this->createMode()->backspace()->roomName());
    }

    public function testFourByteEmojiIsRemovedAsOneCodepoint(): void
    {
        $modal = $this->createMode()->appendChar("Room \u{1F680}");

        $after = $modal->backspace()->roomName();

        // Exactly the trailing space must remain — never a partial
        // \xF0\x9F\x9A sequence, which is what byte-wise substr() left behind.
        self::assertSame('Room ', $after);
        self::assertTrue(mb_check_encoding($after, 'UTF-8'), 'no mojibake remnant');
        // One more keystroke lands on the space: the emoji was one unit.
        self::assertSame('Room', $modal->backspace()->backspace()->roomName());
    }

    public function testTwoByteComposedAccentedCharIsRemovedAsOneCodepoint(): void
    {
        $modal = $this->createMode()->appendChar("caf\u{00E9}");

        self::assertSame('caf', $modal->backspace()->roomName());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function combiningSequenceProvider(): array
    {
        return [
            // 'café' decomposed: base e + combining acute (U+0301)
            'combining acute' => ["caf\u{0065}\u{0301}", "caf\u{0065}", 'caf'],
            // Two women joined by ZWJ: one keystroke removes the last
            // codepoint; the ZWJ is its own deletion step afterwards.
            'zwj sequence' => ["\u{1F469}\u{200D}\u{1F469}", "\u{1F469}\u{200D}", "\u{1F469}"],
            // M43's headline example: CJK — every character is a 3-byte BMP
            // codepoint, the width the 2B/4B pins only bounded by sandwich.
            'cjk characters' => ["\u{4F1A}\u{8B70}\u{5BA4}", "\u{4F1A}\u{8B70}", "\u{4F1A}"],
        ];
    }

    /**
     * Codepoint law, not grapheme law: one backspace removes exactly one
     * codepoint, so a combining mark or ZWJ takes its own keystroke. Every
     * intermediate must remain valid UTF-8 — that is the regression (the
     * byte-wise substr orphaned half of a sequence).
     *
     * @param string $typed  full name put into the field
     * @param string $first  expected name after one backspace
     * @param string $second expected name after two backspaces
     */
    #[DataProvider('combiningSequenceProvider')]
    public function testCombiningSequencesDeleteCodepointByCodepointWithValidUtf8Throughout(
        string $typed,
        string $first,
        string $second,
    ): void {
        $modal = $this->createMode()->appendChar($typed);

        $once = $modal->backspace()->roomName();
        $twice = $modal->backspace()->backspace()->roomName();

        self::assertSame($first, $once);
        self::assertSame($second, $twice);
        self::assertTrue(mb_check_encoding($once, 'UTF-8'), 'intermediate stays valid UTF-8');
        self::assertTrue(mb_check_encoding($twice, 'UTF-8'), 'intermediate stays valid UTF-8');
    }

    public function testBackspaceOutsideCreationStateIsNoOp(): void
    {
        $modal = SyncPlayModal::open([], 80, 24);

        self::assertSame($modal, $modal->backspace(), 'state 0 ignores backspace');

        $joining = $this->createMode()->appendChar('Room')->joining();
        self::assertSame($joining, $joining->backspace(), 'state 2 ignores backspace');
    }
}
