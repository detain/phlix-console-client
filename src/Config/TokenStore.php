<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Config;

/**
 * Reads and writes auth tokens as JSON under the config directory (default
 * `~/.config/phlix/token.json`). Tokens are keyed by server id so multiple
 * servers can each have their own token set.
 *
 * The token file is written 0600 (owner-only): the temp file is created under
 * a 0o077 umask so it is born 0600 - never even momentarily world-readable -
 * and renamed into place atomically.
 *
 * Migration: on load, if the legacy flat TokenBundle format is detected (a
 * single `access_token` key without `by_server` wrapper), the existing bundle
 * is stored under the special "legacy" server id.
 *
 * For backward compatibility, the legacy {@see save(TokenBundle)} and
 * {@see load()} methods operate on the "legacy" server id.
 */
final class TokenStore
{
    /** Server id used for tokens migrated from the legacy single-server format. */
    private const LEGACY_SERVER_ID = 'legacy';

    /** @var array<string, array{token: TokenBundle, migrated: bool}> */
    private array $cache = [];
    private bool $loaded = false;

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
        $this->ensureLoaded();
        return count($this->cache) > 0;
    }

    /**
     * Load the stored bundle for the legacy server id (backward compatibility).
     * Migrates existing single-token set if present.
     *
     * @return TokenBundle|null
     */
    public function load(): ?TokenBundle
    {
        $this->ensureLoaded();

        if (isset($this->cache[self::LEGACY_SERVER_ID])) {
            $entry = $this->cache[self::LEGACY_SERVER_ID];
            if ($entry['token']->isValid()) {
                return $entry['token'];
            }
        }

        return null;
    }

    /**
     * Persist a token bundle under the legacy server id (backward compatibility).
     *
     * @throws \RuntimeException if the directory or file cannot be written
     */
    public function save(TokenBundle $bundle): void
    {
        $this->ensureLoaded();
        $this->cache[self::LEGACY_SERVER_ID] = ['token' => $bundle, 'migrated' => false];
        $this->persist();
    }

    /**
     * Remove all stored tokens (no-op if absent).
     */
    public function clear(): void
    {
        $this->ensureLoaded();
        $this->cache = [];
        $this->persist();
    }

    /**
     * Load the token for a specific server, or null if absent / invalid.
     *
     * @return array{token: string, refresh: string}|null
     */
    public function getForServer(string $serverId): ?array
    {
        $this->ensureLoaded();

        if (isset($this->cache[$serverId])) {
            $entry = $this->cache[$serverId];
            if ($entry['token']->isValid()) {
                return [
                    'token' => $entry['token']->accessToken,
                    'refresh' => $entry['token']->refreshToken,
                ];
            }

            return null;
        }

        return null;
    }

    /**
     * Store a token bundle for a specific server.
     *
     * @param array{token: string, refresh: string} $tokens
     */
    public function setForServer(string $serverId, array $tokens): void
    {
        $this->ensureLoaded();

        $bundle = new TokenBundle(
            accessToken: $tokens['token'],
            refreshToken: $tokens['refresh'],
        );

        $this->cache[$serverId] = ['token' => $bundle, 'migrated' => false];
        $this->persist();
    }

    /** Remove the token for a specific server (no-op if absent). */
    public function removeForServer(string $serverId): void
    {
        $this->ensureLoaded();

        unset($this->cache[$serverId]);
        $this->persist();
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $this->loadFromDisk();
    }

    private function loadFromDisk(): void
    {
        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        // Legacy migration: flat TokenBundle format (no `by_server` wrapper)
        if (isset($data['access_token']) && !isset($data['by_server'])) {
            $bundle = TokenBundle::fromArray($data);
            if ($bundle->isValid()) {
                $this->cache[self::LEGACY_SERVER_ID] = ['token' => $bundle, 'migrated' => true];
            }

            return;
        }

        // New per-server format
        foreach (self::array($data['by_server'] ?? null) as $serverId => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $bundle = TokenBundle::fromArray(self::array($entry));
            if ($bundle->isValid()) {
                $this->cache[(string) $serverId] = ['token' => $bundle, 'migrated' => false];
            }
        }
    }

    /**
     * Persist the cache to disk (0600), creating the directory if needed.
     *
     * @throws \RuntimeException  if the directory or file cannot be written
     */
    private function persist(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create token directory: {$dir}");
        }

        $out = [
            'by_server' => [],
        ];
        foreach ($this->cache as $serverId => $entry) {
            $out['by_server'][(string) $serverId] = $entry['token']->toArray();
        }

        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $tmp = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        // Create the temp file under a 0o077 umask so its inode is born 0600:
        // file_put_contents alone creates 0666 & ~umask (commonly 0644, world
        // readable) and could only chmod after the fact - the plaintext window
        // this closes. If pre-creation fails, the write below surfaces the same
        // error it always did.
        $previousUmask = umask(0o077);
        $handle = @fopen($tmp, 'x');
        umask($previousUmask);
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write token file: {$this->path}");
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write token file: {$this->path}");
        }
        @chmod($this->path, 0o600);
    }

    /** @return array<mixed> */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
