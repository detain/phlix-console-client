<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api;

use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\AlbumPage;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookPage;
use Phlix\Console\Api\Dto\AudiobookProgress;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Api\Dto\BookPage;
use Phlix\Console\Api\Dto\Chapter;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
use Phlix\Console\Api\Dto\RecentlyWatchedItem;
use Phlix\Console\Api\Dto\LetterIndex;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Api\Dto\MediaItem;
use Phlix\Console\Api\Dto\MediaPage;
use Phlix\Console\Api\Dto\MediaRatings;
use Phlix\Console\Api\Dto\Photo;
use Phlix\Console\Api\Dto\PhotoAlbum;
use Phlix\Console\Api\Dto\PhotoAlbumPage;
use Phlix\Console\Api\Dto\PlaybackInfo;
use Phlix\Console\Api\Dto\PlaybackMarkers;
use Phlix\Console\Api\Dto\Rating;
use Phlix\Console\Api\Dto\SubtitleSearchCandidate;
use Phlix\Console\Api\Dto\SubtitleTrack;
use Phlix\Console\Api\Dto\SyncPlayGroup;
use Phlix\Console\Api\Dto\SyncPlaySession;
use Phlix\Console\Api\Dto\TranscodeJob;
use Phlix\Console\Api\Dto\Trickplay;
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
     * Register a new user account.
     *
     * @return PromiseInterface<AuthResult>
     */
    public function register(string $username, string $email, string $password): PromiseInterface
    {
        return $this->exchange('POST', '/api/v1/auth/register', [], [
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ], auth: false)->then(function (array $data): AuthResult {
            // 202 Accepted: the account was created but requires admin approval.
            // No tokens are issued, so surface this as an ApiError so the caller
            // can display the appropriate pending-approval message.
            if (($data['status'] ?? null) === 'pending') {
                throw new ApiError(
                    is_string($data['message'] ?? null) ? $data['message'] : 'Your account is pending approval.',
                    202,
                    $data,
                );
            }

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

    // ---- servers (hub) -------------------------------------------------

    /**
     * The list of servers the authenticated user has access to on the hub.
     * Each entry: {hub_id, name, url}.
     *
     * @return PromiseInterface<list<array{hub_id:string,name:string,url:string}>>
     */
    public function myServers(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/me/servers')->then(static function (array $data): array {
            /** @var list<array{hub_id:string,name:string,url:string}> */
            return Coerce::map($data['servers'] ?? null);
        });
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

    /**
     * The available filter facets (genres) for a library, for the faceted search bar.
     *
     * @return PromiseInterface<array<string, list<string>>>
     */
    public function facets(string $libraryId): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/facets', ['libraryId' => $libraryId])
            ->then(static function (array $data): array {
                /** @var list<string> $genreList */
                $genreList = [];
                foreach (Coerce::map($data['genres'] ?? null) as $genre) {
                    if (is_string($genre)) {
                        $genreList[] = $genre;
                    }
                }

                return ['genres' => $genreList];
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

    /**
     * The chapter list for a media item (movie/episode).
     *
     * @return PromiseInterface<list<Chapter>>
     */
    public function mediaChapters(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/chapters')
            ->then(static function (array $data): array {
                $chapters = [];
                foreach (Coerce::map($data['chapters'] ?? null) as $row) {
                    if (is_array($row)) {
                        $chapters[] = Chapter::fromArray($row);
                    }
                }

                return $chapters;
            });
    }

    /**
     * Similar media items to the given item (More Like This).
     *
     * @return PromiseInterface<list<MediaItem>>
     */
    public function similar(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/similar')
            ->then(static function (array $data): array {
                $items = [];
                foreach (Coerce::map($data['items'] ?? null) as $row) {
                    if (is_array($row)) {
                        $items[] = MediaItem::fromArray($row);
                    }
                }

                return $items;
            });
    }

    /**
     * Initiate shuffle-play for a media item (album, artist, series, or leaf).
     *
     * The server shuffles the item's children and returns the shuffled IDs
     * plus a mode ('shuffle' for containers, 'single' for leaf items that
     * have no children but are themselves playable).
     *
     * @return PromiseInterface<array{shuffled_ids: list<string>, mode: string}>
     */
    public function shufflePlay(string $mediaId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/shuffle', [], ['media_id' => $mediaId])
            ->then(static function (array $data): array {
                /** @var list<string> $shuffledIds */
                $shuffledIds = [];
                foreach (Coerce::map($data['shuffled_ids'] ?? null) as $id) {
                    if (is_string($id)) {
                        $shuffledIds[] = $id;
                    }
                }

                return [
                    'shuffled_ids' => $shuffledIds,
                    'mode' => Coerce::str($data['mode'] ?? null) ?: 'shuffle',
                ];
            });
    }

    /**
     * The missing-episode report for a series media item.
     *
     * The server returns an ENVELOPE (snake_case), NOT a bare array:
     *   `{ total_expected, total_existing, missing_episodes: [{ episode_number }] }`.
     * `total_expected`/`total_existing` are absent on degraded branches (item has
     * no metadata_json or no positive episode_count) which still return
     * `{ missing_episodes: [] }` — hence both totals are optional and the
     * canonical count is always `missing_episodes.length`. Non-2xx throws
     * {@see ApiError}.
     *
     * @return PromiseInterface<array{total_expected?:int,total_existing?:int,missing_episodes:list<array{episode_number:int}>}>
     */
    public function missingEpisodes(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/missing-episodes')
            ->then(static function (array $data): array {
                /** @var list<array{episode_number:int}> */
                $missing = [];
                foreach (Coerce::map($data['missing_episodes'] ?? null) as $row) {
                    if (is_array($row)) {
                        $missing[] = [
                            'episode_number' => Coerce::int($row['episode_number'] ?? null),
                        ];
                    }
                }

                $result = ['missing_episodes' => $missing];
                if (isset($data['total_expected']) && is_numeric($data['total_expected'])) {
                    $result['total_expected'] = (int) $data['total_expected'];
                }
                if (isset($data['total_existing']) && is_numeric($data['total_existing'])) {
                    $result['total_existing'] = (int) $data['total_existing'];
                }

                return $result;
            });
    }

    // ---- music ---------------------------------------------------------

    /**
     * A page of the music library's album list, paged by limit/offset.
     *
     * @return PromiseInterface<AlbumPage>
     */
    public function musicAlbums(int $limit = 100, int $offset = 0): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/music/albums', ['limit' => (string) $limit, 'offset' => (string) $offset])
            ->then(static fn (array $data): AlbumPage => AlbumPage::fromArray($data));
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
     * A page of the photo library's album list, paged by limit/offset.
     * Albums are date-grouped collections of photos, sorted date-descending.
     * Photos are NOT included inline — fetch them separately per album.
     *
     * @return PromiseInterface<PhotoAlbumPage>
     */
    public function photoAlbums(string $libraryId, int $limit = 100, int $offset = 0): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/photo/albums', [
            'library_id' => $libraryId,
            'limit' => (string) $limit,
            'offset' => (string) $offset,
        ])->then(static fn (array $data): PhotoAlbumPage => PhotoAlbumPage::fromArray($data));
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
     * Mark a playback session as complete (removes the item from Continue Watching).
     *
     * @return PromiseInterface<bool>
     */
    public function completeSession(string $sessionId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/sessions/' . rawurlencode($sessionId) . '/complete')
            ->then(static fn (array $data): bool => true);
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
     * Uses the 401 refresh-and-retry path on auth expiry.
     *
     * @return PromiseInterface<string>
     */
    public function subtitleVtt(string $id, int $index): PromiseInterface
    {
        return $this->retryOnAuthError(fn (): PromiseInterface => $this->vttExchange($id, $index));
    }

    /**
     * @return PromiseInterface<string>
     */
    private function vttExchange(string $id, int $index): PromiseInterface
    {
        $headers = ['Accept' => 'application/octet-stream'];
        if ($this->token !== null) {
            $headers['Authorization'] = $this->token->authorizationHeader();
        }

        return $this->transport->send(
            'GET',
            $this->url('/api/v1/media/' . rawurlencode($id) . '/subtitles/' . $index, []),
            $headers,
            '',
        )->then(
            static function (ResponseInterface $response): string {
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    return (string) $response->getBody();
                }
                if ($status === 401) {
                    throw new AuthError('Unauthorized', 401);
                }
                throw new ApiError("Subtitle fetch failed (HTTP {$status})", $status);
            },
            static fn (\Throwable $error): never => throw $error instanceof ApiError
                ? $error
                : new NetworkError('Could not reach the server: ' . $error->getMessage(), 0, null, $error),
        );
    }

    // ---- external subtitle search ----------------------------------------

    /**
     * Search for external subtitles for a media item from enabled providers
     * (e.g. OpenSubtitles). Returns candidate tracks that can be downloaded.
     *
     * @param string $mediaId The media item id
     * @param string|null $lang ISO 639-1 language code (e.g. 'en', 'es'); multiple
     *                          languages can be comma-separated (e.g. 'en,es')
     *
     * @return PromiseInterface<list<SubtitleSearchCandidate>>
     */
    public function searchSubtitles(string $mediaId, ?string $lang = null): PromiseInterface
    {
        $query = $lang !== null ? ['lang' => $lang] : [];

        return $this->authed('GET', '/api/v1/media/' . rawurlencode($mediaId) . '/subtitles/search', $query)
            ->then(static function (array $data): array {
                $candidates = [];
                foreach (Coerce::map($data['candidates'] ?? null) as $row) {
                    if (is_array($row)) {
                        $candidates[] = SubtitleSearchCandidate::fromArray($row);
                    }
                }

                return $candidates;
            });
    }

    /**
     * Download and attach an external subtitle for a media item.
     * Returns the internal URL where the downloaded subtitle can be accessed.
     *
     * @param string $mediaId The media item id
     * @param string $provider The subtitle provider name (e.g. 'opensubtitles')
     * @param string $downloadId The provider's download identifier
     * @param string $language ISO 639-1 language code
     * @param string|null $format Optional format hint (e.g. 'srt', 'ass')
     *
     * @return PromiseInterface<string> The URL to access the downloaded subtitle
     */
    public function downloadSubtitle(
        string $mediaId,
        string $provider,
        string $downloadId,
        string $language,
        ?string $format = null,
    ): PromiseInterface {
        $body = [
            'provider' => $provider,
            'downloadId' => $downloadId,
            'language' => $language,
        ];
        if ($format !== null) {
            $body['format'] = $format;
        }

        return $this->authed('POST', '/api/v1/media/' . rawurlencode($mediaId) . '/subtitles/download', [], $body)
            ->then(static function (array $data) use ($mediaId): string {
                // The server returns the downloaded track with a stream_id.
                // The serve URL follows the pattern: /api/v1/media/{id}/subtitles/external/{stream_id}
                $trackData = Coerce::map($data['track'] ?? null);
                $streamId = Coerce::str($data['stream_id'] ?? ($trackData['stream_id'] ?? ''));

                return '/api/v1/media/' . rawurlencode($mediaId) . '/subtitles/external/' . rawurlencode($streamId);
            });
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

    /** @return PromiseInterface<list<RecentlyWatchedItem>> */
    public function recentlyWatched(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/users/me/recently-watched')->then(static function (array $data): array {
            $items = [];
            foreach (Coerce::map($data['items'] ?? null) as $row) {
                if (is_array($row)) {
                    $items[] = RecentlyWatchedItem::fromArray($row);
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

    /**
     * Trickplay (sprite-preview) URLs for the player's scrubber thumbnail strip.
     *
     * Fetched lazily on first scrub — not on player init — to keep the mount
     * path free of non-critical I/O. Both returned URLs may be null when
     * trickplay has not been generated for the item or the feature is disabled.
     *
     * @return PromiseInterface<Trickplay>
     */
    public function trickplay(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/trickplay')
            ->then(static function (array $data): Trickplay {
                // The server response is {sprite_url, timeline_url}, each possibly null.
                $spriteUrl = is_string($data['sprite_url'] ?? null) ? $data['sprite_url'] : null;
                $timelineUrl = is_string($data['timeline_url'] ?? null) ? $data['timeline_url'] : null;

                return new Trickplay($spriteUrl, $timelineUrl);
            });
    }

    // ---- download -------------------------------------------------------

    /**
     * Get a signed download URL for a media item.
     *
     * The returned URL is signed and time-limited; open it in a browser or
     * download tool to fetch the file. The server validates the rating cap
     * before minting the URL (over-cap items return 404, not a URL).
     *
     * @return PromiseInterface<string> The signed download URL
     */
    public function downloadMedia(string $mediaId): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($mediaId) . '/download')
            ->then(static fn (array $data): string => Coerce::str($data['url'] ?? null));
    }

    // ---- discovery ----------------------------------------------------

    /**
     * The server-wide "most watched" trending rail — the media items most-watched
     * across the WHOLE server (not per-user), served by `StatsCollector::getTopMedia()`.
     *
     * @return PromiseInterface<list<MediaItem>>
     */
    public function mostWatched(int $limit = 20, int $offset = 0): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/most-watched', [
            'limit' => (string) $limit,
            'offset' => (string) $offset,
        ])->then(static function (array $data): array {
            $items = [];
            foreach (Coerce::map($data['items'] ?? null) as $row) {
                if (is_array($row)) {
                    $items[] = MediaItem::fromArray($row);
                }
            }

            return $items;
        });
    }

    /**
     * Dismiss a "because you watched" recommendation so it no longer appears.
     *
     * @return PromiseInterface<bool>
     */
    public function dismissRecommendation(string $mediaItemId): PromiseInterface
    {
        return $this->authed('DELETE', '/api/v1/me/recommendations/' . rawurlencode($mediaItemId))
            ->then(static fn (array $data): bool => true);
    }

    /**
     * The user's "next up" list — next unwatched episode for each series they are watching.
     *
     * @return PromiseInterface<list<MediaItem>>
     */
    public function nextUp(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/users/me/next-up')->then(static function (array $data): array {
            $items = [];
            foreach (Coerce::map($data['items'] ?? null) as $row) {
                if (is_array($row)) {
                    $items[] = MediaItem::fromArray($row);
                }
            }

            return $items;
        });
    }

    // ---- ratings -------------------------------------------------------

    /**
     * All ratings for a media item (TMDB, IMDb, user, aggregated).
     *
     * @return PromiseInterface<MediaRatings>
     */
    public function mediaRatings(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/media/' . rawurlencode($id) . '/ratings')
            ->then(static fn (array $data): MediaRatings => MediaRatings::fromArray($data));
    }

    /**
     * Submit or update the authenticated user's personal rating for a media item.
     * Score is a float from 0.0 to 10.0.
     *
     * @return PromiseInterface<bool>
     */
    public function setMediaRating(string $id, float $score): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/media/' . rawurlencode($id) . '/ratings', [], [
            'score' => $score,
        ])->then(static fn (array $data): bool => true);
    }

    /**
     * Delete the authenticated user's personal rating for a media item (clear).
     *
     * @return PromiseInterface<bool>
     */
    public function deleteMediaRating(string $id): PromiseInterface
    {
        return $this->authed('DELETE', '/api/v1/media/' . rawurlencode($id) . '/ratings')
            ->then(static fn (array $data): bool => true);
    }

    // ---- favorites ----------------------------------------------------

    /**
     * Add a media item to the authenticated user's favorites.
     *
     * @return PromiseInterface<bool>
     */
    public function addFavorite(string $mediaId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/media/' . rawurlencode($mediaId) . '/favorite')
            ->then(static fn (array $data): bool => true);
    }

    /**
     * Remove a media item from the authenticated user's favorites.
     *
     * @return PromiseInterface<bool>
     */
    public function removeFavorite(string $mediaId): PromiseInterface
    {
        return $this->authed('DELETE', '/api/v1/media/' . rawurlencode($mediaId) . '/favorite')
            ->then(static fn (array $data): bool => true);
    }

    /**
     * The authenticated user's favorited media items, paginated.
     *
     * @return PromiseInterface<MediaPage>
     */
    public function favorites(int $limit = 100, int $offset = 0): PromiseInterface
    {
        $query = ['limit' => $limit, 'offset' => $offset];

        return $this->authed('GET', '/api/v1/users/me/favorites', $query)
            ->then(static fn (array $data): MediaPage => MediaPage::fromArray($data));
    }

    // ---- watched -------------------------------------------------------

    /**
     * Mark a media item as watched.
     *
     * @return PromiseInterface<bool>
     */
    public function markWatched(string $mediaId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/media/' . rawurlencode($mediaId) . '/watched')
            ->then(static fn (array $data): bool => true);
    }

    /**
     * Mark a media item as unwatched.
     *
     * @return PromiseInterface<bool>
     */
    public function markUnwatched(string $mediaId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/media/' . rawurlencode($mediaId) . '/unwatched')
            ->then(static fn (array $data): bool => true);
    }

    // ---- SyncPlay ------------------------------------------------------

    /**
     * Create a new SyncPlay room.
     *
     * @return PromiseInterface<SyncPlaySession>
     */
    public function createSyncPlayGroup(string $name, bool $isPublic = true): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/syncplay/groups', [], [
            'name' => $name,
            'is_public' => $isPublic,
        ])->then(static fn (array $data): SyncPlaySession => SyncPlaySession::fromArray($data));
    }

    /**
     * List all public SyncPlay rooms.
     *
     * @return PromiseInterface<list<SyncPlayGroup>>
     */
    public function listSyncPlayGroups(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/syncplay/groups')->then(static function (array $data): array {
            /** @var list<SyncPlayGroup> */
            $rooms = [];
            foreach (Coerce::map($data['groups'] ?? null) as $row) {
                if (is_array($row)) {
                    /** @var array<string, mixed> $row */
                    $rooms[] = SyncPlayGroup::fromArray($row);
                }
            }

            return $rooms;
        });
    }

    /**
     * Join an existing SyncPlay room.
     *
     * @return PromiseInterface<SyncPlaySession>
     */
    public function joinSyncPlayGroup(string $roomId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/syncplay/groups/' . rawurlencode($roomId) . '/join')
            ->then(static fn (array $data): SyncPlaySession => SyncPlaySession::fromArray($data));
    }

    /**
     * Leave the current SyncPlay room.
     *
     * @return PromiseInterface<bool>
     */
    public function leaveSyncPlayGroup(string $roomId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/syncplay/groups/' . rawurlencode($roomId) . '/leave')
            ->then(static fn (array $data): bool => true);
    }

    // ---- user settings --------------------------------------------------

    /**
     * Fetch the authenticated user's persisted settings from the server.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function getUserSettings(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/me/settings');
    }

    /**
     * Persist a full or partial settings map to the server.
     *
     * @param array<string, mixed> $settings
     * @return PromiseInterface<array<string, mixed>>
     */
    public function putUserSettings(array $settings): PromiseInterface
    {
        return $this->authed('PUT', '/api/v1/me/settings', [], $settings);
    }

    // ---- playlists ------------------------------------------------------

    /**
     * The user's playlists (collections).
     *
     * @return PromiseInterface<list<array{id:string,name:string,library_id:string}>>
     */
    public function getPlaylists(): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/collections')->then(static function (array $data): array {
            /** @var list<array{id:string,name:string,library_id:string}> */
            return Coerce::map($data['collections'] ?? null);
        });
    }

    /**
     * Create a new playlist.
     *
     * @param string $name       Playlist name
     * @param string $libraryId  Library to associate with
     * @return PromiseInterface<string> New playlist ID
     */
    public function createPlaylist(string $name, string $libraryId): PromiseInterface
    {
        return $this->authed('POST', '/api/v1/playlists', [], ['name' => $name, 'library_id' => $libraryId])
            ->then(static function (array $data): string {
                $collection = $data['collection'] ?? null;
                if (!is_array($collection) || !isset($collection['id'])) {
                    throw new ApiError('Invalid response: collection.id missing', 500, $data);
                }

                return Coerce::str($collection['id']);
            });
    }

    /**
     * A single playlist with its items.
     *
     * @return PromiseInterface<list<MediaItem>>
     */
    public function getPlaylist(string $id): PromiseInterface
    {
        return $this->authed('GET', '/api/v1/collections/' . rawurlencode($id))
            ->then(static function (array $data): array {
                $items = [];
                foreach (Coerce::map($data['items'] ?? null) as $row) {
                    if (is_array($row)) {
                        $items[] = MediaItem::fromArray($row);
                    }
                }

                return $items;
            });
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
     * Retry a promise once on a 401 by refreshing the token first.
     *
     * @template T
     * @param \Closure(): PromiseInterface<T> $produce
     * @return PromiseInterface<T>
     */
    private function retryOnAuthError(\Closure $produce): PromiseInterface
    {
        return $produce()->then(
            null,
            function (\Throwable $error) use ($produce): PromiseInterface {
                if ($error instanceof AuthError && ($this->token?->hasRefreshToken() ?? false)) {
                    return $this->refresh()->then(
                        fn (): PromiseInterface => $produce(),
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
     * Decodes an HTTP response and unwraps the standard API envelope.
     *
     * For 2xx responses: if the body contains {success:false}, throws ApiError with
     * the message from the "message" or "error" key. If {success:true,data:...} is
     * present, returns only the "data" key. Bare objects and arrays pass through unchanged.
     *
     * For 4xx/5xx responses: throws ApiError (or AuthError on 401) using the "error"
     * key as message, or a generic fallback.
     *
     * @return array<string,mixed>
     * @throws ApiError
     * @throws AuthError
     */
    private static function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            // Centralised envelope handling: {success:false} is an error,
            // {success:true,data:{...}} is unwrapped, bare objects pass through.
            if (array_key_exists('success', $data)) {
                if ($data['success'] === false) {
                    $message = isset($data['message']) && is_string($data['message'])
                        ? $data['message']
                        : (isset($data['error']) && is_string($data['error'])
                            ? $data['error']
                            : 'Request failed');
                    throw new ApiError($message, $status, $data);
                }
                if ($data['success'] === true && array_key_exists('data', $data)) {
                    return $data['data'];
                }
            }

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
