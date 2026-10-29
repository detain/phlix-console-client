<?php

/**
 * Guard against the M-CONSOLE-23 class (review console__enduser): the manual
 * promised a "Media detail screen with cast, chapters, and recommendations"
 * and a Screens-table Detail row of "Hero poster, metadata, synopsis, cast,
 * chapters, recommendations" — while DetailScreen.php renders neither.
 * Chapters are the player's `C` overlay (PlayerScreen::openChapterMenu) and
 * "For You" recommendations are a separate screen opened only from the
 * player's `w` (OpenRecommendationsMsg → App's RecommendationsScreen).
 * What the detail screen really shows — besides hero, metadata, genres,
 * crew, cast and a scrollable synopsis — is a "More Like This" rail of
 * similar titles, fetched lazily (fetchSimilar → GET /api/v1/media/{id}/similar)
 * and rendered only when the server returns a non-empty list.
 *
 * Doctrine, per ScreenInventoryDocsTest / PlayerKeyDocsTest /
 * TokenFileNameDocsTest: the promise text is pinned verbatim, every promised
 * section noun is mapped to a real producer regex over DetailScreen.php
 * source, and the vocabulary the detail screen does NOT render is banned
 * from the detail-side promises — while the true chapter/recommendation
 * surfaces (player key rows, player producers, the Recommendations row's
 * "opened with `w` in the player" clause) must stay documented and produced,
 * so the ban can never be satisfied by erasing the honest player story.
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Docs;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DetailScreenClaimsDocsTest extends TestCase
{
    /** The manual the promises live in. */
    private const MANUAL = 'docs/clients/console.md';

    /** Section marker of the screens table. */
    private const SCREENS_SECTION = '### Screens';

    /** Row label whose text may only promise what DetailScreen renders. */
    private const DETAIL_ROW = 'Detail';

    /** Opening of the Overview feature-list bullet with the same promise duty. */
    private const BULLET_START = '- Media detail screen';

    /**
     * Vocabulary the detail screen never renders (lowercase substrings, so
     * plurals and compounds fall in too). Belongs to the player screens.
     *
     * @var list<string>
     */
    private const BANNED_ON_DETAIL = ['chapter', 'recommend'];

    /**
     * Pinned promise texts (whitespace-normalized). A reword reddens here so
     * the new sentence is re-checked against the producer map in the same
     * change — the drift ceremony of the sibling docs suites.
     */
    private const PINNED_BULLET =
        '- Media detail screen with cast, a scrollable synopsis, and a "More Like This" rail of similar titles';
    private const PINNED_DETAIL_ROW =
        'Hero poster, metadata, genres, crew, cast, and a scrollable synopsis; '
        . 'a "More Like This" rail of similar titles loads in the background and appears when the server has them';

    /**
     * Promise vocabulary → producers in src/Screen/DetailScreen.php. Each
     * phrase is matched case-insensitively against the pinned texts; every
     * phrase present must have its regex hit the screen's source.
     *
     * @var array<string, string>
     */
    private const SECTION_PRODUCERS = [
        'hero poster' => '/heroAnsi|heroPlaceholder/',
        'metadata' => '/metadataColumn/',
        'genres' => '/\$item->genres/',
        'crew' => '/appendCrewLines/',
        'cast' => '/appendCastLines/',
        'synopsis' => '/synopsisWindow/',
        'more like this' => '/renderSimilarSection/',
        'similar titles' => '/fetchSimilar/',
        'background' => '/fetchSimilar/',
    ];

    public function testTheDetailPromiseLinesCarryNoUnrenderedVocabulary(): void
    {
        $promises = [
            'the Overview feature bullet' => self::extractDetailBullet(),
            'the Screens-table **Detail** row' => self::extractScreensRow(self::DETAIL_ROW),
        ];

        foreach ($promises as $where => $text) {
            $lowered = mb_strtolower($text);
            foreach (self::BANNED_ON_DETAIL as $word) {
                self::assertStringNotContainsString(
                    $word,
                    $lowered,
                    'M-CONSOLE-23 regression: ' . $where . ' promises "' . $word . 's" again, but '
                    . 'src/Screen/DetailScreen.php renders none — chapters live in the player '
                    . '(`C`, PlayerScreen::openChapterMenu) and "For You" recommendations in a '
                    . 'screen opened from the player (`w`). Describe what the screen shows '
                    . '(hero, metadata, genres, crew, cast, synopsis, the "More Like This" rail).'
                );
            }
        }
    }

    public function testTheDetailPromiseTextsArePinnedVerbatim(): void
    {
        self::assertSame(
            self::PINNED_BULLET,
            self::extractDetailBullet(),
            'The Overview detail-screen bullet moved. Re-verify every promised noun against '
            . 'DetailScreen.php, then rotate PINNED_BULLET in the same change.'
        );

        self::assertSame(
            self::PINNED_DETAIL_ROW,
            self::extractScreensRow(self::DETAIL_ROW),
            'The Screens-table **Detail** row moved. Re-verify every promised noun against '
            . 'DetailScreen.php, then rotate PINNED_DETAIL_ROW in the same change.'
        );
    }

    public function testEveryPromisedDetailSectionStillHasAProducer(): void
    {
        $source = self::readRepoFile('src/Screen/DetailScreen.php');
        $promised = mb_strtolower(self::PINNED_BULLET . "\n" . self::PINNED_DETAIL_ROW);

        $missing = [];
        foreach (self::SECTION_PRODUCERS as $phrase => $producer) {
            if (!str_contains($promised, $phrase)) {
                continue;
            }

            if (preg_match($producer, $source) !== 1) {
                $missing[] = $phrase;
            }
        }

        self::assertSame(
            [],
            $missing,
            'The manual promises detail sections with no producer left in src/Screen/DetailScreen.php: '
            . implode(', ', $missing) . '. Remove the promise from both pinned texts (and '
            . 'SECTION_PRODUCERS) or restore the section — the two must move together.'
        );
    }

    public function testTheMoreLikeThisRailLabelIsRealAndRendered(): void
    {
        $screen = self::readRepoFile('src/Screen/DetailScreen.php');
        self::assertSame(
            1,
            preg_match("/Lang::t\('detail\.more_like_this'\)/", $screen),
            'The pinned promise names the "More Like This" rail; DetailScreen.php must still render '
            . 'Lang::t(\'detail.more_like_this\') for the phrase to be true.'
        );

        $catalog = self::readRepoFile('resources/lang/en.php');
        self::assertStringContainsString(
            "'detail.more_like_this' => 'More Like This'",
            $catalog,
            'The pinned promise spells the rail header "More Like This"; the English catalog is '
            . 'the rendered source of that wording.'
        );
    }

    public function testTheTrueChapterAndRecommendationSurfacesStayDocumentedAndProduced(): void
    {
        $doc = self::readRepoFile(self::MANUAL);

        self::assertSame(
            1,
            preg_match('/^\|\s*`C`\s*\|.*Chapter picker.*$/m', $doc),
            'The player key table lost its `C` chapter-picker row. The ban in '
            . 'testTheDetailPromiseLinesCarryNoUnrenderedVocabulary exists because chapters are '
            . 'the PLAYER\'s surface — its documentation must not be deleted to satisfy the ban.'
        );

        self::assertSame(
            1,
            preg_match('/^\|\s*`w`\s*\|.*"For You".*$/m', $doc),
            'The player key table lost its `w` "For You" row; recommendations are documented '
            . 'where they actually live (the player), not erased.'
        );

        $detailRow = self::extractScreensRow('Recommendations');
        self::assertStringContainsString(
            'opened with `w` in the player',
            $detailRow,
            'The **Recommendations** row must keep telling the user where the screen is opened '
            . 'from — the clause that replaces the false detail-screen promise.'
        );

        $player = self::readRepoFile('src/Screen/PlayerScreen.php');
        self::assertStringContainsString(
            'openChapterMenu(',
            $player,
            'PlayerScreen no longer produces the chapter picker the `C` row promises.'
        );
        self::assertStringContainsString(
            'OpenRecommendationsMsg',
            $player,
            'PlayerScreen no longer produces the `w` recommendations command.'
        );

        $app = self::readRepoFile('src/App.php');
        self::assertStringContainsString(
            'new RecommendationsScreen(',
            $app,
            'App no longer instantiates the screen the `w` row leads to.'
        );
    }

    /**
     * The Overview feature bullet opening with BULLET_START, folded across
     * its indented continuation lines and whitespace-normalized to a single
     * line (wrapped prose must not dodge the pins).
     */
    private static function extractDetailBullet(): string
    {
        $lines = explode("\n", self::readRepoFile(self::MANUAL));
        $parts = [];

        foreach ($lines as $line) {
            if ($parts === []) {
                if (str_starts_with($line, self::BULLET_START)) {
                    $parts[] = $line;
                }

                continue;
            }

            if (str_starts_with($line, '  ') && trim($line) !== '') {
                $parts[] = trim($line);
                continue;
            }

            break;
        }

        if ($parts === []) {
            throw new RuntimeException(
                'The "' . self::BULLET_START . '" feature bullet vanished from '
                . self::MANUAL . '; update ' . self::class . '.'
            );
        }

        return (string) preg_replace('/\s+/', ' ', implode(' ', $parts));
    }

    /** Row text of a bolded **label** in the docs/clients/console.md "Screens" table. */
    private static function extractScreensRow(string $label): string
    {
        $rows = self::extractScreensTable();
        if (!isset($rows[$label])) {
            throw new RuntimeException(
                'The Screens table has no **' . $label . '** row; did it move out of ' . self::MANUAL . '?'
            );
        }

        return $rows[$label];
    }

    /**
     * The "Screens" table: bolded first-cell label mapped to the trimmed row
     * text (same parsing idiom as ScreenInventoryDocsTest).
     *
     * @return array<string, string>
     */
    private static function extractScreensTable(): array
    {
        $doc = self::readRepoFile(self::MANUAL);
        $start = strpos($doc, self::SCREENS_SECTION);
        if ($start === false) {
            throw new RuntimeException(
                'The "' . self::SCREENS_SECTION . '" section moved out of ' . self::MANUAL . '.'
            );
        }

        $body = substr($doc, (int) $start);
        $end = strpos($body . "\n", "\n## ");

        $rows = [];
        foreach (explode("\n", $end === false ? $body : substr($body, 0, (int) $end)) as $line) {
            if (preg_match('/^\|\s*\*\*(.+?)\*\*\s*\|(.*)$/', trim($line), $match) === 1) {
                $rows[$match[1]] = trim($match[2], " \t|");
            }
        }

        if ($rows === []) {
            throw new RuntimeException('The Screens table parsed to zero rows; did its idiom change?');
        }

        return $rows;
    }

    private static function readRepoFile(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        if (!is_file($path)) {
            throw new RuntimeException($relative . ' moved or was deleted; update ' . self::class . '.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read ' . $relative . '.');
        }

        return $contents;
    }
}
