<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Ui\Theme;

/**
 * Implements {@see Themed} once for every screen: a screen `use`s this trait,
 * adds {@see Themed} to its `implements` list, and passes {@see theme()} to its
 * {@see \Phlix\Console\Ui\Chrome::frame()} call. The theme is held in a private
 * mutable field, copied via clone-mutate (the established screen idiom), and
 * defaults to {@see Theme::nocturne()} so an un-themed screen renders exactly as
 * it did before — no constructor change, no behaviour change.
 */
trait ThemedScreen
{
    private ?Theme $theme = null;

    public function withTheme(Theme $theme): static
    {
        $next = clone $this;
        $next->theme = $theme;

        return $next;
    }

    /** The active theme, defaulting to the identity (Nocturne) when unset. */
    private function theme(): Theme
    {
        return $this->theme ?? Theme::nocturne();
    }
}
