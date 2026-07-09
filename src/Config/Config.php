<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Config;

/**
 * Client configuration — the (configurable, never hard-coded) Phlix server URL,
 * the chosen UI theme name, and the photo-slideshow interval, persisted as JSON
 * under the user's config directory.
 *
 * Honours `XDG_CONFIG_HOME`, falling back to `~/.config/phlix`.
 */
final class Config
{
    /** Floor / ceiling (seconds) for the photo-slideshow interval. */
    private const SLIDESHOW_MIN = 1;
    private const SLIDESHOW_MAX = 300;
    private const SLIDESHOW_DEFAULT = 4;

    public function __construct(
        public readonly ?string $serverUrl = null,
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

    /** Load config from disk, returning defaults when absent or unreadable. */
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

        $url = $data['server_url'] ?? null;
        $theme = $data['theme'] ?? null;
        $interval = $data['slideshow_interval'] ?? null;

        return new self(
            serverUrl: (is_string($url) && $url !== '') ? $url : null,
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
                'server_url' => $this->serverUrl,
                'theme' => $this->theme,
                'slideshow_interval' => $this->slideshowInterval,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if (@file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Cannot write config file: {$path}");
        }

        @chmod($path, 0o600);
    }

    /** Return a copy with the given (normalised) server URL, preserving the theme + slideshow interval. */
    public function withServerUrl(string $url): self
    {
        return new self(serverUrl: self::normalizeUrl($url), theme: $this->theme, slideshowInterval: $this->slideshowInterval);
    }

    /** Return a copy with the given theme name, preserving the server URL + slideshow interval. */
    public function withTheme(string $name): self
    {
        return new self(serverUrl: $this->serverUrl, theme: $name, slideshowInterval: $this->slideshowInterval);
    }

    /**
     * Return a copy with the photo-slideshow interval (seconds), clamped to
     * [1, 300], preserving the server URL + theme.
     */
    public function withSlideshowInterval(int $seconds): self
    {
        return new self(serverUrl: $this->serverUrl, theme: $this->theme, slideshowInterval: self::clampInterval($seconds));
    }

    /** Clamp a slideshow interval into the supported [1, 300] second range. */
    private static function clampInterval(int $seconds): int
    {
        return max(self::SLIDESHOW_MIN, min(self::SLIDESHOW_MAX, $seconds));
    }

    public function hasServer(): bool
    {
        return $this->serverUrl !== null && $this->serverUrl !== '';
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
}
