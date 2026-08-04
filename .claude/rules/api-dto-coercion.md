---
name: api-dto-coercion
description: How src/Api/Dto factories normalise raw API arrays through the Coerce helpers, including the cast/crew list DTOs.
paths:
  - src/Api/Dto/**/*.php
  - tests/Api/Dto/**/*.php
---

# API DTO coercion

Every `src/Api/Dto` factory (`fromArray()`, `fromContinueWatching()`, …) reads decoded
JSON through `Phlix\Console\Api\Dto\Coerce` rather than inline casts, so the DTOs stay
PHPStan level-9 clean against `mixed` payloads:

`Coerce::str()` / `nstr()` · `int()` / `nint()` · `float()` / `nfloat()` · `bool()` ·
`stringList()` · `actorNames()` · `castList()` · `crewList()` · `map()`

The `n*` variants return `null` for absent/blank values; the others take a default
(`''`, `0`, `0.0`).

## Cast and crew

- `Coerce::castList()` returns `list<CastMember>|null` and `Coerce::crewList()` returns
  `list<CrewMember>|null` — **null** for a non-array or empty input, to match the API
  shape — and skips entries with no `name`.
- Keys read per entry: `name`, `role` (`src/Api/Dto/CastMember.php`) / `job`
  (`src/Api/Dto/CrewMember.php`), and `profile_url`.
- `Coerce::actorNames()` still feeds the flat `$actors` name list; it accepts plain
  names or `{name: ...}` objects.
- Do not keep a DTO field nothing reads — that is why `PlaybackInfo::$qualityLadder`
  was removed.

## Tests

`tests/Api/Dto/CoerceTest.php` exercises each helper with valid, absent, wrong-type,
and empty inputs; DTO tests assert the mapped fields, not helper internals.

```sh
vendor/bin/phpunit tests/Api/Dto
```
