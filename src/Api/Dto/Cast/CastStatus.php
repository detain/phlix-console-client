<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Cast;

use Phlix\Console\Api\Dto\Coerce;

/**
 * The unified playback status returned by a backend's `…/{id}/status` endpoint.
 *
 * The backends disagree on key names: Chromecast/Roku/AirPlay report `active`
 * (bool) + `state`; DLNA reports `has_active_session` (bool) + `session_state`.
 * `fromArray` reads either spelling tolerantly. Immutable.
 */
final readonly class CastStatus
{
    public function __construct(
        public bool $active,
        public ?string $state,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            active: Coerce::bool($data['active'] ?? ($data['has_active_session'] ?? false)),
            state: Coerce::nstr($data['state'] ?? ($data['session_state'] ?? null)),
        );
    }
}
