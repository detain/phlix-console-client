<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use SugarCraft\Core\Model;

/**
 * A screen that participates in the header breadcrumb trail (Home › Movies ›
 * The Matrix › Season 1). Only the {@see \Phlix\Console\App} knows the whole
 * screen stack, so it builds the trail from each frame's {@see crumbLabel()} and
 * hands it to the top screen via {@see withCrumbs()} just before rendering — the
 * screen then passes it to its chrome. Auth screens don't implement this, so
 * they render without a breadcrumb.
 */
interface Breadcrumbed extends Model
{
    /** This screen's own label in the trail (e.g. "Home", a library, a title). */
    public function crumbLabel(): string;

    /**
     * A copy that will render $trail as its breadcrumb (clone-mutate; the
     * original is unchanged).
     *
     * @param list<string> $trail
     */
    public function withCrumbs(array $trail): static;
}
