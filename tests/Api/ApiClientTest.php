<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\AuthResult;
use Phlix\Console\Api\MediaQuery;
use Phlix\Console\Api\NetworkError;
use Phlix\Console\Api\Dto\Album;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookPage;
use Phlix\Console\Api\Dto\AudiobookProgress;
use Phlix\Console\Api\Dto\AuthUser;
use Phlix\Console\Api\Dto\Book;
use Phlix\Console\Api\Dto\BookPage;
use Phlix\Console\Api\Dto\ContinueWatchingItem;
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
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

final class ApiClientTest extends TestCase
{
    private const BASE = 'https://srv.example';

    /** Login/refresh response fixture. */
    private function authResponse(string $access = 'access-1', string $refresh = 'refresh-1'): array
    {
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'user' => ['id' => 'u1', 'username' => 'joe', 'email' => 'joe@x.tld', 'is_admin' => 0, 'status' => 'active'],
        ];
    }

    // ---- login ---------------------------------------------------------

    public function testLoginSucceedsAndStoresToken(): void
    {
        $t = (new FakeTransport())->json(200, $this->authResponse());
        $client = new ApiClient(self::BASE, $t);

        $result = $this->await($client->login('joe', 'secret'));

        self::assertInstanceOf(AuthResult::class, $result);
        self::assertSame('joe', $result->user->username);
        self::assertSame('access-1', $result->tokens->accessToken);
        self::assertSame('access-1', $client->token()?->accessToken, 'token is stored on the client');

        $req = $t->requestAt(0);
        self::assertSame('POST', $req['method']);
        self::assertSame(self::BASE . '/api/v1/auth/login', $req['url']);
        self::assertArrayNotHasKey('Authorization', $req['headers'], 'login is unauthenticated');
        self::assertSame(['username' => 'joe', 'password' => 'secret'], json_decode($req['body'], true));
    }

    public function testLoginWithEmailAlsoSendsEmailField(): void
    {
        $t = (new FakeTransport())->json(200, $this->authResponse());
        $client = new ApiClient(self::BASE, $t);

        $this->await($client->login('joe@x.tld', 'pw'));

        $body = json_decode($t->requestAt(0)['body'], true);
        self::assertSame('joe@x.tld', $body['username']);
        self::assertSame('joe@x.tld', $body['email']);
    }

    public function testLoginFiresTokenChangedCallback(): void
    {
        $t = (new FakeTransport())->json(200, $this->authResponse());
        $client = new ApiClient(self::BASE, $t);
        $saved = null;
        $client->onTokenChanged(function (TokenBundle $b) use (&$saved): void {
            $saved = $b;
        });

        $this->await($client->login('joe', 'pw'));

        self::assertSame('access-1', $saved?->accessToken);
    }

    public function testLoginInvalidCredentialsRejectsWithAuthError(): void
    {
        $t = (new FakeTransport())->json(401, ['error' => 'Invalid username or password']);
        $client = new ApiClient(self::BASE, $t);

        $error = $this->awaitError($client->login('joe', 'bad'));

        self::assertInstanceOf(AuthError::class, $error);
        self::assertSame('Invalid username or password', $error->getMessage());
        self::assertSame(401, $error->statusCode);
    }

    public function testLoginPendingAccountRejectsWithPlainApiError(): void
    {
        $t = (new FakeTransport())->json(403, ['error' => 'Account is pending approval', 'code' => 'account_pending']);
        $client = new ApiClient(self::BASE, $t);

        $error = $this->awaitError($client->login('joe', 'pw'));

        self::assertInstanceOf(ApiError::class, $error);
        self::assertNotInstanceOf(AuthError::class, $error, '403 must not be treated as a refreshable 401');
        self::assertSame('Account is pending approval', $error->getMessage());
        self::assertSame(403, $error->statusCode);
    }

    // ---- authed reads --------------------------------------------------

    public function testMeSendsBearerAndMapsUser(): void
    {
        $t = (new FakeTransport())->json(200, ['user' => ['id' => 'u1', 'username' => 'joe', 'is_admin' => 1, 'status' => 'active']]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('tok-abc', 'ref', 'Bearer', null));

        $user = $this->await($client->me());

        self::assertInstanceOf(AuthUser::class, $user);
        self::assertTrue($user->isAdmin);
        self::assertSame('Bearer tok-abc', $t->requestAt(0)['headers']['Authorization']);
    }

    public function testLibrariesMapsList(): void
    {
        $t = (new FakeTransport())->json(200, ['libraries' => [
            ['id' => 'l1', 'name' => 'Movies', 'type' => 'movie', 'item_count' => 10],
            ['id' => 'l2', 'name' => 'TV', 'type' => 'series', 'item_count' => 5],
            'garbage',
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $libs = $this->await($client->libraries());

        self::assertContainsOnlyInstancesOf(Library::class, $libs);
        self::assertCount(2, $libs);
        self::assertSame('Movies', $libs[0]->name);
    }

    public function testMediaBuildsQueryAndMapsPage(): void
    {
        $t = (new FakeTransport())->json(200, [
            'items' => [['id' => 'a', 'name' => 'A', 'type' => 'movie']],
            'total' => 42,
            'limit' => 18,
            'offset' => 0,
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $page = $this->await($client->media(MediaQuery::forLibrary('lib-7', limit: 18)));

        self::assertInstanceOf(MediaPage::class, $page);
        self::assertSame(42, $page->total);
        $url = $t->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/media?', $url);
        self::assertStringContainsString('libraryId=lib-7', $url);
        self::assertStringContainsString('limit=18', $url);
        self::assertStringContainsString('offset=0', $url);
    }

    public function testLetterIndexHitsEndpointWithFiltersAndMaps(): void
    {
        $t = (new FakeTransport())->json(200, [
            'letters' => [
                ['letter' => '#', 'offset' => 0, 'count' => 2],
                ['letter' => 'A', 'offset' => 2, 'count' => 5],
                ['letter' => 'B', 'offset' => 7, 'count' => 0],
            ],
            'total' => 7,
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $index = $this->await($client->letterIndex(MediaQuery::forLibrary('lib-7')));

        self::assertSame(7, $index->total);
        self::assertSame(2, $index->offsetFor('A'));
        self::assertSame(['#', 'A'], $index->enabledLetters());
        $url = $t->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/media/letter-index?', $url);
        self::assertStringContainsString('libraryId=lib-7', $url);
    }

    public function testMediaItemMapsSingleItem(): void
    {
        $t = (new FakeTransport())->json(200, ['item' => ['id' => 'm1', 'name' => 'Matrix', 'type' => 'movie', 'stream_url' => 'https://s/x?sig=1']]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $item = $this->await($client->mediaItem('m1'));

        self::assertInstanceOf(MediaItem::class, $item);
        self::assertSame('https://s/x?sig=1', $item->streamUrl);
        self::assertStringEndsWith('/api/v1/media/m1', $t->requestAt(0)['url']);
    }

    // ---- music ---------------------------------------------------------

    public function testMusicAlbumsHitsEndpointAndMapsAlbumsWithTracks(): void
    {
        $t = (new FakeTransport())->json(200, ['albums' => [
            [
                'name' => 'Abbey Road',
                'artist' => 'The Beatles',
                'year' => 1969,
                'track_count' => 2,
                'tracks' => [
                    ['id' => 't1', 'name' => 'x', 'metadata' => ['title' => 'Come Together', 'track_number' => 1, 'duration_secs' => 259]],
                    ['id' => 't2', 'name' => 'x', 'metadata' => ['title' => 'Something', 'track_number' => 2, 'duration_secs' => 182]],
                ],
            ],
            [
                'name' => 'Revolver',
                'artist' => 'The Beatles',
                'year' => 1966,
                'track_count' => 1,
                'tracks' => [
                    ['id' => 't3', 'name' => 'x', 'metadata' => ['title' => 'Taxman', 'track_number' => 1]],
                ],
            ],
            'garbage',
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $albums = $this->await($client->musicAlbums());

        self::assertContainsOnlyInstancesOf(Album::class, $albums);
        self::assertCount(2, $albums, 'non-array rows are skipped');
        self::assertSame('Abbey Road', $albums[0]->name);
        self::assertCount(2, $albums[0]->tracks);
        self::assertSame('Come Together', $albums[0]->tracks[0]->title);
        self::assertSame('Revolver', $albums[1]->name);
        self::assertSame('Taxman', $albums[1]->tracks[0]->title);

        $req = $t->requestAt(0);
        self::assertSame('GET', $req['method']);
        self::assertSame(self::BASE . '/api/v1/music/albums', $req['url']);
    }

    public function testMusicAlbumHitsEndpointWithRawUrlEncodedNameAndMapsOne(): void
    {
        $t = (new FakeTransport())->json(200, ['album' => [
            'name' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'track_count' => 1,
            'tracks' => [
                ['id' => 't1', 'name' => 'x', 'metadata' => ['title' => 'Come Together']],
            ],
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $album = $this->await($client->musicAlbum('Abbey Road'));

        self::assertInstanceOf(Album::class, $album);
        self::assertSame('Abbey Road', $album->name);
        self::assertCount(1, $album->tracks);
        self::assertSame('Come Together', $album->tracks[0]->title);
        self::assertSame(self::BASE . '/api/v1/music/albums/Abbey%20Road', $t->requestAt(0)['url']);
    }

    // ---- books ---------------------------------------------------------

    public function testBooksHitsEndpointWithLibraryIdAndPagingAndMaps(): void
    {
        $t = (new FakeTransport())->json(200, [
            'books' => [
                ['id' => 'b1', 'name' => 'dune.epub', 'path' => '/x/dune.epub', 'metadata' => ['title' => 'Dune', 'author' => 'Frank Herbert']],
                'garbage',
            ],
            'limit' => 24,
            'offset' => 0,
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $page = $this->await($client->books('lib-1', 24, 0));

        self::assertInstanceOf(BookPage::class, $page);
        self::assertCount(1, $page->books, 'non-array rows are skipped');
        self::assertSame('Dune', $page->books[0]->title);
        self::assertSame('epub', $page->books[0]->format);
        self::assertSame(24, $page->limit);
        self::assertSame(0, $page->offset);

        $req = $t->requestAt(0);
        self::assertSame('GET', $req['method']);
        self::assertStringContainsString('/api/v1/books?', $req['url']);
        self::assertStringContainsString('library_id=lib-1', $req['url']);
        self::assertStringContainsString('limit=24', $req['url']);
        self::assertStringContainsString('offset=0', $req['url']);
    }

    public function testBooksOmitsLibraryIdWhenNull(): void
    {
        $t = (new FakeTransport())->json(200, ['books' => [], 'limit' => 50, 'offset' => 0]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $this->await($client->books(null));

        $url = $t->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/books?', $url);
        self::assertStringNotContainsString('library_id', $url, 'a null library_id is omitted');
        self::assertStringContainsString('limit=50', $url);
        self::assertStringContainsString('offset=0', $url);
    }

    public function testBookMapsTheSignedDetail(): void
    {
        $t = (new FakeTransport())->json(200, ['book' => [
            'id' => 'b1',
            'name' => 'dune.epub',
            'type' => 'book',
            'path' => '/x/dune.epub',
            'metadata' => ['title' => 'Dune', 'author' => 'Frank Herbert'],
            'cover_url' => '/api/v1/books/b1/cover?sig=abc',
            'read_url' => '/api/v1/books/b1/read?sig=def',
            'download_url' => '/api/v1/books/b1/download?sig=ghi',
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $book = $this->await($client->book('b1'));

        self::assertInstanceOf(Book::class, $book);
        self::assertSame('Dune', $book->title);
        self::assertSame('Frank Herbert', $book->author);
        self::assertSame('/api/v1/books/b1/cover?sig=abc', $book->coverUrl);
        self::assertSame('/api/v1/books/b1/read?sig=def', $book->readUrl);
        self::assertSame('/api/v1/books/b1/download?sig=ghi', $book->downloadUrl);
        self::assertSame('epub', $book->format);
        self::assertStringEndsWith('/api/v1/books/b1', $t->requestAt(0)['url']);
    }

    // ---- photos --------------------------------------------------------

    public function testPhotoAlbumsHitsEndpointWithLibraryIdAndMaps(): void
    {
        $t = (new FakeTransport())->json(200, [
            'albums' => [
                [
                    'id' => 'a1',
                    'date' => '2023-11-14',
                    'photo_count' => 2,
                    'cover_photo' => [
                        'id' => 'p1',
                        'name' => 'IMG_0001.jpg',
                        'thumbnail_url' => '/api/v1/photo/photos/p1/thumbnail?sig=cover',
                        'full_url' => '/api/v1/photo/photos/p1/full?sig=cover',
                    ],
                    'photos' => [
                        ['id' => 'p1', 'name' => 'IMG_0001.jpg', 'thumbnail_url' => '/t/p1', 'full_url' => '/f/p1'],
                        ['id' => 'p2', 'name' => 'IMG_0002.jpg', 'thumbnail_url' => '/t/p2', 'full_url' => '/f/p2'],
                    ],
                ],
                'garbage',
            ],
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $albums = $this->await($client->photoAlbums('lib-1'));

        self::assertContainsOnlyInstancesOf(PhotoAlbum::class, $albums);
        self::assertCount(1, $albums, 'non-array rows are skipped');
        self::assertSame('a1', $albums[0]->id);
        self::assertSame('2023-11-14', $albums[0]->date);
        self::assertSame(2, $albums[0]->photoCount);
        self::assertInstanceOf(Photo::class, $albums[0]->coverPhoto);
        self::assertSame('/api/v1/photo/photos/p1/thumbnail?sig=cover', $albums[0]->coverPhoto->thumbnailUrl);
        self::assertCount(2, $albums[0]->photos);
        self::assertSame('p2', $albums[0]->photos[1]->id);
        self::assertSame('/f/p2', $albums[0]->photos[1]->fullUrl);

        $req = $t->requestAt(0);
        self::assertSame('GET', $req['method']);
        self::assertStringContainsString('/api/v1/photo/albums?', $req['url']);
        self::assertStringContainsString('library_id=lib-1', $req['url']);
    }

    public function testPhotoMapsTheDetailWithExifAndSignedUrls(): void
    {
        $t = (new FakeTransport())->json(200, ['photo' => [
            'id' => 'p1',
            'name' => 'IMG_0001.jpg',
            'path' => '/photos/2023/IMG_0001.jpg',
            'metadata' => ['camera_make' => 'Canon', 'camera_model' => 'EOS R5'],
            'exif' => [
                'camera_make' => 'Canon',
                'camera_model' => 'EOS R5',
                'iso' => 400,
                'aperture' => 'f/2.8',
                'width' => 4000,
                'height' => 3000,
                'gps_lat' => 51.5074,
            ],
            'thumbnail_url' => '/api/v1/photo/photos/p1/thumbnail?sig=abc',
            'full_url' => '/api/v1/photo/photos/p1/full?sig=def',
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $photo = $this->await($client->photo('p1'));

        self::assertInstanceOf(Photo::class, $photo);
        self::assertSame('p1', $photo->id);
        self::assertSame('IMG_0001.jpg', $photo->name);
        self::assertNotNull($photo->exif);
        self::assertSame('Canon', $photo->exif->cameraMake);
        self::assertSame('EOS R5', $photo->exif->cameraModel);
        self::assertSame(400, $photo->exif->iso);
        self::assertSame('f/2.8', $photo->exif->aperture);
        self::assertSame(4000, $photo->exif->width);
        self::assertSame(51.5074, $photo->exif->gpsLat);
        self::assertSame('/api/v1/photo/photos/p1/thumbnail?sig=abc', $photo->thumbnailUrl);
        self::assertSame('/api/v1/photo/photos/p1/full?sig=def', $photo->fullUrl);
        self::assertStringEndsWith('/api/v1/photo/photos/p1', $t->requestAt(0)['url']);
    }

    // ---- audiobooks ----------------------------------------------------

    public function testAudiobooksHitsEndpointWithLibraryIdAndPagingAndMaps(): void
    {
        $t = (new FakeTransport())->json(200, [
            'audiobooks' => [
                ['id' => 'a1', 'name' => 'dune.m4b', 'metadata' => ['author' => 'Frank Herbert', 'duration_ms' => 75600000]],
                'garbage',
            ],
            'limit' => 100,
            'offset' => 0,
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $page = $this->await($client->audiobooks('lib-1', 100, 0));

        self::assertInstanceOf(AudiobookPage::class, $page);
        self::assertCount(1, $page->audiobooks, 'non-array rows are skipped');
        self::assertSame('a1', $page->audiobooks[0]->id);
        self::assertSame('Frank Herbert', $page->audiobooks[0]->author);
        self::assertSame(100, $page->limit);
        self::assertSame(0, $page->offset);

        $req = $t->requestAt(0);
        self::assertSame('GET', $req['method']);
        self::assertStringContainsString('/api/v1/audiobooks?', $req['url']);
        self::assertStringContainsString('library_id=lib-1', $req['url']);
        self::assertStringContainsString('limit=100', $req['url']);
        self::assertStringContainsString('offset=0', $req['url']);
    }

    public function testAudiobooksOmitsLibraryIdWhenNull(): void
    {
        $t = (new FakeTransport())->json(200, ['audiobooks' => [], 'limit' => 50, 'offset' => 0]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $this->await($client->audiobooks(null));

        $url = $t->requestAt(0)['url'];
        self::assertStringContainsString('/api/v1/audiobooks?', $url);
        self::assertStringNotContainsString('library_id', $url, 'a null library_id is omitted');
        self::assertStringContainsString('limit=50', $url);
        self::assertStringContainsString('offset=0', $url);
    }

    public function testAudiobookMapsTheSignedDetail(): void
    {
        $t = (new FakeTransport())->json(200, ['audiobook' => [
            'id' => 'a1',
            'title' => 'Dune',
            'author' => 'Frank Herbert',
            'narrator' => 'Scott Brick',
            'duration_ms' => 75600000,
            'cover_url' => '/var/data/covers/dune.jpg', // filesystem path — not exposed
            'stream_url' => '/api/v1/audiobooks/a1/stream?sig=abc',
            'read_url' => '/api/v1/audiobooks/a1/read?sig=def',
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $audiobook = $this->await($client->audiobook('a1'));

        self::assertInstanceOf(Audiobook::class, $audiobook);
        self::assertSame('Dune', $audiobook->title);
        self::assertSame('Frank Herbert', $audiobook->author);
        self::assertSame('Scott Brick', $audiobook->narrator);
        self::assertSame(75600000, $audiobook->durationMs);
        self::assertSame('/api/v1/audiobooks/a1/stream?sig=abc', $audiobook->streamUrl);
        self::assertStringEndsWith('/api/v1/audiobooks/a1', $t->requestAt(0)['url']);
    }

    public function testAudiobookChaptersMapsWithOrdinalFallback(): void
    {
        $t = (new FakeTransport())->json(200, ['chapters' => [
            ['index' => 0, 'title' => 'One', 'start_ms' => 0, 'end_ms' => 1000, 'duration_ms' => 1000],
            ['title' => 'Two', 'start_ms' => 1000, 'end_ms' => 3000, 'duration_ms' => 2000], // no index → ordinal 1
            'garbage',
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $chapters = $this->await($client->audiobookChapters('a1'));

        self::assertContainsOnlyInstancesOf(AudiobookChapter::class, $chapters);
        self::assertCount(2, $chapters, 'the non-array row is skipped');
        self::assertSame(0, $chapters[0]->index);
        self::assertSame('One', $chapters[0]->title);
        self::assertSame(1, $chapters[1]->index, 'a missing index falls back to the list ordinal');
        self::assertSame('Two', $chapters[1]->title);
        self::assertSame(2000, $chapters[1]->durationMs);
        self::assertStringEndsWith('/api/v1/audiobooks/a1/chapters', $t->requestAt(0)['url']);
    }

    public function testAudiobookProgressMaps(): void
    {
        $t = (new FakeTransport())->json(200, ['progress' => [
            'audiobook_id' => 'a1',
            'user_id' => 'u1',
            'position_ms' => 5000,
            'current_chapter_index' => 1,
            'completed_chapters' => [0],
            'percent_complete' => 10.0,
            'last_played_at' => 1700000000,
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $progress = $this->await($client->audiobookProgress('a1'));

        self::assertInstanceOf(AudiobookProgress::class, $progress);
        self::assertSame('a1', $progress->audiobookId);
        self::assertSame(5000, $progress->positionMs);
        self::assertSame(1, $progress->currentChapterIndex);
        self::assertSame([0], $progress->completedChapters);
        self::assertSame(10.0, $progress->percentComplete);
        self::assertSame('GET', $t->requestAt(0)['method']);
        self::assertStringEndsWith('/api/v1/audiobooks/a1/progress', $t->requestAt(0)['url']);
    }

    public function testSaveAudiobookProgressPostsTheBodyAndMaps(): void
    {
        $t = (new FakeTransport())->json(200, [
            'message' => 'saved',
            'progress' => [
                'audiobook_id' => 'a1',
                'user_id' => 'u1',
                'position_ms' => 12345,
                'current_chapter_index' => 2,
                'completed_chapters' => [0, 1],
                'percent_complete' => 25.0,
                'last_played_at' => 1700000001,
            ],
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $progress = $this->await($client->saveAudiobookProgress('a1', 12345, 2, [0, 1], 25.0));

        self::assertInstanceOf(AudiobookProgress::class, $progress);
        self::assertSame(12345, $progress->positionMs);
        self::assertSame(2, $progress->currentChapterIndex);
        self::assertSame([0, 1], $progress->completedChapters);
        self::assertSame(25.0, $progress->percentComplete);

        $req = $t->requestAt(0);
        self::assertSame('POST', $req['method']);
        self::assertStringEndsWith('/api/v1/audiobooks/a1/progress', $req['url']);
        $body = json_decode($req['body'], true);
        self::assertSame(12345, $body['position_ms']);
        self::assertSame(2, $body['current_chapter_index']);
        self::assertSame([0, 1], $body['completed_chapters']);
        // JSON has no float/int distinction, so 25.0 encodes as `25`; assert the
        // numeric value rather than the round-tripped PHP type.
        self::assertEqualsWithDelta(25.0, $body['percent_complete'], 0.0001);
    }

    public function testContinueWatchingMapsEntries(): void
    {
        $t = (new FakeTransport())->json(200, ['items' => [
            ['media_item_id' => 'm1', 'name' => 'Show', 'type' => 'episode', 'position_ticks' => 30, 'duration_ticks' => 100, 'metadata' => ['poster_url' => 'https://p/1.jpg']],
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $entries = $this->await($client->continueWatching());

        self::assertContainsOnlyInstancesOf(ContinueWatchingItem::class, $entries);
        self::assertSame('m1', $entries[0]->item->id);
        self::assertEqualsWithDelta(0.3, $entries[0]->progress(), 0.0001);
        self::assertStringEndsWith('/api/v1/users/me/continue-watching', $t->requestAt(0)['url']);
    }

    public function testPlaybackInfoMaps(): void
    {
        $t = (new FakeTransport())->json(200, ['playback_info' => ['id' => 'm1', 'name' => 'X', 'type' => 'movie', 'media_sources' => [['id' => 'default']]]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $info = $this->await($client->playbackInfo('m1'));

        self::assertInstanceOf(PlaybackInfo::class, $info);
        self::assertStringEndsWith('/api/v1/media/m1/playback', $t->requestAt(0)['url']);
    }

    public function testPlaybackMarkersMapsTheFlatShape(): void
    {
        $t = (new FakeTransport())->json(200, [
            'item_id' => 'm1',
            'intro_marker' => ['start_seconds' => 5, 'end_seconds' => 30],
            'outro_marker' => null,
            'chapters' => [['start_seconds' => 0, 'end_seconds' => 50, 'title' => 'One']],
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $markers = $this->await($client->playbackMarkers('m1'));

        self::assertInstanceOf(PlaybackMarkers::class, $markers);
        self::assertSame(5.0, $markers->intro?->start);
        self::assertNull($markers->outro);
        self::assertCount(1, $markers->chapters);
        self::assertStringEndsWith('/api/v1/media/m1/playback-info', $t->requestAt(0)['url']);
    }

    public function testCreateSessionPostsTheDeviceAndReturnsTheId(): void
    {
        $t = (new FakeTransport())->json(201, ['session_id' => 'sess-9']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $id = $this->await($client->createSession('dev-1', 'Phlix Console', 'console'));

        self::assertSame('sess-9', $id);
        $req = $t->requestAt(0);
        self::assertSame('POST', $req['method']);
        self::assertStringEndsWith('/api/v1/sessions', $req['url']);
        self::assertStringContainsString('"device_id":"dev-1"', $req['body']);
    }

    public function testReportProgressPostsTicks(): void
    {
        $t = (new FakeTransport())->json(200, ['message' => 'Progress updated']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $ok = $this->await($client->reportProgress('sess-9', 'm1', 100000000, 360000000, true));

        self::assertTrue($ok);
        $req = $t->requestAt(0);
        self::assertStringEndsWith('/api/v1/sessions/sess-9/progress', $req['url']);
        self::assertStringContainsString('"position_ticks":100000000', $req['body']);
        self::assertStringContainsString('"is_paused":true', $req['body']);
    }

    public function testEndSessionDeletes(): void
    {
        $t = (new FakeTransport())->json(200, ['message' => 'Session ended']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $ok = $this->await($client->endSession('sess-9'));

        self::assertTrue($ok);
        $req = $t->requestAt(0);
        self::assertSame('DELETE', $req['method']);
        self::assertStringEndsWith('/api/v1/sessions/sess-9', $req['url']);
    }

    public function testStartTranscodePostsWithProfile(): void
    {
        $t = (new FakeTransport())->json(200, ['job_id' => 'j1', 'master_url' => '/hls/j1/master.m3u8', 'status' => 'running']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $job = $this->await($client->startTranscode('m1'));

        self::assertInstanceOf(TranscodeJob::class, $job);
        self::assertSame('j1', $job->jobId);
        self::assertSame('/hls/j1/master.m3u8', $job->masterUrl);
        $req = $t->requestAt(0);
        self::assertSame('POST', $req['method']);
        self::assertStringContainsString('/api/v1/media/m1/transcode', $req['url']);
        self::assertStringContainsString('profile=web', $req['url']);
    }

    public function testTranscodeStatusMaps(): void
    {
        $t = (new FakeTransport())->json(200, [
            'job_id' => 'j1', 'status' => 'running', 'playlist_ready' => true, 'progress' => 42, 'master_url' => '/hls/j1/master.m3u8',
        ]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $job = $this->await($client->transcodeStatus('j1'));

        self::assertTrue($job->playlistReady);
        self::assertSame(42.0, $job->progress);
        self::assertTrue($job->isPlayable());
        self::assertStringEndsWith('/api/v1/transcode/j1/status', $t->requestAt(0)['url']);
    }

    public function testSubtitleTracksMapsRows(): void
    {
        $t = (new FakeTransport())->json(200, ['tracks' => [
            ['index' => 0, 'language' => 'eng', 'label' => 'English', 'default' => true, 'codec' => 'subrip'],
            ['index' => 1, 'language' => 'fra', 'label' => 'French', 'default' => false, 'codec' => 'ass'],
        ]]);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $tracks = $this->await($client->subtitleTracks('m1'));

        self::assertCount(2, $tracks);
        self::assertInstanceOf(SubtitleTrack::class, $tracks[0]);
        self::assertSame('eng', $tracks[0]->language);
        self::assertTrue($tracks[0]->default);
        self::assertSame(1, $tracks[1]->index);
        self::assertStringEndsWith('/api/v1/media/m1/subtitles', $t->requestAt(0)['url']);
    }

    public function testSubtitleVttReturnsTheRawBody(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi";
        $t = (new FakeTransport())->raw(200, $vtt);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $body = $this->await($client->subtitleVtt('m1', 2));

        self::assertSame($vtt, $body);
        self::assertStringEndsWith('/api/v1/media/m1/subtitles/2', $t->requestAt(0)['url']);
    }

    public function testSubtitleVttThrowsOnNon2xx(): void
    {
        $t = (new FakeTransport())->raw(404, 'nope');
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $this->expectException(ApiError::class);
        $this->await($client->subtitleVtt('m1', 0));
    }

    // ---- 401 refresh-and-retry ----------------------------------------

    public function testUnauthorizedTriggersRefreshAndRetry(): void
    {
        $t = (new FakeTransport())
            ->json(401, ['error' => 'Unauthorized'])           // 1: me() rejected
            ->json(200, $this->authResponse('access-2', 'refresh-2')) // 2: refresh
            ->json(200, ['user' => ['id' => 'u1', 'username' => 'joe']]); // 3: me() retried
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('stale', 'refresh-1', 'Bearer', null));
        $refreshed = null;
        $client->onTokenChanged(function (TokenBundle $b) use (&$refreshed): void {
            $refreshed = $b;
        });

        $user = $this->await($client->me());

        self::assertSame('joe', $user->username);
        self::assertSame(3, $t->requestCount(), 'me → refresh → me');
        self::assertSame(self::BASE . '/api/v1/auth/refresh', $t->requestAt(1)['url']);
        self::assertSame(['refresh_token' => 'refresh-1'], json_decode($t->requestAt(1)['body'], true));
        self::assertSame('Bearer access-2', $t->requestAt(2)['headers']['Authorization'], 'retry uses the refreshed token');
        self::assertSame('access-2', $refreshed?->accessToken);
    }

    public function testRefreshFailureRejectsWithAuthError(): void
    {
        $t = (new FakeTransport())
            ->json(401, ['error' => 'Unauthorized'])
            ->json(401, ['error' => 'Invalid refresh token']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('stale', 'refresh-1', 'Bearer', null));

        $error = $this->awaitError($client->me());

        self::assertInstanceOf(AuthError::class, $error);
        self::assertSame('Session expired — please log in again.', $error->getMessage());
        self::assertSame(2, $t->requestCount(), 'no second retry after a failed refresh');
    }

    public function testUnauthorizedWithoutRefreshTokenDoesNotRetry(): void
    {
        $t = (new FakeTransport())->json(401, ['error' => 'Unauthorized']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('stale', '', 'Bearer', null)); // no refresh token

        $error = $this->awaitError($client->me());

        self::assertInstanceOf(AuthError::class, $error);
        self::assertSame(1, $t->requestCount(), 'nothing to refresh with → single attempt');
    }

    public function testConcurrentRefreshCallsShareOneInFlightPromise(): void
    {
        // A never-settling transport keeps the refresh in flight so we can
        // observe that a second call returns the same promise.
        $client = new ApiClient(self::BASE, (new FakeTransport())->pending());
        $client->setToken(new TokenBundle('stale', 'refresh-1', 'Bearer', null));

        $first = $client->refresh();
        $second = $client->refresh();

        self::assertSame($first, $second);
    }

    public function testRefreshWithoutTokenRejects(): void
    {
        $client = new ApiClient(self::BASE, new FakeTransport());

        $error = $this->awaitError($client->refresh());

        self::assertInstanceOf(AuthError::class, $error);
    }

    // ---- transport failures -------------------------------------------

    public function testTransportFailureBecomesNetworkError(): void
    {
        $t = (new FakeTransport())->fail(new \RuntimeException('Connection refused'));
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $error = $this->awaitError($client->libraries());

        self::assertInstanceOf(NetworkError::class, $error);
        self::assertStringContainsString('Could not reach the server', $error->getMessage());
    }

    public function testServerErrorBecomesApiError(): void
    {
        $t = (new FakeTransport())->json(500, ['error' => 'boom']);
        $client = new ApiClient(self::BASE, $t);
        $client->setToken(new TokenBundle('t', 'r'));

        $error = $this->awaitError($client->libraries());

        self::assertInstanceOf(ApiError::class, $error);
        self::assertSame(500, $error->statusCode);
        self::assertNotInstanceOf(AuthError::class, $error);
    }

    public function testClearToken(): void
    {
        $client = new ApiClient(self::BASE, new FakeTransport());
        $client->setToken(new TokenBundle('t', 'r'));

        $client->clearToken();

        self::assertNull($client->token());
    }

    // ---- helpers -------------------------------------------------------

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }

    private function awaitError(PromiseInterface $promise): \Throwable
    {
        try {
            $this->await($promise);
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected the promise to reject, but it resolved.');
    }
}
