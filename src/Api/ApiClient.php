<?php

declare(strict_types=1);

namespace Phlix\Console\Api;

use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\LetterIndex;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\PlaybackInfo;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Config\TokenBundle;
use Psr\Http\Message\ResponseInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

/**
 * Async, typed client for the Phlix server REST API.
 *
 * Every method returns a promise of a DTO; the App wraps these in a candy-core
 * Cmd. The client carries the current {@see TokenBundle}, attaches the Bearer
 * header to authed calls, and transparently refreshes-and-retries once on a
 * 401 (a single shared refresh is reused across concurrent calls). Token
 * changes (login + refresh) fire the {@see ApiClient::onTokenChanged()} hook so
 * the caller can persist them.
 */
final class ApiClient
{
    private readonly Transport $transport;
    private ?TokenBundle $token = null;
    private ?\Closure $onTokenChanged = null;

    /** @var PromiseInterface<TokenBundle>|null  A refresh in flight, shared across callers. */
    private ?PromiseInterface $refreshInFlight = null;

    public function __construct(
        private string $baseUrl,
        ?Transport $transport = null,
    ) {
        $this->transport = $transport ?? new BrowserTransport();
    }

    /** Point the client at a (different) server — set once the URL is known. */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setToken(?TokenBundle $token): void
    {
        $this->token = $token;
    }

    public function token(): ?TokenBundle
    {
        return $this->token;
    }

    public function clearToken(): void
    {
        $this->token = null;
    }

    /** Register a callback invoked whenever the token changes (login/refresh). */
    public function onTokenChanged(callable $callback): void
    {
        $this->onTokenChanged = $callback(...);
    }

    // ---- auth ----------------------------------------------------------

    /**
     * Log in with a username (or email) and password.
     *
     * @return PromiseInterface<AuthResult>
     */
    public function login(string $usernameOrEmail, string $password): PromiseInterface
    {
        $body = ['username' => $usernameOrEmail, 'password' => $password];
        if (str_contains($usernameOrEmail, '@')) {
            $body['email'] = $usernameOrEmail;
        }

        return $this->exchange('POST', '/api/v1/auth/login', [], $body, auth: false)
            ->then(function (array $data): AuthResult {
                $bundle = TokenBundle::fromAuthResponse($data);
                $this->applyToken($bundle);

                return new AuthResult(AuthUser::fromArray(Coerce::map($data['user'] ?? null)), $bundle);
            });
    }

    /**
     * Fetch the authenticated user (used to validate a restored token on boot).
     *
     * @return PromiseInterface<AuthUser>
     */
    public function me(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/auth/me')
            ->then(static fn (array $data): AuthUser => AuthUser::fromArray(Coerce::map($data['user'] ?? null)));
    }

    /**
     * Exchange the refresh token for a fresh access token. Concurrent callers
     * share a single in-flight refresh.
     *
     * @return PromiseInterface<TokenBundle>
     */
    public function refresh(): PromiseInterface
    {
        if ($this->refreshInFlight !== null) {
            return $this->refreshInFlight;
        }

        $refreshToken = $this->token?->refreshToken ?? '';
        if ($refreshToken === '') {
            return reject(new AuthError('No refresh token available', 401));
        }

        // Drive a Deferred so the in-flight guard is set before the inner
        // request can settle (react/promise may resolve synchronously), and is
        // cleared exactly once when it does.
        $deferred = new Deferred();
        $this->refreshInFlight = $deferred->promise();

        $this->exchange('POST', '/api/v1/auth/refresh', [], ['refresh_token' => $refreshToken], auth: false)
            ->then(
                function (array $data) use ($deferred): void {
                    $bundle = TokenBundle::fromAuthResponse($data);
                    $this->applyToken($bundle);
                    $this->refreshInFlight = null;
                    $deferred->resolve($bundle);
                },
                function (\Throwable $error) use ($deferred): void {
                    $this->refreshInFlight = null;
                    $deferred->reject($error);
                },
            );

        return $deferred->promise();
    }

    // ---- media ---------------------------------------------------------

    /** @return PromiseInterface<list<Library>> */
    public function libraries(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/libraries')->then(static function (array $data): array {
            $libraries = [];
            foreach (Coerce::map($data['libraries'] ?? null) as $row) {
                if (is_array($row)) {
                    $libraries[] = Library::fromArray($row);
                }
            }

            return $libraries;
        });
    }

    /** @return PromiseInterface<MediaPage> */
    public function media(MediaQuery $query): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media', $query->toParams())
            ->then(static fn (array $data): MediaPage => MediaPage::fromArray($data));
    }

    /**
     * The A–Z jump index for the same filters as {@see media()} (paging is
     * ignored server-side). Drives the LetterRail.
     *
     * @return PromiseInterface<LetterIndex>
     */
    public function letterIndex(MediaQuery $query): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/letter-index', $query->toParams())
            ->then(static fn (array $data): LetterIndex => LetterIndex::fromArray($data));
    }

    /** @return PromiseInterface<MediaItem> */
    public function mediaItem(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id))
            ->then(static fn (array $data): MediaItem => MediaItem::fromArray(Coerce::map($data['item'] ?? null)));
    }

    /** @return PromiseInterface<list<ContinueWatchingItem>> */
    public function continueWatching(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/users/me/continue-watching')->then(static function (array $data): array {
            $items = [];
            foreach (Coerce::map($data['items'] ?? null) as $row) {
                if (is_array($row)) {
                    $items[] = ContinueWatchingItem::fromArray($row);
                }
            }

            return $items;
        });
    }

    /** @return PromiseInterface<PlaybackInfo> */
    public function playbackInfo(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/playback')
            ->then(static fn (array $data): PlaybackInfo => PlaybackInfo::fromArray(Coerce::map($data['playback_info'] ?? null)));
    }

    /**
     * Intro/outro skip markers + chapters for the player's scrubber.
     *
     * A SEPARATE endpoint from {@see playbackInfo()} (which returns media
     * sources): `/playback-info` is a flat `{item_id, intro_marker, outro_marker,
     * chapters, skip_button_spec}` object served by the Application router.
     *
     * @return PromiseInterface<PlaybackMarkers>
     */
    public function playbackMarkers(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/playback-info')
            ->then(static fn (array $data): PlaybackMarkers => PlaybackMarkers::fromArray($data));
    }

    // ---- internals -----------------------------------------------------

    /**
     * An authed request that refreshes-and-retries once on a 401.
     *
     * @param array<string,scalar|list<string>> $query
     * @param array<string,mixed>|null          $body
     * @return PromiseInterface<array<string,mixed>>
     */
    private function authed(string $method, string $path, array $query = [], ?array $body = null): PromiseInterface
    {
        return $this->exchange($method, $path, $query, $body, auth: true)->then(
            null,
            function (\Throwable $error) use ($method, $path, $query, $body): PromiseInterface {
                if ($error instanceof AuthError && ($this->token?->hasRefreshToken() ?? false)) {
                    return $this->refresh()->then(
                        fn (): PromiseInterface => $this->exchange($method, $path, $query, $body, auth: true),
                        static fn (\Throwable $refreshError): never => throw new AuthError(
                            'Session expired — please log in again.',
                            401,
                            null,
                            $refreshError,
                        ),
                    );
                }

                throw $error;
            },
        );
    }

    /**
     * Perform one request and decode the JSON body, mapping a non-2xx status to
     * a typed error and a transport failure to a {@see NetworkError}.
     *
     * @param array<string,scalar|list<string>> $query
     * @param array<string,mixed>|null          $body
     * @return PromiseInterface<array<string,mixed>>
     */
    private function exchange(string $method, string $path, array $query, ?array $body, bool $auth): PromiseInterface
    {
        $headers = ['Accept' => 'application/json'];
        $payload = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = (string) json_encode($body);
        }
        if ($auth && $this->token !== null) {
            $headers['Authorization'] = $this->token->authorizationHeader();
        }

        return $this->transport->send($method, $this->url($path, $query), $headers, $payload)->then(
            static fn (ResponseInterface $response): array => self::decode($response),
            static fn (\Throwable $error): never => throw $error instanceof ApiError
                ? $error
                : new NetworkError('Could not reach the server: ' . $error->getMessage(), 0, null, $error),
        );
    }

    /**
     * @return array<string,mixed>
     * @throws ApiError
     */
    private static function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return $data;
        }

        $message = isset($data['error']) && is_string($data['error'])
            ? $data['error']
            : "Request failed (HTTP {$status})";

        if ($status === 401) {
            throw new AuthError($message, 401, $data);
        }

        throw new ApiError($message, $status, $data);
    }

    /**
     * @param array<string,scalar|list<string>> $query
     */
    private function url(string $path, array $query): string
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    private function applyToken(TokenBundle $bundle): void
    {
        $this->token = $bundle;
        if ($this->onTokenChanged !== null) {
            ($this->onTokenChanged)($bundle);
        }
    }
}
