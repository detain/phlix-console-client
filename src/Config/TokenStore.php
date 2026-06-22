<?php

declare(strict_types=1);

namespace Phlix\Console\Config;

/**
 * Reads and writes the auth {@see TokenBundle} as JSON under the config
 * directory (default `~/.config/phlix/token.json`).
 *
 * The token file is written 0600 (owner-only) via a temp-file + rename so it is
 * never momentarily world-readable.
 */
final class TokenStore
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    /** Store at the default location (`Config::dir()/token.json`). */
    public static function default(): self
    {
        return new self(Config::dir() . '/token.json');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** Load the stored bundle, or null if absent, unreadable, or invalid. */
    public function load(): ?TokenBundle
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $bundle = TokenBundle::fromArray($data);

        return $bundle->isValid() ? $bundle : null;
    }

    /**
     * Persist the bundle (0600), creating the directory if needed.
     *
     * @throws \RuntimeException  if the directory or file cannot be written
     */
    public function save(TokenBundle $bundle): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create token directory: {$dir}");
        }

        $json = json_encode($bundle->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("Cannot write token file: {$this->path}");
        }
        @chmod($tmp, 0o600);

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write token file: {$this->path}");
        }
        @chmod($this->path, 0o600);
    }

    /** Remove the stored token (no-op if absent). */
    public function clear(): void
    {
        @unlink($this->path);
    }
}
