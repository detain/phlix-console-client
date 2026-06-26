<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Admin\GuideProgram;
use PHPUnit\Framework\TestCase;

final class GuideProgramTest extends TestCase
{
    public function testFromArrayMapsAFullRow(): void
    {
        $p = GuideProgram::fromArray([
            'id' => 'p-1',
            'program_id' => 'EP00112233.0001',
            'channel_id' => 'wxyz.1',
            'title' => 'The Show',
            'description' => 'A fine episode.',
            'start_time' => 1750000000,
            'end_time' => 1750003600,
            'category' => 'Series',
            'season' => 2,
            'episode' => 5,
            'episode_title' => 'Pilot',
            'rating' => 'TV-14',
        ]);

        self::assertSame('p-1', $p->id);
        self::assertSame('EP00112233.0001', $p->programId);
        self::assertSame('wxyz.1', $p->channelId);
        self::assertSame('The Show', $p->title);
        self::assertSame(1750000000, $p->startTime);
        self::assertSame(1750003600, $p->endTime);
        self::assertSame('Series', $p->category);
        self::assertSame('A fine episode.', $p->description);
        self::assertSame(2, $p->season);
        self::assertSame(5, $p->episode);
    }

    public function testFromArrayTolerantDefaults(): void
    {
        $p = GuideProgram::fromArray([]);

        self::assertSame('', $p->id);
        self::assertSame('', $p->programId);
        self::assertSame('', $p->channelId);
        self::assertSame('', $p->title);
        self::assertSame(0, $p->startTime);
        self::assertSame(0, $p->endTime);
        self::assertNull($p->category);
        self::assertNull($p->description);
        self::assertNull($p->season);
        self::assertNull($p->episode);
    }

    public function testEpisodeLabelWithSeasonAndEpisode(): void
    {
        self::assertSame('S02E05', GuideProgram::fromArray(['season' => 2, 'episode' => 5])->episodeLabel());
    }

    public function testEpisodeLabelWithSeasonOnly(): void
    {
        self::assertSame('S03', GuideProgram::fromArray(['season' => 3])->episodeLabel());
    }

    public function testEpisodeLabelWithEpisodeOnly(): void
    {
        self::assertSame('E07', GuideProgram::fromArray(['episode' => 7])->episodeLabel());
    }

    public function testEpisodeLabelEmptyWhenNeitherKnown(): void
    {
        self::assertSame('', GuideProgram::fromArray([])->episodeLabel());
    }

    public function testEpochTimesCoerceNumericStrings(): void
    {
        $p = GuideProgram::fromArray(['start_time' => '1750000000', 'end_time' => '1750003600']);

        self::assertSame(1750000000, $p->startTime);
        self::assertSame(1750003600, $p->endTime);
    }
}
