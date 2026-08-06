<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 * Device-local (never sent to server):
 *   - terminal_render_mode  : ansi|halfblock|braille  (hardware-dependent)
 *   - colour_palette        : 16|256|truecolor        (terminal capability)
 *   - cell_size             : small|medium|large       (DPI scaling preference)
 *   - server_urls + active_server_id                   (tied to this install)
 *
 * Server-persisted (synced via GET/PUT /api/v1/me/settings):
 *   - theme                : Nocturne|Daylight|Midnight|Noir
 *   - slideshow_interval   : int (seconds, 1–300)
 *   - preferred_library_ids: list<string>
 *   - parental_controls_pin: string (hashed)
 *   - ...any future cross-device preference
 *
 * The local JSON file always holds the union of both; server values are
 * merged over local defaults at boot so device-local keys are preserved
 * even when the server has never been contacted.
 */
final class Config
{
    /** Floor / ceiling (seconds) for the photo-slideshow interval. */
    private const SLIDESHOW_MIN = 1;
    private const SLIDESHOW_MAX = 300;
    private const SLIDESHOW_DEFAULT = 4;

    /**
     * Settings keys that live ONLY in the local JSON file and are NEVER sent
     * to or received from the server. These represent hardware/OS-specific
     * preferences that would make no sense on a different device.
     *
     * @var array<string, true>
     */
    public const DEVICE_LOCAL_KEYS = [
        'terminal_render_mode' => true,
        'colour_palette' => true,
        'cell_size' => true,
        // server_urls and active_server_id are also device-local by nature
        // (they point to per-install server installations) but they are
        // stored under the 'servers' array and 'active_server_id' top-level
        // key, not as a flat settings sub-map.
    ];

    /**
     * @param array<int, ServerEntry>|list<ServerEntry> $servers
     */
    public function __construct(
        public readonly array $servers = [],
        public readonly ?string $activeServerId = null,
        public readonly ?string $theme = null,
        public readonly int $slideshowInterval = self::SLIDESHOW_DEFAULT,
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
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::path();

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return new self();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new self();
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
        if (@file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("Cannot write config file: {$path}");
        }
        @chmod($tmp, 0o600);

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
     * Return a new Config with server-persisted settings merged over the local
     * values. Device-local keys (terminal_render_mode, colour_palette, cell_size)
     * are NOT taken from $serverSettings — they stay as-is from $this so that
     * hardware-specific preferences are never overwritten by the server.
     *
     * @param array<string, mixed> $serverSettings server response from GET /api/v1/me/settings
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

    /** Return a copy with the given theme name, preserving the server + slideshow settings. */
    public function withTheme(string $name): self
    {
        return new self(
            servers: $this->servers,
            activeServerId: $this->activeServerId,
            theme: $name,
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
