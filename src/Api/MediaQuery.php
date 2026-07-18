<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Api;

/**
 * An immutable `GET /api/v1/media` query. {@see MediaQuery::toParams()} renders
 * it to a parameter map, omitting anything unset so the URL stays clean.
 */
final readonly class MediaQuery
{
    /**
     * @param list<string> $genres
     * @param list<string> $ratings
     * @param list<string> $actors
     */
    public function __construct(
        public ?string $libraryId = null,
        public ?string $search = null,
        public ?string $parentId = null,
        public ?bool $topLevel = null,
        public ?string $sort = null,
        public ?string $order = null,
        public array $genres = [],
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public array $ratings = [],
        public array $actors = [],
        public ?string $match = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }

    /**
     * Creates a query for the home library rail (Anime/TV/Movies).
     * Sets topLevel=1 so the API returns series/season items with real posters
     * instead of individual episodes with stills.
     */
    public static function forLibrary(string $libraryId, int $limit = 50, int $offset = 0): self
    {
        return new self(libraryId: $libraryId, limit: $limit, offset: $offset, topLevel: true);
    }

    public function withOffset(int $offset): self
    {
        return new self(
            $this->libraryId, $this->search, $this->parentId, $this->topLevel,
            $this->sort, $this->order, $this->genres, $this->yearFrom, $this->yearTo,
            $this->ratings, $this->actors, $this->match, $this->limit, $offset,
        );
    }

    public function withLimit(int $limit): self
    {
        return new self(
            $this->libraryId, $this->search, $this->parentId, $this->topLevel,
            $this->sort, $this->order, $this->genres, $this->yearFrom, $this->yearTo,
            $this->ratings, $this->actors, $this->match, $limit, $this->offset,
        );
    }

    /**
     * A stable identity for this query *ignoring paging* — the cache key a
     * store uses to group pages of the same filtered result set.
     */
    public function cacheKey(): string
    {
        return sha1((string) json_encode([
            $this->libraryId, $this->search, $this->parentId, $this->topLevel,
            $this->sort, $this->order, $this->genres, $this->yearFrom, $this->yearTo,
            $this->ratings, $this->actors, $this->match,
        ]));
    }

    /**
     * @return array<string,scalar|list<string>>
     */
    public function toParams(): array
    {
        $params = ['limit' => $this->limit, 'offset' => $this->offset];

        if ($this->libraryId !== null) {
            $params['libraryId'] = $this->libraryId;
        }
        if ($this->search !== null && $this->search !== '') {
            $params['search'] = $this->search;
        }
        if ($this->parentId !== null) {
            $params['parentId'] = $this->parentId;
        }
        if ($this->topLevel === true) {
            $params['topLevel'] = '1';
        }
        if ($this->sort !== null) {
            $params['sort'] = $this->sort;
        }
        if ($this->order !== null) {
            $params['order'] = $this->order;
        }
        if ($this->genres !== []) {
            $params['genres'] = $this->genres;
        }
        if ($this->yearFrom !== null) {
            $params['yearFrom'] = $this->yearFrom;
        }
        if ($this->yearTo !== null) {
            $params['yearTo'] = $this->yearTo;
        }
        if ($this->ratings !== []) {
            $params['ratings'] = $this->ratings;
        }
        if ($this->actors !== []) {
            $params['actors'] = $this->actors;
        }
        if ($this->match !== null) {
            $params['match'] = $this->match;
        }

        return $params;
    }
}
