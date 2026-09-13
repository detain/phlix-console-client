# Changelog

All notable changes to **phlix-console-client** are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed — W83 (cs44): route-manifest PROVENANCE re-pin (404 tuples — route bytes unmoved) — 2026-09-13

- **cs#44 currency cascade (lane cs44) — PROVENANCE-only, not a content regen.**
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from the
  `@phlix/contracts` canonical master export, and `ServerRouteManifestGateTest.php`
  advances its header cite, `EXPECTED_MD5`, `EXPECTED_SERVER_SHA`, and the
  contracts-drift failure message to the current phlix-server master tip in the
  same commit. The server span since the previous pin is bundle-only: no
  route-registration file and nothing under the server's `include/` or `src/`
  moved, so the `[method, path]` tuples are byte-for-byte identical and the
  `total`/route-count/unique all HOLD at 404. Only the embedded provenance moves,
  which rotates the vendored blob while the described route surface is unchanged.
  The client-side inventories are HELD: `SWEEP_TOKEN_COUNTS`
  (`src/Api/ApiClient.php` = 68), `PER_CLASS_ANCHORS` (67), and `TOTAL_COMPARED`
  (223) — no client request site moved this wave. The two S240 `isServed` query-rail
  assertions (album / artist) survive untouched as preservation checks. `build/phlix.phar`
  is NOT rebuilt (cs#42/#43 precedent — the gate tolerates the stale phar). No
  survival-token home in this repo — the wave token lives in its two verified code
  homes.

### Changed — W81 (cs43): route-manifest CONTENT regen (402→404) + S240 album query-param migration — 2026-09-12

- **cs#43 currency cascade (lane cs43) — a CONTENT regen.** phlix-server S240
  merged two ADDITIVE query-param rails (`GET /api/v1/music/artist?name=` and
  `GET /api/v1/music/album?name=[&artist=]`) alongside the existing legacy
  path-param routes, so the vendored manifest grows **402 → 404 `[method, path]`
  tuples**. `tests/fixtures/server-route-manifest.json` re-vendored byte-identical
   from `@phlix/contracts` master (untagged regen #30 @ the then-current server
   tip; the blob is content-identical across the estate).
- **S240 album client migration (the pinned-broken-spelling reconcile).**
  `ApiClient::musicAlbum` previously built the LEGACY plural path
  `/api/v1/music/albums/{name}` (`rawurlencode`, space → `%20`). It now rides the
  S240 singular query rail `authed('GET', '/api/v1/music/album', ['name' =>
  $name])`, following the `musicAlbums` list method's existing `$query`-array
  building exactly (so `http_build_query` RFC1738-encodes the value, space → `+`,
  server-decoded to the same name). `ApiClientTest::testMusicAlbumHits…` is
  reconciled to the new URL `/api/v1/music/album?name=Abbey+Road`; reverting the
  client to the legacy plural form turns that assertion RED (verified — the exact
  S240 reconcile AC).
- **Gate moves with the content.** `ServerRouteManifestGateTest.php`:
  `EXPECTED_MD5` → the new contracts export md5, `EXPECTED_SERVER_SHA` → the
  era sha, the integrity header + `total`/`count`/uniqueness pins advance `402 →
  404`, the drift-message contracts cite advances to `@42f866f`, and two named
  S240 `isServed` AC checks (album + artist query rails) are added next to the
  S405 rail checks — they RED if the manifest regresses below S240. Every
  client-side count the gate derives from this repo's own code was RE-MEASURED and
  held unchanged: `SWEEP_TOKEN_COUNTS['src/Api/ApiClient.php']` = 68, the
  reconstruction anchor = 67, `TOTAL_COMPARED` = 223 — the migration swaps one
  `/api/v1`-carrying literal for another (the value now rides the query array), so
  the per-file token inventory does not move.
- `build/phlix.phar` deliberately NOT rebuilt (cs#42 precedent). PHPUnit baseline
  holds green at this tip: 2782 tests / 10010 assertions, 0 errors/failures
  (9 skipped); phpstan clean; phpcs introduces no new warnings.

### Changed — W79 (cs42): route-manifest currency re-pin to current server master — 2026-09-12

- **cs#42 currency re-pin cascade (lane cs42).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #29 against the current phlix-server
  master tip). **Pure provenance re-pin, not a content change:** the server span
  since the prior pin is four commits of SyncPlay bridge/worker/room and
  WebSocket plumbing work, a catalog-pin bump and an AGENTS.md theming
  paragraph — an empty diff on the two route-bearing guard-constant
  files — so the manifest still carries exactly 402 `[method, path]`
  tuples and the stripped route content is unchanged (the estate fence digest
  measures equal old-vs-new); only `provenance.serverSha` / `generatedAt` move.
  Gate pins in `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, header cite, failure-message contracts cite) advance in
  the same commit; every client-side scan total the gate derives from this repo's
  own code stays exactly as pinned. No Console request surface changed;
  `build/phlix.phar` deliberately NOT rebuilt (unchanged source, cs31/cs32 held).

### Changed — W75 (cs41): route-manifest currency re-pin to current server master — 2026-09-12

- **cs#41 currency re-pin cascade (lane cs41).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #28 against the current phlix-server
  master tip). **Pure provenance re-pin, not a content change:** the server span
  since the prior pin is one merge confined to the parallel-suite
  junit-merge tooling, two new guard tests and changelog/release-journal
  prose — an empty diff under the route-surface
  directories — so the manifest still carries exactly 402 `[method, path]`
  tuples and the stripped route content is unchanged (the estate fence digest
  measures equal old-vs-new); only `provenance.serverSha` / `generatedAt` move.
  Gate pins in `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, failure-message contracts cite) advance in the
  same commit; every client-side scan total the gate derives from this repo's own
  code stays exactly as pinned. No Console request surface changed;
  `build/phlix.phar` deliberately NOT rebuilt (unchanged source, cs31/cs32 held).

### Changed — W72 (cs40): route-manifest currency re-pin to current server master — 2026-09-12

- **cs#40 currency re-pin cascade (lane cs40).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #27 against the current phlix-server
  master tip). **Pure provenance re-pin, not a content change:** the server span
  since the prior pin is three merges confined to parallel-harness
  suite-runner plumbing, an ignore-rule tidy and changelog/release-journal
  prose — an empty diff under the route-surface
  directories — so the manifest still carries exactly 402 `[method, path]`
  tuples and the stripped route content is unchanged (the estate fence digest
  measures equal old-vs-new); only `provenance.serverSha` / `generatedAt` move.
  Gate pins in `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, failure-message contracts cite) advance in the
  same commit; every client-side scan total the gate derives from this repo's own
  code stays exactly as pinned. No Console request surface changed;
  `build/phlix.phar` deliberately NOT rebuilt (unchanged source, cs31/cs32 held).

### Changed — W70 (cs39): route-manifest currency re-pin to current server master — 2026-09-11

- **cs#39 currency re-pin cascade (lane cs39).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #26 against the current phlix-server
  master tip). **Pure provenance re-pin, not a content change:** the server span
  since the prior pin is one packaging-and-installation merge confined to the
  install script, release-process and changelog journals, and one third-party
  clone pin-guard test — an empty diff under the route-surface
  directories — so the manifest still carries exactly 402 `[method, path]`
  tuples and the stripped route content is unchanged (the estate fence digest
  measures equal old-vs-new); only `provenance.serverSha` / `generatedAt` move.
  Gate pins in `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, docblock + failure-message contracts cite) advance in the
  same commit; every client-side scan total the gate derives from this repo's own
  code stays exactly as pinned. No Console request surface changed;
  `build/phlix.phar` deliberately NOT rebuilt (unchanged source, cs31/cs32 held).

### Changed — W67 (cs38): route-manifest currency re-pin to current server master — 2026-09-11

- **cs#38 currency re-pin cascade (lane cs38).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #25 against the current phlix-server
  master tip). **Pure provenance re-pin, not a content change:** the server span
  since the prior pin is two commits confined to CI workflow definitions, docker
  example files, helm chart value files, support test files and changelog
  prose — an empty diff under the route-surface
  directories — so the manifest still carries exactly 402 `[method, path]`
  tuples and the stripped route content is unchanged (the estate fence digest
  measures equal old-vs-new); only `provenance.serverSha` / `generatedAt` move.
  Gate pins in `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, docblock + failure-message contracts cite) advance in the
  same commit; every client-side scan total the gate derives from this repo's own
  code stays exactly as pinned. No Console request surface changed;
  `build/phlix.phar` deliberately NOT rebuilt (unchanged source, cs31/cs32 held).

### Changed — W63 (cs37): route-manifest currency re-pin to current server master — 2026-09-11

- **cs#37 currency re-pin cascade (lane cs37).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #24 against the current phlix-server
  master tip). **Pure provenance re-pin, not a content change:** the server span
  since the prior pin touched only CI workflow definitions, one CI prerequisite
  script and three test files — an empty diff under the route-surface
  directories — so the manifest still carries exactly 402 `[method, path]`
  tuples and the stripped route content is unchanged (the estate fence digest
  measures equal old-vs-new); only `provenance.serverSha` / `generatedAt` move.
  Gate pins in `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, docblock + failure-message contracts cite) advance in the
  same commit; every client-side scan total the gate derives from this repo's own
  code stays exactly as pinned. No Console request surface changed;
  `build/phlix.phar` deliberately NOT rebuilt (unchanged source, cs31/cs32 held).

### Changed — W61 (cs36): route-manifest currency re-pin to current server master — 2026-09-11

- **cs#36 currency re-pin cascade (lane cs36).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen against the current phlix-server
  master tip). **Pure provenance re-pin, not a content change:** the server span
  since the prior pin touched only a test file and the CHANGELOG, so the manifest
  still carries exactly 402 `[method, path]` tuples and the stripped route
  content is unchanged — only `provenance.serverSha` / `generatedAt` move. Gate
  pins in `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, docblock + failure-message contracts cite) advance in the
  same commit; every client-side scan total the gate derives from this repo's own
  code stays exactly as pinned. No Console request surface changed.

### Changed — W59 (cs35): route-manifest full regen (401 → 402 tuples) — 2026-09-11

- **cs#35 currency re-pin cascade (lane cs35).** Vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen against the current phlix-server
  master tip). Genuine full regen, not provenance-only: the server gained one
  WebPortal route, so the manifest moves 401 → 402 `[method, path]` tuples
  (Application source count holds, WebPortal source count rises by one, shared
  overlap unchanged). Gate pins in `ServerRouteManifestGateTest.php`
  (`EXPECTED_MD5`, `EXPECTED_SERVER_SHA`, provenance.total / routes-count /
  uniqueness assertions, docblock + failure-message cites) advance in the same
  commit. No Console request site calls the new route, so the client-side scan
  totals the gate derives from this repo's own code stay exactly as pinned — the
  manifest simply becomes a superset. Test-only change — the tracked
  `build/phlix.phar` is NOT rebuilt and its content holds. Untagged wave: the
  dependency tag pin stays put.

### Prior era snapshot — W58 (cs34): PURE provenance re-pin (401 tuples)

### Changed — W58 (cs34): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-10

- PURE provenance re-pin, zero route bytes: vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #21 against the current
  phlix-server master tip; purity re-proven before regenerating — the server
  route-relevant span is empty and both generator-input guard blobs hash
  identically at both ends). 401 `[method, path]` tuples unchanged; the
  provenance-stripped route-content md5 and the sorted-tuple fence digest
  measure equal old-vs-new, only provenance bytes move. Gate pins in
  `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`, `EXPECTED_SERVER_SHA`,
  docblock + failure-message cites) advance in the same commit; counts
  untouched. Test-only change — the tracked `build/phlix.phar` is NOT rebuilt
  and its content md5 holds. Untagged wave: the dependency tag pin stays put.

### Changed — W57 (cs33): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-10

- PURE provenance re-pin, zero route bytes: vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #20 against the current
  phlix-server master tip; purity re-proven before regenerating — the server
  route-relevant span is empty and both generator-input guard blobs hash
  identically at both ends). 401 `[method, path]` tuples unchanged; the
  provenance-stripped route-content md5 and the sorted-tuple fence digest
  measure equal old-vs-new, only provenance bytes move. Gate pins in
  `ServerRouteManifestGateTest.php` (`EXPECTED_MD5`, `EXPECTED_SERVER_SHA`,
  docblock + failure-message cites) advance in the same commit; counts
  untouched. Test-only change — the tracked `build/phlix.phar` is NOT rebuilt
  and its content md5 holds. Untagged wave: the dependency tag pin stays put.

### Changed — W55 (cs32): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-10

- PURE provenance re-pin, zero route bytes: vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #19 against the current
  phlix-server master tip; purity re-proven before regenerating — the server
  route-relevant span is empty and both generator-input guard blobs hash
  identically at both ends). 401 `[method, path]` tuples unchanged; the
  provenance-stripped route-content md5 measures equal old-vs-new, only
  provenance bytes move. Gate pins in `ServerRouteManifestGateTest.php`
  (`EXPECTED_MD5`, `EXPECTED_SERVER_SHA`, docblock + failure-message cites)
  advance in the same commit; counts untouched. Test-only change — the tracked
  `build/phlix.phar` is NOT rebuilt and its content md5 holds. Untagged wave:
  the dependency tag pin stays put.

### Changed — W53 (cs31): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-10

- PURE provenance re-pin, zero route bytes: vendored
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  `@phlix/contracts` master (untagged regen #18 against the current
  phlix-server master tip; purity re-proven before regenerating — the server
  route-relevant span is empty and both generator-input guard blobs hash
  identically at both ends). 401 `[method, path]` tuples unchanged; the
  provenance-stripped route-content md5 measures equal old-vs-new, only
  provenance bytes move. Gate pins in `ServerRouteManifestGateTest.php`
  (`EXPECTED_MD5`, `EXPECTED_SERVER_SHA`, docblock + failure-message cites)
  advance in the same commit; counts untouched. Test-only change — the tracked
  `build/phlix.phar` is NOT rebuilt and its content md5 holds. Untagged wave:
  the dependency tag pin stays put.

### Changed — W50 (cs30 era-2): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-09

- Server moved mid-wave (`32183f5b` → `5986b61d`, S210 #749 — docker boot-gate bounds only,
  route-zero re-proven: Router/Application/guard blobs and Routes/+FastPath/ trees byte-identical).
  Vendored `server-route-manifest.json` re-vendored byte-identical from `@phlix/contracts` master
  `57a8528a` (era-2 regen; full-file md5 `cb53d53f` → `045c0984`, blob identity `dd0cbaca` verified
  against the contracts dist artifact; stripped route-content md5 `508a…` holds — 401 tuples).
  Gate pins advance in the same commit; counts unchanged.

### Changed — W49 (cs30): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-09

- **cs#30 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `767146a8` (untagged
  regen #17 against server master `32183f5b`; previous provenance
  `8697c099`/`e15d9543` — the cs#29 leg). The span `e15d9543` → `32183f5b` is
  two merges (S266 #747 deterministic Timer-probe outcomes, S171 #748 resolve
  the unserved `public/index.php` front controller), re-proven route-zero at
  the contracts leg: route-authority blobs/trees identical,
  `WebPortalRouter.php` comment-only, the deleted front controller carried zero
  route-wiring hits. The tuple set is byte-identical (stripped route-content
  md5 `508a6415` old = new, 401 both sides) — only provenance moves.
- `tests/Unit/Api/ServerRouteManifestGateTest.php` follows the currency pins:
  `EXPECTED_MD5` `27834ef4` → `cb53d53f…`, `EXPECTED_SERVER_SHA` → `32183f5b…`,
  the docblock server cite and the drift message contracts cite `8697c099` →
  `767146a8`; the three count assertions stay 401. `build/phlix.phar` is
  UNTOUCHED — md5 `9d57bfbc…` verified identical (bytes, `md5sum`) both sides.

### Changed — W48 (cs29): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-09

- **cs#29 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `8697c099` (untagged
  regen #16 against server master `e15d9543`; previous provenance
  `a1ca39d8`/`a5cde27e` — the cs#28 leg). The span `a5cde27e` → `e15d9543` is
  two merges (S211 #745 absolute config-dir resolution, S114 #746 stats_storage
  unique key), re-proven route-zero at the contracts leg: route-authority
  blobs/trees identical, zero route-wiring hunks in the moved `Application.php`.
  The tuple set is byte-identical (stripped route-content md5 `508a6415` old =
  new, 401 both sides) — only provenance moves.
- `tests/Unit/Api/ServerRouteManifestGateTest.php` follows the currency pins:
  `EXPECTED_MD5` `0331a2d8` → `27834ef4…`, `EXPECTED_SERVER_SHA` → `e15d9543…`,
  the docblock server cite and the drift message contracts cite `a1ca39d8` →
  `8697c099`; the three count assertions stay 401. `build/phlix.phar` is
  UNTOUCHED — md5 `9d57bfbc…` verified identical before and after this leg.

### Changed — W47 (cs28): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-09

- **cs#28 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `a1ca39d8` (regen
  against server master `a5cde27e`; previous provenance `28000fa4`/`afe54c7c` —
  the cs#27 leg). S187 whole-tree unused-import reflow is route-zero: the tuple
  set is byte-identical (stripped route-content md5 `508a6415` old = new,
  401 both sides) — only provenance moves.
- `tests/Unit/Api/ServerRouteManifestGateTest.php` follows the currency pins:
  `EXPECTED_MD5` `5c06306c` → `0331a2d8…`, `EXPECTED_SERVER_SHA` → `a5cde27e…`,
  the docblock server cite and the drift message contracts cite `28000fa4` →
  `a1ca39d8`; the three count assertions stay 401. `build/phlix.phar` is
  UNTOUCHED — md5 `9d57bfbc…` verified identical before and after this leg.
  Suite 2782/10008-era exact on this box.

### Changed — W46 (cs27): route-manifest provenance re-pin — PURE, 401 tuples unchanged — 2026-09-09

- **cs#27 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `28000fa4` (regen
  against server master `afe54c7c`; previous provenance `97c87f27`/`1e14b539` —
  the cs#26 leg). Seven server merges since `1e14b539`, all route-zero: the
  tuple set is byte-identical (stripped route-set md5 `508a6415` old = new,
  401 both sides) — only provenance moves.
- `tests/Unit/Api/ServerRouteManifestGateTest.php` follows the currency pins:
  `EXPECTED_MD5` `e3647899` → `5c06306c`, `EXPECTED_SERVER_SHA` → `afe54c7c…`,
  the docblock server cite and the drift message contracts cite `97c87f27` →
  `28000fa4`; the three count assertions stay 401. `build/phlix.phar` is
  UNTOUCHED — md5 `9d57bfbc…` verified identical before and after this leg.
  Suite 2782/10008-era exact on this box.

### Changed — W43 (cs26): route-manifest currency re-pin (REAL route add, 401 tuples) — 2026-09-08

- **cs#26 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `97c87f27` (regen
  against server master `1e14b539`; previous provenance `e837e31c`/`2746677e`).
  Not a pure provenance move this time: the server span added one real rail —
  `POST /api/v1/admin/updates/check` — so the tuple set goes 400 → **401**.
  That additive tuple is the only route-set delta; every pre-existing tuple is
  byte-identical modulo the provenance block.
- `tests/Unit/Api/ServerRouteManifestGateTest.php` follows the currency pins:
  `EXPECTED_MD5` `4f4dc687` → `e3647899`, `EXPECTED_SERVER_SHA` → `1e14b539…`,
  the docblock server cite, the three manifest-integrity count assertions
  (`provenance.total`, `count(routes)` and the unique-tuple count, each
  400 → 401) and the `contracts@e837e31c` failure cite → `contracts@97c87f27`.
- GATE_ID and every coverage pin (per-class anchors, sweeps, expansions, cast,
  hub negative partition, ws) untouched — the new rail is a server route the
  console does not call, so the client-side scan is byte-stable and an additive
  tuple cannot un-serve an existing site.
- The built `.phar` was NOT rebuilt (md5 `9d57bfbc…` unchanged — fixture is an
  unbundled test asset). CI-faithful run: phpunit 2782 tests / 10008 assertions
  (S448 era), both phpstan legs `[OK]`, phpcs adds no new findings (advisory in
  CI), `composer validate --strict` valid.

### Fixed — W43 (S448): phpstan-tests.neon gate made real — 2026-09-08

- **The gate now exists.** `phpstan-tests.neon` had been committed but was
  invoked by no CI job, and its own header claimed 8 (then 9) remaining
  findings. S448 fixed all nine at source and wired the leg into both
  workflows: a `Run PHPStan (tests)` step (`vendor/bin/phpstan analyse -c
  phpstan-tests.neon --no-progress`) now runs in the `phpstan` job of
  `ci.yml` and the `update` job of `deps.yml`, mirroring the server/hub
  two-leg pattern.
- **property.notFound ×6** — `LoginScreenTest` (`$cols`/`$rows`),
  `PlayerScreenTest` (`$item` ×2, `$ended`), `SettingsScreenTest`
  (`$error`): the old stub relied on `@property` tags on the
  `SugarCraft\Core\Model`/`Msg` stubs, but those are interfaces and
  PHPStan 2.2 ignores `@property` there — dead tags, deleted from
  `phpstan.stubs.php` (rationale left as comments). Fixes narrow types at
  the test site instead: `self::assertInstanceOf(...)` on the concrete
  screen and a `@template T of Msg` signature on
  `PlayerScreenTest::firstOfType()`.
- **phpDoc.parseError** — `MusicStoreTest`: malformed
  `list<array>` annotation → `array<int,array<string,mixed>>`.
- **parameter.phpDocType ×2** — `SidebarTest` (`list<string>` on a
  variadic → `string ...$names`) and `tests/Unit/Api/GateScanner.php`
  (malformed `array{...}|string` return docblock rewritten with keyed
  shape members).
- No `ignoreErrors` entries, no baseline: the leg is green at zero.
- `tests/Unit/Support/PhpstanTestsLegWiredTest.php` pins the wiring —
  delete either CI step and it goes RED (mutation-proven on both files).
  CI-faithful run: phpunit 2782 tests / 10008 assertions (delta +1 test
  file), both phpstan legs `[OK] No errors`, phpcs house gate clean,
  `composer validate --strict` valid, built `.phar` untouched (md5
  `9d57bfbc…`).

### Changed — W37 (cs25): route-manifest provenance re-pin (no route change) — 2026-09-08

- **cs#25 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `e837e31c` (regen
  against server master `2746677e`; previous provenance `59fd9b02`/`df6aa8e5`).
  All 400 tuples byte-identical — only provenance moves.
  `tests/Unit/Api/ServerRouteManifestGateTest.php` follows: `EXPECTED_MD5`
  `b6acafdf` → `4f4dc687`, `EXPECTED_SERVER_SHA` → `2746677e…`, the docblock
  server cite and the `contracts@59fd9b02` failure cite → `contracts@e837e31c`.
  GATE_ID, the 400-count pins and every coverage pin untouched; the built
  `.phar` was NOT rebuilt (md5 `9d57bfbc…` unchanged — fixture is an unbundled
  test asset). CI-faithful run: phpunit 2779 tests / 9997 assertions,
  phpstan clean under a fresh ini-scan dir.

### Changed — W36 (cs24): route-manifest provenance re-pin (no route change) — 2026-09-07

- **cs#24 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `59fd9b02` (regen
  against server master `df6aa8e5`; previous provenance `bcd27df`/`bab33ff2`).
  All 400 tuples byte-identical — only provenance moves.
  `tests/Unit/Api/ServerRouteManifestGateTest.php` follows: `EXPECTED_MD5`
  `e8b23b9b` → `b6acafdf`, `EXPECTED_SERVER_SHA` → `df6aa8e5…`, the docblock
  server cite and the `contracts@bcd27df` failure cite → `contracts@59fd9b02`.
  GATE_ID, the 400-count pins and every coverage pin untouched; the built
  `.phar` was NOT rebuilt (md5 `9d57bfbc…` unchanged — fixture is an unbundled
  test asset). CI-faithful run: phpunit 2779 tests / 9997 assertions,
  phpstan clean under a fresh ini-scan dir.

### Changed — W35 (cs23): route-manifest provenance re-pin (no route change) — 2026-09-06

- **cs#23 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `bcd27df` (regen
  against server master `bab33ff2`; previous provenance `876d0ea`/`e4853f0f`).
  All 400 tuples byte-identical — only provenance moves.
  `tests/Unit/Api/ServerRouteManifestGateTest.php` follows: `EXPECTED_MD5`
  `791235d4` → `e8b23b9b`, `EXPECTED_SERVER_SHA` → `bab33ff2…`, the docblock
  server cite and the `contracts@876d0ea` failure cite → `contracts@bcd27df`.
  GATE_ID, the 400-count pins and every coverage pin untouched; the built
  `.phar` was NOT rebuilt (md5 `9d57bfbc…` unchanged — fixture is an unbundled
  test asset). CI-faithful run: phpunit 2779 tests / 9997 assertions,
  phpstan clean under a fresh ini-scan dir.

### Changed — W35 (cs22): route-manifest provenance re-pin (no route change) — 2026-09-06

- **cs#22 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `876d0ea` (regen
  against server master `e4853f0f`; previous provenance `341fc6e2`/`e729d48a`).
  All 400 tuples byte-identical — only provenance moves.
  `tests/Unit/Api/ServerRouteManifestGateTest.php` follows: `EXPECTED_MD5`
  `7accd31d` → `791235d4`, `EXPECTED_SERVER_SHA` → `e4853f0f…`, the docblock
  server cite and the `contracts@341fc6e2` failure cite → `contracts@876d0ea`.
  GATE_ID, the 400-count pins and every coverage pin untouched; the built
  `.phar` was NOT rebuilt (md5 `9d57bfbc…` unchanged — fixture is an unbundled
  test asset). CI-faithful run: phpunit 2779 tests / 9997 assertions,
  phpstan clean under a fresh ini-scan dir.

### Changed — W35 (cs21): route-manifest provenance re-pin (no route change) — 2026-09-06

- **cs#21 currency leg.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `341fc6e2` (regen
  against server master `e729d48a`; previous provenance `f2e284b3`/`f35a5742`).
  All 400 tuples byte-identical — only provenance moves.
  `tests/Unit/Api/ServerRouteManifestGateTest.php` follows: `EXPECTED_MD5`
  `05db9e75` → `7accd31d`, `EXPECTED_SERVER_SHA` → `e729d48a…`, the docblock
  server cite and the `contracts@f2e284b3` failure cite → `contracts@341fc6e2`.
  GATE_ID, the 400-count pins and every coverage pin untouched; the built
  `.phar` was NOT rebuilt (md5 `9d57bfbc…` unchanged — fixture is an unbundled
  test asset). CI-faithful run: phpunit 2779 tests / 9997 assertions,
  phpstan clean under a fresh ini-scan dir.

### Changed — W34 (cs20retag): route-manifest provenance re-pin (no route change) — 2026-09-05

- **cs#20 currency leg of the combined re-tag wave.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `f2e284b3` (regen against server master
  `f35a5742` — the web-ui `@phlix/ui` v0.99.1 re-pin commit, zero PHP, zero route hunks; previous
  provenance `2250def2`/`3a253991`). All 400 tuples byte-identical — only provenance moves.
  `tests/Unit/Api/ServerRouteManifestGateTest.php` follows: `EXPECTED_MD5` `5bc7dd6d` →
  `05db9e75`, `EXPECTED_SERVER_SHA` → `f35a5742…`, the docblock server cite and the
  `contracts@2250def2` failure cite → `contracts@f2e284b3`. GATE_ID, the 400-count pins and
  every coverage pin untouched; the built `.phar` was NOT rebuilt (md5 `9d57bfbc…` unchanged —
  fixture is an unbundled test asset). CI-faithful run: phpunit 2779 tests / 9997 assertions,
  phpstan clean under a fresh ini-scan dir + tmpdir.

### Changed — W33 (cs19): route-manifest provenance re-pin (no route change) — 2026-09-05

- **cs#19 currency cascade.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `2250def2` (regen
  against server master `3a253991`; previous provenance `e74cdc88` — S431
  executable census, one commit, no route hunks). All 400 tuples byte-identical
  — only provenance moves. `tests/Unit/Api/ServerRouteManifestGateTest.php`
  follows: `EXPECTED_MD5` `9f69628d` → `5bc7dd6d`, `EXPECTED_SERVER_SHA` →
  `3a253991…`, the docblock server cite and the `contracts@51ed6cd3` failure
  cite → `contracts@2250def2`. GATE_ID, the 400-count pins and every coverage
  pin untouched; the built `.phar` was NOT rebuilt (untagged wave, no grants).

### Changed — W31 (cs18): route-manifest provenance re-pin (no route change) — 2026-09-05

- **cs#18 currency cascade.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `51ed6cd3` (regen
  against server master `e74cdc88`; previous provenance `4b620f59`). All 400
  tuples byte-identical — only provenance moves. The gate pins follow in
  `tests/Unit/Api/ServerRouteManifestGateTest.php`: `EXPECTED_SERVER_SHA`
  `4b620f59` → `e74cdc88`, `EXPECTED_MD5` `81eeef82` → `9f69628d`, and the
  fail-message contracts cite `55311c6` → `51ed6cd3`. No coverage or
  reconstruction pin moved; the phar is untouched (zero src/ changes).

### Changed — W29 (cs17): route-manifest provenance re-pin (no route change) — 2026-09-04

- **cs#17 currency cascade.** `tests/fixtures/server-route-manifest.json`
  re-vendored verbatim from `@phlix/contracts` master `55311c68` (regen
  against server master `4b620f59`; previous provenance `888a42b2`). All 400
  tuples byte-identical — only provenance moves. The gate pins follow in
  `tests/Unit/Api/ServerRouteManifestGateTest.php`: `EXPECTED_SERVER_SHA`
  `888a42b2` → `4b620f59`, `EXPECTED_MD5` `9727f2d3` → `81eeef82`, and the
  drift message now cites contracts `55311c68`. No site-count or anchor pin
  moved.

### Added

- **docs**: Add `docs/clients/console.md` user-facing page covering installation, configuration, key bindings, and common usage scenarios (C10.1)
- **docs**: Add `docs/dev/client-console.md` architecture page covering component layout, screen hierarchy, and data-flow patterns for developers (C10.2)
- **cli**: Complete `bin/phlix help` output with all commands, flags, and usage examples; `bin/phlix doctor` now checks PHP version, required extensions, server connectivity, and config validity (C10.4)
- **docs**: Reconstructed server contracts for books, audiobooks, and photos endpoints in `docs/dev/client-console.md`, clarifying request/response shapes and pagination behavior (C10.6)
- **docs**: Cross-repo documentation sweep across both `phlix` and `phlix-console-client` clarifying setup instructions, architecture docs, and user guides (C10.7)
- **cli**: Add `phlix watch`, a long-running consumer of the hub's SyncPlay relay `pending_command` surface. Connects to `ws(s)://<hub>:8804/syncplay/{server_id}` with the relay token in the `Authorization: Bearer` upgrade header (S237 — never the query string), minted via `POST /api/v1/me/servers/{id}/relay-token` or `--relay-token` and re-read per (re)connect. A capped exponential-backoff ladder keeps the socket open; each `pending_command`/`play_media` frame goes to a named, tested dispatch point that prints the play request. `--once` waits for one frame (or `--timeout`) then exits. New `HubRelayConsumer` + `PendingPlayMediaCommand` DTO; 16 + 6 tests; phar rebuilt. (S298)
- **tests**: Add a phpunit route gate that vendors the canonical server route manifest (400 tuples) and asserts every URL the console can put on the server wire is tuple-exact against it: per-class site-count pins, suffix helpers expanded from their call sites, the cast surface enumerated from the production backend enum, per-path hub negatives (the hub client rides the shared transport), the SyncPlay WebSocket pinned outside the HTTP manifest, and a per-file literal-blindness sweep — planted probes must and do fail it. The client's distributable phar was rebuilt from the production vendor tree, mirroring the CI build workflow. (S405)
- **tests**: Add six `AdminClientTest` wire-shape cases pinning the restored restart/filesystem rails to the real envelopes, and one `AdminFilesystemScreenTest` regression pinning that the roots-view `/` sentinel never reaches the wire — the deleted trio had zero coverage pre-S405. The route gate was re-pinned by measurement: `AdminClient` anchors 116→118, total compared 221→223, `AdminClient` sweep tokens 29→30, and a planted-red regression proof kept the gate sharp. The distributable phar was rebuilt from the production vendor tree (26741849→26823712 bytes) with CI smoke lines green; full suite 2758 tests green, PHPStan level 9 clean. (S406)
- **tests**: S404 wire-shape coverage — a new `StreamSubtitleTrackTest` hydrates the DTO from the golden playback-info subtitle rows captured from the REAL `StreamTrackShaper` at server `01340633` (same vectors `@phlix/contracts` pins), with an explicit never-emitted-keys guard (`title`/`is_forced`/`is_default` on a row must not change the parse), and a `PlaybackTrackWireShapeTest` feeds the golden `audio_tracks`/`subtitle_tracks` rows through the real `ApiClient` decode via `FakeTransport`. Suite 2758→2768 tests (9899→9947 assertions). (S404)

### Fixed

- **syncplay**: the SyncPlay response DTOs unwrapped an envelope the server
  never sends (`room_id`/`session_id`/`server_url` top-level keys exist only
  in the dead `WebSocket/SyncPlay` classes — re-measured live at server
  `01340633`), so `roomId` was always the empty string, every WS join/leave
  frame the service built carried `group_id => ''`, and password-protected
  groups listed as public. `SyncPlaySession` now unwraps the real
  `{success, group:{…}}` rail output to `group.group_id` (fails closed — the
  phantom keys must not steer it), keeps `serverUrl` as the DERIVED
  configured API base (the wire has no such field and
  `SyncPlayService::buildWebSocketUrl` consumes it verbatim — there was no
  fallback), and drops the never-consumed `sessionId`. `SyncPlayGroup`'s
  public/private view is now the inverted `has_password` of the real LIST ROW
  (tested both directions; absent ⇒ public, documented honest default; the
  never-emitted `is_public` key no longer steers it). New connection-factory
  seam on `SyncPlayService` (production path constructs the identical
  Workerman object) lets the S414 gate drive REST→DTO→onConnect→FRAME-BUILDER
  over the REAL captured envelope bytes (provenance: phlix-contracts
  `syncplay-envelope-vectors.json`, server `01340633`) — the join/leave frame
  payload and the absolute WS URL are asserted from production code, plus a
  planted-broken red on the phantom-key read. (S414)
- **player**: the subtitle picker could never open — the `PlayerScreen`
  property was declared and never assigned because `PlaybackInfo` dropped the
  server's `subtitle_tracks` rows at the boundary (audio survived). The DTO
  now parses them into the S404-honest `StreamSubtitleTrack` shape; the
  playback-info message carries both track lists (the second field defaults
  to keep every existing construction site valid) and the screen feeds
  msg→property→menu. New tests drive a fake-transport playback payload whose
  `subtitle_tracks` rows match `StreamTrackShaper` output: the menu OPENS,
  lists wire labels, the pick pins the row + flips captions (the observable,
  audio-precedent state effect; stream-level subtitle OUTPUT is named as the
  tested refusal — no player surface for it, mirroring how audio selection is
  state-only), and an empty wire list keeps the menu closed (empty-set
  defence). Planted-broken red at the boundary, then green. Suite
  2768→2779 tests (9947→9997 assertions); the tracked phar rebuilt from the
  production vendor tree (26824725→26829132 bytes): `--version` OK, `bogus`
  exits 2, stub-server selftest 4/4 OK; PHPStan level 9 clean; PSR-12 delta
  zero. (S413)


- **docs**: Fix README.md to reflect current PHP version requirement (8.3+), production-ready status, accurate key bindings list, and coherent install story (C10.3)
- **docs**: Fix stale docblocks throughout `src/` and `tests/` and triage deferral comments with either concrete issue references or completed markers (C10.5)
- **admin**: Parental-controls mirrors reconciled to `@phlix/contracts` v0.4.4 (S234 canonicalization). `profile_id` is a CHAR(36) UUID **string**; the mirrors typed it `int`, so `Coerce::int()` collapsed every real profile id to `0` and each schedules/tags/stream-limits call against a real profile 404'd. Eight `AdminClient` parental methods, the `ParentalControlsScreen` call sites (9 `(int)` casts removed) and the `AccessSchedule`/`ProfileTag` DTOs now carry `string $profileId` via `Coerce::str`; `addProfileTag()` posts the canonical `tag_type` body key (was `type`). Stale mirror citations corrected to v0.4.4. `StreamAudioTrack`/`StreamSubtitleTrack` docblocks now record a known divergence — the contracts `AudioTrack`/`SubtitleTrack` shape (`display_title`, `url?`) disagrees with the server `StreamTrackShaper` emission (`title`, `bitrate`); filed as S404, deliberately NOT mirror-fixed. Tests pinned to UUID-shaped ids so an int collapse can never pass silently again. Phar rebuilt. (S325b)
- **api**: 12 request rails the console called but phlix-server never registered were reconciled against the canonical server route manifest (S280 estate closure). Four were repointed 1:1 to their registered equivalents: the personal-rating delete to the singular `/rating`, user settings GET/PUT to `/users/me/settings`, and duplicate detection to the admin-prefixed libraries path via a new client-side constant (the shared libraries constant is untouched). (S405)
- **admin**: Eight rails had no registered equivalent and were removed along with their dead feature paths: the user quota display/edit on the admin Users screen, the webhooks enable/disable toggle (a PUT against a never-registered rail — always failed), and the Server Restart, Filesystem, Services, and Metadata Match admin screens (routes, menu entries and orphaned messages/DTOs deleted). One of the eight was a phantom the earlier audit had missed, found while classifying — an unparameterized media match-review listing that silently collided with the media-detail route (the server answered "404 media not found"). Methods for served rails that merely lost a UI caller (social-account disconnect, metadata match, poster selection) were retained. The restart/status/filesystem aggregate trio was left removed client-side at merge time; that ruling was later reopened — the server was found to register lookalike rails one segment off the trio (S405 reviewer finding) and the owner ruled repoint-and-restore for the restart and filesystem arms (S406), while the status arm stays removed forever: `/api/v1/admin/server/status` was never registered anywhere server-side, so the old poll could only ever time out. (S405)
- **admin**: The Restart and Filesystem screens return, repointed to the verified served rails `POST /api/v1/admin/restart` and `GET /api/v1/admin/fs/browse` (exact tuples in the vendored canonical route manifest). The owner ruling reopened from S405 reviewer D1 after the server was found to register lookalike rails one segment off the removed trio, and restored these two arms. The filesystem parse was rewritten against the real handler: the response is enveloped `{success,data:{path,parent,entries}}` (`ApiClient::decode` unwraps it) and `entries` are directories-only name+path rows, sorted and jailed to the server's configured browse roots — the old top-level `entries[type,size,modified]` parse described a response the server never sent. Rows map client-side to the screen's dir-only shape, and the screen's roots-view `/` sentinel never rides the wire: the jail 403s a literal `/` unless it is a configured root, so re-fetching roots uses the server's documented empty-path roots response. Poll-after-restart is dropped with cause on record — the rail it polled never existed; the server ACKS before the deferred SIGUSR2 worker cycle and the master never dies (`AdminRestartController.php:38-41`) — so the screen now confirms via the ack: no status panel, no poll. The other arms of the old removed quintet (status, services aggregate, quota, webhook PUT, media-match) stay removed. (S406)
- **api**: `StreamSubtitleTrack` retitled to the wire truth per the S404 authority ruling (`StreamTrackShaper::subtitleTracks()` at server `01340633` IS the contract): it parsed `title`, `is_forced` and `is_default` — keys the subtitle wire NEVER emits (its docblock claimed the opposite) — and none of its consumers could ever have received them. It now parses the emitted `{label, source, hearing_impaired}` trio (`displayLabel()` reads the server-derived `label`; the `[forced]` suffix, backed by a never-emitted flag, is gone), and the docblock states the full wire key set plus the deliberately-unmodelled `index`/`stream_index`/`url`. `StreamAudioTrack` keeps its honest subset (6 ⊂ 9 wire fields); its stale S325b divergence note is replaced with the resolution (contracts `0.4.5` moved to the shaper shape). The S325b note's warning held: the fix was made against the shaper, never against the old TS fiction. Consumer sweep: the only readers of the removed fields were the DTO's own `fromArray`/`displayLabel` — `PlayerScreen`'s `$subtitleTracks` property is never assigned (dead subtitle picker; feeding it is functional wiring, filed S407) and `Ui\SubtitleTrackList` only calls `displayLabel()`. The tracked phar was rebuilt from the production vendor tree (26823712→26824725 bytes): `--version` OK, `bogus` exits 2, stub-server selftest 4/4 OK. PHPStan level 9 clean. (S404)

## [1.0.0] - 2026-08-08

### Added

- **auth**: Add user sign-up (C9.8)
- **settings**: Sync user settings with the server (C9.7)
- **download**: Allow downloading video files (C9.6)
- **discovery**: Add playlists and collections (C9.5)
- **discovery**: Add external subtitle search (C9.4)
- **discovery**: Add missing episodes row to detail screen (C9.3)
- **discovery**: Add dismiss recommendation (C9.2)
- **discovery**: Add shuffle play (C9.2)
- **discovery**: Add most-watched rail (C9.1)
- **discovery**: Add a More Like This row on the detail screen (C9.1)
- **cli**: Add `--version` / `-V` / `version` command printing `phlix {version}`. Unknown commands now exit `2` with error to STDERR instead of printing help. Split `help` and `default` case branches. Help text refactored to shared `HELP_TEXT` constant. (C0.5)
- **cli**: Add `run --selftest` mode that boots the full object graph and performs config-loaded, graph-wired (including `PlayerScreen`'s `SyncPlayService` null check), http-reachable, and decode-ok checks. Exits 0 on all pass, 1 otherwise. Honours `PHLIX_SERVER_URL`. (C0.6)
- **i18n**: Add internationalization scaffolding using SugarCraft's `T` translation system. Three screens are converted: `RecommendationsScreen`, `DetailScreen`, and `FilterBar`. Locale is auto-detected from `$LANG` / `$LC_ALL` / `$LC_MESSAGES` at boot. See `docs/i18n.md` for conversion guide. **Coverage is partial** — the remaining ~40 screens still need conversion. (C8.10)
- **player**: Add `completeSession()` to report playback completion. Sends `POST /api/v1/sessions/{id}/complete` when playback reaches 95% or natural end-of-stream. Guard prevents double-fire. Rejection raises `ShowToastMsg`. (C1.3)
- **syncplay**: `PlayerScreen` now constructs `SyncPlayService` (required, not optional). All 7 callbacks registered: `onPlaybackCommand`, `onMemberJoined`, `onMemberLeft`, `onHostChanged`, `onGroupState`, `onDisconnect`, `onError`. New `SyncPlayHostChangedMsg`, `SyncPlayDisconnectedMsg`, `SyncPlayGroupStateMsg` messages. (C1.2)
- **syncplay**: All four SyncPlay HTTP calls now use `/syncplay/groups` (not `/rooms`), leave uses `POST` (not `DELETE`), and response parsing reads `groups` key. DTOs renamed `SyncPlayRoom` → `SyncPlayGroup`. (C1.1)
- **ci**: Add PHP version matrix (8.3, 8.4, 8.5) with `fail-fast: false` (C9.2)
- **ci**: Add PHPCS PSR-12 gate for `src/` and `tests/` with `composer cs` and `composer cs:fix` scripts (C9.3)
- **ci**: Add `phpunit.xml.dist` coverage floor at 66% global, 70% per-file for `PlayerScreen` (C9.1)
- **build**: Add dual publish paths: master → rolling `latest` prerelease, tag → real release. Generate `SHA256SUMS` for both. Smoke-test before publish. Tag-vs-version consistency check. (C9.7)
- **tests**: Add PHPStan level 9 analysis for `tests/` directory (C9.4)
- **tests**: Add graphics decoder tests (`SixelDecoder`, `Iterm2Decoder`, `KittyDecoder`) and `PosterRenderTest` (C9.11)
- **Dependency drift is now detected automatically.** A scheduled workflow runs every
  Monday at 06:00 UTC (and can be triggered manually), pulling the latest `dev-master`
  resolves for all SugarCraft packages, running PHPStan and PHPUnit, and — only
  when both pass — committing the updated `composer.lock` directly to `master`.

### Changed

- **ci**: Add PHP CodeSniffer and configure PSR-12 (C9.9)
- **ci**: Remove Codacy coverage upload (C9.5)

### Fixed

- **player**: `PlayerScreen::fetchSiblings()` now pages through episodes in 100-item chunks (server maximum) when locating the current episode for next/previous navigation. Previously it requested up to 500 items which exceeded the server clamp and silently failed for series beyond the first page. Shows a toast error if the episode cannot be found after exhausting all pages (up to 10,000 episodes). (C1.5)
- **api**: `{success:false}` responses now throw `ApiError` instead of silently returning an empty DTO. The `decode()` method now centralises envelope handling for all 2xx responses, unwrapping `{success:true,data:...}` responses and surfacing `{success:false}` with the `message` or `error` field as the exception message. (C1.4)
- **discovery**: Remove dead null checks in `DetailScreen::onLoaded` (C9.1)
- **discovery**: Restore branching return contract for hero/ratings/similar batch (C9.1)
- **config**: `TokenStore::exists()` checks cache not file presence (C9.7)
- **config**: Store migration data as array so `load()` can read it back (C9.7)
- **tests**: Update `AppTest` for multi-server Config API and `TokenStore` changes (C9.7)
- **tests**: Update `ConfigTest` for multi-server API after C7.1 refactor (C9.7)
- **api**: Restructure VTT promise chain in `fetchCaptionsCmd()` from `.then(success, error)` to `.then(success)->catch(error)` to satisfy PHPStan level 9 type inference. When VTT fetch fails, shows `ShowToastMsg` with error. (C0.4)
- **A stalled server can no longer freeze the TUI on a shimmer skeleton for 60 seconds.** `BrowserTransport` now enforces a 15-second request timeout (configurable via the constructor) instead of relying on PHP's `default_socket_timeout` (~60 s). GET and HEAD requests that fail with a genuine connection or timeout error are retried exactly once after a 250 ms delay, while `POST`/`PUT`/`PATCH`/`DELETE` requests are not retried because they are not idempotent.
- **Fixed ensureRange() calculating lastOffset incorrectly causing multi-page windows to under-fetch data** (MediaStore and BooksStore). The lastOffset calculation used `$windowEnd = max($start, $end - 1)` but then computed `lastOffset = intdiv($windowEnd, $limit) * $limit` — when $end was the exact boundary of a page, this truncated the final page.
- **Search results now show poster images** (relative URLs resolved via baseUrl + resolveUrl())
- **Detail page hero images and child-episode thumbnails now render** (relative poster URLs resolved via `baseUrl` + `resolveUrl()`)
- **Photo library thumbnails now render** (`PhotoAlbumScreen` album covers and `PhotosScreen` per-album thumbnails). Both now apply `baseUrl` + `resolveUrl()` **before** the scheme check.
- **Library rails (Anime/TV) now show posters instead of stills** (`topLevel=1` in API request via `forLibrary()`).
- **Test coverage for the poster-URL fixes is now genuine.** `resolveUrl()` relative→absolute behavior is exercised against a real local HTTP server.
- **Admin forms keep an invalid submit open with an inline error instead of a toast.** candy-forms now gates submit on each field's validator.
- **Removed the dead `PlaybackInfo::$qualityLadder` field.** The quality picker is driven entirely by a real transcode job's `variants[]`. Corrected the `Rendition`/`PlaybackInfo`/`PlayerScreen` docblocks' endpoint attribution.

### Added — in-player quality selection

- **Quality picker overlay** in the player — press `v` to open a menu of **Auto** plus each ABR rung the active transcode advertises. ↑/↓ navigate, Enter pins the rung, Esc/`q` dismisses. The `v` key only appears when the item is actually being transcoded with a real ABR ladder.

### Added — Phase 8 (admin parity + Cast)

- **Admin menu**, exposed via the command-palette **Admin** action only when signed in as an admin. Every section is wired: Dashboard, Users, Plugins, Logs, Backup, Server Settings, Libraries, DLNA server, Remote Access, Live TV.
- **Cast** — `C` on a media detail screen discovers Chromecast / Roku / AirPlay / DLNA devices, sends the item, and drives a transport overlay (pause / resume / stop).

### Added — Phase 7 (theming + settings + polish)

- Three built-in themes — **Nocturne** (default), **Daylight**, **Midnight**.
- A **Settings** screen (palette-reachable) for theme and photo-slideshow interval, applied live.
- A persistent cross-screen **Now-Playing bar** so music and audiobook audio survives navigation.
- A palette-toggled diagnostic **metrics HUD** overlay and a read-only **Stats** screen.
- Animated **shimmer loading skeletons** for lists.
