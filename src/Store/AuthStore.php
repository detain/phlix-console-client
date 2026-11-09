<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

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
 *
 * Per-server isolation [review console__secaudit #1 / CU-004]: when bound to a
 * roster server (constructor id, re-pointed via {@see setCurrentServer()}),
 * every persist/read/delete path is keyed to THAT server's entry — a switch,
 * a rejected token, or a logout can never ship or wipe another server's
 * bundle. An unbound store (env-stub runs; the pre-per-server construction
 * shape) keeps the single legacy slot, exactly as before.
 */
final class AuthStore
{
    private ?AuthUser $user = null;
    private ?string $serverId;

    /**
     * @param ?string $serverId roster id of the server this client is bound
     *                          to, or null for the unbound (legacy-slot) path
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly TokenStore $tokens,
        ?string $serverId = null,
    ) {
        $this->serverId = $serverId;

        // Persist on every token change (login + transparent refresh), keyed
        // to the bound server so a multi-server roster never overwrites one
        // server's bundle with another's [review console__secaudit #1 / CU-004].
        // Best-effort: a disk hiccup must not break an otherwise-good session.
        $this->api->onTokenChanged(function (TokenBundle $bundle): void {
            try {
                if ($this->serverId === null) {
                    $this->tokens->save($bundle);
                } else {
                    $this->tokens->setForServer($this->serverId, $bundle);
                }
            } catch (\Throwable) {
                // ignore — the in-memory token still works for this session
            }
        });
    }

    /**
     * Re-point the store at a different roster server (server-switch and
     * server-submit paths in App). Called immediately after
     * ApiClient::setBaseUrl() so the id and the URL always move together —
     * the pair is what "stamped to the server that minted it" means here.
     */
    public function setCurrentServer(?string $serverId): void
    {
        $this->serverId = $serverId;
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
     * The lookup is per-server when bound. When the bound server has no keyed
     * bundle, the legacy slot is honoured ONCE as a migration feed and
     * validation decides provenance: success stamps the bundle to the server
     * that just proved it good against (and retires the slot); rejection
     * retires the unattributed slot rather than letting a foreign bearer
     * wander on to the next server's origin. A rejection against a KEYED
     * bundle removes only that server's entry — never the whole store
     * [review console__secaudit #1 / CU-004].
     *
     * @return PromiseInterface<AuthUser|null>
     */
    public function restore(): PromiseInterface
    {
        $serverId = $this->serverId;
        $fromLegacy = false;
        /** @var string|null server to stamp a migrated legacy bundle to on success. */
        $stampServer = null;
        $bundle = $serverId === null
            ? $this->tokens->load()
            : $this->tokens->getForServer($serverId);

        if ($bundle === null && $serverId !== null) {
            $legacy = $this->tokens->load();
            if ($legacy !== null) {
                $bundle = $legacy;
                $fromLegacy = true;
                // The legacy fallback is only ever reached for a BOUND store,
                // so provenance carries the stamp target with it: the success
                // arm needs nothing else to prove the receiver non-null.
                $stampServer = $serverId;
            }
        }

        if ($bundle === null) {
            // Nothing to validate for THIS server — so the client must hold no
            // bearer. Leaving the previous server's token attached is exactly
            // the foreign shipping this cure exists to end: a switch with no
            // stored bundle issues zero authenticated requests.
            $this->api->clearToken();

            return resolve(null);
        }

        $this->api->setToken($bundle);

        return $this->api->me()->then(
            function (AuthUser $user) use ($bundle, $stampServer): AuthUser {
                $this->user = $user;

                if ($stampServer !== null) {
                    // The validation that just succeeded IS the provenance
                    // proof: stamp the bundle to the server that minted it.
                    try {
                        $this->tokens->setForServer($stampServer, $bundle);
                        $this->tokens->clearLegacy();
                    } catch (\Throwable) {
                        // best-effort stamp — the live session is valid either way
                    }
                }

                return $user;
            },
            function (\Throwable $error) use ($serverId, $fromLegacy): ?AuthUser {
                if ($error instanceof AuthError) {
                    $this->api->clearToken();
                    if ($serverId !== null && !$fromLegacy) {
                        $this->tokens->removeForServer($serverId);
                    } else {
                        $this->tokens->clearLegacy();
                    }
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

    /**
     * Drop this session: clear the in-memory bearer and forget the stored
     * bundle for the bound server ONLY — other servers' keyed entries stay
     * for their own sessions [review console__secaudit #1 / CU-004].
     */
    public function logout(): void
    {
        $serverId = $this->serverId;
        $this->user = null;
        $this->api->clearToken();

        if ($serverId === null) {
            // Unbound: this store never reads keyed entries, and the session
            // it ends lives in the legacy slot — retire that slot, not the
            // keyed entries a bound session would have owned.
            $this->tokens->clearLegacy();

            return;
        }

        $this->tokens->removeForServer($serverId);
    }

    /**
     * Forget the stored bundle for one server (its roster entry was removed)
     * and hand it back to the caller — the removal path uses it for the
     * best-effort server-side revoke while the credential is still in hand
     * [review console__secaudit #2 / CU-004]. If the removed id is somehow
     * also the bound one, the in-memory bearer drops with it; the active-
     * removal guard in App keeps that combination theoretical.
     */
    public function detachServerToken(string $serverId): ?TokenBundle
    {
        $bundle = $this->tokens->getForServer($serverId);
        $this->tokens->removeForServer($serverId);

        if ($this->serverId === $serverId) {
            $this->api->clearToken();
        }

        return $bundle;
    }

    /**
     * Clear the in-memory user (used during server switch: the identity of
     * the previous server must not render while the new server's bundle is
     * being resolved — {@see restore()} then attaches that server's own
     * token, or drops the bearer entirely [review console__secaudit #1]).
     */
    public function clearUser(): void
    {
        $this->user = null;
    }
}
