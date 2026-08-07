<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\SugarCraft\Screen;

/**
 * Interface for screens that display breadcrumbs in their header.
 */
interface Breadcrumbed
{
    /**
     * Returns the breadcrumb trail for this screen.
     *
     * @return list<array{label: string, screen?: mixed}>
     */
    public function crumbs(): array;

    /**
     * Returns the label for the current screen in breadcrumbs.
     */
    public function crumbLabel(): string;

    /**
     * Returns a copy of this screen with updated crumbs.
     *
     * @param list<array{label: string, screen?: mixed}> $crumbs
     */
    public function withCrumbs(array $crumbs): self;
}