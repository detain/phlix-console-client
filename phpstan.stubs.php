<?php

declare(strict_types=1);

/**
 * PHPStan stub files for external dependencies that are referenced but not yet
 * installed or don't have type definitions in the codebase.
 */

namespace SugarCraft\Screen;

/**
 * Stub for Breadcrumbed interface.
 */
interface Breadcrumbed
{
    /**
     * @return list<array{label: string, screen?: mixed}>
     */
    public function crumbs(): array;

    public function crumbLabel(): string;

    /**
     * @param list<array{label: string, screen?: mixed}> $crumbs
     */
    public function withCrumbs(array $crumbs): self;
}

/**
 * Stub for Themed interface.
 */
interface Themed
{
    public function theme(): ?string;
}
