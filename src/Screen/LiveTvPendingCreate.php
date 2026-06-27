<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Admin\GuideProgram;

/**
 * A create-from-guide-program action that has been armed and is awaiting an
 * inline (y/n) confirmation on the {@see AdminLiveTvScreen}'s status line.
 * Immutable.
 *
 * `record` schedules a one-off recording of the selected program; `series`
 * creates a series-recording rule from the program's `series_id` (only ever armed
 * when that id is non-blank). Both schedule DVR work, so they confirm first.
 */
final readonly class LiveTvPendingCreate
{
    public const RECORD = 'record';
    public const SERIES = 'series';

    public function __construct(
        public string $kind,
        public GuideProgram $program,
    ) {
    }

    /** The confirm prompt, e.g. "Record 'The News'? (y/n)". */
    public function prompt(): string
    {
        $title = $this->program->title === '' ? 'this program' : $this->program->title;

        return $this->kind === self::SERIES
            ? "Record the whole series for '{$title}'? (y/n)"
            : "Record '{$title}'? (y/n)";
    }
}
