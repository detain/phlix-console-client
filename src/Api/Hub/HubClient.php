<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api\Hub;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Coerce;
use React\Promise\PromiseInterface;

/**
 * The typed client for the hub's federation, sharing, and invite-link surfaces,
 * layered over {@see ApiClient::send()} (the public authed-JSON seam). One method
 * per surface returns a `PromiseInterface<Dto>`; later surfaces add sibling methods.
 *
 * The App holds NO HubClient field — it is built locally in the App's
 * hub-section handler from the shared {@see ApiClient} (mirroring the
 * BooksStore-built-locally pattern), so hub code needs no constructor wiring.
 */
final class HubClient
{
    /** The base path every share endpoint hangs off. */
    private const SHARES = '/api/v1/me/shares';

    /** The base path every invite-link endpoint hangs off. */
    private const INVITE_LINKS = '/api/v1/me/invite-links';

    /** The base path every federation endpoint hangs off. */
    private const FEDERATION = '/api/v1/me/federation';

    public function __construct(
        private readonly ApiClient $api,
    ) {
    }

    // ---- shared-with-me -------------------------------------------------

    /**
     * List the libraries shared with the current user.
     * UNLIKE the admin dashboard endpoints, the sharing controller returns
     * data at the TOP LEVEL with NO `{success, data}` envelope, so the list is
     * read straight from `$body['shares']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function sharedWithMe(): PromiseInterface
    {
        return $this->api->send('GET', self::SHARES)->then(static function (array $body): array {
            return self::mapList(
                $body['shares'] ?? null,
                static fn (array $row): array => $row,
            );
        });
    }

    // ---- invite links ----------------------------------------------------

    /**
     * List the invite links created by the current user.
     * The invite-link controller returns data at the TOP LEVEL with NO
     * `{success, data}` envelope, so the list is read straight from
     * `$body['inviteLinks']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function inviteLinks(): PromiseInterface
    {
        return $this->api->send('GET', self::INVITE_LINKS)->then(static function (array $body): array {
            return self::mapList(
                $body['inviteLinks'] ?? null,
                static fn (array $row): array => $row,
            );
        });
    }

    /**
     * Create a new invite link. The body is `{label, expires_in_seconds?}`; on
     * 201 the server returns `{inviteLink: {...}}` and this resolves the message.
     * Rejects with the server `error` on a 400 (validation) — the
     * {@see \Phlix\Console\Api\ApiError} carries it as the exception message.
     *
     * @return PromiseInterface<string>
     */
    public function createInvite(string $label, ?int $expiresInSeconds = null): PromiseInterface
    {
        $body = ['label' => $label];
        if ($expiresInSeconds !== null) {
            $body['expires_in_seconds'] = $expiresInSeconds;
        }

        return $this->api->send('POST', self::INVITE_LINKS, [], $body)
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Revoke (delete) an invite link by id. Resolves the server `message`;
     * rejects with the server `error` on 404.
     *
     * @return PromiseInterface<string>
     */
    public function revokeInvite(string $id): PromiseInterface
    {
        return $this->api->send('DELETE', self::INVITE_LINKS . '/' . rawurlencode($id))
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    // ---- federation shares -----------------------------------------------

    /**
     * List the outgoing federation library shares (libraries this server has
     * shared with other federation peers). The federation controller returns
     * data at the TOP LEVEL with NO `{success, data}` envelope, so the list is
     * read straight from `$body['outgoing']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function federationSharesOutgoing(): PromiseInterface
    {
        return $this->api->send('GET', self::FEDERATION . '/library-shares/outgoing')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['outgoing'] ?? null,
                    static fn (array $row): array => $row,
                );
            });
    }

    /**
     * List the incoming federation library shares (libraries other federation
     * peers have shared with this server, pending acceptance). The federation
     * controller returns data at the TOP LEVEL with NO `{success, data}` envelope,
     * so the list is read straight from `$body['incoming']`. A non-list payload
     * yields an empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function federationSharesIncoming(): PromiseInterface
    {
        return $this->api->send('GET', self::FEDERATION . '/library-shares/incoming')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['incoming'] ?? null,
                    static fn (array $row): array => $row,
                );
            });
    }

    /**
     * Accept an incoming federation library share. Resolves the server `message`;
     * rejects with the server `error` on 400 (already accepted/rejected) or 404.
     *
     * @return PromiseInterface<string>
     */
    public function acceptShare(string $id): PromiseInterface
    {
        return $this->api->send('POST', self::FEDERATION . '/library-shares/incoming/' . rawurlencode($id) . '/accept')
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Reject an incoming federation library share. Resolves the server `message`;
     * rejects with the server `error` on 400 or 404.
     *
     * @return PromiseInterface<string>
     */
    public function rejectShare(string $id): PromiseInterface
    {
        return $this->api->send('POST', self::FEDERATION . '/library-shares/incoming/' . rawurlencode($id) . '/reject')
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Remove (delete) an outgoing federation library share by id. Resolves the
     * server `message`; rejects with the server `error` on 404.
     *
     * @return PromiseInterface<string>
     */
    public function removeShare(string $id): PromiseInterface
    {
        return $this->api->send('DELETE', self::FEDERATION . '/library-shares/outgoing/' . rawurlencode($id))
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    // ---- helpers --------------------------------------------------------

    /**
     * Map a mixed-list payload to a list of typed items. A null/non-array payload
     * yields an empty list (tolerance on malformed responses).
     *
     * @param mixed $list
     * @param \Closure(array<string, mixed>): T $mapper
     * @return list<T>
     * @template T
     */
    private static function mapList(mixed $list, \Closure $mapper): array
    {
        if (!is_array($list)) {
            return [];
        }
        /** @var list<array<string, mixed>> $typedList */
        $typedList = array_values($list);
        if ($typedList === [] || !is_array($typedList[0])) {
            return [];
        }

        return array_map($mapper, $typedList);
    }
}
