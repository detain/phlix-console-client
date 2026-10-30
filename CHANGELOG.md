# Changelog

All notable changes to **phlix-console-client** are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed — minors batch W-min14 (M-CONSOLE-03 · 37 · 45 · 46 · 48 · 53 · 69 · 92): the README speaks human instead of campaign — no phase jargon, the install answer moves up, the Live TV bullet reports what shipped — and token/config temp files are born 0600, initials and cast labels go grapheme-safe, the manual documents the recommendations and filter-bar keymaps, the graphics doc spells colour — 2026-10-30

- **The defects.** (console__a11y · console__enduser · console__newcomer ×3 · console__secaudit · console__tuispec · console__uxdesign · console__writer.) The README opened the reader's journey with "**Status: Phases 0–10 complete**" — internal campaign vocabulary never defined for readers — then parked a 114-line status wall (render-mode tables included) *before* Requirements/Install, told the reader Live TV "create / edit are deferred to the web admin" while `AdminLiveTvScreen` ships full in-terminal editing (tuner scan/toggle/rename/delete, channel toggle/rename, `R` record program and `S` record series from the guide, rule edit forms), and the manual skipped the Recommendations keymap the code actually answers (`j`/`k` navigation, `X`/`Q` aliases) and the filter-bar editing keys its own hint advertises (`Tab`/shift-`Tab` cycle fields, `←`/`→` change the focused value, `c` clears, `Esc` closes with filters applied). `TokenStore::persist()` and `Config::save()` created their temp secret with `file_put_contents` — born under the caller's umask, commonly 0644 world-readable, *then* chmod'd — while the class docblock promised it was "never momentarily world-readable". `DetailScreen::initials()` built cast labels with byte `substr`/`strtoupper`: Cyrillic and CJK names surfaced mojibake. And `docs/terminal-graphics.md` mixed five American `color` prose spellings into the otherwise British family the rest of the docs ratified. (The badge half of M-CONSOLE-48 verified stale: the README badge already resolves `v/release?include_prereleases`, and the rolling `latest` prerelease now exists on origin.)
- **The cures.** README: the phase jargon is gone ("every screen and subsystem below is shipped", the five `Phase N` connectors became plain prose — `grep -i phase README.md` is now empty); the `## Render modes` table and status wall moved *after* Run it so pitch → Documentation → Requirements → Install → Run it answers "can I run this?" in one scroll; the two cross-pointers flipped to stay honest ("under **Run it** above", "see *Status* below"); the intro gained the full command-palette label census (Search through Quit plus the per-library `Go to <library>` jump) the shipped registry actually offers; the Live TV bullet now lists what the terminal does. Both persist paths pre-create the temp file with `fopen(..., 'x')` under a `umask(0o077)` guard — born 0600 — and restore the caller's umask before writing anything. `initials()` moved to `mb_substr`/`mb_strtoupper` (the house multibyte idiom used by `Truncate`, `FilterBar`, `SyncPlayModal`), preserving its shipped last-then-first initial order; `docs/terminal-graphics.md` prose became colour/Colours/coloured (code identifiers like `TrueColor` untouched; the `docs/dev/client-console.md` site verified stale — no prose `color` remains there). Catalog hint strings were deliberately NOT restyled (M-CONSOLE-11 owns hint style, landed in W-min13) — the keymaps are documented, the hint literals stay.
- **The pins.** NEW `tests/Screen/DetailScreenInitialsTest.php` (8 tests via `ReflectionMethod`): ASCII rows pin the preserved order (`Luke Robinson` → `RL`) while the Cyrillic (`Анна Иванова` → `ИА`) and CJK (`佐藤 花子` → `花佐`) rows carry true teeth — the old byte code produced mojibake there (one-shot probe-proven, then reverted). `tests/Config/ConfigTest` and `TokenStoreTest` each gain `testSaveUnderAnOpenUmaskStillLandsOwnerOnlyAndRestoresTheCaller` — save under `umask(0o000)` lands mode `0600` and leaves the caller's umask untouched; these are contract pins, not mutation teeth (the old chmod-after path reached the same end state — the cured window is creation-instant and not externally observable; disclosed). The README restructure is held green by `ReadmeFeatureIntroDocsTest` (its palette-census law reddened on the moved wall — measured red, then green with the intro enumeration), `PlayerKeyDocsTest`, `ReadmeTestFixturesDocsTest`, and `TerminalGraphicsDocsTest`. Suite: 3319→3329 tests / 20084→20096 assertions (exactly +10/+12: two umask pins + eight initials datasets).

### Fixed — minors batch W-min13 (M-CONSOLE-06 · 08 · 11 · 15 · 16 · 30): half-block depth told three ways, five failure-copy templates unified to the `Could not X` family, key hints house-styled in en, French apostrophes typographed, the sign-in/sign-up flow named one way, grid keys reach the summaries — 2026-10-30

- **The defects.** (console__a11y · console__copywriter ×4 · console__docmaint.) `docs/terminal-graphics.md` priced the half-block renderer at "16/256" colors while README and the manual said 24-bit — and the code settles it: `HalfBlockRenderer` tints every cell through `Ansi::fgRgb()`/`Ansi::bgRgb()`. End-user failure copy spoke five grammars at once (`Could not X.` / `X failed to Y` / `Failed to X: ` + raw / bare `'Failed: ' . getMessage()` / `Action failed.` / `An error occurred.`). The en key hints mixed Title-Case-with-colons, Title-Case-bare, and lowercase families — and the colon hints on four screens advertised `A:`/`R:` shift-state for handlers that read lowercase-only runes. `fr.php` showed straight and curly apostrophes in one language. The account flow cycled four names (`Sign in` / `Login failed` inside it / `Register` + `create account` / `back to login`) within two keystrokes. The README keys summary and the manual's Navigation table omitted the grid keys the in-app hints advertise (`R` refresh, `s` shuffle, `c` clear, `q` quit, `H`/`P`/`a`).
- **The cures.** Graphics table row now reads 16.7M (24-bit), matching both other surfaces and the vendor renderer (new law 7 in `TerminalGraphicsDocsTest` derives `Ansi::fgRgb(` from the renderer source and reddens the fossil `16/256` on any surface). Failure copy unified onto the `Could not X` family the codebase already ratified: `StatsScreen`'s raw `getMessage()` echo routed through `ExceptionSentences::sentence()` with a curated fallback; the three `ExceptionSentences::composed()` prefixes and the two remaining PlayerScreen toast sentences (`Subtitles failed to load.`, `Failed to mark as watched.`), `ParentalControlsScreen`'s `Action failed.` fallback, and `SyncPlayModal`'s `An error occurred.` placeholder all moved; `detail.rating_save_failed` en value became `Could not save the rating: `. Admin-detail terse forms stay (the review's explicit exemption), `DownloadService`'s path-bearing exception message is log surface, not UI copy. Key hints: `recommendations.hint` and `detail.actions_hint` restated lowercase/space-separated/`⏎`, and the four colon-family screen HINTs (`WatchHistory`, `Favorites`, `Playlists`, `Collections`) swept into the same house style — now also honest about case (`a  add      r  remove`, lowercase handlers). `fr.php`'s five remaining straight apostrophes became typographic `’` (13/13 values curly). Account naming canonicalized: login-error fallback `Could not sign in. Please try again.`, frame titles `Sign in`/`Sign up`, Register hint `Enter  sign up      Esc  back to sign in`; `Last login` stays — the ratified noun-as-data-label exception. README keys line completed (`R s c u/n [ ] q H P a`); manual Navigation table gains six rows; the palette half of the ask was already cured at parent (README carries the full derived census, `ReadmeFeatureIntroDocsTest` proves it green untouched). M-CONSOLE-22 and M-CONSOLE-85 verified stale-cured at 2902519 (`merge-base --is-ancestor` in this repo): no `PHLIX_DEBUG` claim survives anywhere, and the dev guide's Config key-field list names exactly the four live readonly props.
- **The pins.** NEW `tests/Docs/GridKeysDocsTest.php` (2 laws): the single-key census is regex-derived from `BrowseScreen::SIDEBAR_HINT`, the `railsHint()` literal, and `LibraryScreen::HINT`, and every derived key must be back-ticked in both README and the manual's Keybindings section — mutation-proven red on the parent docs, green on the cure. `TerminalGraphicsDocsTest` law 7 as above. Lockstep moves (copy pins, not new laws): `TranslatorTest` (recommendations.hint), `AppTest` (login fallback), `LoginScreenTest` (frame title), `ToastSentencePunctuationTest` (SyncPlayModal named fallback + subtitle docblock-echo law). Suite: 3316→3319 tests / 20075→20084 assertions (exactly +3 from the two new pin files); locale parity guard holds at 61 keys × 6 catalogs.

### Fixed — minors batch W-min12 (M-CONSOLE-20 · 43 · 82): config-directory precedence told backwards vs `Config::dir()`, and "carrier law" estate jargon leaves the user manual — 2026-10-30

- **The defects.** (console__devops · console__enduser · console__ipd · console__writer.) The manual's Config-directory bullets inverted the lookup order — they promised `~/.config/phlix/` on Linux/macOS and made `XDG_CONFIG_HOME` an "Other"-platforms courtesy, while `Config::dir()` honours `XDG_CONFIG_HOME` on every platform first; and the SyncPlay `ws_port` row spoke in internal shorthand ("phlix-server 424c14d0 carrier law") that no user-facing doc should require a campaign glossary to decode.
- **The cures.** The bullets now mirror the code exactly — `$XDG_CONFIG_HOME/phlix/` whenever set (all platforms), else `~/.config/phlix/`, with the Windows `HOME`→`%USERPROFILE%`→temp resolution called out; the parenthetical is rewritten in plain prose per the queue fix-essence. Batch's other five items verified stale-cured at tips already in `master`'s ancestry: M-CONSOLE-02 @8e8b068, M-CONSOLE-36 @d8c1d2d, M-CONSOLE-38 @a2ae65d, M-CONSOLE-49 @6ef66fc (no `git@github` clone URL remains in docs or README), M-CONSOLE-80 @5b1a7ec — each proof `git merge-base --is-ancestor` in this repo.
- **The pins.** `tests/Docs/ConfigVocabularyDocsTest.php` gains law 6: `Config::dir()` is RUN under a scratch `XDG_CONFIG_HOME` and the manual's Config-directory section must claim that unconditional precedence (plus the Windows fallback), while the fossil inverted bullet reddens the test — mutation-proven: re-injecting the old text fails exactly the new law, restoring greens all six.

### Fixed — minors batch W-min24 rework (M-CONSOLE-44): the README Test section stops generalizing past the one fixture it ships — 2026-10-30

- **The defect.** (console__ipd m.) README.md claimed "the poster test
  synthesizes its own image with `gd`, so the suite needs no fixtures" while
  `tests/Unit/Api/ServerRouteManifestGateTest.php` loads
  `tests/fixtures/server-route-manifest.json` — a vendored byte-for-byte copy
  of the contracts route manifest. True of image/video synthesis; false as a
  generalization about the suite.
- **The cure.** The sentence is qualified per the queue fix-essence: no
  BINARY fixtures need downloading, and the one vendored text fixture — the
  route-manifest JSON — is named where the gate uses it. M-CONSOLE-31, the
  batch's other item, needed no code: its docs/terminal-graphics.md cure
  landed earlier under the writer-M49 wave (81426c0 + 74175be).
- **The pins.** NEW `tests/Docs/ReadmeTestFixturesDocsTest.php` (3 laws,
  bidirectional, fixture inventory re-derived from the tree): every file in
  `tests/fixtures/` is named in the README Test section; every fixture path
  the section names exists on disk; while the directory is non-empty the
  "needs no fixtures" claim reddens (mutation-proven: reverting the cure
  reddens laws 1 and 3, restoring greens all three).

### Fixed — minors batch W-min11 (M-CONSOLE-09 · 60 · 61): CLI usage surface made self-honest, malformed `--mode` priced at its contract exit code, one toast-sentence grammar — 2026-10-30

- **The defects.** (console__tuiarch m2×2, console__copywriter m2.)
  `--help`/`-h` fell to `default:` — "Unknown command: --help" on stderr,
  exit 2 — while `--version`/`-V` and `run --selftest` worked unadvertised:
  HELP_TEXT listed none of the accepted alias forms. A malformed `--mode`
  value (`--mode=banana`) was never validated at the scan; it reached
  `MosaicFactory::forMode()`'s throw and the outer catch, exiting 1 — the
  generic-exception code — for the textbook usage error the file's own
  header contract prices at 2. The bare-mode scan absorbed any known mode
  word anywhere in any argv (`$i >= 2`), so `phlix doctor sixel` and a mode
  word mid-`frame` silently mutated command behaviour. And the toast family
  carried two grammars: period-less standalone sentences ('Could not play
  this track', 'Cannot play this audiobook', 'Could not locate this episode
  in its series', 'Subtitles failed to load', 'Failed to mark as watched',
  'An error occurred', 'User updated'/'User created', 'Schedule updated',
  'Library job queued', 'Type "restart" to confirm') beside period-ed
  siblings ('Setting updated.', 'Server removed.', 'PIN must be 4 or 6
  digits.').
- **The cures.** `--help`/`-h` map to the `help` case; HELP_TEXT and the
  README command-inventory sentence advertise every accepted form
  (`-V`/`--version`, `-h`/`--help`, `run --selftest`). The mode scan
  validates flag-form values against `$KNOWN_MODES` at the boundary —
  exit 2 with the usage line, before any TTY gate or command dispatch —
  and restricts bare absorption to a trailing argv element of
  run/poster/frame. Every named toast literal takes its period (the
  ExceptionSentences composed family already normalized its own via
  `terminate()`), and the PlayerScreen docblock echo moves with its
  literal.
- **The pins.** BinPhlixTest +6: both help flags exit 0 printing usage;
  HELP_TEXT advertises all five alias tokens; README inventory carries the
  same alias set (help-is-SSOT both-sides); `--mode=banana` exits 2 naming
  the mode (on `run` before the TTY refusal — the review-era exit-2
  message differs — and on modeless `doctor`, review-era exit 0); the
  scan-law structural pin refuses the `$i >= 2` anywhere-absorption shape.
  New ToastSentencePunctuationTest (4): token_get_all sweep pinning every
  direct `ShowToastMsg::kind('<literal>')` first-arg as terminated (or an
  explicit `:` prefix), the App playback consts pinned by reflection, and
  named guards for the fallback shapes the parser cannot see through.
  Mutation teeth: parent-shape bin → all 5 behavioural/alias pins red
  (measured); one reverted period pair → 2 punctuation pins red (measured);
  restored both ways green.
- **Measured.** Full serial run 3302 → 3312 tests, 20028 → 20068
  assertions, Skipped 9 → 9 (the pre-existing 14 collection deprecations
  held on both sides); phpstan prod corpus unchanged (the single inherited
  AdminClient.php:2697 `return.type` error, file untouched — non-attribution
  by zero diff), tests corpus 0; phpcs `phpcs.xml` src + `phpcs-tests.xml`
  tests exit 0 (raw PSR12 clean on the new/edited test code;
  AdminLibrariesScreenTest's raw-PSR12 line-length report is pre-existing
  prose untouched apart from its one re-pinned assertion). One local test
  pin (`AdminLibrariesScreenTest`) moved with its cured literal — from
  measured red.
- **The stale.** Queue siblings M-CONSOLE-07 (watch docs — README 'Hub
  relay watch' + manual 'Hub relay consumer' sections +
  WatchCommandDocsTest), M-CONSOLE-29 (manual Admin row to the full shipped
  surface + ScreenInventoryDocsTest drift pin), M-CONSOLE-35 (render-mode
  config claim purged — a2ae65d lineage, console.md:121 states the truth),
  M-CONSOLE-93 (both Config.php cites read `/api/v1/users/me/settings`),
  M-CONSOLE-94 (no invented mode list, `braille` gone — 1d42d37) verified
  cured at HEAD, no edits.

### Changed — paste-replay prose to reviewer-exact truth: CapturesSlash enumeration completed, per-PasteMsg chunk law stated, mutation-teeth header measured; the M44 landed-parent absolutes folded and the OSC-52 channel note faded (review console__tuispec, M-CONSOLE-44 r4) — 2026-10-29

- **The defect.** r2's SHIP verdict queued four prose minors on the M44
  paste twin; r3 re-confirmed all four live at tip after adjudicating the
  previously-claimed fix pass a zero-footprint phantom. The `pasteAsKeys`
  docblock enumerated six CapturesSlash surfaces where the tree has eight
  (RecommendationsScreen was already missing from the r1 list; ServersScreen
  gained the interface post-8793a43 via CON-40 `19b2d3e`); the
  `MAX_PASTE_REPLAY_KEYS` docblock said "per paste" where the framework
  delivers per chunk; the `AppPasteTest` header repeated r1's "drop the
  dispatch branch → tests 1/3/4" while r2's re-measurement showed all
  seven consumption pins red (and the entry-pin insertion had drifted the
  surviving ordinals — a named method, not an ordinal, carries each law);
  and 8793a43's immutable entry carries stale-inherited suite absolutes
  plus the OSC-52 channel mischaracterization.
- **The cure.** Docblock enumerates all eight implementers (ServerScreen/
  ServersScreen/Login/Register/Search/Library/Player/Recommendations); the
  cap docblock states the chunk law — InputReader splits pastes larger
  than its `MAX_PASTE_BYTES` 1MiB buffer (InputReader.php:57, surfaced by
  `surfaceOversizePaste` at :467) into several `PasteMsg`s, so the 4096
  replay cap binds per chunk: proportional total, each dispatch bounded.
  The test header restates the measured teeth: drop the dispatch branch →
  all 7 consumption pins red / the entry-pin green; the newline→Enter, cap
  and space-as-Char probes now name their methods
  (`testPasteNeverProducesEnterOrTab`, `testPasteReplayIsCapped`,
  `testPasteSpaceBecomesSpaceKeyType`).
- **The fold.** 8793a43's entry states "Full serial run 3210→3217 tests,
  17248→17340 assertions" — figures inherited from the fix lane's scratch
  baseline, not its landed parent. Measured at the real parent `2156f59`
  (r1 round): 3219 tests / 17,793 assertions → 3226 / 17,885 at 8793a43.
  The +7/+92 delta the entry itself claims stays exact; these are the
  landed absolutes (the shipped entry remains verbatim — history is
  verbatim, corrections fold forward).
- **The fade.** The same entry's "(or any OSC52 paste) was a silent no-op
  estate-wide" mischaracterizes the channel, faded here for the record:
  OSC-52 operates through the `ClipboardMsg` request/reply channel
  (InputReader.php:808-817); `PasteMsg` originates solely from the
  bracketed-paste mode-2004 CSI envelope (`ESC[200~`…`ESC[201~`); with
  2004 off — the default at every terminal before c272cad enabled it —
  pastes typed straight through as raw keys, which is what kept the
  replay seam inert.
- **Suite.** Comment-only change; full serial run held at the tip
  baseline measured this pass: 3302 tests / 20,028 assertions,
  deprecations 14, skips 9, exit 0; `--filter AppPasteTest` 8/8 green.
  phpstan src = the one inherited `AdminClient.php:2697` finding
  unchanged, phpstan-tests clean; phpcs `phpcs.xml` / `phpcs-tests.xml`
  and bare PSR12 add zero new findings (the same 18 long-line warnings in
  `App.php`, none on touched lines). i18n catalogs inert (61 keys,
  parity check green).

### Changed — vendor-patches doc reconciled with the workflow, .gitignore and the filesystem; the fictional patch pipeline purged with a runtime-derived pin (review console__writer, M-CONSOLE-51) — 2026-10-29

- **The defect.** `docs/vendor-patches.md` contradicted itself and the
  repository. Its header called the file "this directory" that "captures
  hand-patched vendor files" whose patches "are applied as part of the CI
  pipeline" — while a few lines below the policy reads "**Do not hand-patch
  vendor files.**" The CI job neither applies nor is named as written: the
  doc says `vendor-patches-check`, the workflow says `vendor-patches`, and
  its step is a tamper grep over a plain `composer install` tree. The
  "`patches/` directory exists as a placeholder" was false — no such
  directory has ever existed in the repository's history (`git log --all --
  patches/` is empty) — and the `extra.patches` instruction was inert
  decoration: no patch plugin in `require`/`require-dev`, `extra` empty.
  The quoted grep block had drifted from the live one on three axes
  (still listing bare `ORIGINAL`, which `09b4228` deliberately dropped
  because packages ship `COMPOSER_ORIGINAL_INIS`; missing the ANSI-red
  `PATCH` marker; wrong command shape). Meanwhile `vendor/` is untracked
  (`.gitignore`: `/vendor/`), so the whole "capture" premise had nowhere
  to live.
- **The cure.** The doc now states the executed truth: a policy document,
  not a directory; `vendor/` Composer-managed and untracked; the
  `vendor-patches` job **applies nothing — it only detects and fails**;
  the live detection step quoted verbatim from `.github/workflows/ci.yml`;
  the marker set and the `ORIGINAL` exclusion told honestly; the
  unavoidable-patch section reduced to the sanctioned ladder (fork +
  `repositories` / upstream PR) with any future real pipeline required to
  land tooling, prose and pin in one change. Sibling docs swept: the only
  other teller of this story, `docs/steps/ci-baseline-C9.md`, was already
  cured by the M-CONSOLE-53 pass; README carries no mention.
- **The pin.** `tests/Docs/VendorPatchesDocsTest.php` (8 tests, 91
  assertions, tests/Docs tradition): job name, detection line, marker
  alternation, `patches/` absence, untracked-vendor posture and
  patch-tooling absence all DERIVED from ci.yml, .gitignore, composer.json
  and disk; the prose fossils may never return to any README/docs markdown
  (CHANGELOG history exempt). Red-on-parent measured (8 failures against
  the pre-cure doc); mutation probes planted: a marker swap in the
  workflow reddens exactly the verbatim-quote law, restored green.

### Changed — terminal-graphics doc to the real pipeline and the real test harness; retired decoder doubles purged from prose with a runtime-derived pin (review console__writer, M-CONSOLE-49) — 2026-10-29

- **The defect.** `docs/terminal-graphics.md` still narrated a removed
  architecture in present tense: the Testing section advertised test
  doubles `SixelDecoder`/`Iterm2Decoder`/`KittyDecoder` living in
  `tests/Graphics/`, and How-It-Works plus the architecture diagram cast
  those same ghosts as the poster ENCODERS over an `RgbFrame`. None of
  those classes exist anywhere in src/ or tests/ (grep: docs only); the
  suite's own successor (`PosterGraphicsStreamTest`) says in its header
  that it replaces those hand-made doubles, which "merely implemented
  the sugar-video Decoder interface over canned frames" — the doubles
  were the retired strategy, not the current one.
- **The cure.** The three stale sections were rewritten to executed truth:
  the encode path is `MosaicFactory` → sugar-image `Mosaic::render()` →
  `SixelRenderer`/`Iterm2Renderer`/`KittyRenderer` (+ the cell renderers,
  with ANSI256/TrueColor as tinted ASCII ramps), graphics blobs painted
  through `ImageLayer` markers; the harness is
  `tests/Graphics/PosterGraphicsStreamTest.php` (real protocol bytes
  round-tripped through sugar-testing's `SixelStream`/`KittyStream`/
  `Iterm2Stream` — measured OK, 10 tests/1,627 assertions) and
  `tests/Ui/PosterRenderTest.php` (MediaItem → PosterCard → PosterLoader →
  Mosaic — measured OK, 21 tests/51 assertions). `RgbFrame` remains true
  only for the video decode path and no longer appears in the doc.
- **The pin.** `tests/Docs/TerminalGraphicsDocsTest.php` (5 tests, 35
  assertions): README/docs and src/bin/tests carry none of the retired
  double names; the covered modes derive at runtime from the stream
  suite's own data provider and each must be named by its `<Mode>Stream`
  decoder; every backticked `tests/...php` cite must exist; every
  encoder class the doc names must autoload. Red-on-parent measured
  (1 error + 3 failures against the pre-cure doc); mutations planted:
  re-adding a dead name reddens law 1 alone, swapping `SixelStream` out
  of the doc reddens law 3 alone, both restored green.
- **The r2 cure** (review verdict REWORK, issues=1): the r1 diagram had
  re-rooted itself on the same defect class — a "PlayerScreen (orchestrates
  rendering) —render mode→ MosaicFactory" edge the code contradicts (the mode
  is parsed from CLI argv and consumed at container-build time in `bin/phlix`,
  before any screen exists; `PlayerScreen` never references `MosaicFactory` and
  is in fact downstream: `App` opens it with
  `PlayerScreen::productionFactory($this->posters->protocol(),
  $this->posters->cellSize())`). The diagram now traces the real spine
  (CLI → factory → Mosaic → renderers) with a code-anchored note showing
  `PlayerScreen` where it actually sits. A sixth runtime-derived law pins the
  edges in both directions (measured: the suite is now 6 tests / 42
  assertions; planted r1-fiction doc and planted player-side factory
  reference each redden law 6 alone).

### Fixed — hardcoded truecolor hexes routed through the Theme palette; a census law keeps the bypass class dead (review console__uxdesign, M-CONSOLE-48) — 2026-10-29

- **The defect.** Four widgets emitted raw 24-bit colour straight into the
  frame: `RecommendationCard` (`#ffcc00` stars, `#0066cc` badge, `#ffffff`
  title), `RatingBadge` and `UserRatingPicker` (`#ffcc00`, `#ff6666`), and
  DetailScreen's cast avatars (a fixed six-hue saturated ramp applied as
  `->bg($hex)`). None took the active `Theme`, none went near
  `resolveProfile()` — so the theme picker silently failed to cover these
  surfaces, the `#ffffff` card title was invisible on precisely the light
  terminals `daylight` exists for, and 16-colour terminals got undowngraded
  SGR. `Style::new()->fg('#rrggbb')` was measured emitting
  `\e[38;2;…` byte-verbatim under every theme.
- **The cure.** `Theme` gains the content-widget role palette — roles
  `star`/`badge`/`title`/`danger` + a six-hue avatar ramp, mapped per theme
  — and two style factories following the house `brandStyle()` idiom
  (`Color::hex()` + `resolveProfile()`): `widgetStyle(role, reversed:)` and
  `avatarStyle(slot)`. Nocturne carries the historical hexes in the same
  emission order, so the default theme stays byte-identical (all four
  widgets' pre-fix renders are pinned golden); Daylight remaps every role
  that was illegible on a light field (dark gold, its own accent, an
  honest terminal-default title, dark red, pastel avatar chips); Midnight
  lifts the badge onto its accent. Unknown role/palette-less theme/negative
  slot fail loud at the boundary.
- **The law.** `ThemePaletteSoleSourceTest` censuses src/ for colour hexes
  and raw `38;2` sequences outside `Theme.php` (the sole legal palette home
  — red on the 14-offender parent) and carries the byte-goldens, the
  daylight legibility sweep, full preset coverage and the fail-loud arms.
  Re-adding one bypass reddens the census leg by filename.
- **Numbers.** Unit suite 3264→3275 tests (+11), 18702→18867 assertions
  (+165), skips 9 held, measured serial at the 65a31fa base; phpstan
  prod+tests identical to parent (sole error remains the inherited
  AdminClient:2697 return-shape); phpcs src+tests+PSR12 on every touched
  file at 0 errors and no new warnings; i18n hardcoded + 6-catalog parity
  gates green; mutation probe (bypass re-added to RecommendationCard) → the
  named census failure. Capability-posture disclosure: the app never
  negotiates a terminal profile at startup (`Style::setDefaultProfile()`
  has no call site outside `phlix doctor`), so `resolveProfile()` is a
  pass-through today — the cure matches the existing chrome idiom exactly;
  startup negotiation is a separate decision.

### Fixed — truncation honors display cells at the shared choke; the codepoint-counting admin family is extinct (review console__uxdesign, M-CONSOLE-47) — 2026-10-29

- **The defect.** `AdminDuplicatesScreen`, `AdminWatchHistoryScreen` and
  `AdminWebhooksScreen` each carried a byte-identical private `truncate()`
  counting CODEPOINTS (`mb_strlen`/`mb_substr`) where the terminal bills
  CELLS: the review measured `怪物映画の非常に長いタイトル` (28 cells, 14
  codepoints) coming back 19 columns wide on a 10-cell budget — every CJK or
  emoji media title blew its table column ~2×. The same codepoint cut can
  split a ZWJ family emoji or orphan a combining mark, and
  `ExceptionSentences::clamp()` shared that weakness via `mb_strimwidth`.
- **The choke.** New `Ui\Truncate::withEllipsis(string $text, int $maxCells)`
  over the vendor's grapheme + east-asian-width `Width` splitter (the same
  helper Sidebar/DetailScreen/Playlists already call directly): result never
  exceeds the budget, surviving clusters are always whole, the ellipsis
  appears exactly when a cut happened, `$maxCells <= 0` yields `''`.
  All three screens' cells and the exception-sentence clamp now route through
  it; the private copies are deleted and the `ScreenConcernSingleSourceTest`
  ratchet baseline drops its `truncate:189b1d7818ff => 3` row.
- **Pins.** +2 files / +13 tests / +1027 assertions (suite 3264 → 3277,
  deprecation and skip counters unchanged): width tables (CJK 28-at-10, ZWJ
  never severed, no lone trailing combining mark at any budget, invariant
  sweep over a mixed corpus at budgets 1..40), per-screen cell pins driven
  through the public `update(LoadedMsg)` seam (red on the old helpers:
  46 vs 28 and 52 vs 40 cells measured), and an extinction + caller-route
  census over `src/`. Mutation probe: reverting one caller reddens exactly
  its three named pins.

### Fixed — compositor honors single-logical-line graphics frames; overlays measure display width, not blob lines (review console__tuispec, M-CONSOLE-42) — 2026-10-29

- **The defect.** `PlayerScreen::renderBase()` deliberately keeps a graphics-mode
  frame on ONE logical line (`$blob . graphicsChrome()` — cursor moves only, no
  `"\n"`, docblock-player). Every App- and player-level compositor disagrees:
  `compositeNowPlayingBar()` `explode("\n")` + last-line replace swaps the whole
  video+chrome for the 1-row bar; `MetricsOverlay::overlay()` splices row 0 and
  `Width::dropAnsi` (which passes only CSI+OSC through) severs the DCS/APC sixel
  payload mid-blob; all eight `Veil::composite` menus resolve their canvas from
  newlines and so see a 1-row screen; the vendor toast splices the same way.
  Reachable today: `App::openPlayer()` never tears down the App-owned
  now-playing session, so music + sixel video co-exist by design.
- **The model.** A graphics frame is OPAQUE-FIRST-LINE and stays canonical.
  New `Screen\LineAligned` contract (PlayerScreen: false only with a graphics-mode
  inner mounted); new `Ui\Graft` writes each overlay row via
  `Ansi::cursorTo(row, col)` with the anchors mirroring sugar-overlay's
  `Position::CENTER` (`floor((bg − fg) / 2)`) and the top-right splice
  (`max(0, cols − boxWidth)`) 1:1 — same box, same cells, never byte-splitting
  the blob. `render($background, bool $opaqueFrame = false)` threads the seam
  through the 7 reachable overlays (chapter/audio/subtitle/quality/sleep-timer
  menus, SyncPlay modal, command palette) + `SyncPlayOverlay::render` +
  `MetricsOverlay::render`; the now-playing bar writes the bottom row with an
  absolute move + erase after the chrome (bar wins its row); the sugar-toast
  splice, having no graft path in the vendor, is skipped over opaque frames —
  queued toasts wait the video out (the review's sanctioned alternative).
- **Pins.** `tests/Ui/GraftTest.php` +6 (anchor math incl the live Veil-center
  numbers, DCS byte-fidelity, 1-line invariant, clamp legs);
  `tests/Screen/GraphicsFrameCompositingTest.php` +9 (App-level HUD/bar/palette/
  toast graft vs a live mounted `Player::fromDecoder(mode: Mode::Sixel)` frame —
  the sixel view really is one `"\x1bP"`-headed line — legacy-splice-unchanged
  control over plain frames, text-mode control). Red-on-parent doubly proven:
  parent `MetricsOverlay::render` replaces the blob head live, and the new files
  fatal-load on 9cd33c2.
- **Gates (measured at this commit).** Full suite 3234 tests / 17,845 assertions,
  0 failures / 0 errors / 9 skipped (shared tree also carries sibling-lane docs
  edits, +14 tests over the clean 3205/17,239 base). The lane adds exactly +15
  tests / +51 assertions, measured per-file: `GraftTest` 6/18,
  `GraphicsFrameCompositingTest` 9/33; the docs content-scan tripwires re-derive
  their loop counts from this CHANGELOG/README text itself and shift by a few
  assertions with each prose pass (never pin them from memory). phpstan: 1 error
  = the known `AdminClient::alternatePosters()` inherited-red (identical at
  parent). phpcs both repo standards exit 0. i18n: zero new user strings
  (hardcoded + catalog checks pass).
### Added — run entrypoint enables bracketed-paste terminal mode 2004 so the M44 replay actually fires (review console__tuispec, M-CONSOLE-44) — 2026-10-29

- **The missing half.** `8793a43` shipped the consumption seam (`App::dispatch()`
  PasteMsg → typed-key replay, sanitizer law, 4096 cap) but the shipped app
  never entered it: `bin/phlix` constructed `Program` with the default
  `ProgramOptions` (`bracketedPaste = false`), and Program writes `ESC[?2004h`
  only when the option is set — terminals never wrapped pastes, InputReader
  never emitted a `PasteMsg`, and the replay seam stayed inert (raw pasted
  newlines also keep auto-submitting half-filled forms without mode 2004).
- **The cure.** The `run` case now passes `new ProgramOptions(bracketedPaste: true)`
  as Program's second argument, with a rationale docblock; the consumption
  law is untouched.
- **New pin.** `tests/AppPasteTest.php` +1:
  `testBracketedPasteModeIsEnabledAtTheEntrypoint` — a source-content regex on
  `bin/phlix` (precedent: `UpgradeGuideV1DocsTest` parses the same entrypoint).
  Red-on-parent measured at `19b2d3e` (1/1); mutation: deleting the option
  reddens exactly this pin while the 7 consumption pins stay green.
- **Verification.** Suite 3248→3249 tests / 18649→18650 assertions (+1/+1
  exact, both sides measured serial at this head), 0 failures, 9 skips,
  14 PHPUnit deprecations held. phpstan src config = the pre-existing
  `AdminClient.php:2697` inherited finding only (measured at parent; separately
  owed); phpstan-tests clean; phpcs `phpcs.xml` src + `phpcs-tests.xml` tests
  exit 0 with zero new findings (`bin/phlix` bare-PSR12 delta 9→9, all
  pre-existing); i18n catalogs (61 keys) + hardcoded scanner green — no
  user-facing strings added.

### Fixed — admin tables degrade by dropping named columns; the primary column never vanishes (review console__uxdesign, M-CONSOLE-46) — 2026-10-29

- **The defect.** `Table::render` passed the caller's column spec straight to
  sugar-table, whose width solver deducts every named column AND a 1-cell gap
  per column boundary before the `width: 0` flex column gets what remains —
  on narrow terminals the remainder went negative and the PRIMARY SILENTLY
  VANISHED (measured at HEAD on AdminUsersScreen's 68-col spec rendered into
  `cols - 4`: Username was a 4-cell stub at 76 content cols — 80-col terminal,
  the app's most common venue — deleted with its header at ≤72, and at 60 the
  clip ate even the last fixed header). No priority or degrade policy existed
  anywhere in the stack; ~31 call sites shared the pattern.
- **The cure.** One budget pre-pass at the same choke point:
  `Table::reservePrimaryColumns()` drops the RIGHTMOST named column until
  every kept column can show its own header (primary floor = its header's
  DISPLAY width via `Width::string`, grapheme/East-Asian aware, additionally
  floored at 4 cells for degenerate empty-title specs), spending the same
  gap-cell currency the vendored solver charges. Declaration order IS
  priority — every screen already lists its identifier first and its
  trailing metadata last. The primary is never dropped: at worst it renders
  at its own floor with sugar-table's honest `…`-marked truncation. Tables
  that fit (every wide-terminal render) and tables with no primary at all
  pass through byte-identical. No caller specs changed; no display strings,
  so the 7-locale catalogs are untouched.
- **Pins.** `tests/Ui/TableTest.php` +6 tests (red-on-parent: the 80-col-band
  stub test, the never-deleted sweep, and the ladder test — the other three
  are overreach guards); the sweep asserts the Username header and a full
  `alice` cell survive every content width 8..120 with every line exactly
  the requested width; the ladder pins the exact drop boundaries (Last login
  below 80, Status below 59, Role below 48, Email below 39); a 1..7 sweep
  pins the primary shrinking to its honest truncation instead of being
  deleted; the width-100 header line is pinned BYTE-IDENTICAL to the
  pre-cure render. Mutation probes: charging no gap cells reddens 3 tests;
  removing the pre-pass reddens 3; letting the ladder eat the primary itself
  reddens the degenerate-width pin (the first two law tests alone were
  blind to it — the sweep floor was lowered to expose it).
- **Verification.** Suite 3219→3225 tests / 17793→18501 assertions (both
  sides measured serial at this head; 14 deprecations / 9 skips unchanged),
  0 failures. phpstan src unchanged at the one pre-existing inherited
  `AdminClient::alternatePosters()` red (owner-reel-tracked, not this file);
  phpstan-tests clean. phpcs `phpcs.xml`/`phpcs-tests.xml` exit 0; bare PSR12
  shows only the signature-line warning inherited from HEAD. i18n hardcoded
  + catalogs gates green.

### Changed — Servers-screen `a` key wired to a real inline add-server form (review console__tuiarch, M-CONSOLE-40) — 2026-10-29

- **The defect.** The class docblock promised "'a' add a server manually (URL +
  label, via form that submits AddServerMsg)", but the handler toasted "Use the
  palette 'Add server' action to add manually." — and `staticActions()` contains
  no such palette action. The empty-state copy pointed at the same phantom, the
  footer never advertised `a`, and `AddServerMsg` → `App::onServerAdd()` was dead
  wiring nothing in the UI ever produced. The documented manual multi-server flow
  was broken end to end.
- **The cure (inline form per the docblock; a working form mechanism exists in
  the action system — the sugar-forms machinery ServerScreen already embeds).**
  `a` now opens an inline two-field form (URL required, label optional) owned by
  ServersScreen; while it is open every key routes to it, and Esc cancels back to
  the list instead of popping the screen. Submitting normalizes the URL via
  `Config::normalizeUrl` (blank → error + fresh form, mirroring ServerScreen's
  never-touched-Enter belt), falls a blank label back to the URL host, and emits
  `Cmd::batch(AddServerMsg, OpenServersMsg)` — persisting through the live
  `onServerAdd` path and re-rendering the list from the saved config. The toast
  dead-end is deleted; the empty-state copy and footer now advertise `a`
  truthfully. The screen gains `CapturesSlash` (same law ServerScreen carries for
  URL typing: the App must not hijack `:`/`/` — Ctrl-K still opens the palette).
- **Docs.** The manual's Screens-table Servers row now advertises the `a` add
  key with the same one-sentence story as the footer and docblock (the row
  previously listed only Enter/h/r).
- **Tests.** New `ServersScreenAddServerTest`: 9 tests / 24 assertions — all red
  on parent, green after (incl. the regression pin that `a` must not return a
  `ShowToastMsg`). phpstan src/tests, phpcs (both standards) and the i18n checks
  green; the one phpstan src error (AdminClient return.type) is pre-existing.

### Fixed — SyncPlayModal room-name backspace cuts a codepoint, not a byte (review console__tuispec, M-CONSOLE-43) — 2026-10-29

- **The defect.** `SyncPlayModal::backspace()` sliced the room name with
  `strlen()`/`substr()` — byte semantics. One keystroke on a UTF-8 name cut a
  single byte out of the tail codepoint and orphaned a partial sequence:
  "café" became "caf\xC3" and "Room 🚀" grew a dangling "\xF0\x9F\x9A", both
  rendering as a mojibake replacement glyph in the field.
- **The cure.** `mb_substr($name, 0, -1, 'UTF-8')` — the same idiom the
  palette/search backspace paths already use (`FilterBar::editSearch()`,
  `SearchScreen`), with the emptiness guard tightened from a byte-length
  probe to a plain non-empty comparison.
- **Granularity law.** Deletion is per codepoint, deliberately not per
  grapheme cluster: a decomposed "e + U+0301" or a ZWJ emoji sequence sheds
  one mark per keystroke. Grapheme clustering needs ext-intl, which
  `composer.json` does not require and the CI matrix does not install —
  mbstring is the safe, already-assumed floor (a dozen `src/` call sites use
  it today). The tradeoff is documented at the method and pinned by tests.
- **Tests.** New `tests/Ui/SyncPlayModalBackspaceTest` (7 cases): 4-byte
  emoji, 2-byte composed accent, decomposed combining sequence, ZWJ
  sequence — each asserting valid UTF-8 at every intermediate step — plus
  ASCII regression parity, empty-field no-op, and the non-creation-state
  no-op pins. Red-on-parent measured at commit base: 4 failures, one per
  multi-byte vector; green after.

### Added — bracketed paste replays as typed keys into every text surface (review console__tuispec, M-CONSOLE-44) — 2026-10-29

- **The defect.** sugar-core's InputReader delivers a bracketed paste as ONE
  `PasteMsg` (InputReader.php:124), while every text surface in this app —
  the command palette, Search, FilterBar, the SyncPlay room name, the
  sugar-forms fields — consumes only Char/Space `KeyMsg`s. `App::dispatch()`
  had no `PasteMsg` arm at all: the message fell through to the catch-all,
  each screen's model returned itself untouched, and Ctrl+Shift+V (or any
  OSC52 paste) was a silent no-op estate-wide. `grep -rn PasteMsg src/`
  returned zero hits at 1d42d37.
- **The cure.** A `PasteMsg` branch (after the `WindowSizeMsg` guard) hands
  the payload to `App::pasteAsKeys()`: line endings and tabs are FLATTENED
  (never replayed as Enter/Tab — a paste must not submit a half-filled form
  or jump focus), malformed UTF-8 is repaired before the Unicode sweep (the
  framework sanitizer keeps `\t\n\r`, so `/u` patterns would null-fail
  open on hostile bytes), invisible format chars (`\p{C}`: ZWSP, bidi
  overrides) are stripped, and the replay is capped at
  `MAX_PASTE_REPLAY_KEYS` 4096 (hostile-clipboard DoS valve). Surviving
  runes replay one `Cmd::send` per key — space as `KeyType::Space`, exactly
  what InputReader.php:499 emits for a typed 0x20, everything else
  `KeyType::Char` — batched via `Cmd::batch` (children are pure sends;
  Program.php:679-684 drains the batch FIFO, so order is preserved). State
  folds through the NORMAL dispatch path, so every capture gate keeps its
  teeth: palette modal capture, `CapturesSlash` skip-screens, `s`-arm. An
  all-filtered paste produces no Cmd at all.
- **New pins.** `tests/AppPasteTest.php` (7 tests, 92 assertions), every one
  RED on parent 1d42d37 measured (7/7 behavioral failures). Mutation teeth
  measured: dropping the 4096 cap reddens exactly the cap pin; replaying
  space as `Char` reddens exactly the Space-type pin; skipping the
  newline-flatten reddens the never-Enter/Tab and empty-noop pins (2).
- **Suite.** Full serial run 3210→3217 tests, 17248→17340 assertions
  (+7/+92 exact), deprecations 14 and skips 9 held. phpstan src level 9
  unchanged (the 1 inherited `AdminClient.php:2697` finding is present at
  pristine tip — measured); phpstan-tests level 2 clean; phpcs
  `phpcs.xml`/`phpcs-tests.xml` exit 0. i18n guards green, catalogs inert
  (61 keys; key-handling adds no user-facing strings).
### Fixed — status-line hints fit the terminal width by whole-word middle elision with an omission marker (review console__uxdesign, M-CONSOLE-45) — 2026-10-29

- **The defect.** `Chrome::frame()` put the hint on a borderless 1-row leaf
  that clips silently at `cols - 2` (measured at HEAD: cols=60 showed 58 hint
  cells; at 80 the worst case, `AdminLibrariesScreen`'s 135-col hint, rendered
  through "D clear-artwork D" and "elete-all s scan R rescan m match h history
  r refresh Esc back" was gone — right-clipping deletes exactly the tail
  tokens, and every hint in the app parks its safety key (`Esc  back`,
  `q  quit`) at the END. No `…`, no signal, on the 80-col default of most
  terminals.
- **The cure.** One helper at the choke point every ~25 `Chrome::frame` call
  sites already pass through: `Chrome::fitHint(string $hint, int $cols)` keeps
  a run of whole whitespace-delimited words from the front and from the back
  (tail capped at half the usable budget so the safety key always survives),
  joined by ` … ` — the omission signal. A hint that fits is returned
  BYTE-IDENTICAL (wide-terminal renders and the Docs key-table pins that read
  the shipped hint constants are untouched); degenerate widths and
  no-whitespace hints fall back to the canonical display-width
  `Width::truncateMiddle` (grapheme + East-Asian aware — no codepoint
  counting, per the same review's helper law). Hint constants across the
  screens stay verbatim; the fit happens at render time.
- **Pins.** `tests/Ui/ChromeTest.php` +8 tests (byte-identity incl the exact
  `cols - 2` capacity boundary, tail-safety-key at 60/80, marker + head-kept,
  width-invariant sweep cols 1..120 for both quoted hints, whole-words-only
  invariant, wide-CJK cell budget, degenerate widths, frame integration —
  red-on-parent: the probe lost `Esc` at both 60 and 80 before the cure);
  `tests/Docs/ScreenGuideDocsTest.php` +1 (the guide now teaches
  `Chrome::fitHint` and the ` … ` law; red-on-parent: neither string nor
  method existed). Mutation probes: dropping the frame's fit call reddens the
  integration pin; always-passthrough reddens 2; right-truncation reddens 5.
- **Verification.** Suite 3210→3219 tests / 17248→17793 assertions (both sides
  measured serial at this head; the sibling M-CONSOLE-38 lane landed `1d42d37`
  mid-lane and its 331-test file is inside both counts), 0 failures, 9 skips
  and 14 deprecations held. phpcs: src corpus unchanged (one inherited
  LineLength warning; the new multi-line status call is clean), tests corpus +2
  warnings — the two verbatim long hint literals restating the review's quote,
  deliberate. phpstan src: only the known pre-existing `AdminClient:2697`
  return-type red (not this file's); phpstan-tests corpus [OK]. i18n: catalogs
  untouched; both checks pass (61 keys, parity held).

### Changed — dead config vocabulary purged; manual & dev guide to persisted-key truth with runtime-derived pins (review console__tuiarch, M-CONSOLE-38) — 2026-10-29

- **The defect.** `Config` carried a wholly invented vocabulary:
  `DEVICE_LOCAL_KEYS` (`terminal_render_mode`, `colour_palette`, `cell_size` —
  with fabricated value sets that matched no real mode list) was declared
  public and read by zero code anywhere; the class docblock also advertised
  `preferred_library_ids` and `parental_controls_pin` as "server-persisted"
  although no client path sends, receives, or parses them. Meanwhile the
  manual told users `config.json` holds "render mode preferences" (it never
  did — the mode is a per-invocation `--mode` choice) and the dev guide
  re-listed the dead device-local keys. Two docblock cites named the settings
  endpoint as `/api/v1/me/settings`; the live path is
  `/api/v1/users/me/settings` (ApiClient's own literals). A queue
  `cured-by` note claiming a prior fix (5e33c84) was verified false — that
  commit cured the token.json filename, a different item.
- **The cure (docs/vocabulary arm of the review's two options; wiring a
  persisted-mode ladder is a behavior decision outside a docs lane).**
  `DEVICE_LOCAL_KEYS` deleted; the SETTING PERSISTENCE SPLIT docblock
  rewritten to the provable buckets (device-local: `servers` +
  `active_server_id`; server-synced: `theme` + `slideshow_interval`, honest
  allowedFields NOTE kept) with corrected endpoint cites and an explicit
  "render mode is not persisted" statement; `withServerSettings()` docblock
  rewritten; the manual's `config.json` row and Render modes section, and the
  dev guide's Config split, all moved to runtime truth.
- **New pin.** `tests/Docs/ConfigVocabularyDocsTest.php` (5 tests,
  9 assertions), each RED on parent 9cd33c2 (measured): code and prose scans
  for the dead vocabulary (src/bin/docs/README), a config.json row that must
  match the persisted-key set DERIVED by running `Config::save()` into a
  scratch file, the manual's never-persisted render-mode statement, and
  settings-endpoint cites verified against the paths derived from
  `ApiClient.php` itself. Mutation teeth measured: planting a dead key into
  the dev guide reddens the docs pin; sneaking a fifth key into `save()`
  reddens the runtime-derived set with it named.
- **Verification.** Suite 3205→3210 tests / 17239→17248 assertions,
  0 failures; 9 skips and 14 deprecations held. PHPStan src corpus: the one
  pre-existing `AdminClient.php` return.type error is byte-identical at
  parent and tip (non-attributed); phpstan-tests.neon [OK]. phpcs: repo src
  and tests standards exit 0; plain PSR12 clean on the new test file (the
  lone 122-char Config.php warning pre-exists at parent, untouched). i18n
  hardcoded + catalogs checks exit 0 (no lang surface touched). No tags, no
  branches.

### Changed — byte-identical screen helper families single-sourced into concern traits; drift ratchet pin (review console__srphp, M-CONSOLE-37) — 2026-10-29

- **The defect.** A token census (visibility + signature + body, comments
  normalized) found the review's copy-paste families alive and GROWN at HEAD:
  `resizedTo` 37 byte-identical private copies across `src/Screen/` (raw text
  identical at every site), `resolveUrl` 11 (the review-era inline comment
  included), `selectPrev` 9, and the `items`-shaped `selectNext` 5. Exactly the
  bodies the review named as fix-prone (resize bounds, URL-join edge cases,
  selection wrap-around) — each future cure had to be hand-applied 5–37 times.
- **The cure.** Four concern traits next to the existing `ThemedScreen` /
  `PosterFetchPolicy` / `ShimmeringScreen` trio: `ResizesTo`, `ResolvesUrls`,
  `SelectsPrevious`, `SelectsNext`. 62 local copies deleted from 46 screens,
  each `use` line appended in place; bodies moved verbatim (method text
  raw-identical, docblocks included). Behavior byte-identical: every class
  keeps the same method set, visibility and bodies as before. `HasSelection`
  ships split (the four admin screens share `selectPrev` but their `selectNext`
  bodies differ by collection property — one both-directions trait would hand
  them a shadowed member).
- **Deliberately NOT extracted.** `withError` is six distinct state-machine
  shapes under one name (x9/x8/x5/x5/x4/x2; each resets a different field set)
  and `onActionDone` six message-typed signatures share one body — either
  consolidation would change behavior or types, so both are ratchet-pinned
  instead, alongside everything else the sweep found (the review missed
  `withCrumbs` x46, `error()` x37, `isLoaded` x24, `init` x20).
- **New pin.** `tests/Unit/Screen/ScreenConcernSingleSourceTest.php` (3 tests,
  222 assertions): the four canonical families must exist exactly once, in
  their trait file (red-on-parent: 37/11/9/5 copies, traits absent), and the
  remaining 104-family duplicate census over `src/Screen/` must match the
  pinned (name, hash) → count baseline exactly — a planted byte-identical twin
  reddens both guards; partial peels must re-pin from a measured red.
- **Verification.** Suite 3202→3205 tests / 17005→17239 assertions, 0 failures,
  9 skips and 14 deprecations held; the +234 asserts are this entry's 222 plus
  12 from two pre-existing per-file scan pins (ScreenGuideDocsTest's navigation
  contract, SessionExpiredCopyTest's expiry-wording scan) simply iterating the
  four new trait files — both green on them. phpstan src keeps its single known
  inherited red (`AdminClient::alternatePosters` return.type, same signature as
  parent), phpstan-tests clean; phpcs src holds the declared 236-warning
  baseline exactly, tests gate clean; i18n catalogs untouched (traits carry no
  strings).

### Changed — console manual linked from the README; front-door section + link-integrity pin (review console__newcomer, M-CONSOLE-31) — 2026-10-29

- **The defect.** `docs/clients/console.md` is the actual newcomer guide —
  config-file layout, full keybinding tables, the render-mode reference,
  genuine Troubleshooting — yet the README named it only as backticked
  prose (3 mentions, 0 links; backticks are not clickable on GitHub).
  The tuiarch twin (`watch` absent from both docs) was already cured by
  the WatchCommandDocsTest era; its unclaimed residue — `version`/`help`
  missing from the README command inventory — lands here.
- **The cure.** README gains a `## Documentation` front-door section
  (manual deep-links: Configuration, Keybindings, Screens, Watching &
  ratings, Hub relay consumer, Troubleshooting, plus i18n /
  terminal-graphics / upgrade-notes); the three backticked mentions
  became real anchor links; the diagnostics inventory now names all
  seven commands (`run doctor poster frame watch version help`) —
  `version` (`-V`/`--version`) and `help` print and exit, no TTY
  (bin/phlix:734-742). The manual's Installation gains a reciprocal
  pointer to the README front door. The README↔manual passage
  duplication (install lists, watch prose, palette census, key loops) is
  the estate's ratified lockstep — each pair is pinned to the same code
  source by its own tests/Docs gate — so it is kept, now with doors both
  ways instead of orphan copies.
- **New pin.** `tests/Docs/ManualLinkingDocsTest.php` (4 tests, 6
  assertions): the README must carry the front-door section, every
  manual mention must be inside a link, and every relative link in
  README.md / docs/clients/console.md must resolve (file exists +
  GitHub-slugified heading anchor). Red-on-parent proven: on `3c54ff3`
  both law tests fail; the resolver also caught (and fixed) a
  `../README.md` one-level error in the pointer itself —
  two-levels-up is the correct relative path from docs/clients/.
- **Measured.** Full suite `3198→3202` tests / `16999→17017` assertions
  / skipped 9 held (+1 PHPUnit deprecation 13→14 is the new
  `@dataProvider` annotation count — repo-wide config migration stays
  owed separately); phpstan prod carries only the inherited
  `AdminClient:2697` red (non-attribution: diff touches no `src/`);
  phpstan-tests OK; phpcs `phpcs.xml` src + `phpcs-tests.xml` tests +
  bare PSR12 on the new file all 0. i18n catalogs untouched.

### Fixed — ci-baseline-C9 gate coordinates re-anchored to workflows on disk; claims pin (review console__ipd, M-CONSOLE-27) — 2026-10-29

- **The defect.** The four phantom gates this item originally filed (coverage
  floors, uniform bare-PSR12 phpcs, the `ORIGINAL` grep, a `help` smoke leg)
  were cured by the M-CONSOLE-53 currency pass (`8854648`, anchored at
  `759d7c5`) — but the pass's own `path:line` contract then broke: `7b08db5`
  expanded the two phar release-body texts in `build.yml`, shifting lines by
  +2 (from old `:126`) and +4 (from old `:212`), leaving five release-job
  coordinates stale at HEAD (`build.yml:76-135`→`:76-137`, `:137-218`→
  `:139-222`, `:170-174`→`:172-176`, `:176-199`→`:178-201`, `:201-216`→
  `:203-220`). The line-4 blanket header "All items below are enforced in CI"
  also sat over sections the doc itself labels historical.
- **Cure.** The five coordinates re-anchored to `65f5f23`, the header scoped
  to "gate claims cited to disk; labelled sections are point-in-time records",
  and the currency note records pass 2 naming the drift cause. New
  `tests/Docs/CiBaselineClaimsDocsTest.php` (family shape) makes the doc's
  promise executable: gate headings must equal the ci.yml/build.yml job-key
  inventory (phantom or missing gates go red), and every `path:line` citation
  in the anchor table is re-derived from the file on disk — stale coordinates
  fail CI. Proven red-on-parent: three assertions trip on the pre-fix doc.
- **Non-attribution.** Zero workflow/src files touched; the phpstan prod
  suite carries exactly the inherited `AdminClient:2697 return.type` red
  (byte-ident at parent — this change adds no src). Suite census: 3190 at
  `65f5f23` → 3193 after the concurrently landed M-CONSOLE-29 (+3) → **3198
  here (+5 mine, assertions +61)**; 0 failures, Skipped 9, same 13
  pre-existing deprecation notices.

### Fixed — README feature intro to the shipped registry surface; coverage pin (review console__newcomer, M-CONSOLE-29) — 2026-10-29

- **The defect.** The intro undersold the product. The palette sentence
  still carried a Phase-5-era shortlist ("fuzzy-ranked actions — search,
  jump to any library, open any screen, log out, quit") while
  `App::staticActions()` registers twenty-three labels across the whole
  screen registry — fifteen of them appeared nowhere in the feature intro
  (measured at 65f5f23: Collections, Favorites, Federation Shares, Go to,
  Hide metrics, Home, Invite Links, Music Artists, Pause playback,
  Playlists, Resume playback, Servers, Shared With Me, Show metrics,
  Stop playback) — and the sentence deferred "the full list" to
  "*Controls* below", a section the README never had (heading census:
  Render modes / Requirements / Install / Run it / Test / Static
  analysis / License). The opening pitch omitted shipped subsystems
  outright: SyncPlay watch parties (`src/Api/SyncPlay/SyncPlayService.php`,
  `y`), casting (`CastScreen`), item downloads
  (`src/Download/DownloadService.php`, `d` on DetailScreen), the admin
  client (twenty-three `Admin*` screens, palette action gated on
  `isAdmin`), and trickplay scrub previews (`src/Media/TrickplayCache.php`
  + the lazy first-scrub fetch in `PlayerScreen`) — "trickplay" appeared
  zero times in the README. The *Run it* census also split four registry
  labels into non-literal forms ("Show/Hide metrics", "Resume/Pause").
- **Cure (docs-truth, every named feature cited).** The opening pitch now
  names the full subsystem set; the palette sentence enumerates all
  twenty-three registry labels verbatim — both ternary arms included
  (**Show metrics** / **Hide metrics**, **Resume playback** / **Pause
  playback**) — with the admin and playback-session gates stated honestly,
  the dynamic `Go to <library>` jump, and the dead *Controls* pointer
  replaced by its real targets (*Run it* + the manual's "Screens" table);
  the player paragraph gains its trickplay sprite thumbnails, scoped to
  "where the server publishes them" (the fetch is lazy and resolves null
  when absent); *Run it* labels un-split to match the registry.
- **Pin.** New `tests/Docs/ReadmeFeatureIntroDocsTest` (3 tests /
  3 assertions), sibling of `ScreenInventoryDocsTest` (which pins the
  same live `PaletteAction` census against the manual): the token walker
  re-derives every registered label from `src/App.php` and requires it in
  the README feature intro (H1 → "## Requirements"); the intro must name
  the shipped subsystems (command palette / direct-play / SyncPlay /
  watch party / trickplay / download / cast / admin); and every
  "under *X* below"-style pointer must resolve to a real README heading —
  the dead-pointer class itself is what that leg kills. Red-on-parent
  proven 3/3 at 65f5f23 (15-label gap; `trickplay` missing; `Controls`
  dead); tooth probe: deleting **Favorites** from the new enumeration
  reddens leg one, restore greens.
- **Measured gates.** Full suite 3190 → 3193 tests / 16935 → 16938
  assertions (+3/+3 = the new pin exact; isolated HEAD worktree + this
  change, skips 9 and the 13 pre-existing PHPUnit XML-deprecation notices
  unchanged on both sides); phpstan prod holds the byte-identical
  inherited AdminClient.php:2697 `return.type` red at parent and tip
  (zero src/ touched), phpstan-tests `[OK]`; phpcs clean under
  `phpcs.xml` (src), `phpcs-tests.xml` (tests) and PSR12 on the new test
  file; i18n checks untouched and green (no catalog or PHP string
  surface changed).

### Fixed — README answers "What is Phlix?" and states the server prerequisite; claims pin (review console__newcomer, M-CONSOLE-28) — 2026-10-29

- **The defect.** The README introduced itself as "A full-window terminal
  (TUI) client for Phlix" and never answered what Phlix is; the
  "## Requirements" list named PHP, ffmpeg and a terminal but not the one
  thing this client cannot run without — a running Phlix server — and no
  link to the server project appeared anywhere in the file (grep for
  `phlix-server`: zero hits at tip 72096b8; the review-era :132-138 block
  had drifted, the gap stayed).
- **Cure.** The opening paragraph now leads with the definition — an
  open-source media server you run yourself — names
  [`phlix-server`](https://github.com/detain/phlix-server) as the separate
  project, and surfaces the already-true "direct-plays HEVC/AV1 the
  browser can't" (previously buried in the Status block); the Requirements
  list leads with the server prerequisite and its link. All claims are
  estate-true (server README "Phlix Media Server", MPL-2.0; the client is a
  pure remote HTTP client — every screen reads through
  `src/Api/ApiClient.php`'s configured `baseUrl`, no local-library mode).
- **Pin.** New `tests/Docs/WhatIsPhlixDocsTest` (4 tests / 7 assertions):
  the opening block must carry the media-server definition, the
  run-it-yourself statement and the canonical server URL; the Requirements
  section's first bullet must state the server prerequisite with the link;
  the URL census is pinned at exactly its two sites. Red-on-parent proven
  (4/4 failures against the pristine 72096b8 README); mutation probes
  M1 strip opening link → 2 red, M2 strip definition → 1 red, M3 drop the
  bullet → 2 red; all restored green.
- **Measured gates.** Full suite 3186 → 3190 tests / 16928 → 16935
  assertions (+4/+7 = the new pin exact), Skipped 9 and the 13 pre-existing
  PHPUnit XML-deprecation notices unchanged on both sides; phpstan prod
  holds the byte-identical inherited AdminClient.php:2697 `return.type` red
  at parent and tip (zero src/ touched), phpstan-tests `[OK]`; phpcs clean
  under `phpcs.xml`, PSR12 and `phpcs-tests.xml`; i18n checks untouched and
  green (README is not a converted file).

### Fixed — README static-analysis posture to the measured suppression census; claims pin (review console__docmaint, M-CONSOLE-26) — 2026-10-29

- **The defect.** README.md claimed "**no baseline and no
  suppressions**: the whole tree is level-9 clean" and
  `docs/dev/client-console.md` repeated "No baseline, no
  suppressions." The enforced half is still true — no baseline FILE
  and no `ignoreErrors` in either config — but `src/` carries
  **five** inline `@phpstan-ignore-next-line property.onlyWritten`
  suppressions (three write-only SyncPlayService ingest mirrors,
  two re-stamped SyncPlayModal dimensions). The queue's "six" was
  the 60ac303-era count; the sixth died with the admin-server-restart
  rail at a944cab.
- **Liveness (mutation, not grep).** Stripping all five ignores moves
  CI-faithful `phpstan analyse` from the known 1 inherited
  AdminClient error to exactly 6 — every suppression still fires, so
  the cure is to document, not delete. The `$onMemberLeft` row
  discloses the genuine gap (the setter is wired from PlayerScreen
  yet the service never dispatches it) as an owed code-plane item,
  not silently fixed under a docs scope.
- **Cure.** Both doc surfaces reworded to the true posture plus a
  five-row table (site, field, why). New
  `tests/Docs/StaticAnalysisClaimsDocsTest` (5 tests / 23 assertions)
  re-derives the census from the tree — fail-loud on unclassifiable
  suppression shapes — and pins the README table, stated count,
  per-site identifiers, the false-phrasing ban, and the config-truth
  half in both directions. Measured probes: restoring the old README
  reddened 4 of 5 tests; planting a sixth live-shaped suppression
  reddened exactly the census and count pins with targeted messages.
- **r2 registry cure.** `ScreenGuideDocsTest`'s guide-file registry
  widened from src/ alone to src/ + tests/: the guide's new pointer
  names a REAL `tests/Docs/` file, which a src-only index could never
  resolve (full suite reddened 1/3186). The law is "a file named in
  the guide resolves against the tree"; teeth verified kept — a
  planted phantom name still reddens the guard.

### Fixed — Collections/Playlists rows to shipped membership truth; Series/Stats row attribution (review console__enduser, M-CONSOLE-22 round 2) — 2026-10-29

- **The defect.** The r1 review proved the Collections row ("items are
  added/removed from the collection's detail screen") a phantom:
  `DetailScreen.php` carries zero membership surface, the `a`/`r` keys on
  CollectionsScreen/PlaylistsScreen only raise the
  `ADD_ITEM_NOT_IMPLEMENTED` notice, and ApiClient's
  `addPlaylistItem`/`removePlaylistItem`/`createPlaylist` have no caller
  anywhere in `src/`. The notice string itself points at that detail
  screen — an in-code mislabel (ledger-noted; fixing the app's own toast
  text is out of this docs scope). The Playlists row inherited the claim
  through "with the same keys". Sweeping the same manual pass for
  sibling attribution phantoms caught two more: the **Series** row named
  a screen that does not exist (the seasons→episodes drill-down is
  nested Detail screens in container mode, breadcrumbs via the
  `Breadcrumbed` trait), and the **Stats** row claimed the metrics HUD
  (StatsScreen is a read-only server-statistics view; the HUD is the
  palette's Show/Hide metrics overlay).
- **Cure.** All four rows reworded to handler-walked truth — membership
  is managed on the server's web portal, not in the console. New
  `tests/Docs/CollectionsClaimsDocsTest` (7 tests / 20 assertions) pins
  the four row texts verbatim, bans the phantom sentence across the
  table, re-derives the notice + `a`/`r` producers on both list
  screens, fails loud the moment DetailScreen wording or a src/ caller
  contradicts "not implemented", and pins the row-label set so an orphan
  row (the Series failure mode) cannot reappear unannounced. Mutation
  probes measured: re-planting the phantom reddens exactly the pin +
  ban tests; a planted caller reddens the tripwire.
- **Also r1's MINOR-1** (Recently Watched "opens where you left off")
  was already cured forward in a2ae65d.

### Fixed — detail-screen manual promises to rendered truth; new claims-pin guard (review console__enduser, M-CONSOLE-23) — 2026-10-29

- **The defect.** The Overview bullet promised a "Media detail
  screen with cast, chapters, and recommendations" and the Screens
  row "Hero poster, metadata, synopsis, cast, chapters,
  recommendations" — but `src/Screen/DetailScreen.php` renders
  neither: chapters are the player's `C` overlay
  (`PlayerScreen::openChapterMenu()`) and "For You" recommendations
  are a separate screen opened only from the player's `w`
  (`OpenRecommendationsMsg` → `App`'s `RecommendationsScreen`). The
  two `cured-by` queue marks (C24/C25) were verified to belong to
  other items — `a96ae71` fixed the `detail.loading_hint` i18n key
  and `5e33c84` docs token naming — neither touched these lines.
  A third false site: the `docs/dev/client-console.md` file-tree
  comment listing "chapters" under DetailScreen.
- **Cure.** Bullet and Screens row reworded to what the column
  actually draws — hero, metadata, genres, crew, cast, a scrollable
  synopsis, and the conditional "More Like This" rail (`fetchSimilar()`
  → `GET /api/v1/media/{id}/similar`, rendered only when the server
  returns a non-empty list); the dev-doc comment now names the rail.
  The true chapter/`w` player story (M-CONSOLE-22-era rows) was left
  untouched.
- **Guard.** New `tests/Docs/DetailScreenClaimsDocsTest.php` (5
  tests/15 assertions): banned-vocabulary check on both detail-side
  promises, verbatim pins of the two sentences, a promised-noun →
  DetailScreen producer-regex map, the rail label pinned to
  `Lang::t('detail.more_like_this')` + the `en.php` string, and
  anti-erasure pins that the player `C`/`w` rows and their producers
  stay documented. Mutation-proven: parent's row re-added → 2 reds;
  `appendCastLines` renamed → 1 red; the `C` key row deleted → 1 red.
  Suite 3174 tests/16884 assertions (+5/+15 exact), 0 failures.

### Fixed — three manual screen rows to their handlers' exact truth (review console__enduser, M-CONSOLE-22 follow-through) — 2026-10-29

- **The defect.** The freshly added rows overstated or omitted key
  arms in three places: Recently Watched claimed `Enter` "opens where
  you left off" while the handler sends `OpenDetailMsg` (the recorded
  item's detail screen, not direct playback); Settings documented `s`
  to Servers but omitted its own `l` log-out arm (`SettingsScreen`
  dispatches `LogoutMsg`); and the Cast row hid that `C` on Detail is
  gated on the item having a stream URL.
- **Cure.** The three rows reworded to the code truth; no test pins
  moved (the strings were unpinned — verified by grep before editing).

### Fixed — env-var guide covers the real shipped inventory; poster/frame mode lists pinned to the entrypoint arms (review console__docmaint, M-CONSOLE-17 round 3) — 2026-10-29

- **The defect.** r2 left two r1 asks open. (a) `ScreenGuideDocsTest` still
  pinned the guide's Debugging section to a hand-kept six-item `BIN_ENV_CENSUS`
  and asserted guide-tokens ⊆ that list, so truthfully naming the two env vars
  `src/` actually honours — `PHLIX_POSTER_CONCURRENCY`
  (`PosterLoader::concurrencyLimit()`, default 6) and `PHLIX_DOWNLOAD_DIR`
  (`DownloadService::resolveDownloadDir()`, default `~/Downloads`) — reddened
  the guard: the undercount was structurally locked. (b) The poster `--mode`
  sentence omitted the shipped `iterm2` arm and claimed `frame` "likewise",
  overclaiming — bin/phlix:681 accepts nine poster modes
  (`auto|halfblock|quarterblock|ascii|ansi256|truecolor|sixel|kitty|iterm2`)
  while frame:690 accepts only five (`auto|halfblock|sixel|kitty|ascii`).
- **Cure.** The Debugging prose now names all eight shipped vars (six
  entrypoint + two src-side) with their real defaults. The mode sentence
  distinguishes poster's nine from frame's narrower five.
- **Pin.** `BIN_ENV_CENSUS` is deleted; `shippedEnvVars()` derives the
  inventory by scanning every quoted `'PHLIX_*'` literal across `bin/*` and
  `src/**/*.php` (entrypoint `getenv()`, src `$_ENV`/`$_SERVER`, and
  `const ENV_X = 'PHLIX_X'` registrations alike; the unquoted `PHLIX_VERSION`
  and docblock prose carry no quotes and correctly do not count). A second
  test re-blesses that derived list so a code change reddens a pin, not the
  guide. `testPosterAndFrameModeListsMatchTheEntrypointUsage()` parses both
  `--mode` arms straight out of `bin/phlix` and pins the guide's rendered list
  verbatim; if either arm moves, the fail-loud names it.
- **Mutation proofs.** (1) r2's bin-only guard run against the corrected guide
  reddens exactly on `names PHLIX_POSTER_CONCURRENCY, which no shipped
  entrypoint reads` — the structural lock, demonstrated; my derived guard passes
  it (8 guide tokens, all in census). (2) Planting `PHLIX_FAKE_TELEMETRY` in the
  guide → 1 failure (not-in-census). (3) Dropping `PHLIX_DOWNLOAD_DIR` from the
  guide → 1 failure (completeness arm). (4) Removing `iterm2` from the guide's
  mode sentence → 1 failure (verbatim-match). All restored to green (12 tests).
- **Suite.** Parent `8e8b068` full serial 3167/16858/9; with this round +2 tests
  /+11 assertions = 3169/16869/9 (two new tests, the rewritten env test's
  census re-bless + both-direction checks). phpstan tests corpus [OK]; src leg
  byte-identical to parent (lone pre-existing `AdminClient::alternatePosters()`
  return.type, unchanged — this lane touches no `src/`/`bin/` code); phpcs clean
  under both PSR-12 and `phpcs-tests.xml`; i18n gates pass (no catalog moved).

### Added — the manual now covers every live screen, pinned to the App registry (review console__enduser, M-CONSOLE-22) — 2026-10-29

- **The defect.** The "Screens" table in `docs/clients/console.md`
  stopped at fourteen rows while `src/App.php` instantiated 55 screen
  classes: ten live, reachable screens had no row anywhere in the manual
  — Collections, Playlists, Favorites, the watch-history screen
  (Recently Watched), Recommendations ("For You"), Servers, Shared With
  Me, Invite Links, Federation Shares, and Register (`s` at the login
  prompt) — plus Server Setup, Music Artists, and Cast as adjacent
  casualties. The Command palette row still advertised the Phase-5-era
  "search, jump, settings, stats, admin" shortlist while
  `staticActions()` ships 23 labels, and the Admin row named nine admin
  surfaces while the family is 23 screens deep.
- **Cure.** `docs/clients/console.md` gains honest rows for every
  missing screen — each describing only shipped behavior walked from the
  screen's own key handlers and endpoints (Collections/Playlists as the
  `GET /api/v1/collections` twins with `d` + typed-`delete` removal;
  Recommendations reached solely by the player's `w`; Servers'
  `Enter`/`h`/`r` switcher; Shared With Me, Invite Links and Federation
  Shares truthfully flagged as placeholder views whose handlers act
  without a rendered list) — rewrites the palette and Admin rows to the
  real inventories, teaches `s`-to-register in the first-run paragraph,
  and points Settings at Servers. README.md's palette sentence now
  enumerates the actual action set and defers to the manual's Screens
  table.
- **Pin.** `tests/Docs/ScreenInventoryDocsTest` (8 tests/10 assertions;
  red-on-parent 4 failures): the live registry is re-derived AT TEST
  RUNTIME from App's `new XScreen(` / `XScreen::create(` instantiation
  points via `token_get_all`, the palette census from its
  `PaletteAction` literals, and both pinned against the table — a screen
  or action added without its manual row reddens the suite (the
  PlayerKeyDocsTest / WatchStateLoopDocsTest doctrine).
- **Suite.** 3159 → 3167 tests, 16848 → 16858 assertions (+8/+10; local
  PHP 8.3, serial). phpstan unchanged (the one pre-existing
  `AdminClient::alternatePosters` return.type error is untouched — no
  src changes); phpcs clean under both standards; docs/tests-only commit,
  i18n gates inert.

### Added — the manual teaches the watch-state core daily loop, pinned to the shipped keys (review console__enduser, M-CONSOLE-21) — 2026-10-29

- **The defect.** `docs/clients/console.md` documented
  arrows/Enter/Esc/Tab/Ctrl-C/A–Z/`/`/Ctrl-K plus the player keys, and
  nothing for the watch-state layer: `r` rate, `F` favorite, `w` watched,
  `l` thumbs up, `j` thumbs down (`src/Screen/DetailScreen.php:457,461,465,
  470,475`), the home watch-filter cycle `a` All → Watched → Unwatched →
  ♥ Favorites (`src/Screen/BrowseScreen.php:620-642`) or the sidebar `H`
  history / `P` playlists jumps (`src/Screen/BrowseScreen.php:570-577`).
  The app itself teaches every one of them in its footers (`detail.hint`,
  `SIDEBAR_HINT`, `railsHint()`) — the product's self-documented help had
  outgrown the manual. README.md's global Keys line taught the sibling
  detail keys `p`/`C`/`d` while omitting the same loop.
- **Cure.** `docs/clients/console.md` gains a "Watching & ratings"
  section: the per-key table in footer case, the optimistic flip /
  revert-on-failed-save semantics (`DetailScreen` failure handlers,
  :222-301), the per-user server writes each key performs (favorite /
  watched / unwatched / like −2…+2 / ratings + singular-`/rating` clear —
  cross-read against the server's route registrations), and where the
  state surfaces back: Continue Watching and Up Next rails read your own
  positions, the ♥ Favorites filter rail serves from
  `/api/v1/users/me/favorites`, `H` opens the `recently-watched` history
  screen; **Most Watched** is named as the server-wide trending rail it
  is, not a personal one. README.md gains the matching "**Watch state**"
  paragraph.
- **Pin.** `tests/Docs/WatchStateLoopDocsTest` (8 tests/18 assertions;
  red-on-parent 4 errors): the loop key set is parsed from the
  `$msg->rune ===` handler arms via `token_get_all` and from the real
  footer strings — never restated from prose — and pinned against both
  docs surfaces; dropping a shipped key from a handler, a hint, either
  doc, or the pinned census reddens its row (the PlayerKeyDocsTest /
  TokenFileNameDocsTest doctrine).
- **Suite.** 3150 → 3158 tests, 16824 → 16842 assertions (local PHP 8.3,
  exact +8/+18 from the new class); Skipped 9 and the 13 pre-existing
  suite-wide PHPUnit deprecations held. Foreign reds present at the tip
  and untouched here: the `phpstan` prod leg
  (`AdminClient::alternatePosters` return.type, all three CI PHP legs) and
  `ScreenGuideDocsTest::testBootSignatureIsPinnedVerbatimToTheGuide`
  (CI 8.5 only).

### Fixed — dev-guide worked-example attribution corrected; boot-return pin made PHP 8.5-stable (review console__docmaint, M-CONSOLE-17 follow-through) — 2026-10-29

- **The defect.** Two residuals survived the landing commit above. (1) The
  rewrite kept the Step-1 example's "modeled on src/Screen/StatsScreen.php
  (the smallest shipped screen exercising the full contract): ... render
  through the shared Chrome" sentence, but StatsScreen renders a raw string
  (zero `Chrome::frame` in the file) and injects an `AdminClient` — the
  example's true shipped model is `RecommendationsScreen.php` (same
  `implements Model, Themed` + `use SubscriptionCapable, ThemedScreen`, the
  `(ApiClient, cols, rows)` constructor, the `Cmd::promise` fetch resolving
  to a Msg, the seven-argument `Chrome::frame` render, Esc returning through
  `NavigateBackMsg`). (2) CI's `test (8.5)` leg reddened on
  `ScreenGuideDocsTest::testBootSignatureIsPinnedVerbatimToTheGuide`: PHP 8.5
  reflection resolves the `): self` return literal to the declaring FQCN
  (`Phlix\Console\App`), where 8.3/8.4 report the token `self` — the
  `assertSame('self', ...)` could not pass on the 8.5 matrix leg (run
  40888044454; the 8.3/8.4 test legs and every other gate stayed green).
- **Cure.** Docs-truth: the docblock now cites `RecommendationsScreen.php`
  with the superlative dropped. Test-stability: the pinned return literal is
  re-read from the `src/App.php` source text (the version-stable ground
  truth), and reflection is demoted to a cross-check accepting `self` or
  `App::class`.
- **Pinned.** New `testTheWorkedExampleIsModeledOnAScreenThatMatchesTheExample`:
  whatever screen the guide's "modeled on src/Screen/X.php" names must itself
  exhibit the example's shape (`Chrome::frame(`, `ApiClient $api`,
  `Cmd::promise`, `NavigateBackMsg`) — red exactly on `StatsScreen`, green on
  the true model — so a future re-attribution that breaks the shape reddens
  the suite instead of shipping fiction.
- **Measured at HEAD.** Suite 3150 → 3151 tests (+1 exact), assertions
  16824 → 16830 (+6 exact), skips 9 → 9, exit 0 (the 13 PHPUnit deprecations
  are the pre-existing venue state). Red-on-parent: the attribution pin fails
  1 test against the landing document ("StatsScreen does not render through
  the shared Chrome..."); the boot-pin 8.5 red is attested by run 40888044454
  itself. phpstan (tests config) [OK]; phpcs exit 0 under both CI standards
  and bare PSR-12 on the changed test file. The src phpstan leg is UNCHANGED
  — its single `AdminClient::alternatePosters()` return.type error is the
  pre-existing red recorded by the M-CONSOLE-19 entry; this change touches no
  `src/` file. `resources/lang/` untouched (no catalog writes); both i18n
  gates re-run green.

### Fixed — the dev guide's "Adding a Screen" recipe rewritten to the shipped screen architecture (review console__docmaint, M-CONSOLE-17) — 2026-10-29

- **The defect.** `docs/dev/client-console.md` contradicted HEAD at eight
  points and the recipe could not compile: a `HomeScreen.php` tree entry
  (no such file — the home screen is `BrowseScreen.php`: poster rails +
  sidebar, Tab/Shift-Tab focus per its own docblock); `Loadable` described
  (and `use`-d in the example) as a trait — it is an interface extending
  `Model` declaring `isLoading()`, with no `load()` hook called anywhere; a
  `ThemedScreen` "Base for all screens" table row — it is the trait that
  implements the `Themed` interface; `App::boot(...): ?App` six params when
  src/App.php:280-288 takes seven (`?\Closure $audioFactory = null`) and
  returns `self`; registration into a `$messageHandlers` array in `boot()`
  — that mechanism never existed; real routing is the App-private
  `dispatch()` (App.php:341) plus `push(Route…)` openers; Step 3/4 told
  screens to call `$this->dispatch(...)` — private to the App, unreachable;
  `tests/phpunit/` — the suite is flat `tests/` (phpunit.xml.dist);
  `composer phpcs` — the scripts are `cs`/`cs:fix`.
- **Cure.** Docs-truth pass re-verified every named class, method, file and
  step against HEAD. The worked example now implements the real contract
  (`init(): \Closure`, `update(Msg): [self, ?\Closure]`, `view(): string`,
  `Cmd::promise` resolving to a Msg, `Chrome::frame($title, $body, $hint,
  $cols, $rows, $trail, $theme)`) and is modeled on `StatsScreen` with the
  grid/shimmer variant pointed at `BooksScreen`; Step 2 registers a `Route`
  enum case + one `instanceof` arm in `dispatch()` + a private `open*()`
  that pushes the frame and runs `init()`; Step 3 emits navigation via
  `Cmd::send(...)` returned from a screen; Step 4 splits global keys (App)
  from screen keys (`$msg->rune` / `SugarCraft\Core\KeyType`). Secondary
  truths fixed in the same file: the `Route.php` tree line (was "screen →
  URL mapping"), the diagram's "Routes Msg::* messages to Screen handlers"
  box, the `app->run()` phantom (the loop is sugar-core `Program`: bin/phlix
  :721-728), the `Config` key fields (real readonly props `servers`,
  `activeServerId`, `theme`, `slideshowInterval`; `serverUrl`/`renderMode`
  never existed as properties), the `Coerce::str(...) ?? ''` fiction
  (`str()` returns non-null `string`), the phantom `PHLIX_DEBUG` env var
  (replaced by the shipped six-var `PHLIX_*` census), and the counts
  (79 screen files, 23 admin screens).
- **Disposition vs review M4.** Seven of the eight enumerated points fixed
  as above. The eighth (":112 tree `SugarCraft/` — no such directory") is a
  FALSE POSITIVE: `src/SugarCraft/Screen/` exists and carries the local
  `Breadcrumbed.php` + `Themed.php` wrappers; the tree entry stays. The
  frame-diff claim ("App diffs against the previous frame") verified TRUE
  (sugar-core `Program.php:1423` line-diff repaint) and is kept, tightened
  to name `Program::renderFrame`.
- **Pinned.** New `tests/Docs/ScreenGuideDocsTest.php` (9 tests,
  tests/Docs venue): `App::boot()` and `Chrome::frame()` signatures
  re-derived by reflection and pinned verbatim into the guide; the ten-row
  screen-contract table must match `interface_exists`/`trait_exists` verdicts;
  every `*.php` token the guide names must exist under `src/` (the recipe's
  own example files allow-listed; `HomeScreen` regression-pinned forbidden);
  navigation truth — `dispatch()`/`push()` stay private, zero
  `$this->dispatch(` in `src/Screen/`, `$messageHandlers` forbidden;
  composer script keys, the flat `tests/` suite directory, and the
  `PHLIX_*` census pinned against the guide; and every complete PHP block
  in the guide is syntax-linted with the running binary (minimum-4
  non-vacuity assertion) so "the recipe cannot compile" is now structurally
  impossible for this document.
- **Measured at HEAD.** Suite 3141 → 3150 tests (+9 exact), assertions
  16584 → 16824 (+240 exact), skips 9 → 9, exit 0 (the "but there were
  issues" 13 PHPUnit deprecations are pre-existing venue state — identical
  on the sibling `TokenFileNameDocsTest` filter run). phpstan (tests config)
  [OK]; the src phpstan leg is UNCHANGED — its single error
  (`AdminClient::alternatePosters()` return.type) is the pre-existing red the
  M-CONSOLE-19 entry above records with the CI-greening lane owed; this lane
  touched no `src/` file. phpcs exit 0 under both CI standards and bare
  PSR-12 on the new test file (0 errors, 0 warnings after reflow). i18n:
  `resources/lang/` untouched (zero catalog writes — no en.php scope).

### Fixed — docs/upgrade/v1.md method cites and CLI compatibility claims match the shipped truth (review console__docmaint, M-CONSOLE-19) — 2026-10-29

- **The defect.** The guide told readers migrating off the `exists()` change to
  "update your logic to explicitly call `TokenStore::fileExists()`" — a method
  TokenStore never had (its public API is `default()`, `path()`, `exists()`,
  `load()`, `save()`, `clear()`, `getForServer()`, `setForServer()`,
  `removeForServer()`). It promised "existing workflows using `run`, `browse`,
  `search`, and other commands will continue to work" — `browse` and `search`
  were never CLI commands: bin/phlix's `switch ($cmd)` dispatches
  doctor/poster/frame/run/watch/version/help, and `git log --all -S` finds no
  browse/search case label in any commit. And its "Config Migration Data
  Format" section described a `var_export`-to-PHP-array storage change that
  never existed (zero `var_export` history in src/, bin/, or scripts/; the
  config is JSON).
- **Cure.** Docs-truth pass only: the `exists()` section now states the
  shipped semantics (cache-backed, valid-bundle-gated, expiry-unaware) and
  offers a working file-based check (`is_file()` on `TokenStore::path()`)
  instead of the phantom method; the fabricated var_export section was
  replaced by the REAL auto-migrations (legacy `server_url` → `servers[]` in
  `Config::load()`, legacy flat bundle → reserved `legacy` server id in
  TokenStore); the compatibility section became a "CLI Commands" section
  listing the actual command set, the unknown-command stderr + exit 2
  behavior, and the honest note that Browse and Search are in-app screens
  only. The feature list and PSR-12 sections are untouched (verified true).
- **Pinned.** New `tests/Docs/UpgradeGuideV1DocsTest.php` (5 tests): every
  `Class::method()` cite in the guide resolves via reflection; the guide's
  command list is checked against bin/phlix's `switch ($cmd)` case labels in
  both directions; `fileExists`, `browse`, and `search` are regression-pinned
  as forbidden; docs/upgrade/v1.md joined TokenFileNameDocsTest's DOC_FILES
  (+1 provider case). Red-on-parent: 4 of the 5 new tests are red against the
  pre-fix guide.
- **Measured at HEAD.** Suite 3135 → 3141 tests, assertions 16572 → 16584,
  skips 9 → 9, exit 0. phpstan (tests config) [OK]; phpcs exit 0 under both
  `phpcs-tests.xml` and bare PSR-12 on the two changed test files; i18n gates
  untouched and green. The prod `src/` phpstan leg is a PRE-EXISTING
  estate-wide CI red streak (sole error: `AdminClient::alternatePosters()`
  return.type at src/Api/Admin/AdminClient.php:2697, phpstan 2.3.1,
  byte-identical local vs CI at `6b640ad`; last all-green run `081ca0d`,
  2026-10-14) — untouched by this docs/test-only change; a separate
  CI-greening lane is owed.

### Fixed — the [Unreleased] SugarCraft-rename deferral note contradicted the tree; closed out forward-only (review console__docmaint, M-CONSOLE-15) — 2026-10-29

- **The defect.** The 2026-10-17 "SugarCraft dependency rename (campaign
  Phase 11.3)" entry still claimed — inside [Unreleased], where prose must
  be true at HEAD — that "`composer.lock` still pins the OLD package
  names", with the lock + `build/phlix.phar` regeneration "Deferred to
  campaign Phase 12 (known red window)". The same day the note was
  written, `5ba0c6b` ("build: regenerate composer.lock from renamed
  packagist slugs and rebuild phar") landed: "Lock moves 27 old-name
  entries to 26 new-name entries; zero candy-/honey- names remain", phar
  rebuilt 26,829,132 → 28,084,683 bytes. The note was never closed out and
  still sat in [Unreleased] at tip `230d1a23`.
- **Cure.** Docs + CHANGELOG only; no history deleted. Per the house
  forward-only precedent (the 2026-10-02 bridge entry's "superseded by the
  … entry above" correction), the stale deferral bullet now carries an
  inline supersession pointer naming `5ba0c6b`, and this entry records the
  closure. The surrounding Phase 11.3 narrative is untouched.
- **Measured at HEAD.** `grep -c '"name": "sugarcraft/candy-'
  composer.lock` = 0; `grep -c '"name": "sugarcraft/sugar-'` = 26;
  `build/phlix.phar` committed (last rebuilt at `2ee2735`, 8,981,867
  bytes — the dev-group de-grouping shrank it). No other surface repeats
  the claim: `grep -rn "still pins" docs/ README.md CHANGELOG.md` names
  only the now-pointered bullet.
- **No pin (disclosed).** The repo's `tests/Docs/` doctrine pins README /
  console.md / build.yml surfaces and never CHANGELOG prose (`grep -rln
  CHANGELOG tests/ scripts/` = zero hits); a self-referential pin would
  rot on the next top-prepend. This fix is deliberately prose-truth only.
- **Gates.** Full suite serial unchanged at 3129 tests / 16566 assertions
  (worktree-measured on this edit; zero PHP/test files touched → phpstan/
  phpcs N/A by lane rule).

### Fixed — player feature narrative omitted the shipped feature areas behind the six player keys (review console__docmaint, M-CONSOLE-16) — 2026-10-29

- **The defect.** The M-CONSOLE-02 cure (`d8c1d2d` + `7335185`) extended both
  player KEY lists — the README "**In the player:**" line and the
  docs/clients/console.md "Media playback" table — and pinned them against
  `PlayerScreen.php`'s handler arms via `PlayerKeyDocsTest`, but the FEATURE
  narrative stayed silent: the README Status blockquote narrated Phases 0–8
  plus Cast while naming none of SyncPlay watch parties, the in-player sleep
  timer, or the track pickers, and the console.md Overview "Key features"
  list omitted the same three shipped feature areas.
- **Cure.** Narrative prose only, quoted from shipped code: the README
  Status blockquote gained an "Also working today, inside the player"
  paragraph — `a`/shift-`S`/shift-`C` track pickers (chapter picks seek via
  `applyChapterSelection`; a subtitle pick turns the caption overlay on via
  `applySubtitleTrackSelection`), `v` transcode quality, `w` "For You"
  recommendations (`OpenRecommendationsMsg` → `App` screen push), the `y`
  SyncPlay watch-party modal (create a public/private room — `p` toggles —
  or join a listed one; room/member-count/sync-status overlay; the room's
  play/pause/seek commands drive the local player through
  `onSyncPlayPlaybackCommand`), and the `z` sleep timer (presets
  15/30/45/60/90/120 min, pause-on-expiry via `onSleepTimerFire`, last
  preset persisted through PlayerPrefs). console.md's Key features list
  gained the same three areas. No absent feature is promised: the local
  transport never broadcasts to the room (`SyncPlayService::sendPlay/
  sendPause/sendSeek` have zero production callers), so the prose claims
  only the receive-side sync truth.
- **Pin.** `PlayerKeyDocsTest` gained narrative-family pins:
  `NARRATIVE_FEATURE_AREAS` (SyncPlay watch parties / sleep timer / track
  pickers, each a list of required phrases) crossed with the two narrative
  surfaces (README Status blockquote line-range, console.md Overview
  section) as a 6-row data provider — all six red on the unfixed narrative
  (measured: filtered run went 3 tests / 3 assertions at parent to 9 / 9
  green after the cure), green on the cured prose.
- **Measured.** Full suite serial: 3135 tests / 16572 assertions / OK
  (parent derived from the exact +6 tests / +6 assertions pin delta; the
  13 PHPUnit deprecations and 9 skips held). phpstan tests config [OK];
  the prod config carries one INHERITED error (`src/Api/Admin/
  AdminClient.php:2697` `alternatePosters()` return shape — stash-proven
  present on the clean parent; this commit touches zero src/ files). phpcs
  `phpcs-tests.xml` and bare `PSR12` both clean on the changed test file.
  i18n gates untouched; no en.php edits.

### Added — `watch` documented to code truth in the two remaining doc surfaces (review console__docmaint, M-CONSOLE-14) — 2026-10-29

- **What.** `README.md` gained a "### Hub relay watch" section (between Render
  diagnostics and Test): the `watch` invocation, the four env vars
  (`PHLIX_HUB_URL`, `PHLIX_HUB_SERVER_ID`, `PHLIX_HUB_TOKEN`,
  `PHLIX_RELAY_TOKEN`), the overriding flags, the STDIN carriers, the
  deprecated-argv warning (CWE-214), `--once` / `--timeout` (default 30), and
  the 0/1/2 exit codes — every fact read off `bin/phlix` (usage :22-24, options
  :36-50, `parseWatchArgs` env ladder :292-296 with flag-over-env assignment,
  `runWatch` docblock exit codes :481-484) and `src/Api/SyncPlay/
  HubRelayConsumer.php` (`:8804`, `/syncplay/{server_id}`, :57/:300).
  `docs/dev/client-console.md:14` diagram now lists `watch` in the CLI
  entrypoint row.
- **Already cured, verified this lane.** `docs/clients/console.md` carries the
  four env-var rows (:130-133) and the "Hub relay consumer (`watch`)" usage
  section (:153+) since `93c2ad4` (M-CONSOLE-35); those sites were confirmed
  against code, not rewritten.
- **Teeth.** New `tests/Docs/WatchCommandDocsTest.php` (15 tests, 24
  assertions): derives the four names from `parseWatchArgs`' own `getenv()`
  calls plus a six-name `bin/phlix` env census, and pins README/console.md/
  dev-diagram against them; a diagram-column alignment pin guards the
  fixed-width box. Red-on-parent: exactly the 5 README errors + 1 diagram
  failure; the console.md/bin-phlix pins held green (cure attribution). Full
  suite serial 3114 → 3129 tests, 16542 → 16566 assertions (+15/+24 exact,
  the new file alone; 13 PHPUnit deprecations and 9 skips held at the
  worktree-measured `7b08db5` baseline); phpstan src error-set IDENTICAL to
  parent (the documented pre-existing `AdminClient::alternatePosters`
  return.type only — no `src/` changes this lane); phpstan-tests `[OK]`;
  phpcs `phpcs-tests.xml` and `PSR12` both exit 0 on the new test.

### Fixed — README extension requirements restated to measured truth, both directions (review console__devops, M-CONSOLE-13) — 2026-10-29

- **What.** `README.md` "## Requirements" claimed `pcntl` + `posix` were
  needed for "TTY/raw mode" while naming only `ext-gd` — yet `src/` and
  `bin/` contain zero `pcntl_*`/`posix_*` calls (raw mode is
  `shell_exec('stty …')` in sugar-core `Util/RawMode.php`; pcntl/posix exist
  only behind `function_exists` guards in vendor signal paths and in one
  self-skipping fork test), and composer's production graph hard-enforces
  **five** extensions no requirements doc mentioned: gd (sugar-image),
  mbstring (sugar-image, league/commonmark; 11 src/ files call `mb_*`
  directly), intl (sugar-core), ffi (sugar-pty, in the prod set via
  sugar-core/quickpick/animation), json (workerman; 14 src/ files).
  `ext-curl` — called unguarded by `doctor` (`bin/phlix:653`) and
  `run --selftest` (`:219`) — appeared nowhere either.
- **Cure.** docs-truth on all four surfaces: README source-install list,
  README PHAR list, `docs/clients/console.md` PHAR list, and both
  `build.yml` release-note bodies now carry the enforced five + curl
  diagnostics note, with pcntl/posix restated as *optional* (Ctrl-Z suspend,
  test harness). No code or `composer.json` change: the transitive platform
  requires already guard source installs; the PHAR lists matter because a
  PHAR bypasses composer's platform check entirely.
- **Guard.** New `tests/Docs/RuntimeRequirementsDocsTest` (7 tests, 35
  assertions) extends the in-repo docs-pin doctrine: the enforced set is
  *derived from composer.lock* (never restated from prose), every list is
  pinned to name it, pcntl/posix mentions must say "optional", and a src/
  scan pins "the TUI itself needs no curl". Mutation-proven: reverting the
  old prose reddens 5/7.
- **Suite.** 3107→3114 tests, 16507→16542 assertions, 13 deprecations/
  9 skips held; phpstan tests-L2 `[OK]`; phpcs both standards exit 0
  (PSR-12 too on the changed test file); i18n gates pass. Disclosed
  inherited: phpstan src-L9 carries 1 pre-existing `return.type` error at
  `src/Api/Admin/AdminClient.php:2697` — byte-identical on a clean worktree
  of parent `2ee27359`, zero src/ files in this diff.

### Fixed — humbug/box dev tree de-grouped from the production lock — `--no-dev` installs and the shipped PHAR are now clean (review console__seceng, M-CONSOLE-12) — 2026-10-29

- **What.** `humbug/box` sat correctly in `require-dev`, but the committed
  `composer.lock` pinned the whole box tree (55 packages: box, php-scoper,
  amphp/*, nikic/php-parser, jetbrains/phpstorm-stubs, symfony/console …) in
  the *production* group — so `composer install --no-dev` installed it and the
  checked-in `build/phlix.phar` bundled 1,651 of its files. Root cause:
  `league/commonmark` (prod, via sugar-markdown) requires
  `symfony/polyfill-php80`, and box 4.7 `replaces` it with `*` — the solver
  satisfies that prod edge through the already-installed dev root, structurally
  re-polluting on every re-resolve (reproduced with a from-scratch
  `composer update`; a fresh `--lock` regeneration is a no-op).
- **Cure.** Root `"provide": {"symfony/polyfill-php80/81/82": "*"}` — truthful
  on this platform (`php >= 8.3`: the polyfills are no-ops), giving the virtual
  a non-removable provider so the box tree falls back to the dev group. Lock
  re-derived via a scoped re-resolve (`composer update humbug/box
  league/commonmark -W` with temporary exact pins holding `nette/schema`
  `v1.3.6` / `nette/utils` `v4.1.5`): the prod group is now exactly the true
  46-package closure, versions AND dist refs byte-identical to tip (zero prod
  drift; the 13 dev-side bumps — box 4.7.0→4.7.1 et al. — are dev-tooling
  only). `provide` must stay in `composer.json`: deleting it lets the next
  `composer update` re-promote the tree.
- **Artifact.** `build/phlix.phar` rebuilt with box's default (no `--dev`)
  vendor selection: 4,416 → 1,920 files, 28,084,683 → 8,981,867 bytes (−68%);
  smoke legs `--version`→0, `bogus`→2, non-TTY `run`→CON-32 guard 2 pass on
  both the phar and a fresh `composer install --no-dev` tree, where vendor
  contains zero `humbug`/`amphp`/`jetbrains` directories.
- **Consumers.** `.github/workflows/build.yml` unaffected (box comes from
  setup-php `tools: box`, its `composer install --no-dev` leg now installs 46
  instead of 101 packages); `ci.yml` dev legs unaffected; `vendor/bin/box`
  still present after a plain `composer install` (`scripts/build-phar.sh`,
  README:196 and docs/clients/console.md:83 "declared dev dependency" guidance
  — copy-paste-true, now lock-honest too).
- **Gates.** `composer validate --strict` OK; phpunit full serial
  3,107/16,507/0F/9S — exact M11-era baseline (prod universe unchanged);
  phpstan-tests, phpcs (both configs), i18n checks green. PHPStan *prod* shows
  one inherited error (`AdminClient::alternatePosters()` return type, :2697,
  from d8a7218) — reproduced identically at tip with the old lock; CI's
  phpstan job has been red on master since the M9 era; re-green owed as its
  own lane.

### Fixed — exception→toast internals leak cured via typed sentences + prefix dedup (review console__copywriter, M-CONSOLE-11) — 2026-10-29

- **What.** `friendlyError()` echoed any throwable's raw `getMessage()` into
  the LoginScreen headline (PHP/socket/JSON prose under "Sign in to Phlix."),
  `ApiClient` minted `NetworkError('Could not reach the server: ' . raw)` —
  and because `NetworkError extends ApiError`, every `instanceof ApiError`
  display arm re-echoed that cURL/socket tail. Composition sites then nested
  two sentence prefixes ("Could not add server: Could not reach the server:
  cURL error 7…"), and two toasts embedded the config file path.
- **Cure.** New `src/I18n/ExceptionSentences.php` (150 lines, beside the
  `SyncPlayErrors` error→copy precedent): `sentence()` typed ladder —
  NetworkError → curated `errors.network` house sentence; ApiError wire prose
  (server-authored, boundary-sanitized by the C22 `untrustedForDisplay`
  lineage) passes through flattened and capped at 160 cells; any other
  throwable → generic `errors.unexpected` / screen-supplied curated fallback.
  `composed()` enforces never-nest-two-prefixes (network → curated sentence
  alone); `contextOnly()` parks local-throw prose in `error_log()` (with the
  `previous`-chain unwrap) and renders the context sentence alone.
- **Sites.** App.php: `friendlyError` delegation + 8 toasts (server URL/
  selection/add/remove saves — config path moved to the log context; boot
  merge/fetch fallbacks; settings sync notice). ApiClient :771/:1394 mint the
  curated message, raw tail rides `previous` only. PlayerScreen: playback
  start + 4 watch-room sites (load/create/join/socket-join) via `composed`/
  `contextOnly`. ParentalControlsScreen :173/:195 routed through the ladder
  (the `instanceof ApiError` guard let NetworkError through — NetworkError
  IS-A ApiError). SyncPlayService :730 producer fix: the
  `websocket_connect_failed` error-channel message is now composed-safe
  (code absent from `SyncPlayErrors::CODE_KEYS` → `localize()` passthrough
  made it a visible toast). Catalog: `errors.network` + `errors.unexpected`
  × 7 locales (61 keys, parity green).
- **Tests.** `tests/I18n/ExceptionSentencesTest.php` (14 tests): SQLSTATE /
  home-path / cURL / mpv hostile payload asserted absent from ladder output
  and from a `Chrome::frame` render capture; nested-prefix regex pin; wire
  prose byte-identity + 160-cell cap; 2 new AppTest pins (login headline
  integration + reflection-pinned `friendlyError`). 8 pre-fix pins flipped to
  the sanitized truth with rationale comments (red-flip-then-update, none
  deleted): 2× InviteLinks, 2× SharedWithMe, Stats, PlayerScreen factory,
  SyncPlay socket-join, SyncPlayService ladder-rung. Mutation matrix: M1
  friendlyError reversion → 1 named red; M2 source-fix reversion → 1 named
  red (exact-shape mint pin); M3 dedup removal → 1 named red; M4
  contextOnly-leak reintroduction → 3 named reds incl. the render probe.
- **Measured.** Suite 3091 → 3107 tests (+16), 16316 → 16507 assertions;
  0 failures, 9 skips held, 13 pre-existing deprecations held. Tracked diff
  +145/−41 across the 20 changed code/catalog/test files plus two new files
  (380 lines); this entry rides the same commit. phpstan (both configs):
  touched files 0 errors (`AdminClient.php` `return.type` red is
  pre-existing HEAD-state, non-attributed); phpcs src 0 errors / tests exit
  0; i18n catalogs + hardcoded-string gates pass.
- **Boundary.** Server wire prose stays (intentional, envelope-sanitized);
  the WS-inbound control-char path was already C22/C23/C24-cured and was not
  re-touched. The ~40 unguarded bare `new XFailedMsg($e->getMessage())` arms
  and the DetailScreen/DownloadService double-prefix composition are
  follow-up sweep input (their raw tails are now curated at the
  NetworkError source for the transport family; non-Api throwables remain
  raw there) — full derivation in `.reviews/fixlog/` (lane-C census).

### Fixed — group_limit_reached invented a sixth group synonym in every non-en catalog; family pin added (review console__copywriter, M-CONSOLE-10) — 2026-10-29

- **What.** `syncplay.group_limit_reached` was the lone key in each of the
  six non-en catalogs naming the group with a term no sibling key uses —
  per-locale census of the `syncplay.*` cluster (family term ← invented
  outlier at `:82`, pt-br `:87`): de *synchronisierte Gruppe* ←
  "Watch-Parties"; es *grupo de visionado* ← "salas de visionado"; fr
  *groupe synchronisé* ← "soirées synchronisées"; it *gruppo di visione*
  ← "sale di visione"; ja *ウォッチグループ* ← "ウォッチパーティ"; pt-br
  *grupo de exibição* ← "sessões sincronizadas". en itself is consistent
  ("watch groups") and stays untouched; the CON-07 room-vs-watch-group IA
  ruling is owner-gated, so no established family was renamed — the
  outlier keys were aligned TO their own catalogs' majority usage.
- **Cure.** de → 'Der Host hat die maximale Anzahl an synchronisierten
  Gruppen erreicht.'; es → 'El anfitrión alcanzó el límite de grupos de
  visionado.'; fr → 'L’hôte a atteint la limite de groupes
  synchronisés.'; it → 'L’host ha raggiunto il limite di gruppi di
  visione.'; ja → 'ホストのウォッチグループが上限に達しました。'; pt-br →
  'O anfitrião atingiu o limite de grupos de exibição.' — every locale's
  host term (the M-CONSOLE-08 `HOST_ROLE_TERMS` guard) and the typographic
  U+2019 apostrophes preserved; no placeholders in play. The two byte-pins
  of the old outlier text in `SyncPlayErrorsLocalizationTest` (es/ja rows
  of `group_limit_reached`) rode the cure to the new strings.
- **Pin.** New `LocaleCatalogsTest::testGroupLimitReachedUsesTheCatalogsOwnGroupTerm`
  (family term PRESENT in the key, one regex per locale) and
  `::testInventedGroupSynonymsAppearNowhereInCatalog` (absolute tripwire:
  the outlier family appears in NO value — pt-br's net is the compound
  'sessões sincronizadas' because bare 'sessão'/'sincronização' are
  legitimate elsewhere; probed at the parent catalogs). Deliberately not
  derived en-wide: siblings like ja not_in_group 'グループ' and de
  join_failed 'Der Gruppe' are legitimate anaphoric shortening, and
  broadening the family to every mention would be the owner-gated rename.
  Red-on-parent measured: all 12 new rows (2 × 6 locales) fail at `fc1611c`
  catalogs, exact reds `de/fr/es/it/ja/pt-br` quoted in the fixlog;
  mutation-toothed: reverting just the es string reddens exactly its 2
  rows, all 10 others stay green.
- **Gates.** Suite 3079→3091 tests / 15956→16316 assertions (+12/+360
  exact: 12 data rows; 6 family rows × 1 + 6 tripwire rows × 59 keys);
  Skipped 9, PHPUnit deprecations 13 held. `check-i18n-catalogs.php`
  (59 keys, placeholders in sync) and `check-i18n-hardcoded.php` green;
  phpstan prod + tests [OK] 0; phpcs `phpcs-tests.xml` and bare `PSR12`
  both exit 0 on the changed test files (`resources/` is outside both CI
  phpcs paths). Maybe-cured check: `0846107` (WS-ingest) and `5e33c84`
  (docs) touch no lang file — item was live.

### Fixed — Spanish missing-episodes warning said "sin ver" (unwatched); semantic missing/unwatched guard added (review console__copywriter, M-CONSOLE-09) — 2026-10-29

- **What.** `resources/lang/es.php:56–57` rendered the detail-screen
  missing-episodes warning as "episodio(s) sin ver" — literally "episode(s)
  left to watch" — where en says "episode(s) missing" (`en.php:53–54`): the
  opposite diagnostic for a gap-in-your-library alert, and contradicting
  es's own correct term for the same feature one section above (`:35` "No se
  pudieron cargar los episodios que faltan."). Sibling census, this key
  family only (`missing_episodes_one`/`_many`/`_load_failed`, both sides
  quoted): `de:56–57` "Folge fehlt"/"Folgen fehlen" ✓, `fr:56–57` "épisode
  manquant"/"épisodes manquants" ✓, `it:56–57` "episodio mancante"/"episodi
  mancanti" ✓, `ja:56–57` "欠落エピソード" ✓, `pt-br:61–62` "episódio
  faltante"/"episódios faltantes" ✓ — es the sole defect; nothing else to
  cure.
- **Cure.** es `sin ver` → the catalog's own established term: `'⚠  {count}
  episodio que falta'` / `'⚠  {count} episodios que faltan'` (`{count}`
  token and `⚠  ` double-space prefix preserved exactly; placeholder-parity
  check green). New
  `LocaleCatalogsTest::testMissingEpisodeStringsSayMissingAndNeverUnwatched`
  — the M-CONSOLE-08 host/guest idiom applied to this family, derived from
  en.php both directions: values whose English says "missing" must carry the
  locale's missing term (`que falt|faltante` / `manquant` / `fehl` /
  `mancant` / `faltant` / `欠落`); unwatched ("left to watch") stems may
  appear only where English itself says "unwatched" (en never does —
  absolute tripwires). pt-br's loose "a assistir" form deliberately excluded
  from the ban set: it collides with the benign "comece a assistir" copy in
  `recommendations.empty` (probed at `0ea9bdf`). Red→green proven (es red on
  the parent catalog at `0ea9bdf`, 5 siblings green = non-vacuous);
  mutation-toothed (reverting just `_many` reddens exactly the es row).
  Suite 3073→3079 tests / 15584→15956 assertions (+6/+372 exact, one row
  per locale), Skipped 9 and deprecations 13 held; phpstan-tests [OK] 0;
  phpcs BOTH standards 0E/0W on the changed test file (`resources/` is
  outside both CI phpcs paths); `check-i18n-catalogs.php` all 6 locales in
  sync (59 keys); `check-i18n-hardcoded` both legs pass. Pre-existing and
  untouched: phpstan src leg red at `src/Api/Admin/AdminClient.php:2697`
  (same error as recorded by the M-CONSOLE-08 entry).

### Fixed — Italian SyncPlay host-role strings said "ospite" (guest); semantic host/guest guard added (review console__copywriter, M-CONSOLE-08) — 2026-10-29

- **What.** All four host-role values in `resources/lang/it.php` used
  `ospite`, which means GUEST in Italian — the precise opposite of the
  permission being described (`:72` "Solo l'ospite del gruppo può farlo."
  for en "Only the group host can do that.", `:76` invalid-new-HOST,
  `:78` already-the-HOST, `:82` HOST-reached-limit). The both-directions
  census: en.php contains zero `guest` values, and `ospite` matched zero
  correctly-used lines — all 4 occurrences were inversions, none kept.
  The five sibling catalogs were probed for the same class and are clean
  (`de:72,76,78,82` Gruppenhost/Host, `es:72,76,78,82` anfitrión,
  `fr:72,76,78,82` hôte, `pt-br:77,81,83,87` anfitrião, `ja:72,76,78,82`
  ホスト; zero hits for huésped/invité/Gast/hóspede/ゲスト anywhere).
- **Cure.** `ospite` → `host` — the established Italian software loanword
  (register-consistent with ja ホスト and de Gruppenhost), grammar repaired
  per line (`l'ospite` → `l'host`, elision kept; `Il nuovo host richiesto`,
  `già l'host`, `L'host ha raggiunto`), one uniform term across it.php.
  `sale di visione` on `:82` left untouched — separate finding (M5 drift).
  New `LocaleCatalogsTest::testHostRoleStringsSayHostAndNeverTheGuestTerm`
  pins both directions per locale, derived from en.php: host-valued keys
  must match the locale's host-term pattern; guest-term stems
  (morphological: `ospit` catches ospite/ospiti) may never appear where
  English says no guest. Red→green proven (`it` red on the inverted
  catalog, 5 siblings green = non-vacuous); mutation-toothed (reverting
  one line reddens the pin). Suite 3067→3073 tests / 15206→15584
  assertions (+6/+378 exact), Skipped 9 held; phpstan-tests [OK] 0;
  phpcs src 0E and tests 0E; `check-i18n-catalogs.php` all 6 locales in
  sync (59 keys); `check-i18n-hardcoded` both legs pass. Pre-existing and
  untouched: phpstan src leg red at `src/Api/Admin/AdminClient.php:2697`
  (CI already red at the parent `7335185`); `it.php` file-header docblock
  phpcs error + 2 long-line warnings (`resources/` is outside both CI
  phpcs paths; byte-identical at HEAD).

### Fixed — the player-keys-inert prose now matches the code: TRANSPORT keys are inert, `q`/`Esc` always close (follow-up to M-CONSOLE-02) — 2026-10-29

- **What.** The prose shipped beside the six-key tables read "All player
  keys are inert until the stream is up" (`docs/clients/console.md`) /
  "Player keys are inert until the stream is up" (README). Overbroad:
  in `PlayerScreen::handleKey()` the `Escape`/`q`/`Q` exit arm (`:1411`–
  `:1423`) precedes the `if ($this->inner === null) return` guard
  (`:1424`, whose own comment reads "transport keys do nothing until the
  player is ready") — so `q`/`Esc` close the player even before the
  stream is up.
- **Cure.** Prose-only, both files, code's own vocabulary: "Transport
  keys are inert until the stream is up (`q`/`Esc` always close …)".
  `tests/Docs/PlayerKeyDocsTest.php` untouched and still green — the
  edit adds no census key and removes none (`q` was already listed in
  both tables); no `src/` change.

### Added — six shipped player keys documented in both key tables + a token-pinned docs guard (review console__a11y, M-CONSOLE-02) — 2026-10-29

- **What.** `a`/`A` (audio-track picker), `S` (subtitle-track picker),
  `C` (chapter picker), `w`/`W` ("For You" recommendations), `y`/`Y`
  (SyncPlay room modal) and `z`/`Z` (sleep timer) are handled by
  `src/Screen/PlayerScreen.php` (arms at `:1449`/`:1454`/`:1459`/`:1491`/
  `:1496`/`:1501` as of `8854648`) yet appeared in NO documentation table:
  the README "**In the player:**" line and the `docs/clients/console.md`
  "Media playback" table both stopped at the eight siblings. The shift
  semantics are also non-obvious — `S`/`C` are picker-only arms that
  consume the shift rune before the unshifted `s` skip / `c` captions
  arms can see them.
- **Cure.** Truth read off the code, into both tables: all six arms with
  their preconditions (the three pickers open only when the item actually
  has audio tracks / subtitle tracks / chapters; `z` presets are
  15/30/45/60/90/120 min and pause playback at expiry; `y`'s modal toggles
  public/private with `p` while naming a new room; every player key is
  inert until the stream is up; an open picker/modal captures keystrokes).
  New `tests/Docs/PlayerKeyDocsTest.php` parses the handler's single-letter
  `$msg->rune` arms from the token stream (census pin: 14 keys — `q f v a
  S C s o c n p w y z`) and requires each in both tables, failing loudly
  when a section marker moves. Red-on-old proof: against the `8854648` doc
  blobs it reports missing exactly `[a, S, C, w, y, z]`. Suite
  3061→3064 tests / 14068→14071 assertions (+3/+3 exact; skips 9 and the
  13 pre-existing deprecations held); phpstan-tests, both phpcs standards
  and the i18n checks green; `src/` untouched (the lone phpstan-prod red at
  `AdminClient::alternatePosters` pre-dates this change, fix lane owed).

### Fixed — one session-expiry sentence: three divergent wordings unified onto a single translated catalog key (review console__copywriter, M-CONSOLE-06) — 2026-10-29

- **What.** The same event ("your auth died") rendered in three different
  ways: the catalog sentence `Your session expired. Please sign in again.`
  (reachable via `recommendations.session_expired`/`detail.session_expired`
  on only those 2 screens), 44 hardcoded English copies of it (41 screens'
  `private const SESSION_EXPIRED`, `App::fetchTrackCmd`, `PhotosScreen`,
  plus a stray inline each in `LibraryScreen`/`BrowseScreen`), and the
  em-dash variant `Session expired — please log in again.` thrown from
  `ApiClient::authed()`/`retryOnAuthError()` — so under `de` a DetailScreen
  expiry spoke German while a Favorites/Cast/Search expiry stayed English,
  and a raw error pass-through could surface the third wording.
- **Cure.** One catalog key `auth.session_expired` (localized in all 7
  locale files, value held identical to the previous two keys' per-locale
  strings; the `recommendations.`/`detail.` keys removed). All 41 const
  screens now follow the pre-existing DetailScreen idiom
  (`SESSION_EXPIRED_KEY` + `Lang::t(...)`): 78 `self::SESSION_EXPIRED`
  call sites and 4 inline literals rewrapped, `Lang` import added to the
  41 screens plus `App.php`/`ApiClient.php`. The 2 `ApiClient` throw sites
  reuse the same key (per the review's sanctioned option) so the message
  that may reach a raw pass-through matches the catalog. Test pins
  re-anchored: `TranslatorTest` ×2 key refs, `ApiClientTest` refresh-failure
  message pin to the canonical sentence.
- **Teeth.** New `tests/I18n/SessionExpiredCopyTest.php`: the catalog must
  carry exactly one `*.session_expired` key; no English wording (canonical
  or retired em-dash) may exist as a literal anywhere under `src/`; every
  `new SessionExpiredMsg(` dispatch must wrap a `Lang::t(` call.

### Changed — CI baseline doc re-anchored to the commands the workflows actually run (review console__writer, M-CONSOLE-53) — 2026-10-29

- **What.** `docs/steps/ci-baseline-C9.md` had drifted against `ci.yml`/
  `build.yml`/`deps.yml`: the phpcs gate was described as bare `--standard=
  PSR12` on both trees when the live gate is `phpcs.xml` on `src/` plus
  `phpcs-tests.xml` on `tests/` (the 2026-10-02 split); the 66%/70% coverage
  floors were still stated as "enforced" though PHPUnit 11 schema rejection
  removed them on 2026-08-22 (`09b4228`/`0e3dbce`); the vendor-patches marker
  list still named bare `ORIGINAL` (deliberately dropped — legitimate vendor
  constants match it) and missed the ANSI-red `PATCH` marker; the phar smoke
  step claimed a `help` invocation that does not exist (actual: `--version`,
  unknown-command exit-2 probe, stub-server `run --selftest`); and the job
  inventory missed the phpstan-tests, i18n, composer-audit and release-latest
  gates that postdate C9, while the deps bullet still implied the workflow
  could land `composer.lock` on master (it opens a PR since `6fba622`).
- **Cure.** Docs follow code: every command claim restated to the workflow
  text at `759d7c5` with `path:line` citations so the next drift is catchable
  by reading the citation; historically-true records (20-run table, C9.1
  coverage snapshot) kept verbatim with dated notes. Workflow files untouched.

### Fixed — nested event-loop drains on the share/invite screens (review console__srphp, M-CONSOLE-36) — 2026-10-29

- **What.** `SharedWithMeScreen` and `InviteLinksScreen` each carried a
  ~28-line near-identical triplicate of a blocking drain: the command
  closures re-entered `Loop::get()->run()` and called `Loop::stop()` to wait
  synchronously on a hub promise. Program::scheduleCmd() invokes command
  closures from a tick INSIDE that same already-running global loop, and the
  shared StreamSelectLoop has no re-entry guard — the drain's `stop()` clears
  the single running flag and the app's event pump exits early, hijacking or
  killing the UI mid-frame. The drained closures then returned raw
  `array{0: self, 1: ?Closure}` values that Program's Msg dispatch can never
  consume, so every fetch and action was dead on arrival regardless.
- **Cure.** All six drain sites (init fetch + two key actions per screen)
  now use the estate idiom the other 30+ async UI sites already prove safe:
  the promise resolves to a `Loaded/Failed/ActionDone/Created/Revoked` Msg
  and is handed to the Program as an `AsyncCmd` via `Cmd::promise(...)`
  (`AdminUsersScreen` action pattern), riding the OUTER loop with the
  runtime-generation teardown guard. `src/Screen/` now contains zero
  references to `React\EventLoop` outside comments; `InviteLinksLoadedMsg`'s
  aspirational tuple `@param` was restated to the honest wire-passthrough
  `list<array<string, mixed>>` (the sibling `SharedWithMeLoadedMsg` already
  documented loose rows; consumers guard per-field as before).
- **Tests.** +15 tests / +66 assertions: `tests/Screen/SharedWithMeScreenTest`
  and `tests/Screen/InviteLinksScreenTest` assert every command yields an
  `AsyncCmd` (never a drained array) resolving to the right Msg, request
  routes, failure mapping, and no-op guards; each carries a `run()`-re-entry
  tripwire (`tests/Support/RunCountingLoopSpy` installed via `Loop::set()`)
  proving the command closure never re-enters the loop from a live tick.
  `tests/Unit/Screen/ScreensNeverReferenceEventLoopTest` is the class gate: a
  `token_get_all` walk forbidding any non-comment `EventLoop` reference in
  `src/Screen/`. Red-on-parent measured at 93c2ad4: 11 of the 15 new tests
  fail (8 AsyncCmd contract, 2 tripwires, 1 gate — gate names all six drain
  sites); all 15 green on the cure.
- **Gates.** Full serial suite 3046→3061 tests, 14002→14068 assertions,
  skipped 9→9, exit 0; phpstan CI-faithful shows only the pre-existing
  `AdminClient::alternatePosters` return-shape red (disclosed by the
  CONSOLE-34 review, not this lane); phpcs PSR12 + repo `phpcs.xml` on
  changed src and `phpcs-tests.xml` on all new tests: 0/0; i18n hardcoded +
  catalogs gates: exit 0 (no user-visible strings added).

### Security — `watch` bearer/JWT secrets pushed out of process argv (review console__secaudit, M-CONSOLE-35) — 2026-10-29

- **What.** `phlix watch --hub-token JWT --relay-token TOKEN` accepted live
  bearer secrets as command-line literals. Every argument is visible in
  `ps(1)` / `/proc/<pid>/cmdline` to all local users for the process
  lifetime and persists in shell history (CWE-214). The env fallbacks
  (`PHLIX_HUB_TOKEN` / `PHLIX_RELAY_TOKEN`) already existed but the silent
  flags were the documented headline form.
- **Cure (carrier ladder, safest first).** (1) env — unchanged, now the
  documented preference; (2) NEW `--hub-token-stdin` / `--relay-token-stdin`
  read the whole STDIN as the token (trailing newlines trimmed; pipes /
  process substitution / secret-file redirection; at most one may be used,
  an empty STDIN is a loud exit-2 usage error); (3) the literal argv flags
  are DEPRECATED but kept — breaking scripts silently is its own honesty
  violation — and now emit a `warning:` line on stderr on EVERY use
  (before validation, so it is never silent), naming the env variable and
  the stdin flag, never echoing the secret itself. Help text and
  `docs/clients/console.md` (previously watch carried no user-doc surface
  at all) document the ladder and add the four `PHLIX_*` env rows. The
  minting hop itself always sent the hub token as an `Authorization:`
  header (never argv/URL) and is untouched; no other in-repo process launch
  carries secrets (ffplay/mpv spawn is the vendor array-form `proc_open`,
  stream URLs are server-signed paths, the audit script is argv-array).
- **Tests.** `tests/BinPhlixTest.php` +9 tests/+37 assertions
  (3037→3046, 13965→14002; deprecations 13 and skips 9 held): both argv
  literals still function (exit 1, not 2) but warn from line start on
  stderr; the warning survives a later usage error; env and both stdin
  carriers are warning-silent and reach the dial ladder; empty-stdin →
  exit 2; both-stdin flags → exit 2; inline `--*-stdin=value` → exit 2;
  help-text pins. No secret literal ever appears in the child's output.
  Venue law noted in the test comment: the hub-token path mints via curl and
  this box's PHP 8.3.6+curl 8.5.0 segfaults at shutdown after any
  `curl_exec` (bare-`php -r` reproducible, zero repo code), so every
  hub-carrier test shadows minting with a relay token via a non-minting
  carrier; the ws dial (:8804, closed) is socket-only. No test executes
  `mintRelayToken()`.
- **Gates (measured).** Full suite serial OK 3046/14002 (+9/+37 exact vs
  `6fba622` worktree baseline); phpstan src error-set IDENTICAL to parent
  (1 pre-existing `AdminClient::alternatePosters` error since `5ba0c6b`,
  not this lane); phpstan-tests `[OK]`; phpcs both standards exit 0;
  `scripts/check-i18n-hardcoded.sh` clean (bin/phlix stderr prose is the
  file's raw-English convention; no `src/` strings added, catalogs held).

### Security — `dev-master` supply chain put under human review + whole-lock audit gate (review console__devops, M-CONSOLE-34) — 2026-10-29

- **What.** All 26 `sugarcraft/*` packages resolve to `dev-master`, and the
  weekly `Dependencies` workflow ran `composer update` and pushed the refreshed
  `composer.lock` **straight to `master`** whenever its gates were green — a
  recurring, unreviewed auto-merge of tens of upstream commits into mainline.
  This repository also had **no security audit gate at all**: nothing in CI
  ever asked whether a locked dependency carried a published advisory.
- **Tag investigation (measured 2026-10-29, read-only `git ls-remote` +
  ancestry over all 26 upstreams).** No upstream exposes a single semantic
  version tag; 23 carry one cosmetic `candy-*-final`-style tag each and
  `sugar-gallery`/`sugar-table`/`sugar-toast` carry none; **all 23 tags are
  non-ancestors of their repository's current `master`** (histories rewritten
  after tagging) and lag it by 4–85 commits, while **every locked ref sits on
  `master`**. Pinning a tag would freeze upstream-abandoned, off-mainline code
  and cannot interoperate with the remaining moving-dev-master siblings — the
  route is structurally impossible, so the constraint stays `dev-master` as a
  documented, measured exception (`docs/dev/dependencies.md`, per-package
  table). A coordinated upstream retag cascade is out of scope for this repo.
- **Cure (the two review points that were missing, now added).**
  `.github/workflows/deps.yml` no longer writes `master`: all gates run
  in-workflow against the proposed lock (a `Security Audit` step added) and the
  result is published as a **pull request** for human diff-review — the weekly
  refresh can propose, never merge. `.github/workflows/ci.yml` gained the
  estate-standard S246 whole-lock gate as its last job (`composer-audit:` →
  `php scripts/security-audit-check.php`): blocks on any advisory in runtime
  *or* development dependencies, prints and floors the audited corpus
  (129 locked = 101 require + 28 require-dev, floors 100/24), and fails loudly
  when it cannot measure (missing/unparseable lock or payload, unreachable
  advisory source, composer < 2.4). The script and its 30-test guard suite
  `tests/Unit/SecurityAuditCheckTest.php` (lane token
  `S440CONSOLEGUARDW9T4`) are ported from the hardened family template
  (phlix-plugin-trakt), with only floors and repository narrative adapted.
- **Evidence.** Gate live-green on the committed lock (`composer audit
  --locked`: 0 advisories, 0 abandoned, corpus line printed); mutation probes
  all killed — planted `continue-on-error` / `|| true` on the audit step and an
  inline-argv spawn each redden exactly their guard test, floor-lowering
  reddens `testFloorsMatchTheScript`, restored tree 30/30 green.

### Fixed — four admin-DTO render-boundary bypassers Coerce-routed (review console__tuispec, M-CONSOLE-33 r3) — 2026-10-29

- **What.** The r1 review proved (live `Input::view()` probes at 4608a92) that
  sugar-forms `Input` has NO render-boundary sanitizer — unlike sugar-table
  cells — so four DTO parse boundaries still painted server-sourced control
  sequences verbatim into admin edit prefills: `AccessSchedule::fromArray()`
  (raw `is_string` days loop → ParentalControlsScreen days field),
  `ServerSetting::render()` and the identical `PluginSettingField::render()`
  (`(string) json_encode($value)` / `(string) $value` raw → the two settings
  screens' `Input::withValue` arms), and `MusicArtist::fromArray()` albums
  (raw after `Coerce::map`, which is only an is_array gate). This entry also
  corrects the 2026-10-29 entry's "the only DTO bypassing the Coerce law"
  claim — it was not the only one (old entry kept verbatim per house law).
- **Cure (ingest, C22 law).** All four route through the `Coerce` SSOT: days
  and albums via `Coerce::stringList()`; the render arms via `Coerce::str()`
  plus a new `Coerce::sanitizedTree()` that neutralises every string key/leaf
  of json-typed values BEFORE encoding — required because `json_encode`
  leaves DEL/NUL raw and returns false on malformed UTF-8 (folding the whole
  display to `''`), while the deep-sanitised result is inert terminal text
  AND valid JSON for the editor round-trip (non-string leaves keep their
  types). Law note: `stringList` coerces scalars (int 7 → `'7'` survives;
  null/false fold), so the pre-existing `testFromArrayFiltersNonStringDays`
  contract legitimately changed and is renamed and re-pinned accordingly.
- **Proof.** `ServerTextControlInjectionTest` +6 tests / +51 assertions:
  hostile-payload-in-through-the-real-`Input::view()`-assert-inert pins for
  all four named chains (four carried from the r2 lane, their two exact-list
  expectations corrected to measured Sanitize behavior: raw-C1 residue
  `'2Jsat'`/`'31mNeon'`, `'7'` kept) plus a family guard that token-scans
  `src/Api/Dto/` and registry-pins every remaining `is_string` site per file
  with counts (the three table-cleared classes r1 re-probed inert through
  sugar-table's render boundary, and the two decode guards) — new sites,
  count drift, stale exemptions, and cured-class regressions all fail loud.
  Red measured with the src cures stashed: 4 bypasser pins + guard + helper
  = 5 failures + 1 error; green restored. Full suite 3007 tests / 13832
  assertions / 9 skipped, OK (13 PHPUnit deprecations identical to the
  worktree-measured 4608a92 baseline); phpstan unchanged (sole pre-existing
  AdminClient return.type) + tests config clean; phpcs src 0E/236W and tests
  0E/461W exactly at baseline; bare PSR12 on changed files 0 errors (the one
  >120 line is a pre-existing AccessSchedule docblock); i18n untouched
  (catalogs + hardcoded checks pass).

### Fixed — escape-sequence trust boundary completed per tuispec census (review console__tuispec) — 2026-10-29

- **What.** The C22 WS-sanitize chain closed the big ingest holes; this lane
  re-verified every site the review cites and finished the remaining
  same-class ones. At HEAD the three cited sites are NOT gaps:
  `I18n/Locale.php:102` reads env locale vars (terminal-owner, never server
  data) and `resolve()` returns only a SUPPORTED whitelist member — hostile
  bytes structurally cannot exit; `Download/DownloadService.php` filenames
  derive solely from a `Coerce::str`-sanitized URL through
  parse_url/basename (which cannot reconstitute an ESC), and the repo has no
  OSC-8 `file://` hyperlink producer at all; `Ui/Chrome.php` BRAND/BRAND_SEP
  are app constants feeding a sink the repo law deliberately does not
  re-escape. Each rationale is now documented at the site.
- **Cure (two real same-class gaps).** `SyncPlayService::handleGroupState()`
  stored WS `current_media_id`/`playback_state` as raw wire bytes — today
  they only feed `===` comparisons and `getSyncStatus()` literal arms, but
  ingest law says no raw server string enters state, so both now pass
  `Sanitize::untrustedForDisplay()` at parse. `Dto/Trickplay::fromArray()`
  and `ApiClient::trickplay()` parsed the two server URLs with a bare
  `is_string` — the only DTO bypassing the Coerce law — and those values
  round-trip the on-disk TrickplayCache blob; both parse sites collapsed
  into the one `fromArray` choke routed through `Coerce::nstr` (an empty or
  all-noise URL now folds to null, exactly the "unavailable" contract).
- **Proof.** `ServerTextControlInjectionTest` +2 tests / +14 assertions
  driving the REAL paths (private `handleMessage` GROUP_STATE dispatch, real
  `trickplay()` promise through `FakeTransport`); mutations: stripping the
  `playback_state` sanitizer or restoring the raw URL parse reddens exactly
  the new pins. Full suite 3001 tests / 13781 assertions / 9 skipped, OK;
  phpstan unchanged (sole pre-existing AdminClient return.type); phpcs
  src/tests standards and bare PSR12 on changed files 0 new findings; i18n
  untouched (catalogs + hardcoded checks pass).

### Fixed — `phlix run` refuses non-TTY stdin with a clean exit instead of freezing (review console__qa M-1) — 2026-10-29

- **What.** With stdin a pipe, a file, or `/dev/null`, `phlix run` never
  exited: the SugarCraft `Program` painted roughly 14KB of terminal escape
  sequences into the pipe while the input reader spun forever against a dead
  stream, and the process ended only when something external killed it
  (`timeout 10` → exit 124). The help text and README had always promised
  "needs a TTY" — but neither `bin/phlix` nor the vendored `Program` guarded
  it (vendor defaults `input = STDIN` unconditionally; its `openTty` option
  ships off).
- **Cure.** A guard at the boundary in `bin/phlix` — the only
  `new Program(...)->run()` launch site; the repo carries no composer-patches
  idiom, so the app layer holds the check instead of a vendor edit. When
  `stream_isatty(STDIN)` is false, the entrypoint prints a two-line usage
  error to STDERR mirroring the existing unknown-command/bad-watch-flag idiom
  and exits 2 — the code already reserved for that class of problem. The
  check sits after the `run --selftest` branch (the headless check stays
  eligible by design) and before container construction, so the refusal has
  zero config side effects. No fake headless run was shipped.
- **Proof.** `tests/BinPhlixTest.php` +3 tests / +11 assertions driving the
  real binary through `proc_open`: bare `run` on non-TTY stdin under a 10s
  deadline (SIGKILL past it) — red-before is an honest freeze catch (the
  deadline fires; pre-fix probe exited 124), green-after is exit 2, zero
  stdout bytes, and the "needs a TTY" refusal (the freeze leaked 14KB of
  escapes to stdout); `run --selftest` stays headless under the same
  non-TTY stdin with a quarantine HOME that stays empty; and a pin that the
  help-text promise matches the refusal wording. CI runners are non-TTY;
  the suite never spawned a bare `run` before, so the freeze was CI-invisible
  — the new test exercises that exact path and can only ever fail at CI,
  never hang it. Full suite 2996→2999 tests / 13756→13767 assertions
  (+3/+11 exact, measured in-lane at the clean tip and at head; 13
  pre-existing deprecations and 9 skips unchanged); phpstan tests corpus
  `[OK]`, prod count unchanged (the sole pre-existing
  `AdminClient::alternatePosters()` error, byte-ident to the CI red at
  `48385e7`); phpcs `phpcs-tests.xml` and strict `PSR12` on the changed test
  file 0 errors 0 warnings (the five long-line warnings from the first
  draft were wrapped); `bin/phlix` untouched pre-existing long lines, all
  guard lines ≤120; i18n catalogs untouched — the plain-ASCII STDERR error
  follows `bin/phlix`'s existing untranslated usage-error precedent and lies
  outside the pinned `check-i18n-hardcoded.php` file list.

### Fixed — SyncPlay WebSocket ingest sanitized at the parse boundary; vacuous C1 decode test rebuilt (fix campaign C22 r2) — 2026-10-29

- **What.** The r1 review (console__tuispec) found the C22 sanitize law
  stopped at the HTTP/DTO/relay boundaries and missed the SyncPlay WebSocket
  ingest. `SyncPlayService::handleError()` forwarded raw server
  `syncplay_error` frame text (and the deprecated
  `{type:'error', data:{message}}` envelope) to `onError`, and
  `SyncPlayErrors::localize()`'s unknown-code fallback passes that text
  through verbatim into PlayerScreen's error toast — a server-emitted (or
  hostile-room-name-echoed) ESC/CSI/OSC payload executes on the operator's
  terminal, since `Width::truncateAnsi` forwards control sequences with zero
  width. `handleInfo()` additionally built its member DTO through the raw
  `SyncPlayUser` constructor, bypassing the `Coerce` boundary every other
  member construction uses. And one of r1's twelve tests,
  `testDecodeSanitisesEightBitC1ErrorText`, was vacuous: its raw-`0x9b`
  payload made `json_encode` fail, the response body arrived empty, and the
  test passed on the generic fallback branch even with the ApiClient
  sanitizer reverted.
- **Cure.** Neutralize once where server WS text enters trusted state:
  `handleError()` runs the message through
  `Sanitize::untrustedForDisplay()` at the parse boundary — the placement
  mirrors `ApiClient::decode()`'s error-envelope extraction and
  `Coerce::str()`'s DTO construction (quoted in the docblock), so
  `SyncPlayErrors::localize` and every toast consumer render trusted text by
  construction. `handleInfo()` maps `member_id`/`member_name` onto
  `SyncPlayUser::fromArray()`'s canonical keys, making WS-built and
  HTTP-built member DTOs identically sanitized.
- **Proof.** `ServerTextControlInjectionTest` +3 tests / +18 assertions:
  hostile messages driven through the real `handleMessage` path for both the
  `syncplay_error` and legacy envelopes — including the verbatim-passthrough
  `localize` sink — plus a WS-vs-HTTP DTO sanitization-parity pin; red
  before the rework (3 of 3), green after. The C1 decode test is rebuilt on
  the well-formed UTF-8 spelling (`\xC2\x9B`) behind a `JSON_THROW_ON_ERROR`
  harness with a payload-survival assert; mutation-checked: reverting the
  ApiClient sanitizer reddens it (the vacuous version passed). Boundary
  probes: reverting the `handleError` sanitize reddens exactly its 2 tests;
  restoring the direct-ctor bypass reddens exactly the parity test. Full
  suite 2993→2996 tests / 13738→13756 assertions (+3 tests / +18 assertions
  exact over the clean `a96ae71` baseline — the figures first printed here,
  2991→2994 / 13717, were measured against a pre-C24/C25 sibling baseline
  and restated to the re-verified tip truth); phpstan error count
  unchanged (1 pre-existing in `AdminClient::alternatePosters()`), phpstan
  tests corpus `[OK]`, phpcs PSR12 on the changed files 0 errors (the one
  remaining warning is a pre-existing untouched line shifted by the import),
  i18n catalog/hardcoded checks green, `resources/lang` untouched. r1's
  entry absolute counts restated to the landed baselines.

### Fixed — detail loading frame shows the translated hint, not the raw catalog key (fix campaign C24) — 2026-10-29

- **What.** While a detail page loaded, the status bar rendered the literal
  string `detail.loading_hint` instead of the translated hint (`Esc  back`
  and its six mirrors). The loading branch of `DetailScreen::view()` passed
  `self::LOADING_HINT_KEY` straight to `Chrome::frame()`, which renders its
  hint argument verbatim — while the adjacent error and loaded branches
  correctly translate their hint keys through `Lang::t()`. The key itself
  was present and correct in all seven locale catalogs, so the parity
  checker could never see this; only the call site was wrong.
- **Cure.** `Lang::t(self::LOADING_HINT_KEY)` at the loading frame
  (`DetailScreen.php:392`), matching the protocol of both sibling frames.
  Census of every other `Chrome::frame()` hint site in the repo: all pass
  either `Lang::t(...)` results or literal display strings — no sibling of
  this raw-key mechanism remains.
- **Guard.** `tests/Screen/DetailScreenTest.php` gains two tests: the
  not-yet-loaded `view()` must contain the English hint text and must NOT
  contain the raw `detail.loading_hint` key (red pre-fix, verified against
  the rendered frame dump), and the key must exist in every locale catalog
  as a non-empty, non-key-shaped string (presence pin complementing
  `LocaleCatalogsTest`'s parity law). Suite 2988 → 2990 tests / 13694 →
  13733 assertions, zero failures.

### Changed — token-file name in the docs matches the file the code writes (fix campaign C25) — 2026-10-29

- **What.** Three doc sites named the auth token file `tokens.json` (plural)
  while `TokenStore::default()` persists `token.json` (singular): the
  config-directory table and the expired-session troubleshooting step in
  `docs/clients/console.md`, and the `TokenStore` section of
  `docs/dev/client-console.md`. The critical one was the troubleshooting
  step — a user with a dead session followed it, deleted a file that does
  not exist, and the re-auth silently did nothing.
- **Cure.** Docs follow code: all three sites now say `token.json`, the
  config table notes the file is keyed by server id, and the dev doc mirrors
  the actual legacy-format migration behavior (flat bundles are migrated
  under the `legacy` server id on load).
- **Guard.** `tests/Docs/TokenFileNameDocsTest.php` derives the real
  filename from `TokenStore::default()` under an isolated `XDG_CONFIG_HOME`
  (pure path building; the real config is never touched) and pins every doc
  that names the token file to it — wrong spelling red, doc moved red, and a
  future code rename red with a message directing docs+pin to update
  together. Mutation-proven: re-introducing the plural reddens exactly one
  test.

### Fixed — server-sourced text is sanitized for terminal injection at the parse boundary (fix campaign C22) — 2026-10-29

- **What.** ESC/CSI/OSC/DEL/C1 control sequences arriving inside
  server-/hub-controlled strings (media titles, SyncPlay room names,
  `success:false` error text, hub-relay `play_media` frame titles) reached the
  render surface and the `bin/phlix watch` stdout unsanitized. The framework
  documents the outcome (`SugarCraft\Core\Util\Sanitize`): a raw ESC desyncs
  the frame-diff renderer's line model, `\x1b[2J` erases regions mid-diff, OSC
  `\x1b]0;…\x07` re-titles the operator's window, and `Width::truncateAnsi`
  passes CSI sequences through untouched.
- **Cure.** Sanitize where server text is PARSED, never as a global output
  filter (the app's own styling still emits its intentional escapes):
  `Coerce::str()`/`Coerce::nstr()` — the choke point behind every DTO string
  field — now run `Sanitize::untrustedForDisplay()` (strips 7-bit and 8-bit
  escape sequences, raw + UTF-8-encoded C1, DEL, stray C0; folds CR to LF;
  values that neutralise to empty become absent), which makes the
  `Chrome::frame` header, `SyncPlayOverlay`, `NowPlayingBar`, and every toast
  fed by DTO text inert by construction. The same policy guards the
  `ApiError`/`AuthError` message extraction in `ApiClient::decode()`, the
  register-pending message, `AdminClient::reThrowFriendly()`, and all four
  string fields of `PendingPlayMediaCommand` at
  `HubRelayConsumer::parsePendingCommandFrame()` — the boundary that feeds both
  the TUI and `bin/phlix watch`'s raw `fwrite(STDOUT, …)`.
- **Proof.** `tests/Api/ServerTextControlInjectionTest` (12 tests, 36
  assertions): one hostile payload covering SGR, OSC, `CSI 2J`, BEL, DEL, NUL
  and both C1 spellings fed through Coerce (incl. `stringList`),
  `MediaItem`→`Chrome::frame`, `SyncPlayGroup`→`SyncPlayOverlay`, the decode
  error envelope, `reThrowFriendly`, and a relay frame — each boundary asserted
  terminal-inert with the visible payload preserved, plus a byte-identical
  no-op pin for legitimate multibyte/emoji text. Red before the fix (10 of 12
  failed), green after; full suite 2976→2988 tests (+12 exact), phpstan error
  count unchanged (1 pre-existing in `AdminClient::alternatePosters()`),
  phpcs src/tests/PSR12 all exit 0, i18n checks green. Historical entries
  above left verbatim.

### Changed — install docs re-anchored to the download paths that actually work (fix campaign C18) — 2026-10-29

- **What.** `README.md` and `docs/clients/console.md` advertised the PHAR
  install via `releases/latest/download/phlix.phar` and a `SHA256SUMS.txt`
  asset — both 404 as written: the only published release is the rolling
  **prerelease** tagged `latest` (GitHub's `/releases/latest` shortlink skips
  prereleases), and the build uploads the checksum asset as plain `SHA256SUMS`
  (`build.yml`). No stable `v*.*.*` release has ever been cut, so the primary
  end-user install path failed with an opaque `curl: (22) 404` for every
  self-hoster following the docs.
- **Cure.** Docs-truth only, no release/pipeline changes (cutting a versioned
  release stays an owner decision): both install sections now name the
  **verified working** tag-path URLs
  (`releases/download/latest/phlix.phar` + `…/SHA256SUMS`), state honestly that
  the artifact is a prerelease rolling `master` build and that a stable release
  does not exist yet, and replace the hand-compare checksum step with a real
  `sha256sum -c SHA256SUMS` flow. The dev path gains explicit verified steps
  (`git clone` over HTTPS — the SSH URL required keys — `composer install`,
  `bin/phlix run`); the build-from-source section drops the bogus
  `composer require --dev humbug/box` step (box is already a declared dev
  dependency and `scripts/build-phar.sh` auto-detects `vendor/bin/box`) and
  notes the `phar.readonly=0` requirement. The README version badge moves from
  `v/tag?sort=semver` (which resolved the non-semver `latest` tag) to
  `v/release?include_prereleases` linking the releases page.
- **Proof.** Every published command was probed live from scratch: tag-path
  downloads returned 200, `sha256sum -c SHA256SUMS` verified `phlix.phar: OK`,
  `php phlix.phar --version` and the in-tree `php bin/phlix --version` both
  print `phlix 1.0.0`, `bin/phlix bogus` exits 2, and `git ls-remote` resolves
  the HTTPS clone URL at master. Historical entries above left verbatim.

### Fixed — PSR-4 misplaced tree fatals two screens (fix campaign C10) — 2026-10-29

- **What.** `src/Phlix/Console/SugarCraft/Screen/{Breadcrumbed,Themed}.php` declared
  namespace `Phlix\Console\SugarCraft\Screen` while composer.json maps
  `"Phlix\\Console\\"` to `src/` — under that mapping the autoloader looks at
  `src/SugarCraft/Screen/`, so in a plain `composer install` checkout opening
  "Shared with me" or "Invite links" threw the unrecoverable
  `Error: Interface "Phlix\Console\SugarCraft\Screen\Breadcrumbed" not found` at
  class-link time (commit 4f24749 had moved the files the wrong way; phpstan
  scans src/ by path and the box PHAR embeds a forgiving classmap, so no gate
  saw it). Both files are back at their PSR-4-correct paths; composer.json — the
  authority — and the declared namespaces are unchanged, consumers untouched.
- **Cure.** `tests/Unit/AutoloadMapTest.php`: a static path↔namespace↔primary-symbol
  guard over the whole src/ tree plus link-time resolution probes for every
  `src/Screen/` class and the two affected screens — path/namespace drift can
  never ship silently again. Red on the old tree (2 errors + 2 failures), green
  after the move; suite 2969 → 2973 tests, 13646 → 13652 assertions, 0 errors,
  PSR-12 and phpstan (src + tests) clean on all changed files.

### Changed — SugarCraft dependency rename (campaign Phase 11.3) — 2026-10-17

- **What.** Every `sugarcraft/*` requirement moved to its renamed package:
  `candy-core`→`sugar-core`, `candy-focus`→`sugar-focus`, `candy-forms`→`sugar-forms`,
  `candy-fuzzy`→`sugar-fuzzy`, `candy-hermit`→`sugar-quickpick`, `candy-mosaic`→`sugar-image`,
  `candy-palette`→`sugar-colorprofile`, `candy-shine`→`sugar-markdown`,
  `candy-sprinkles`→`sugar-style`, `candy-testing`→`sugar-testing` (dev),
  `sugar-boxer`→`sugar-panels`, `sugar-crumbs`→`sugar-navstack`, `sugar-reel`→`sugar-video`,
  `sugar-veil`→`sugar-overlay`. Namespaces follow (`SugarCraft\Reel`→`\Video`,
  `\Mosaic`→`\Image`, `\Sprinkles`→`\Style`, `\Veil`→`\Overlay`, `\Boxer`→`\Panels`,
  `\Palette`→`\ColorProfile`, `\Hermit`→`\QuickPick`, `\Shine`→`\Markdown`,
  `\Crumbs`→`\NavStack`, `\Core` unchanged); require statements, `use` imports,
  doc-comments, the CI libsixel comment and the `SugarCraft\Reel\Tests` autoload map
  flipped accordingly. The phlix-owned `Phlix\Console\SugarCraft\Screen` traits are
  untouched — they were never a library namespace.
- **API follow-through.** The renamed upstream packages also drifted since the lock era:
  `Hermit` is now the `QuickPick` class, `RendererFactory`/`DecoderFactory` expose
  `new()` (no `create()`), and `tests/Reel/` became `tests/Video/` (namespace, phpstan
  exclude path). The vendor `stopAll` watchdog pin in `WorkermanEventBridgeTest` is now
  version-tolerant (asserts the crash stack names `Workerman\Worker::stopAll` instead of
  the 5.2.2-only `feof()` message) — workerman 5.2.3's crash site differs.
- **Deferred to campaign Phase 12 (known red window).** `composer.lock` still pins the
  OLD package names: a `composer update` cannot resolve the new names until the
  renamed packages' packagist `dev-master` syncs land (the 406-lag family), so the
  lockfile and the committed `build/phlix.phar` are regenerated at the P12 batch
  verification, together with a post-rename CI green run. Until then CI `composer
  install` resolves old code and the renamed namespaces are absent — expected, not a
  regression. (**Superseded — closed out the same day it was written:** `5ba0c6b`
  regenerated the lock from the renamed packagist slugs and rebuilt the phar
  ("27 old-name entries to 26 new-name entries; zero candy-/honey- names
  remain"); the premise never held past that commit. Text kept verbatim as
  history per the house forward-only precedent — see the 2026-10-29
  correction entry at the top of [Unreleased].)

### Fixed — settings saves send canonical lowercase theme ids to the server — 2026-10-14

- **The defect.** phlix-server commit `5072cb18` made `PUT
  /api/v1/users/me/settings` strict about `theme`: the value must be one of
  the canonical lowercase ids `nocturne` / `daylight` / `midnight` (or an
  enabled plugin-theme slug) or the request is rejected with a `400` before
  anything is written. The console had always PUT the *display* names from
  `Ui\Theme` — `Nocturne`, `Daylight`, `Midnight` — so every settings save
  from this client began failing with the "saved locally, server sync
  failed" toast. Per estate law the wire contract (lowercase ids) is the
  authority and the outdated client is the side that moves; no server-side
  normalization was added.
- **The fix.** `Theme` now carries a second, wire-facing identity: the
  constructor takes `(name, id)` and each factory passes its canonical id
  (`nocturne`, `daylight`, `midnight`) while `name` stays the display label
  for the UI. `App::saveSettings()` resolves the picker's display selection
  through `Theme::byName()` immediately and persists/PUTs `$theme->id`, so
  the local config and the wire payload agree on the lowercase id.
  `byName()` — the single parse point for any incoming theme value — now
  accepts both ids and display names case-insensitively and keeps its
  fail-soft fallback to `nocturne` for unknown, legacy (`phlix-dark`-era
  fossil) or absent server values. `SettingsScreen::create()` seeds the
  picker through the same parse, so a config holding a wire id renders the
  right human option.
- **Read path.** `Config::withServerSettings()` already preferred the server
  theme string when non-empty; with `byName()` id-tolerant, a GET returning
  `midnight` (new truth), `Midnight` (stale local), `phlix-dark` (fossil row)
  or nothing (key absent) all resolve sanely.
- **Known local-only field (unchanged behavior, now documented).** The same
  PUT carries `slideshow_interval`, which is *not* in the server's
  `allowedFields` for that endpoint — it is accepted-then-dropped and never
  echoed back, so the console's slideshow value remains local-only. The
  server dropping it was pre-existing theater; the `Config` docblock now
  states this honestly instead of implying persistence.
- **Tests.** New wire-law pins: `ThemeTest` pins `Theme::all()` ids to
  exactly `['nocturne','daylight','midnight']` and that each id is the
  lowercase of its display name; `AppTest` drives the real save path for all
  three themes and asserts the PUT body's `theme` is byte-exactly the
  lowercase id; `SettingsScreenTest` pins picker-seed normalization for
  wire ids, legacy display names, and fossils. Suite: 2962→**2969 tests**,
  13602→**13646 assertions**, 0 failures; phpstan (src + tests), phpcs
  (src + tests), and the i18n catalog gates all green.

### Fixed — `phpcs-tests.xml` exit-code prose aligned to the pinned phpcs 4.0.4 bitmask — 2026-10-02

- Prose-currency follow-up to the gate repair below, found by the 2026-10-02
  estate intelligence pass. The ruleset `<description>` and its `<!-- -->`
  comment framed exit codes in generic nonzero/zero terms (pre-4.x register),
  unlike the already-corrected `phpcs.xml` and `ci.yml`. Not a behavioral fix —
  the wording was not false for 4.0.4, just unpinned — so both passages now
  state the pinned 4.0.4 bitmask (`Util/ExitCode.php`: 1 fixable / 2 non-fixable
  / 3 both) and explicitly retire the 3.x-era "errors exit 2, warnings exit 1"
  reading. Verification: `phpcs --standard=phpcs-tests.xml -e` loads unchanged
  (60 sniffs); tests gate re-measured **0 errors / 460 warnings, exit 0**,
  identical to the pre-edit run; the `ci.yml` tests step command is untouched.

### Fixed — the phpcs CI gates were structurally dead; PSR-12 backlog burned down — 2026-10-02

- **The defect.** Both `ci.yml` phpcs steps had the shape
  `output=$(./vendor/bin/phpcs ...) || true` followed by a grep for
  `A TOTAL OF [0-9]+ ERROR`. Two independent killers: `|| true` swallowed
  the exit code, and the grepped line exists ONLY in phpcs's `summary`
  report — the default `full` report the steps actually ran prints
  `FOUND n ERRORS AFFECTING m LINES` — so the grep could never fire and the
  steps stayed green no matter what phpcs found. Same defect class as
  phlix-server S146; the 2026-10-02 estate audit proved it with a planted
  violation.
- **The hidden backlog, measured at 7a1763c** (phpcs 4.0.4, the PSR-12
  standard the steps named): `src/` **522 errors / 239 warnings** across
  517+ files, `tests/` **25 errors / 460 warnings**. Dominated by one
  systematic class: 524× `PSR12.Files.FileHeader.IncorrectOrder` — every
  file's `@copyright/@license` docblock sat *below* `declare(strict_types=1);`
  instead of directly after the opening tag (PSR-12 §4.1).
- **The burn (fa48fe5 + 3c9a745).** Scripted docblock-above-declare swaps in
  524 files — each verified line-multiset-identical to its parent, comments
  moved, nothing rewritten; phpcbf for the mechanical fixes (2 end-file
  newlines, 1 call-signature space, 12 inline empty closures in
  WorkermanEventBridgeTest); six secondary top-level classes split into
  PSR-4 files with no consumer import changes (same namespace):
  `PosterLoadResult`, `AcceleratorInfo`, and the four `Parental*Msg`; and the
  three underscore-prefixed private properties in `SyncPlayService` renamed
  camelCase, with the Reflection lookup string in `SyncPlayServiceTest` that
  pinned the old name.
- **After:** `src/` **0 errors / 236 warnings**, `tests/` **0 errors /
  460 warnings** — every residual warning is `Generic.Files.LineLength`
  (single-line UI hint constants whose bytes are the rendered text, long
  fluent chains, `@param` docblocks): a house-style tradeoff, resolved by
  policy, not silence.
- **The live gates (5740acd).** Steps now invoke phpcs and let the nonzero
  exit code gate — no `|| true`, no prose grep. New `phpcs-tests.xml` mirrors
  phlix-server's S128 doctrine (measure → classify → justify; warnings stay
  printed via `ignore_warnings_on_exit`, which is exit-code policy, not `-n`
  suppression): exactly one exclusion, `PSR1.Classes.ClassDeclaration.MultipleClasses`,
  for the two recording doubles in `AppTest.php` kept beside their consumer.
  `phpcs.xml` (previously dead config no CI step ever invoked) is now the src
  gate: zero sniff exclusions, documented warning policy. Exit-code semantics
  were read from the vendored `Util/ExitCode.php` — 4.0.4 uses a bitmask
  (1 fixable, 2 non-fixable, 3 both) — not trusted from 3.x-era prose.
  Falsifiability proven both directions: a planted violation reds each step;
  removing it greens them.
- **Behavior truth.** `phpunit` 2962 tests unchanged (the 9 PosterLoaderTest
  "settle" errors are host-only timing flakes, present identically pre- and
  post-, passing in isolation and in CI); `phpstan` src+tests corpora both
  OK; both i18n guards exit 0; `php -l` clean on all 530 touched files.

### Fixed — relay dial installs the per-connection errorHandler (22faf00 idiom mirrored) — 2026-10-02

- **Bridge-review advisory (ledger P3).** `HubRelayConsumer::connect()` dialed
  the relay socket without a per-connection `errorHandler`, so a throwing
  user callback on that socket funneled through `ConnectionInterface::error()`
  → `Worker::stopAll(250)` — whose child branch logs the original through
  `safeEcho`, dies on `feof(null)` (Worker.php:2427) eating the original, and
  lets the secondary TypeError reach the pump watchdog (c28395e): the bridge
  drains and the relay lane goes down for one bad handler. The syncplay socket
  got its attach at 22faf00; the relay socket never did.
- **The fix.** `connect()` now sets `$socket->errorHandler` to a logging
  closure at the dial site — same shape and register as SyncPlayService's:
  the connection stays alive, handler bugs log instead of funneling. Placed
  per-consumer rather than in `WebSocketDialer`: the dialer is scoped to
  URL→socket construction translation, and every consumer test injects a
  fake connection factory that bypasses the dialer — a dialer-level attach
  could not be pinned by the tests that protect the behavior.
- **The pin.** `HubRelayConsumerTest::testDialInstallsLoggingErrorHandlerOnTheConnection`
  mirrors the syncplay-path test; mutation (attach removed) reds exactly it.

### Fixed — pump watchdog: interactive bridge gains watch-path error-handler parity — 2026-10-02

- **Review follow-up (P2 close, second-pass addendum of the bridge review).**
  `runWatch` arms its `Select` loop with an error handler that "logs and stops
  cleanly instead of escaping run() with a raw trace" (bin/phlix:479-484);
  `WorkermanEventBridge::installOnce()` armed none, so a throwing
  pump-dispatched callback only reached `safeCall`'s bare `error_log()`
  fallback and the pump kept ticking. On the vendor funnel
  `ConnectionInterface::error()` (no per-connection `errorHandler`) →
  `Worker::stopAll(250, $e)` the child branch first `log()`s the original
  through `safeEcho`, which dies on `feof(self::$outputStream)`
  (Worker.php:2427 — `runAll()` never ran interactively, the stream is null):
  the original exception is swallowed, `stopAll` aborts before arming its
  exit watchdog, and the TUI continues pumping in `STATUS_SHUTDOWN`.
- **The watchdog.** `installOnce()` now arms
  `setErrorHandler(self::pumpWatchdog($bridge))`: it `error_log`s the
  throwable in full (class, message, file:line, stack — the diagnostics the
  zombie eats; into `error_log`'s configured sink, never the TUI canvas),
  then calls the existing non-throwing `stop()` — the bridge's
  cancel-everything path — so timers/streams/signals drain and late work is
  refused loudly. **No `exit()`:** interactive code never ends the process
  from a loop callback — the SugarCraft Program owns shutdown; the watch
  path likewise stops its loop rather than killing the process. A bare
  bridge (unit tests, manual construction) keeps the log-only fallback,
  now pinned as-is.
- **P3 was already closed at tip; verified, not re-done.** The addendum's
  doc-correction follow-up — the `deleteAllTimer()` "diverges from Select"
  claim — had landed the same evening in the `stop()`-never-throws rework:
  docblock corrected to "FAITHFUL to Select", the 2026-10-01 entry carries
  the forward-only dated correction above, and
  `testSelectDeleteAllTimerNeverStopsItsLoopPinningTheDocblockTruth` pins
  the vendor truth (Select.php:376-381 resets only the scheduler/eventTimer
  tables). Repo-wide grep: no residual divergence claim survives in
  src/tests/docs/bin.
- **Proof.** `WorkermanEventBridgeTest` grows 26 → 29: watchdog install via
  `installOnce` (original message/class/file:line in `error_log`, both
  timers drained, the periodic can never re-fire, late work refused);
  no-handler baseline pinned (logs, never drains, pump stays alive); and
  the reviewer's exact funnel reproduced against real vendor code —
  dispatched callback → `Worker::stopAll(250, $original)` with
  `Worker::$outputStream = null` — where the `feof(): Argument #1 ($stream)
  must be of type resource, null given` TypeError escapes `stopAll` into the
  watchdog, which logs it and drains the pump (the test's
  `popToErrorHandler` mirrors the handler `safeEcho` leaks when its
  pre-`feof` `set_error_handler` is skipped). The original's absence from
  that capture is asserted honestly — no loop-layer handler can resurrect
  what `stopAll` ate; the per-connection `errorHandler` (22faf00) prevents
  the funnel, the watchdog guarantees the drain. Mutation proof: deleting
  the `setErrorHandler` line from `installOnce` reddens both new watchdog
  tests with exactly the zombie's signature (fallback log, no marker, no
  drain). Full suite 2961/13599/9-skip (baseline 2958/13577/9 measured at
  22faf00 +3 tests/+22 assertions, 13 pre-existing deprecations unchanged),
  phpstan both corpora OK, phpcs PSR12 zero-new vs tip.

### Fixed — bridge `stop()` never throws: Workerman's stop-before-exit shutdown ordering — 2026-10-02

- **The zombie-TUI defect (P2, adversarial review of the 2026-10-01 bridge
  lane).** `WorkermanEventBridge::stop()` threw by design, mirroring `run()`.
  But `Worker::stopAll()`'s child branch arms a `Timer::repeat(0.01, …)`
  watchdog whose callback calls `static::$globalEvent?->stop()` **before**
  its `try { exit($code) }` (Worker.php:2087-2090, verified on the pinned
  v5.2.2). Every vendor callback-error funnel — `ConnectionInterface::error()`
  with no `errorHandler` set routes to `Worker::stopAll(250, $e)`
  (ConnectionInterface.php:174-186) — therefore passed through the throwing
  `stop()`: the throw was caught by the bridge's own `safeCall`, `exit()`
  became unreachable, the 0.01 s watchdog kept re-firing, and the process
  rotted as a `STATUS_SHUTDOWN` TUI flooding `error_log` at ~100 Hz.
  Pre-bridge, `Select::stop()` (Select.php:443-457) merely cancelled and
  returned, so the identical path exited cleanly.
- **New semantics (owner-sanctioned middle option).** `stop()` is now
  non-throwing, idempotent and self-cancelling: it cancels every
  bridge-owned timer, removes every read/write stream and signal from the
  React loop, clears its tables and sets a stopped flag — then **late work
  is refused loudly**: `delay()`/`repeat()`/`onReadable()`/`onWritable()`/
  `onSignal()` after `stop()` throw `LogicException` ("… after stop() …")
  instead of silently leaking onto a winding-down loop. `run()` keeps
  throwing (grep-proven no vendor or console path calls it).
  `deleteAllTimer()` and the `off*` family stay honest no-ops post-stop.
- **Per-connection errorHandler.** `SyncPlayService::connectWebSocket()`
  installs a logging `$conn->errorHandler` at dial so a throwing user
  callback logs (`error_log`, stdout is the TUI canvas) instead of funnelling
  to `Worker::stopAll(250)` — the same "one bad handler must not kill the
  pump" law the bridge's `safeCall` already enforces. `error()` only reads
  the property; no handshake/TLS path touches it (verified across
  v5.2.2 vendor sources).
- **Finding 2 (P3 doc truth).** The bridge docblock and the 2026-10-01
  entry below both claimed `deleteAllTimer()` diverges from `Select` by not
  stopping the loop. FALSE for v5.2.2: `Select::deleteAllTimer()`
  (Select.php:376-381) only resets the scheduler/eventTimer tables —
  stopping is `Select::stop()`'s job, which itself calls `deleteAllTimer()`.
  The bridge behavior was always faithful; only the prose was wrong.
- **Proof.** `WorkermanEventBridgeTest` grows 21 → 26: the old
  `stop()`-throws pin converted to the new no-throw semantics (it pinned
  the design being fixed); full-detach sweep (timers/streams/signals,
  zero loop involvement); idempotent double-stop detaches once; the actual
  vendor failure mode reproduced — `repeat(0.01)` callback calling
  `Worker::$globalEvent?->stop()` then reaching the exit marker, with
  self-cancel freezing the flood (mutation-proven red if `stop()` throws
  again: `Failed asserting that null is identical to 250`); late-work
  refusal across all five registration methods; and a vendor-parity pin
  proving `Select::deleteAllTimer()` never stops its own loop.
  `SyncPlayServiceTest` +1: dial installs the logging errorHandler and the
  installed handler writes to `error_log` rather than funnelling.

### Fixed — interactive SyncPlay WS is alive: Workerman→React event bridge + fail-loud stopguard — 2026-10-01

- **Prod-dead feature, root cause.** The interactive TUI (`bin/phlix run`)
  pumps a React loop; Workerman's `AsyncTcpConnection::connect()` resolves
  its loop from `Worker::getEventLoop()` → `Worker::$globalEvent`, which
  only the `watch` command installs (`new Select()`, bin/phlix:479). In
  interactive mode it is null → TypeError at connect → **every** room join
  silently failed regardless of port/URL, the rejection discarded with the
  join promise (`PlayerScreen::onSyncPlayJoined` dropped it; the
  reconnect-ladder timer callback called `connectWebSocket()` bare).
- **The bridge (owner ruling option-a).** New `Api\SyncPlay\WorkermanEventBridge`
  implements the pinned Workerman v5.2.2 `Events\EventInterface`
  (15 methods) by delegating every concern to an injected React
  `LoopInterface`: one int-id timer table mirroring `Select` (delay/repeat
  share a counter; fired one-shots self-forget), streams keyed by
  `(int)$stream`, signals via `addSignal`/`removeSignal` (wrapper stored so
  React's identity contract holds), and a `safeCall` mirror — one throwing
  handler must never kill the shared pump (fallback `error_log`, not
  `echo`: stdout is the TUI canvas). `run()` refuses (throws): the
  SugarCraft Program owns the loop. (This entry originally had `stop()`
  refusing too and `deleteAllTimer()` a "documented divergence from
  Select" — both superseded by the 2026-10-02 entry above: `stop()` must
  never throw per Worker.php:2087 shutdown ordering, and
  `deleteAllTimer()` is in fact **faithful** to Select, which likewise
  never stops its loop there — Select.php:376-381.)
- **Boot install.** `App::openPlayer()` — the sole interactive
  `SyncPlayService` construction site — calls
  `WorkermanEventBridge::installOnce(Loop::get())` before wiring the
  service: idempotent, never clobbers a pump that exists (watch path
  untouched), and on first install wires `Workerman\Timer::init($bridge)`
  so vendor-internal `Timer::add` (e.g. `AsyncTcpConnection::reconnect`)
  rides the React loop instead of falling through to the SIGALRM/throw
  path in a no-worker process.
- **Fail-loud stopguard.** `WebSocketDialer::dial()` (the shared choke
  point for BOTH consumers — SyncPlayService and HubRelayConsumer default
  factories) calls `WorkermanEventBridge::pumpOrThrow()` after the
  deterministic URL-argument check: `Worker::$globalEvent === null` now
  throws `RuntimeException` with the actionable law ("interactive boot
  installs it — this call site bypassed App boot") instead of dying as a
  swallowed vendor TypeError. Predicate is "a pump exists for this
  process" — a watch-path `Select` passes; unit fakes injected through the
  `connectionFactory` seam bypass dialing entirely.
- **Silence closed at both seams.** `onSyncPlayJoined` now returns a
  `Cmd::promise` mapping join rejection → `SyncPlayFailedMsg` (rides the
  Cmd channel, not the service-callback queue, which writes to the
  instance captured at init time while `update()` stacks clones);
  `onSyncPlayFailed` with the modal closed toasts instead of vanishing;
  the reconnect-ladder rung wraps the dial in try/catch → `onError
  ('websocket_connect_failed', …)` and climbs the next rung (technical,
  non-localized text per the `reconnect_exhausted` precedent).
- **Proof.** New `WorkermanEventBridgeTest` (21 tests: exact interval
  delegation, shared id space, arg forwarding, periodic repeat/cancel,
  oneshot self-forget, stream re-registration ordering, signal listener
  identity, safeCall routing on both timer+stream paths, run/stop refusal
  (stop's pin superseded same day — see the 2026-10-02 entry above),
  installOnce set/idempotent/non-clobber + Timer-facade wiring,
  pumpOrThrow accept/reject). New `tests/Api/RecordingEventLoop.php`
  double (sibling of `FakeClockLoop`, which stays stream-strict).
  `WebSocketDialerTest` +3 (stopguard throws loud, argument errors precede
  the pump guard, Select pump accepted) with setUp/tearDown install
  discipline. `SyncPlayServiceTest` +2 (default factory + null pump → loud
  join rejection; throwing ladder rung reports and climbs, never escapes).
  `PlayerScreenSyncPlayTest` +2 (rejection surfaces via the Cmd promise
  end-to-end; failed-msg toasts without modal). `AppTest` +1 (player open
  installs the bridge exactly once). The forked
  `SyncPlayBearerCarrierHandshakeTest` child now completes the REAL WS
  handshake through the bridge over the React loop (was Select) —
  end-to-end pump proof; the TLS round-trip test stays on Select.
- **Contracts pin (v0.5.2-era consumer).** Vendored
  `server-route-manifest.json` re-vendored byte-for-byte from contracts
  tag `v0.5.3` (410→412 tuples, md5 `915796837d38a77733c169996d97640c`,
  server `758f9149`); gate pins rotated. `SyncPlayErrors` gains the 8th
  dotted row `syncplay.queue_limit_exceeded` (LIVE on server since
  `7baa398a`) with the catalog key added to all 7 locales
  (order-sensitive parity holds: 60 keys).

### Fixed — `wss://` dials no longer throw at construction (Workerman has no `Protocols\Wss`) — 2026-09-30

- **Prod-latent crash class, both WS consumers.** The pinned Workerman
  v5.2.2 `AsyncTcpConnection` maps a URL scheme to EITHER a built-in
  transport (`BUILD_IN_TRANSPORTS` = tcp/udp/unix/ssl/sslv2/sslv3/tls,
  AsyncTcpConnection.php:58-68) OR a `\Workerman\Protocols\{Scheme}`
  framing class (:212-224). `Protocols\Ws` exists, `Protocols\Wss` does
  NOT — so every `https:`-based dial (`SyncPlayService::buildWebSocketUrl()`
  and `HubRelayConsumer::buildRelayUrl()` both emit `wss://` for https
  bases) died with `RuntimeException("class \Protocols\Wss not exist")`
  at construction, before a socket existed. Only `ws://` (http bases)
  worked; the syncplay path had shipped unreviewed for this class.
- **Fix at the shared construction seam.** New `Api\SyncPlay\WebSocketDialer`
  implements the vendor's sanctioned two-layer TLS form — keep the `ws`
  scheme so `Protocols\Ws` framing is chosen, then set the PUBLIC
  `$transport = 'ssl'` (:87) before `connect()`; `connect()` dials a plain
  `tcp://` async socket with the stream context applied (:289-292) and
  `checkConnection()` upgrades it via `doSslHandshake()` /
  `stream_socket_enable_crypto()` when `transport === 'ssl'` (:476-478 →
  TcpConnection.php:924). SNI (`SNI_enabled`) and CA-verified
  `peer_name` are pinned to the URL host by default; loosening trust is
  only possible through the explicit `$sslOptions` parameter (harness use).
  `SyncPlayService`'s default connection factory and a new
  `HubRelayConsumer` construction seam (mirrors the S414 SyncPlayService
  seam) both route through it. Canonical `wss://` URL notation is
  unchanged at both builders (spec-facing tests keep pinning it).
- **Proof.** New `WebSocketDialerTest` (12 tests: raw vendor `wss://`
  construction throws — the regression pin; dialer returns an armed
  connection with `ssl` transport, `Protocols\Ws` framing, host/port/URI
  and SNI context intact; both consumers' default factories route through
  the dialer; non-ws schemes fail fast). New
  `SyncPlayTlsDialRoundTripTest` forks a real self-signed TLS WebSocket
  responder (b620e4e1/04a1590 harness idiom) and drives the PRODUCTION
  `createRoom()` path over `wss://`: real TLS handshake, HTTP upgrade read
  inside the encrypted layer (bearer offer present, no `token=`, clean
  room path), 101, buffered join replayed masked through TLS, and
  post-101 `group_state` delivered to the production handler. Mutation
  proof: swapping the dialer back to `new AsyncTcpConnection($url)` in the
  harness turns the test red at the dial (child dies at construction).
- **Gates:** PHPUnit 2923 tests green (13 new), phpstan level 9 (src) and
  tests corpus clean, phpcs baseline-parity (533 errors / 696 warnings —
  zero new), i18n hardcoded + catalogs checks pass.

### Changed — SyncPlay handshake carrier flipped to `Sec-WebSocket-Protocol: bearer, <jwt>` — 2026-09-30

- **The JWT left the URL.** `buildWebSocketUrl()` no longer appends the
  retired `?token=` query carrier (phlix-server 424c14d0,
  `docs/dev/WEBSOCKET_AUTH_CARRIERS.md`); the room path, host derivation and
  dedicated `:8097` worker law are unchanged. `connectWebSocket()` now sets
  the vendor-native `websocketClientProtocol` property on the
  `AsyncTcpConnection` **before** `connect()`, so the vendored client
  handshake builder (`Protocols\Ws::sendHandshake()`, Workerman v5.2.2
  Ws.php:372/:379) emits `Sec-WebSocket-Protocol: bearer, <jwt>`.
- **No transitional fallback needed — echo-strictness verdict: LENIENT.**
  The pinned vendor's 101 handler (`Ws::dealHandshake()`, Ws.php:398-417)
  validates only `Sec-WebSocket-Accept` and never inspects the echoed
  protocol, so the server's subset echo of `bearer` (without the credential)
  is accepted. Mutation-proven: forcing the vendor client to strict-match the
  full offer turns the new harness red at exactly the buffered-join read.
- **Empty-token posture:** no token ⇒ no offer header and no query — the
  server rejects such a handshake pre-101 exactly as it rejected the legacy
  empty `?token=`; there is no silent-anonymous path.
- **Proof:** new `SyncPlayBearerCarrierHandshakeTest` (forked loopback
  listener, phlix-server `NatPmpClientTest` idiom b620e4e1) asserts on wire
  bytes: offer header present, `token=` absent from the whole handshake,
  clean `/syncplay/{room}` request target, and the pre-101-buffered join
  frame flushed by the vendor ONLY after the subset-echo 101 — then drives a
  post-handshake `group_state` through the production message path in the
  child. Wire-shape/gate pins updated honestly (new dial literal, carrier
  pins, no-token pin); `docs/clients/console.md` ws_port row updated.

### Fixed — Workerman callback arity (websocket_error was a guaranteed TypeError) — 2026-09-29

- **`SyncPlayService`'s socket handlers now match Workerman's real invocation
  shapes.** The vendor emits `onError` as `($connection, $code, $message)`
  (`AsyncTcpConnection.php:336`, plus the `SEND_FAIL` paths in
  `TcpConnection.php:513/:1130`) and `onMessage` as `($connection, $data)`
  (`TcpConnection.php:715-831`). The service had registered `onError` typed
  `function (\Throwable $e)` and `onMessage` typed `function (string $_, …)`
  — every genuine connection error was therefore a `TypeError` thrown inside
  the vendor's own try/catch, routed to `ConnectionInterface::error()` and
  `Worker::stopAll(250)`: the service-level `websocket_error` event never
  reached `PlayerScreen`, the initial-dial join promise never rejected, and
  inbound frames would have died the same way. `onError` now accepts the
  vendor triple (house convention per `HubRelayConsumer`), normalizes the
  `(int code, mixed message)` pair into one detail line via
  `describeConnectionError()`, and still surfaces `websocket_error`, rejects
  the pending dial promise, and enters the capped ladder. `onMessage` takes
  the connection object as its first parameter. `onConnect`/`onClose` were
  verified safe (the vendor sends one argument; zero-parameter closures
  legally ignore it), and the stale-socket identity guards are unchanged.
- **Test-freeze retired.** The 2d3557a ladder pins invoked the handlers with
  the WRONG (Throwable/string) shapes and stayed green while production threw;
  they now fire with the vendor arity, plus three new pins: a
  `ReflectionMethod::emitError` drive of the vendor's real error funnel
  (proving both the surfaced event and the once-dead initial-dial rejection),
  a vendor-shaped `onMessage` dispatch, and a closure-signature assertion so
  a wrong-type first parameter cannot silently recur.

### Fixed — independent audit (syncplay WS endpoint law, capped ladder, genre chips) — 2026-09-29

- **C1 — SyncPlay WS dial moved to the dedicated worker.** `buildWebSocketUrl()`
  used to re-use the HTTP API base verbatim (`https://host:8096/api/v1/syncplay/…`),
  a socket the server never opens: the SyncPlay WebSocket lives on the plaintext
  `:8097` worker (server `config/server.php` → `websocket.port`). The console now
  dials `ws(s)://{host}:{ws_port}/syncplay/{room}?token=…` — scheme/host derived
  from the configured base, port defaulting to `SyncPlayService::DEFAULT_WS_PORT`
  (8097), per-server override via the new `ws_port` key on `config.json` server
  entries (`ServerEntry::$wsPort`, parsed at the Config boundary, documented in
  `docs/clients/console.md`). The `?token=` query carrier is current server
  handshake law; the bearer sub-protocol (tracked estate debt) is deliberately
  NOT adopted.
- **C6 — the reconnect ladder is now capped exponential and actually wired.**
  The old fixed-3s one-shot silently stalled (retry close never re-armed because
  `reconnecting` stayed true) and never ran at all in production (the service was
  constructed without a loop). `App::openPlayer()` now passes the global react
  loop, and `attemptReconnect()` mirrors the `HubRelayConsumer` budget idiom:
  1s×2^n, at most `MAX_RECONNECT_ATTEMPTS` (5), terminal loud
  `reconnect_exhausted` onError instead of an infinite retry, budget reset per
  (re)join, stale-socket identity guards on close/error, and single-arm ping
  timer across reconnects.
- **C3 — genre facet chips are reachable.** `FilterBar` renders facet chips but
  had no control that could select one (`toggleGenre()` had zero call sites) —
  a dead affordance. GENRE joins the Tab cycle only while facets are present
  (`cycleSize()` 3↔4): ←/→ move the chip cursor, Space/Enter toggles the chip
  under it. New `filter.genre_label` key added to all seven locale catalogs in
  the same commit (key-set parity gate is order-sensitive).
- **Gate re-pins (legitimate moves, same commit):** `GateScanner::ws()` now
  recognises the dedicated-worker literal (path pin `/api/v1/syncplay/{P}` →
  `/syncplay/{P}`) and `SWEEP_TOKEN_COUNTS` drops the
  `src/Api/SyncPlay/SyncPlayService.php` entry — the file no longer contains a
  single `/api/v1` string token (its old one WAS the bug). Frames pin stays 6.
  `SyncPlayEnvelopeWireShapeTest` asserts the new URL shape.
- Gates: phpunit 2897/2897 (13 pre-existing deprecations, 9 pre-existing skips),
  phpstan OK, phpcs net −4 warnings vs the pristine same-tree control,
  `i18n:hardcoded` + `i18n:catalogs` pass, no new PHP files.

### Changed — W111 (cs47b): route-manifest CONTENT re-vendor (404→410 tuples) — 2026-09-17

- **cs#47 currency re-vendor (lane cs47b) — CONTENT regen, console is NOT pure this
  wave.** `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from
  the `@phlix/contracts` canonical master export (untagged regen #34), and
  `ServerRouteManifestGateTest.php` advances its header cite, `EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, the contracts-drift failure message and the three tuple
  counts (`total`/route-count/unique all RISEN 404→410 — six new quick-connect
  pairing + consent-gated telemetry routes) in the same commit. The client-side
  inventories are HELD: `SWEEP_TOKEN_COUNTS` (`src/Api/ApiClient.php` = 68),
  `PER_CLASS_ANCHORS` (67) and `TOTAL_COMPARED` (223) — no console request site
  moved this wave; none of the six new server tuples is issued client-side. The CS46
  ritual token constant is untouched; full suite 2776 tests / 11586 assertions with
  zero delta vs the pristine same-tree control.

### Changed — W96 (cs46c): route-manifest PROVENANCE re-vendor (404 tuples — route bytes unmoved) — 2026-09-15

- **cs#46 currency re-vendor (lane cs46c) — PROVENANCE-only, not a content regen.**
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from the
  `@phlix/contracts` canonical master export (untagged regen #33), and
  `ServerRouteManifestGateTest.php` advances its header cite, `EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, and the contracts-drift failure message to the current
  phlix-server master tip in the same commit. The server span since the previous
  pin is bundle-only: no route-registration file and nothing under the server's
  `include/` or `src/` moved, so the `[method, path]` tuples are byte-for-byte
  identical and the `total`/route-count/unique all HOLD at 404 — only the embedded
  provenance moves, which rotates the vendored blob while the described route
  surface is unchanged. The client-side inventories are HELD:
  `SWEEP_TOKEN_COUNTS` (`src/Api/ApiClient.php` = 68), `PER_CLASS_ANCHORS` (67),
  and `TOTAL_COMPARED` (223) — no client request site moved this wave. The two
  S240 `isServed` query-rail assertions (album / artist) survive untouched as
  preservation checks. `build/phlix.phar` is NOT rebuilt (cs#42/#43/#44/#45
  precedent — the gate tolerates the stale phar). This leg additionally plants the
  lane ritual token as a public class constant beside the pins (cs#46 ceremony;
  no behavior change), giving this repo a survival-token home for the merge gate.

### Changed — W85 (cs45): route-manifest PROVENANCE re-pin (404 tuples — route bytes unmoved) — 2026-09-13

- **cs#45 currency cascade (lane cs45) — PROVENANCE-only, not a content regen.**
  `tests/fixtures/server-route-manifest.json` re-vendored byte-identical from the
  `@phlix/contracts` canonical master export (untagged regen #32), and
  `ServerRouteManifestGateTest.php` advances its header cite, `EXPECTED_MD5`,
  `EXPECTED_SERVER_SHA`, and the contracts-drift failure message to the current
  phlix-server master tip in the same commit. The server span since the previous
  pin is bundle-only: no route-registration file and nothing under the server's
  `include/` or `src/` moved, so the `[method, path]` tuples are byte-for-byte
  identical and the `total`/route-count/unique all HOLD at 404. Only the embedded
  provenance moves, which rotates the vendored blob while the described route
  surface is unchanged. The client-side inventories are HELD:
  `SWEEP_TOKEN_COUNTS` (`src/Api/ApiClient.php` = 68), `PER_CLASS_ANCHORS` (67),
  and `TOTAL_COMPARED` (223) — no client request site moved this wave. The two
  S240 `isServed` query-rail assertions (album / artist) survive untouched as
  preservation checks. `build/phlix.phar` is NOT rebuilt (cs#42/#43/#44
  precedent — the gate tolerates the stale phar). No survival-token home in this
  repo — the wave token lives in its two verified code homes.

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
