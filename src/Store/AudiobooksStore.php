<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Store;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Audiobook;
use Phlix\Console\Api\Dto\AudiobookChapter;
use Phlix\Console\Api\Dto\AudiobookPage;
use Phlix\Console\Api\Dto\AudiobookProgress;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Caches audiobook lists (keyed by library), single-audiobook details and
 * chapter lists over the {@see ApiClient} with a short TTL, de-duplicating
 * concurrent fetches. Mirrors {@see BooksStore}.
 *
 * Unlike books (whose grid pages lazily), the audiobook screen wants the WHOLE
 * library at once: {@see all()} pages through the server's 100-capped endpoint
 * accumulating every audiobook, then caches the assembled list. Progress is
 * never cached (it must always be read fresh).
 */
final class AudiobooksStore
{
    /** The server caps a page at 100; a full page means there may be more. */
    private const PAGE_SIZE = 100;

    /** A hard safety ceiling on the page loop (100 * 50 = 5000 audiobooks). */
    private const MAX_PAGES = 50;

    /** @var array<string, array{list: list<Audiobook>, at: float}>  library key → cached list */
    private array $lists = [];

    /** @var array<string, PromiseInterface<list<Audiobook>>>  library key → in-flight list fetch */
    private array $listsInFlight = [];

    /** @var array<string, array{audiobook: Audiobook, at: float}>  id → cached detail */
    private array $audiobooks = [];

    /** @var array<string, PromiseInterface<Audiobook>>  id → in-flight detail fetch */
    private array $audiobooksInFlight = [];

    /** @var array<string, array{chapters: list<AudiobookChapter>, at: float}>  id → cached chapters */
    private array $chapters = [];

    /** @var array<string, PromiseInterface<list<AudiobookChapter>>>  id → in-flight chapters fetch */
    private array $chaptersInFlight = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(
        private readonly ApiClient $api,
        private readonly float $ttl = 60.0,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The full audiobook list for a library (or all libraries when `$libraryId`
     * is null), TTL-cached and de-duplicated. On a miss it pages through the
     * server's 100-capped endpoint, accumulating every audiobook.
     *
     * @return PromiseInterface<list<Audiobook>>
     */
    public function all(?string $libraryId, bool $force = false): PromiseInterface
    {
        $key = $libraryId ?? '';
        $now = ($this->clock)();

        if (!$force && isset($this->lists[$key]) && ($now - $this->lists[$key]['at']) < $this->ttl) {
            return resolve($this->lists[$key]['list']);
        }

        // Coalesce concurrent fetches of the same library. Drive a Deferred so
        // the guard is registered before the inner request chain can settle
        // (react may resolve synchronously) and cleared exactly once.
        if (isset($this->listsInFlight[$key])) {
            return $this->listsInFlight[$key];
        }

        /** @var Deferred<list<Audiobook>> $deferred */
        $deferred = new Deferred();
        $this->listsInFlight[$key] = $deferred->promise();

        $this->fetchPage($libraryId, 0, 0, [])->then(
            function (array $list) use ($key, $now, $deferred): void {
                $this->lists[$key] = ['list' => $list, 'at' => $now];
                unset($this->listsInFlight[$key]);
                $deferred->resolve($list);
            },
            function (\Throwable $error) use ($key, $deferred): void {
                unset($this->listsInFlight[$key]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * Fetch one page at `$offset`, append it to `$accumulated`, then either
     * resolve the full list (a short or empty page, or the safety cap) or chain
     * the next page. A recursive promise chain so the whole loop rides the one
     * Deferred {@see all()} registered for dedup.
     *
     * @param list<Audiobook> $accumulated
     *
     * @return PromiseInterface<list<Audiobook>>
     */
    private function fetchPage(?string $libraryId, int $offset, int $pageCount, array $accumulated): PromiseInterface
    {
        return $this->api->audiobooks($libraryId, self::PAGE_SIZE, $offset)->then(
            function (AudiobookPage $page) use ($libraryId, $offset, $pageCount, $accumulated): PromiseInterface {
                foreach ($page->audiobooks as $audiobook) {
                    $accumulated[] = $audiobook;
                }

                // A short (or empty) page means the library is exhausted; the
                // safety cap stops a misbehaving server from looping forever.
                if (count($page->audiobooks) < self::PAGE_SIZE || ($pageCount + 1) >= self::MAX_PAGES) {
                    return resolve($accumulated);
                }

                return $this->fetchPage($libraryId, $offset + self::PAGE_SIZE, $pageCount + 1, $accumulated);
            },
        );
    }

    /**
     * A single audiobook's detail — the shape that adds the signed stream URL
     * the list omits — TTL-cached and de-duplicated like {@see all()}.
     *
     * @return PromiseInterface<Audiobook>
     */
    public function audiobook(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && isset($this->audiobooks[$id]) && ($now - $this->audiobooks[$id]['at']) < $this->ttl) {
            return resolve($this->audiobooks[$id]['audiobook']);
        }

        if (isset($this->audiobooksInFlight[$id])) {
            return $this->audiobooksInFlight[$id];
        }

        /** @var Deferred<Audiobook> $deferred */
        $deferred = new Deferred();
        $this->audiobooksInFlight[$id] = $deferred->promise();

        $this->api->audiobook($id)->then(
            function (Audiobook $audiobook) use ($id, $now, $deferred): void {
                $this->audiobooks[$id] = ['audiobook' => $audiobook, 'at' => $now];
                unset($this->audiobooksInFlight[$id]);
                $deferred->resolve($audiobook);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->audiobooksInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * The formatted chapter list for an audiobook, TTL-cached and de-duplicated.
     *
     * @return PromiseInterface<list<AudiobookChapter>>
     */
    public function chapters(string $id, bool $force = false): PromiseInterface
    {
        $now = ($this->clock)();

        if (!$force && isset($this->chapters[$id]) && ($now - $this->chapters[$id]['at']) < $this->ttl) {
            return resolve($this->chapters[$id]['chapters']);
        }

        if (isset($this->chaptersInFlight[$id])) {
            return $this->chaptersInFlight[$id];
        }

        /** @var Deferred<list<AudiobookChapter>> $deferred */
        $deferred = new Deferred();
        $this->chaptersInFlight[$id] = $deferred->promise();

        $this->api->audiobookChapters($id)->then(
            function (array $chapters) use ($id, $now, $deferred): void {
                $this->chapters[$id] = ['chapters' => $chapters, 'at' => $now];
                unset($this->chaptersInFlight[$id]);
                $deferred->resolve($chapters);
            },
            function (\Throwable $error) use ($id, $deferred): void {
                unset($this->chaptersInFlight[$id]);
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }

    /**
     * The listener's progress through an audiobook — NEVER cached (it must
     * always reflect the latest position), a thin delegate to the client.
     *
     * @return PromiseInterface<AudiobookProgress>
     */
    public function progress(string $id): PromiseInterface
    {
        return $this->api->audiobookProgress($id);
    }

    /**
     * Persist the listener's progress — a thin delegate to the client.
     *
     * @param list<int> $completedChapters
     *
     * @return PromiseInterface<AudiobookProgress>
     */
    public function saveProgress(
        string $id,
        int $positionMs,
        int $currentChapterIndex,
        array $completedChapters = [],
        float $percentComplete = 0.0,
    ): PromiseInterface {
        return $this->api->saveAudiobookProgress($id, $positionMs, $currentChapterIndex, $completedChapters, $percentComplete);
    }

    public function invalidate(): void
    {
        $this->lists = [];
        $this->listsInFlight = [];
        $this->audiobooks = [];
        $this->audiobooksInFlight = [];
        $this->chapters = [];
        $this->chaptersInFlight = [];
    }
}
