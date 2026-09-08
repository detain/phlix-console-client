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
 * ⚠ S448: the `@property` tags that used to sit here (cols/rows/ended/error/item)
 * were DEAD. PHPStan 2.2.x ignores `@property`/`@property-read` on an INTERFACE
 * unless the interface declares a native `__get()` — and a stub-declared `__get()`
 * does not count as native. The vendor `SugarCraft\Core\Model` is an interface, so
 * those tags never merged (only the `@method` tags below do). The real
 * `$model->cols` accesses now typecheck because the tests narrow to the concrete
 * class first (self::assertInstanceOf(...)), not because of anything declared here.
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
 * Stub for Msg class with test accessor methods.
 *
 * ⚠ S448: the `@property mixed $item` tag that used to sit here was DEAD for the
 * same interface reason documented above. `$msg->item` now typechecks via the
 * templated `firstOfType()` helper (PlayerScreenTest), not via this stub.
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
