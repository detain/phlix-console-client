<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Ui;

use Phlix\Console\Api\Dto\Rendition;
use Phlix\Console\Ui\QualityMenu;
use PHPUnit\Framework\TestCase;

final class QualityMenuTest extends TestCase
{
    /**
     * @param list<array{string, ?string}> $rungs [id, url] pairs (url null = not playable)
     * @return list<Rendition>
     */
    private function variants(array $rungs): array
    {
        return array_map(
            static fn (array $r): Rendition => Rendition::fromArray(['id' => $r[0], 'label' => $r[0], 'url' => $r[1]]),
            $rungs,
        );
    }

    /** @return list<Rendition> a standard 1080p/720p/480p ladder, all playable */
    private function ladder(): array
    {
        return $this->variants([
            ['1080p', '/hls/j1/media_v1080p.m3u8'],
            ['720p', '/hls/j1/media_v720p.m3u8'],
            ['480p', '/hls/j1/media_v480p.m3u8'],
        ]);
    }

    public function testAutoIsTheFirstRowAndTheDefault(): void
    {
        $menu = QualityMenu::open($this->ladder(), null, 80, 24);

        self::assertSame(0, $menu->cursor());
        self::assertTrue($menu->isAuto());
        self::assertNull($menu->selectedRendition(), 'the Auto row maps to no pinned rung');

        $labels = $menu->optionLabels();
        self::assertStringContainsString('Auto', $labels[0]);
        self::assertSame(['Auto (recommended)', '1080p', '720p', '480p'], $labels);
    }

    public function testOpenPreselectsThePinnedRendition(): void
    {
        $menu = QualityMenu::open($this->ladder(), '720p', 80, 24);

        self::assertSame(2, $menu->cursor(), 'cursor lands on the pinned rung (+1 for the Auto row)');
        self::assertFalse($menu->isAuto());
        self::assertSame('720p', $menu->selectedRendition()?->id);
    }

    public function testUnknownPinFallsBackToAuto(): void
    {
        $menu = QualityMenu::open($this->ladder(), '4320p', 80, 24);

        self::assertTrue($menu->isAuto());
        self::assertSame(0, $menu->cursor());
    }

    public function testCursorNavigationClampsToTheEnds(): void
    {
        $menu = QualityMenu::open($this->ladder(), null, 80, 24);

        self::assertSame(0, $menu->up()->cursor(), 'up from Auto stays at Auto');

        $down = $menu->down();
        self::assertSame(1, $down->cursor());
        self::assertSame('1080p', $down->selectedRendition()?->id);

        // Walk past the bottom — clamps to the last rung (480p at index 3).
        $bottom = $menu->down()->down()->down()->down()->down();
        self::assertSame(3, $bottom->cursor());
        self::assertSame('480p', $bottom->selectedRendition()?->id);
    }

    public function testNonPlayableRungsAreDropped(): void
    {
        $menu = QualityMenu::open(
            $this->variants([
                ['1080p', '/hls/j1/media_v1080p.m3u8'],
                ['720p', null], // pre-flight preview rung — no signed url, not pinnable
                ['480p', ''],
            ]),
            null,
            80,
            24,
        );

        self::assertSame(['Auto (recommended)', '1080p'], $menu->optionLabels());
    }

    public function testEmptyLadderStillOffersAuto(): void
    {
        $menu = QualityMenu::open([], null, 80, 24);

        self::assertSame(['Auto (recommended)'], $menu->optionLabels());
        self::assertTrue($menu->isAuto());
        self::assertSame(0, $menu->down()->cursor(), 'nothing below Auto to move to');
    }

    public function testRenderCompositesTheRungsOverTheBackground(): void
    {
        $background = implode("\n", array_fill(0, 24, str_repeat('x', 80)));
        $out = QualityMenu::open($this->ladder(), '720p', 80, 24)->render($background);

        self::assertStringContainsString('1080p', $out);
        self::assertStringContainsString('720p', $out);
        self::assertStringContainsString('Auto', $out);
    }

    public function testResizedToRefitsTheBoxAndPreservesTheSelection(): void
    {
        // Open wide (720p pinned → cursor on the 720p row), then shrink the
        // terminal: the box must re-fit the new width without losing the
        // highlighted rung or its rows.
        $menu = QualityMenu::open($this->ladder(), '720p', 120, 40);
        self::assertSame(2, $menu->cursor());

        $resized = $menu->resizedTo(30, 12);

        self::assertSame(2, $resized->cursor(), 'the resize keeps the pinned rung highlighted');
        self::assertSame(['Auto (recommended)', '1080p', '720p', '480p'], $resized->optionLabels());

        // The narrower box still composites cleanly over a matching backdrop.
        $background = implode("\n", array_fill(0, 12, str_repeat('x', 30)));
        $out = $resized->render($background);
        self::assertStringContainsString('720p', $out);
        self::assertStringContainsString('Auto', $out);
    }
}
