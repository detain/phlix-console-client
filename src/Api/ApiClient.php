<?php

declare(strict_types=1);

namespace Phlix\Console\Api;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookPage;
use Phlix\Console\Api\Dto\AudiobookProgress;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Api\Dto\BookPage;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\LetterIndex;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Api\Dto\PlaybackInfo;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Api\Dto\SubtitleTrack;
use Phlix\Console\Api\Dto\TranscodeJob;
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

        $refreshToken = $this->token->refreshToken ?? '';
        if ($refreshToken === '') {
            return reject(new AuthError('No refresh token available', 401));
        }

        // Drive a Deferred so the in-flight guard is set before the inner
        // request can settle (react/promise may resolve synchronously), and is
        // cleared exactly once when it does.
        /** @var Deferred<TokenBundle> $deferred */
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

    // ---- music ---------------------------------------------------------

    /**
     * The full album list — the server returns every album (each carrying its
     * full track list) in one call, with no pagination.
     *
     * @return PromiseInterface<list<Album>>
     */
    public function musicAlbums(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/music/albums')->then(static function (array $data): array {
            $albums = [];
            foreach (Coerce::map($data['albums'] ?? null) as $row) {
                if (is_array($row)) {
                    $albums[] = Album::fromArray($row);
                }
            }

            return $albums;
        });
    }

    /**
     * A single album by name (the server keys albums by name, case-insensitive).
     *
     * @return PromiseInterface<Album>
     */
    public function musicAlbum(string $name): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/music/albums/' . rawurlencode($name))
            ->then(static fn (array $data): Album => Album::fromArray(Coerce::map($data['album'] ?? null)));
    }

    // ---- books ---------------------------------------------------------

    /**
     * A page of books — scoped to a library when `$libraryId` is given (which
     * paginates that library), otherwise up to 1000 books across all libraries.
     * The server sends no total.
     *
     * @return PromiseInterface<BookPage>
     */
    public function books(?string $libraryId, int $limit = 50, int $offset = 0): PromiseInterface
    {
        $query = array_filter(
            ['library_id' => $libraryId, 'limit' => $limit, 'offset' => $offset],
            static fn (mixed $value): bool => $value !== null,
        );

        return $this->authed('GET', '/api/v1/books', $query)
            ->then(static fn (array $data): BookPage => BookPage::fromArray($data));
    }

    /**
     * A single book's detail, which adds the signed cover/read/download URLs the
     * list shape omits.
     *
     * @return PromiseInterface<Book>
     */
    public function book(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/books/' . rawurlencode($id))
            ->then(static fn (array $data): Book => Book::fromArray(Coerce::map($data['book'] ?? null)));
    }

    // ---- photos --------------------------------------------------------

    /**
     * The date-grouped photo albums for a library (required) — each album
     * carries its full photo list, so no separate flat-photo fetch is needed.
     * The server returns every album in one call, sorted date-descending.
     *
     * @return PromiseInterface<list<PhotoAlbum>>
     */
    public function photoAlbums(string $libraryId): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/photo/albums', ['library_id' => $libraryId])
            ->then(static function (array $data): array {
                $albums = [];
                foreach (Coerce::map($data['albums'] ?? null) as $row) {
                    if (is_array($row)) {
                        $albums[] = PhotoAlbum::fromArray($row);
                    }
                }

                return $albums;
            });
    }

    /**
     * A single photo's detail — the shape that adds the full EXIF map alongside
     * the signed thumbnail/full URLs (no `library_id` needed; looked up by id).
     *
     * @return PromiseInterface<Photo>
     */
    public function photo(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/photo/photos/' . rawurlencode($id))
            ->then(static fn (array $data): Photo => Photo::fromArray(Coerce::map($data['photo'] ?? null)));
    }

    // ---- audiobooks ----------------------------------------------------

    /**
     * A page of audiobooks — scoped to a library when `$libraryId` is given,
     * otherwise across all audiobook libraries. The server caps the page at 100
     * and sends no total.
     *
     * @return PromiseInterface<AudiobookPage>
     */
    public function audiobooks(?string $libraryId, int $limit = 50, int $offset = 0): PromiseInterface
    {
        $query = array_filter(
            ['library_id' => $libraryId, 'limit' => $limit, 'offset' => $offset],
            static fn (mixed $value): bool => $value !== null,
        );

        return $this->authed('GET', '/api/v1/audiobooks', $query)
            ->then(static fn (array $data): AudiobookPage => AudiobookPage::fromArray($data));
    }

    /**
     * A single audiobook's detail — the flat shape that adds the signed
     * `stream_url` the list omits.
     *
     * @return PromiseInterface<Audiobook>
     */
    public function audiobook(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/audiobooks/' . rawurlencode($id))
            ->then(static fn (array $data): Audiobook => Audiobook::fromArray(Coerce::map($data['audiobook'] ?? null)));
    }

    /**
     * The formatted chapter list for an audiobook. Each row already carries an
     * `index`; a missing one falls back to its position in the list.
     *
     * @return PromiseInterface<list<AudiobookChapter>>
     */
    public function audiobookChapters(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/audiobooks/' . rawurlencode($id) . '/chapters')
            ->then(static function (array $data): array {
                $chapters = [];
                $ordinal = 0;
                foreach (Coerce::map($data['chapters'] ?? null) as $row) {
                    if (is_array($row)) {
                        $chapters[] = AudiobookChapter::fromArray($row, $ordinal);
                    }
                    $ordinal++;
                }

                return $chapters;
            });
    }

    /**
     * The current listener's progress through an audiobook (position in ms,
     * current/completed chapters, percent complete).
     *
     * @return PromiseInterface<AudiobookProgress>
     */
    public function audiobookProgress(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/audiobooks/' . rawurlencode($id) . '/progress')
            ->then(static fn (array $data): AudiobookProgress => AudiobookProgress::fromArray(Coerce::map($data['progress'] ?? null)));
    }

    /**
     * Persist the listener's progress through an audiobook (position in ms,
     * current chapter, optionally the completed-chapter set and percent), and
     * return the saved progress.
     *
     * @param list<int> $completedChapters
     *
     * @return PromiseInterface<AudiobookProgress>
     */
    public function saveAudiobookProgress(
        string $id,
        int $positionMs,
        int $currentChapterIndex,
        array $completedChapters = [],
        float $percentComplete = 0.0,
    ): PromiseInterface {
        return $this->authed('POST', '/api/v1/audiobooks/' . rawurlencode($id) . '/progress', [], [
            'position_ms' => $positionMs,
            'current_chapter_index' => $currentChapterIndex,
            'completed_chapters' => $completedChapters,
            'percent_complete' => $percentComplete,
        ])->then(static fn (array $data): AudiobookProgress => AudiobookProgress::fromArray(Coerce::map($data['progress'] ?? null)));
    }

    // ---- playback sessions / progress ---------------------------------

    /**
     * Open a playback session (for progress reporting). Returns the session id.
     *
     * @return PromiseInterface<string>
     */
    public function createSession(string $deviceId, string $deviceName = 'Phlix Console', string $deviceType = 'console'): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/sessions', [], [
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'device_type' => $deviceType,
        ])->then(static fn (array $data): string => Coerce::str($data['session_id'] ?? ''));
    }

    /**
     * Report playback position for a session. `position`/`duration` are seconds;
     * the server stores Jellyfin-style 100ns ticks (1s = 10,000,000).
     *
     * @return PromiseInterface<bool>
     */
    public function reportProgress(string $sessionId, string $mediaItemId, int $positionTicks, int $durationTicks, bool $isPaused): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/sessions/' . rawurlencode($sessionId) . '/progress', [], [
            'media_item_id' => $mediaItemId,
            'position_ticks' => $positionTicks,
            'duration_ticks' => $durationTicks,
            'is_paused' => $isPaused,
        ])->then(static fn (array $data): bool => true);
    }

    /**
     * End a playback session.
     *
     * @return PromiseInterface<bool>
     */
    public function endSession(string $sessionId): PromiseInterface
    {
        return $this->authed('DELETE', '/api/v1/sessions/' . rawurlencode($sessionId))
            ->then(static fn (array $data): bool => true);
    }

    // ---- transcode fallback --------------------------------------------

    /**
     * Start (or reuse) a server HLS transcode for an item that can't be
     * direct-played, returning the job (incl. the signed master playlist URL and
     * the ABR ladder's `variants`).
     *
     * `$profile` selects the server-side encode profile / target quality; it
     * defaults to `web` (the master multi-variant ladder — server-driven ABR).
     * A caller that has let the viewer pin a rung passes that rendition id
     * instead (the server clamps unknown/too-high rungs to what it can produce).
     *
     * @return PromiseInterface<TranscodeJob>
     */
    public function startTranscode(string $id, string $profile = 'web'): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/media/' . rawurlencode($id) . '/transcode', ['profile' => $profile])
            ->then(static fn (array $data): TranscodeJob => TranscodeJob::fromArray($data));
    }

    /**
     * Poll a transcode job's readiness (status + playlist_ready + progress).
     *
     * @return PromiseInterface<TranscodeJob>
     */
    public function transcodeStatus(string $jobId): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/transcode/' . rawurlencode($jobId) . '/status')
            ->then(static fn (array $data): TranscodeJob => TranscodeJob::fromArray($data));
    }

    // ---- subtitles -----------------------------------------------------

    /**
     * List an item's text subtitle tracks (for the player's caption toggle).
     *
     * @return PromiseInterface<list<SubtitleTrack>>
     */
    public function subtitleTracks(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/subtitles')->then(static function (array $data): array {
            $tracks = [];
            foreach (Coerce::map($data['tracks'] ?? null) as $row) {
                if (is_array($row)) {
                    $tracks[] = SubtitleTrack::fromArray($row);
                }
            }

            return $tracks;
        });
    }

    /**
     * Fetch one subtitle track as raw WebVTT text (a `text/vtt` body, not JSON).
     * Best-effort — no refresh-and-retry; a failure just leaves captions off.
     *
     * @return PromiseInterface<string>
     */
    public function subtitleVtt(string $id, int $index): PromiseInterface
    {
        $headers = ['Accept' => 'text/vtt'];
        if ($this->token !== null) {
            $headers['Authorization'] = $this->token->authorizationHeader();
        }
        $url = $this->url('/api/v1/media/' . rawurlencode($id) . '/subtitles/' . $index, []);

        return $this->transport->send('GET', $url, $headers, '')->then(
            static function (ResponseInterface $response): string {
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new ApiError("Subtitle fetch failed (HTTP {$status})", $status);
                }

                return (string) $response->getBody();
            },
            static fn (\Throwable $error): never => throw $error instanceof ApiError
                ? $error
                : new NetworkError('Could not reach the server: ' . $error->getMessage(), 0, null, $error),
        );
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

    // ---- admin seam ----------------------------------------------------

    /**
     * The single public authed-JSON seam admin code uses: an authed request that
     * attaches the Bearer token, refreshes-and-retries once on a 401, and resolves
     * the decoded JSON body. Admin clients ({@see \Phlix\Console\Api\Admin\AdminClient})
     * call this rather than reaching into the private {@see authed()} internal.
     *
     * @param array<string,scalar|list<string>> $query
     * @param array<string,mixed>|null          $body
     * @return PromiseInterface<array<string,mixed>>
     */
    public function send(string $method, string $path, array $query = [], ?array $body = null): PromiseInterface
    {
        return $this->authed($method, $path, $query, $body);
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
