<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Msg;

use SugarCraft\Core\Msg;
use SugarCraft\Reel\Subtitle\WebVtt;

/**
 * The chosen subtitle track has been fetched + parsed (or null when the item has
 * no usable text track / the fetch failed). The
 * {@see \Phlix\Console\Screen\PlayerScreen} shows its active cue while captions
 * are on.
 */
final readonly class SubtitleVttLoadedMsg implements Msg
{
    /**
     * @param WebVtt|Msg|null  $captionsOrError  Parsed VTT captions, or a Msg (e.g. ShowToastMsg) when load failed
     */
    public function __construct(
        private WebVtt|Msg|null $captionsOrError = null,
    ) {
    }

    public function getCaptions(): ?WebVtt
    {
        return $this->captionsOrError instanceof WebVtt ? $this->captionsOrError : null;
    }

    public function getError(): ?Msg
    {
        return !$this->captionsOrError instanceof WebVtt ? $this->captionsOrError : null;
    }
}
