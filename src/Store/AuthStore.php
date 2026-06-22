<?php

declare(strict_types=1);

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\AuthResult;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Config\TokenStore;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Owns authentication state over the {@see ApiClient} and {@see TokenStore}:
 * boot restore, login, and logout. Persists tokens whenever the client issues
 * or refreshes them.
 */
final class AuthStore
{
    private ?AuthUser $user = null;

    public function __construct(
        private readonly ApiClient $api,
        private readonly TokenStore $tokens,
    ) {
        // Persist on every token change (login + transparent refresh).
        // Best-effort: a disk hiccup must not break an otherwise-good session.
        $this->api->onTokenChanged(function (TokenBundle $bundle): void {
            try {
                $this->tokens->save($bundle);
            } catch (\Throwable) {
                // ignore — the in-memory token still works for this session
            }
        });
    }

    public function currentUser(): ?AuthUser
    {
        return $this->user;
    }

    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    /**
     * Validate a stored token on boot. Resolves with the user when a valid
     * token is present, or null otherwise. Never rejects.
     *
     * A genuine auth failure clears the stored token; a transient network
     * failure leaves it intact (so a server blip doesn't force re-login next
     * boot) — either way the caller is sent to login.
     *
     * @return PromiseInterface<AuthUser|null>
     */
    public function restore(): PromiseInterface
    {
        $bundle = $this->tokens->load();
        if ($bundle === null) {
            return resolve(null);
        }

        $this->api->setToken($bundle);

        return $this->api->me()->then(
            function (AuthUser $user): AuthUser {
                $this->user = $user;

                return $user;
            },
            function (\Throwable $error): ?AuthUser {
                if ($error instanceof AuthError) {
                    $this->api->clearToken();
                    $this->tokens->clear();
                }

                return null;
            },
        );
    }

    /**
     * Log in with a username (or email) and password.
     *
     * @return PromiseInterface<AuthUser>
     */
    public function login(string $usernameOrEmail, string $password): PromiseInterface
    {
        return $this->api->login($usernameOrEmail, $password)->then(function (AuthResult $result): AuthUser {
            $this->user = $result->user;

            return $result->user;
        });
    }

    /** Drop the session and forget the stored token. */
    public function logout(): void
    {
        $this->user = null;
        $this->api->clearToken();
        $this->tokens->clear();
    }
}
