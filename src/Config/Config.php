<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Config;

/**
 * Client configuration — the (configurable, never hard-coded) Phlix server URL(s),
 * the chosen UI theme name, and the photo-slideshow interval, persisted as JSON
 * under the user's config directory.
 *
 * Honours `XDG_CONFIG_HOME`, falling back to `~/.config/phlix`.
 *
 * SETTING PERSISTENCE SPLIT
 * -------------------------
 * Exactly two buckets exist, and every key below is a key this class (and
 * only this class) parses and persists — review M-CONSOLE-38 purged a whole
 * invented vocabulary (hardware "device-local" keys, extra "synced" keys)
 * that was declared here but read by no code path.
 *
 * Device-local (never sent to server):
 *   - servers + active_server_id : the per-install server roster.
 *
 * Server-synced (GET/PUT /api/v1/users/me/settings):
 *   - theme              : canonical lowercase wire id — nocturne|daylight|
 *                          midnight (or an enabled plugin theme slug); this is
 *                          what the server validates and stores. Legacy local
 *                          files may still carry display names ('Midnight')
 *                          and the server may still return pre-S85 fossils —
 *                          readers resolve both tolerantly via Theme::byName().
 *   - slideshow_interval : int (seconds, 1–300) — NOTE: the server's
 *                          updateSettings allowedFields does NOT include this
 *                          key, so it round-trips local-only for now.
 *
 * The render mode is deliberately NOT a persisted setting: it is chosen per
 * invocation on the command line (bin/phlix `--mode`), never stored here.
 *
 * The local JSON file always holds the union of both buckets; server values
 * are merged over local defaults at boot so the device-local roster is
 * preserved even when the server has never been contacted.
 */
final class Config
{
    /** Floor / ceiling (seconds) for the photo-slideshow interval. */
    private const SLIDESHOW_MIN = 1;
    private const SLIDESHOW_MAX = 300;
    private const SLIDESHOW_DEFAULT = 4;

    /**
     * @param array<int, ServerEntry>|list<ServerEntry> $servers
     * @param string|null $loadError diagnostic sentence when a config file on disk
     *                               could not be honoured (unreadable or not valid
     *                               JSON); null means defaults come from absence or
     *                               a clean read. The file itself is NEVER clobbered
     *                               on this path — only an explicit save() rewrites it.
     */
    public function __construct(
        public readonly array $servers = [],
        public readonly ?string $activeServerId = null,
        public readonly ?string $theme = null,
        public readonly int $slideshowInterval = self::SLIDESHOW_DEFAULT,
        public readonly ?string $loadError = null,
    ) {
    }

    /** The config directory (`$XDG_CONFIG_HOME/phlix` or `~/.config/phlix`). */
    public static function dir(): string
    {
        $base = getenv('XDG_CONFIG_HOME');
        if (!is_string($base) || $base === '') {
            $home = getenv('HOME');
            if (!is_string($home) || $home === '') {
                $home = getenv('USERPROFILE') ?: sys_get_temp_dir();
            }
            $base = rtrim($home, '/') . '/.config';
        }

        return rtrim($base, '/') . '/phlix';
    }

    /** Path to the config file. */
    public static function path(): string
    {
        return self::dir() . '/config.json';
    }

    /**
     * Load config from disk, returning defaults when absent or unreadable.
     * Migrates legacy single-server format to multi-server format.
     *
     * Absence is silence (fresh install); a file that exists but cannot be
     * honoured is LOUD: {@see self::$loadError} carries the reason so doctor
     * and boot surface it [review console__qa m-3, M-CONSOLE-51]. The broken
     * file is deliberately left untouched.
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::path();

        $raw = @file_get_contents($path);
        if ($raw === false) {
            if (file_exists($path)) {
                // The file is there but we cannot read it — never silent.
                return new self(loadError: "Config file at {$path} exists but could not be read; using defaults (the file was left untouched).");
            }

            return new self();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            if (trim($raw) === '') {
                return new self(loadError: "Config file at {$path} is empty; using defaults (the file was left untouched — it may be the tail of a truncated write).");
            }

            $reason = json_last_error() === JSON_ERROR_NONE ? 'not a JSON object' : json_last_error_msg();

            return new self(loadError: "Config file at {$path} is not valid JSON ({$reason}); using defaults (the file was left untouched).");
        }

        // Migrate legacy single-server format
        if (isset($data['server_url']) && is_string($data['server_url']) && $data['server_url'] !== '') {
            $id = self::generateUuid();
            $data['servers'] = [[
                'id' => $id,
                'label' => self::extractLabelFromUrl($data['server_url']),
                'url' => self::normalizeUrl($data['server_url']),
                'hub_id' => null,
            ]];
            // Atomic: overwrite servers key entirely so migration runs once
            unset($data['server_url']);
            $data['active_server_id'] = $id;
        }

        $servers = [];
        foreach (self::array($data['servers'] ?? null) as $row) {
            if (is_array($row)) {
                $servers[] = new ServerEntry(
                    id: self::string($row['id'] ?? null) ?: self::generateUuid(),
                    label: self::string($row['label'] ?? null) ?: 'Server',
                    url: self::normalizeUrl(self::string($row['url'] ?? null) ?: ''),
                    hubId: self::string($row['hub_id'] ?? null) ?: null,
                    // Parse at the boundary: only a positive int is a port;
                    // anything else (junk, out-of-range, absent) is null = default.
                    wsPort: (isset($row['ws_port']) && is_int($row['ws_port']) && $row['ws_port'] > 0)
                        ? $row['ws_port']
                        : null,
                );
            }
        }

        $activeServerId = is_string($data['active_server_id'] ?? null) ? $data['active_server_id'] : null;
        $theme = $data['theme'] ?? null;
        $interval = $data['slideshow_interval'] ?? null;

        return new self(
            servers: $servers,
            activeServerId: $activeServerId,
            theme: (is_string($theme) && $theme !== '') ? $theme : null,
            slideshowInterval: is_numeric($interval) ? self::clampInterval((int) $interval) : self::SLIDESHOW_DEFAULT,
        );
    }

    /**
     * Persist this config to disk (0600), creating the directory if needed.
     *
     * @throws \RuntimeException  if the directory or file cannot be written
     */
    public function save(?string $path = null): void
    {
        $path ??= self::path();

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create config directory: {$dir}");
        }

        $json = json_encode(
            [
                'servers' => array_map(
                    static fn (ServerEntry $s): array => [
                        'id' => $s->id,
                        'label' => $s->label,
                        'url' => $s->url,
                        'hub_id' => $s->hubId,
                        'ws_port' => $s->wsPort,
                    ],
                    $this->servers,
                ),
                'active_server_id' => $this->activeServerId,
                'theme' => $this->theme,
                'slideshow_interval' => $this->slideshowInterval,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
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
            throw new \RuntimeException("Cannot write config file: {$path}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write config file: {$path}");
        }
        @chmod($path, 0o600);
    }

    /** Return the active server entry, or null if none is selected. */
    public function activeServer(): ?ServerEntry
    {
        if ($this->activeServerId === null) {
            return null;
        }
        foreach ($this->servers as $server) {
            if ($server->id === $this->activeServerId) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Return a new Config with the server-synced settings (theme,
     * slideshow_interval) merged over the local values. Keys the server
     * response carries beyond these two are ignored; the device-local server
     * roster stays as-is from $this and is never overwritten by the server.
     *
     * @param array<string, mixed> $serverSettings server response from GET /api/v1/users/me/settings
     */
    public function withServerSettings(array $serverSettings): self
    {
        // theme — server wins if present and non-empty
        $theme = isset($serverSettings['theme']) && is_string($serverSettings['theme']) && $serverSettings['theme'] !== ''
            ? $serverSettings['theme']
            : $this->theme;

        // slideshow_interval — server wins if numeric and in range
        $interval = $this->slideshowInterval;
        if (isset($serverSettings['slideshow_interval']) && is_numeric($serverSettings['slideshow_interval'])) {
            $interval = self::clampInterval((int) $serverSettings['slideshow_interval']);
        }

        return new self(
            servers: $this->servers,
            activeServerId: $this->activeServerId,
            theme: $theme,
            slideshowInterval: $interval,
        );
    }

    /** Return a copy with a different active server id, preserving everything else. */
    public function withActiveServerId(string $id): self
    {
        return new self(
            servers: $this->servers,
            activeServerId: $id,
            theme: $this->theme,
            slideshowInterval: $this->slideshowInterval,
        );
    }

    /**
     * Return a copy with a new server list, preserving activeServerId and other settings.
     *
     * @param list<ServerEntry> $servers
     */
    public function withServers(array $servers): self
    {
        return new self(
            servers: $servers,
            activeServerId: $this->activeServerId,
            theme: $this->theme,
            slideshowInterval: $this->slideshowInterval,
        );
    }

    /** Return a copy with the given (normalised) server URL added as a new entry. */
    public function withServerUrl(string $url, string $label = 'Server'): self
    {
        $id = self::generateUuid();
        $newServers = $this->servers;
        $newServers[] = new ServerEntry(
            id: $id,
            label: $label,
            url: self::normalizeUrl($url),
        );

        return new self(
            servers: $newServers,
            activeServerId: $id,
            theme: $this->theme,
            slideshowInterval: $this->slideshowInterval,
        );
    }

    /**
     * Return a copy with the given theme wire id (canonical lowercase, e.g.
     * 'midnight'), preserving the server + slideshow settings. Readers resolve
     * the stored string tolerantly via Theme::byName(), so legacy display
     * names already on disk keep working.
     */
    public function withTheme(string $themeId): self
    {
        return new self(
            servers: $this->servers,
            activeServerId: $this->activeServerId,
            theme: $themeId,
            slideshowInterval: $this->slideshowInterval,
        );
    }

    /**
     * Return a copy with the photo-slideshow interval (seconds), clamped to
     * [1, 300], preserving the server + theme settings.
     */
    public function withSlideshowInterval(int $seconds): self
    {
        return new self(
            servers: $this->servers,
            activeServerId: $this->activeServerId,
            theme: $this->theme,
            slideshowInterval: self::clampInterval($seconds),
        );
    }

    /** Clamp a slideshow interval into the supported [1, 300] second range. */
    private static function clampInterval(int $seconds): int
    {
        return max(self::SLIDESHOW_MIN, min(self::SLIDESHOW_MAX, $seconds));
    }

    public function hasServer(): bool
    {
        return $this->activeServer() !== null;
    }

    /**
     * A stable, non-empty device id for playback-session tracking — derived from
     * the host and config location so it is consistent across runs without
     * needing to be persisted.
     */
    public static function deviceId(): string
    {
        $host = gethostname();
        if (!is_string($host) || $host === '') {
            $host = 'host';
        }

        return 'phlix-console-' . substr(sha1($host . '|' . self::dir()), 0, 16);
    }

    /**
     * Normalise a user-entered server URL: trim, default to https://, and
     * strip any trailing slash. Returns '' for blank input.
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }

    private static function extractLabelFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        if (is_array($parsed) && isset($parsed['host'])) {
            return $parsed['host'];
        }

        return 'Server';
    }

    private static function generateUuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return array<mixed> */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
