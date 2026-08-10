<?php

declare(strict_types=1);

/**
 * PHPStan stub files for external dependencies that are referenced but not yet
 * installed or don't have type definitions in the codebase.
 */

namespace SugarCraft\Core;

/**
 * Stub extending the vendor Model interface with test accessor methods.
 * These accessors (route(), screen(), stackDepth(), palette(), etc.) are
 * convenience methods for writing assertions in tests and are implemented
 * by concrete classes like App but not declared in the interface itself.
 *
 * @property int $cols
 * @property int $rows
 * @property bool $ended
 * @property mixed $error
 * @property mixed $item
 *
 * @method mixed route()
 * @method mixed screen()
 * @method int stackDepth()
 * @method mixed toast()
 * @method mixed palette()
 * @method mixed theme()
 * @method mixed config()
 * @method mixed nowPlaying()
 * @method int shimmerPhase()
 * @method bool isShimmerTicking()
 * @method bool isLoading()
 * @method bool isCreating()
 * @method bool isBusy()
 * @method bool isEditingSchedule()
 * @method bool isEditing()
 * @method mixed editingKey()
 * @method bool isAddingSource()
 * @method bool isInstalling()
 * @method mixed schedule()
 */
class Model
{
}

/**
 * Stub for Msg class with test accessor properties.
 *
 * @property mixed $item
 */
class Msg
{
}

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
