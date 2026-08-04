---
name: poster-placement-modes
description: How PosterLoader hands posters to screens in inline vs overlay render modes, and the digest dedupe that keeps the ImageLayer bounded.
paths:
  - src/Media/PosterLoader.php
  - src/Screen/LibraryScreen.php
  - src/Screen/DetailScreen.php
  - tests/Media/PosterLoaderTest.php
---

# Poster placement: inline vs overlay

`PosterLoader::load()` resolves to a `PosterLoadResult`:

- inline modes (`halfblock` / `quarterblock` / `ascii` / `ansi256` / `truecolor`) —
  `marker` *is* the poster text and `imageId` is `null`.
- overlay modes (`sixel` / `kitty` / `iterm2`) — `marker` is a placeholder block and
  `imageId` identifies the placement returned by `PosterLoader::imageLayer()`.

A screen must branch on both before storing the result on a card:

```php
$newCard = ($imageId !== null && !$this->posters->isInline())
    ? $card->withImage($ansi, $imageId)   // overlay: marker + placement id
    : $card->withPoster($ansi);           // inline: marker is the pixels
```

Calling `withPoster()` in an overlay mode throws the `imageId` away, so the runtime
never paints (or clears) the placement.

## Digest dedupe

`PosterLoader::present()` keys placements by `hash('xxh3', $bytes)` and reuses the
cached `marker` / `imageId` for identical bytes. Without it the `ImageLayer` grows
unbounded and every poster redraws each frame. Place bytes only through
`present()` — never call `ImageLayer::placeTracked()` from a screen.

```sh
vendor/bin/phpunit tests/Media/PosterLoaderTest.php tests/Screen/LibraryScreenTest.php
```
