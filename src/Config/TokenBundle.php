<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Config;

/**
 * The auth tokens returned by `/auth/login` and `/auth/refresh`, plus a
 * computed absolute expiry. Immutable.
 */
final readonly class TokenBundle
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $tokenType = 'Bearer',
        public ?int $expiresAt = null,
    ) {
    }

    /**
     * Build from a login/refresh response. `expires_in` (seconds) is converted
     * to an absolute unix timestamp using $now (defaults to time()).
     *
     * @param array<string,mixed> $response
     */
    public static function fromAuthResponse(array $response, ?int $now = null): self
    {
        $now ??= time();
        $expiresIn = isset($response['expires_in']) && is_numeric($response['expires_in'])
            ? (int) $response['expires_in']
            : null;

        return new self(
            accessToken: self::string($response['access_token'] ?? null),
            refreshToken: self::string($response['refresh_token'] ?? null),
            tokenType: self::string($response['token_type'] ?? null) ?: 'Bearer',
            expiresAt: $expiresIn !== null ? $now + $expiresIn : null,
        );
    }

    /**
     * @param array<array-key,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: self::string($data['access_token'] ?? null),
            refreshToken: self::string($data['refresh_token'] ?? null),
            tokenType: self::string($data['token_type'] ?? null) ?: 'Bearer',
            expiresAt: isset($data['expires_at']) && is_numeric($data['expires_at'])
                ? (int) $data['expires_at']
                : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => $this->tokenType,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** The `Authorization` header value, e.g. "Bearer eyJ...". */
    public function authorizationHeader(): string
    {
        return $this->tokenType . ' ' . $this->accessToken;
    }

    /**
     * Whether the access token is expired (or within $skew seconds of expiry).
     * Tokens with no known expiry are treated as not-expired.
     */
    public function isExpired(?int $now = null, int $skew = 30): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? time()) >= ($this->expiresAt - $skew);
    }

    public function hasRefreshToken(): bool
    {
        return $this->refreshToken !== '';
    }

    /** A bundle is usable only if it carries an access token. */
    public function isValid(): bool
    {
        return $this->accessToken !== '';
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
