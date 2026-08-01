---
name: poster-url-resolution
description: How screens resolve relative poster/artwork URLs before loading them, and the API-side flags that make posters resolvable.
paths:
  - src/Screen/**/*.php
  - src/Api/MediaQuery.php
  - src/Api/Dto/MediaItem.php
  - tests/Screen/**/*.php
---

# Poster / artwork URL resolution

Signed artwork URLs from the API arrive **relative** (no scheme), so a
`parse_url(..., PHP_URL_SCHEME)` check on the raw value silently drops every
poster. Always resolve first, then scheme-check, then load:

```php
$url = $this->resolveUrl($card->posterUrl);   // baseUrl + relative -> absolute
if ($url === '') {
    continue;                                  // treat as a missing poster
}
$scheme = parse_url($url, PHP_URL_SCHEME);     // false = malformed, null = no scheme
if ($scheme === null || $scheme === false || !in_array($scheme, ['http', 'https'], true)) {
    continue;
}
$cmds[] = $this->loadCover($i, $url);          // pass the RESOLVED url to PosterLoader
```

- Do **not** call `resolveUrl()` again inside the `Cmd::promise()` closure — the loader gets an already-absolute URL.
- Screens on this pattern: `src/Screen/BrowseScreen.php`, `src/Screen/DetailScreen.php`,
  `src/Screen/SearchScreen.php`, `src/Screen/PhotosScreen.php`, `src/Screen/PhotoAlbumScreen.php`.

## API side

- `MediaQuery::forLibrary()` sets `topLevel=1` so library rails/grids get
  series/season posters instead of episode stills. A `parentId` drill-down query
  must **not** set it.
- `MediaItem::fromContinueWatching()` prefers the top-level re-minted
  `poster_url` and falls back to `metadata.poster_url`.
- `MediaItem::$cast` / `$crew` come from `Coerce::castList()` / `Coerce::crewList()`
  (null when the API omits them); each member's `profileUrl` is relative like any
  other artwork URL — `src/Screen/DetailScreen.php` draws initials-based ANSI
  avatars for the `Cast` list instead of loading it.

## Tests

Assert real resolve-and-load behavior — no skipped or vacuous assertions. Use a real
local HTTP server for the relative case and deterministic scheduled-load counts for
empty/malformed/non-http URLs.

```sh
vendor/bin/phpunit tests/Screen tests/Api
```
