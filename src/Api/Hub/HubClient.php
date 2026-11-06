<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

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
     * List the incoming federation share offers — libraries other hubs have
     * offered to this account, awaiting `accept`/`reject` [review
     * console__uxdesign C1]. The list is deliberately the SAME resource family
     * as the answer verbs below: the first cut listed `/api/v1/me/shares`
     * (already-materialized shares keyed by `share_id`) but answered
     * `/library-shares/incoming/{id}` (pending offers keyed by `id`), so no id
     * this screen ever displayed could be accepted from the real hub. The
     * federation controller returns data at the TOP LEVEL with NO `{success,
     * data}` envelope, so the list is read straight from
     * `$body['incoming_offers']`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function sharedWithMe(): PromiseInterface
    {
        return $this->api
            ->send('GET', self::FEDERATION . '/library-shares/incoming')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['incoming_offers'] ?? null,
                    static fn (array $row): array => $row,
                );
            });
    }

    // ---- invite links ----------------------------------------------------

    /**
     * List the invite links created by the current user.
     * The invite-link controller returns data at the TOP LEVEL with NO
     * `{success, data}` envelope, so the list is read straight from
     * `$body['invite_links']` — the controller's own snake_case key [review
     * console__uxdesign C1: the first cut read a fabricated `inviteLinks` key
     * that the hub never sends, so the list parsed empty forever]. A non-list
     * payload yields an empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function inviteLinks(): PromiseInterface
    {
        return $this->api->send('GET', self::INVITE_LINKS)->then(static function (array $body): array {
            return self::mapList(
                $body['invite_links'] ?? null,
                static fn (array $row): array => $row,
            );
        });
    }

    /**
     * The hub servers this user has access to — the pool the invite-create
     * endpoint's required `server_id` is drawn from. Rides
     * {@see \Phlix\Console\Api\ApiClient::myServers()} (the single canonical
     * send site for `GET /api/v1/me/servers` — GateScanner HUB_TUPLES law);
     * rows pass through as the hub's camelCase ServerInfoDto payloads, whose
     * `serverId` is the UUID {@see createInvite()} must carry.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function servers(): PromiseInterface
    {
        return $this->api->myServers();
    }

    /**
     * Create a new invite link for a hub-registered server. The body is
     * `{server_id, expires_in?}` — the hub REQUIRES a non-empty `server_id`
     * and answers 400 `missing_server_id` without it [review w01L2console
     * C1: the first cut posted `{label, expires_in_seconds?}`, a fabricated
     * contract — the hub never reads `label`, and its expiry key is
     * `expires_in`, not `expires_in_seconds`. Estate second source: phlix-ui
     * src/api/invite-links.ts `CreateInviteLinkInput`]. The omitted
     * `expires_in` falls back to the hub's shipped default expiry;
     * permission/max_uses/library_id ride the hub-side defaults
     * ('read'/1/all-libraries).
     * On 201 the server returns the created link FLAT at the top level
     * (`{url, token, expires_at, id, permission, max_uses, library_id}` — the
     * plaintext token rides ONLY this response; listed links carry its hash),
     * and this resolves that row as a map [review console__uxdesign C1: the
     * first cut narrated an `{inviteLink: {...}}` wrapper that does not exist
     * and discarded the response, letting the screen fabricate an empty link].
     * Rejects with the server `error` on a 400 (missing_server_id/validation)
     * — the {@see \Phlix\Console\Api\ApiError} carries it as the exception
     * message.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function createInvite(string $serverId, ?int $expiresInSeconds = null): PromiseInterface
    {
        $body = ['server_id' => $serverId];
        if ($expiresInSeconds !== null) {
            $body['expires_in'] = $expiresInSeconds;
        }

        return $this->api->send('POST', self::INVITE_LINKS, [], $body)
            ->then(static fn (array $resp): array => Coerce::map($resp));
    }

    /**
     * Revoke (delete) an invite link by id. The hub answers 204 with NO body,
     * so this resolves an empty string on success (the value is discarded —
     * the screen re-reads the server-owned list); rejects with the server
     * `error` on 404.
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
     * List the outgoing federation library shares (libraries this hub has
     * shared with other federation peers). The federation controller returns
     * data at the TOP LEVEL with NO `{success, data}` envelope, so the list is
     * read straight from `$body['outgoing_shares']` — the controller's own key
     * (hub FederationController::getOutgoingShares) [review console__uxdesign
     * C2: the first cut parsed a stale `$body['outgoing']` the hub never sends,
     * so the screen's list was forever-empty against the real wire]. Rows carry
     * `id`, `library_id`, `library_name`, `peer_id`, `permission`, `status`,
     * `shared_at`, `revoked_at`. A non-list payload yields an empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function federationSharesOutgoing(): PromiseInterface
    {
        return $this->api->send('GET', self::FEDERATION . '/library-shares/outgoing')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['outgoing_shares'] ?? null,
                    static fn (array $row): array => $row,
                );
            });
    }

    /**
     * List the incoming federation library offers (libraries other federation
     * peers have offered to this hub, pending acceptance). Same top-level law:
     * the list is read straight from `$body['incoming_offers']` — the key
     * getIncomingOffers actually emits [review console__uxdesign C2: the stale
     * `$body['incoming']` read parsed nothing] — and it is the resource the
     * accept/reject verbs answer, keyed by `id`. A non-list payload yields an
     * empty list.
     *
     * @return PromiseInterface<list<array<string, mixed>>>
     */
    public function federationSharesIncoming(): PromiseInterface
    {
        return $this->api->send('GET', self::FEDERATION . '/library-shares/incoming')
            ->then(static function (array $body): array {
                return self::mapList(
                    $body['incoming_offers'] ?? null,
                    static fn (array $row): array => $row,
                );
            });
    }

    /**
     * Accept an incoming federation library offer. The hub's success body is
     * the offer row itself — `{id, status: 'accepted', accepted_by}` — it
     * carries NO `message` key, so this resolves an empty string and the
     * screen discards the value (the list is re-read from the hub) [review
     * w01L2console m1: the old docblock narrated a `message` resolution and a
     * "400 already accepted/rejected" rejection the hub never sends].
     * Rejects with the server `error`: 409 `offer_already_responded` on an
     * already-answered offer, 404 `offer_not_found` on an unknown id (hub
     * FederationController acceptIncomingOffer).
     *
     * @return PromiseInterface<string>
     */
    public function acceptShare(string $id): PromiseInterface
    {
        return $this->api->send('POST', self::FEDERATION . '/library-shares/incoming/' . rawurlencode($id) . '/accept')
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Reject an incoming federation library offer. Same wire truth as
     * {@see acceptShare()}: the success body is `{id, status: 'rejected',
     * accepted_by}` (no `message`; the resolved value is discarded), and the
     * rejections are 409 `offer_already_responded` / 404 `offer_not_found`.
     *
     * @return PromiseInterface<string>
     */
    public function rejectShare(string $id): PromiseInterface
    {
        return $this->api->send('POST', self::FEDERATION . '/library-shares/incoming/' . rawurlencode($id) . '/reject')
            ->then(static fn (array $resp): string => Coerce::str($resp['message'] ?? ''));
    }

    /**
     * Revoke (delete) an outgoing federation library share by id. Hub truth
     * (FederationController::revokeOutgoingShare): success is 204 with NO body,
     * so this always resolves the empty string — the screen supplies its own
     * sentence. Rejects with the server `error` text: 404 `share_not_found` on
     * an unknown id; a 400 `missing_share_id` is unreachable from here since the
     * id comes from the row itself.
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
        if ($typedList === []) {
            return [];
        }

        return array_map($mapper, $typedList);
    }
}
