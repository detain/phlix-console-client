<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Dto\Admin;

use Phlix\Console\Api\Dto\Coerce;

/**
 * A tailed log payload, covering both server shapes:
 *
 *  - `GET /api/v1/admin/logs/tail?file=…` → `{file, lines, truncated}` (one file;
 *    `file` set, `files` empty).
 *  - `GET /api/v1/admin/logs/tail-all` → `{files, lines, truncated}` (every file
 *    pre-merged chronologically, each line prefixed with its source; `file` null,
 *    `files` populated).
 *
 * `lines` are already in display order; `truncated` is true when older lines were
 * dropped. Tolerant; immutable.
 */
final readonly class LogTail
{
    /**
     * @param list<string> $files the merged sources (all-tail) — empty for one file
     * @param list<string> $lines the tail lines, in display order
     */
    public function __construct(
        public ?string $file,
        public array $files,
        public array $lines,
        public bool $truncated,
    ) {
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            file: Coerce::nstr($data['file'] ?? null),
            files: Coerce::stringList($data['files'] ?? null),
            lines: Coerce::stringList($data['lines'] ?? null),
            truncated: Coerce::bool($data['truncated'] ?? false),
        );
    }
}
