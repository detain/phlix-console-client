<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto;

use Phlix\Console\Api\Dto\Chapter;
use Phlix\Console\Api\Dto\Marker;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use PHPUnit\Framework\TestCase;

final class PlaybackMarkersTest extends TestCase
{
    private function response(array $overrides = []): array
    {
        return array_merge([
            'item_id' => 'm1',
            'intro_marker' => ['start_seconds' => 5, 'end_seconds' => 30],
            'outro_marker' => ['start_seconds' => 90.5, 'end_seconds' => 100],
            'chapters' => [
                ['start_seconds' => 0, 'end_seconds' => 50, 'title' => 'Part 1'],
                ['start_seconds' => 50, 'end_seconds' => 100, 'title' => 'Part 2'],
            ],
        ], $overrides);
    }

    public function testFromArrayMapsTheFlatPlaybackInfoShape(): void
    {
        $m = PlaybackMarkers::fromArray($this->response());

        self::assertSame('m1', $m->itemId);
        self::assertSame(5.0, $m->intro?->start);
        self::assertSame(30.0, $m->intro?->end);
        self::assertSame(90.5, $m->outro?->start);
        self::assertCount(2, $m->chapters);
        self::assertSame('Part 2', $m->chapters[1]->title);
        self::assertSame(50.0, $m->chapters[1]->start);
    }

    public function testNullMarkersBecomeNull(): void
    {
        $m = PlaybackMarkers::fromArray($this->response(['intro_marker' => null, 'outro_marker' => null, 'chapters' => []]));

        self::assertNull($m->intro);
        self::assertNull($m->outro);
        self::assertSame([], $m->chapters);
    }

    public function testEmptyFactory(): void
    {
        $m = PlaybackMarkers::empty();

        self::assertSame('', $m->itemId);
        self::assertNull($m->intro);
        self::assertSame([], $m->chapters);
    }

    public function testActiveSkipSelectsIntroThenOutro(): void
    {
        $m = PlaybackMarkers::fromArray($this->response());

        self::assertSame($m->intro, $m->activeSkip(10.0), 'inside the intro');
        self::assertSame($m->outro, $m->activeSkip(95.0), 'inside the outro');
        self::assertNull($m->activeSkip(60.0), 'between windows');
    }

    public function testSkipLabelMatchesTheActiveWindow(): void
    {
        $m = PlaybackMarkers::fromArray($this->response());

        self::assertSame('Skip Intro', $m->skipLabel(10.0));
        self::assertSame('Skip Outro', $m->skipLabel(95.0));
        self::assertNull($m->skipLabel(60.0));
    }

    public function testMarkerContainsIsHalfOpen(): void
    {
        $marker = new Marker(5.0, 30.0);

        self::assertFalse($marker->contains(4.9));
        self::assertTrue($marker->contains(5.0), 'inclusive start');
        self::assertTrue($marker->contains(29.9));
        self::assertFalse($marker->contains(30.0), 'exclusive end');
    }

    public function testChapterFromArray(): void
    {
        $c = Chapter::fromArray(['start_seconds' => 12.5, 'end_seconds' => 60, 'title' => 'Intro']);

        self::assertSame(12.5, $c->start);
        self::assertSame(60.0, $c->end);
        self::assertSame('Intro', $c->title);
    }

    public function testNonNumericSecondsCoerceToZero(): void
    {
        // A non-numeric/absent second falls back to 0.0 (Coerce::float default).
        $marker = Marker::fromArray(['start_seconds' => 'oops', 'end_seconds' => null]);

        self::assertSame(0.0, $marker?->start);
        self::assertSame(0.0, $marker?->end);
    }

    public function testMalformedMarkerOrChaptersDegradeGracefully(): void
    {
        // A scalar where a marker object is expected → null; a non-array chapter is skipped.
        $m = PlaybackMarkers::fromArray([
            'item_id' => 'x',
            'intro_marker' => 'nope',
            'chapters' => [['start_seconds' => 1, 'end_seconds' => 2, 'title' => 'ok'], 'bad'],
        ]);

        self::assertNull($m->intro);
        self::assertCount(1, $m->chapters, 'non-array chapter rows are dropped');
    }
}
