# Dependency policy: the `sugarcraft/*` `dev-master` chain

## The constraint, stated plainly

All 26 `sugarcraft/*` packages in `composer.lock` resolve to `dev-master`:
16 are required directly (`sugar-core`, `sugar-focus`, `sugar-forms`,
`sugar-fuzzy`, `sugar-quickpick`, `sugar-image`, `sugar-colorprofile`,
`sugar-markdown`, `sugar-style`, `sugar-panels`, `sugar-navstack`,
`sugar-gallery`, `sugar-video`, `sugar-table`, `sugar-toast`,
`sugar-overlay`), 1 is a direct development dependency (`sugar-testing`), and
9 arrive transitively (`sugar-animation`, `sugar-ansi-parser`, `sugar-async`,
`sugar-cellbuffer`, `sugar-constraints`, `sugar-input`, `sugar-mouse`,
`sugar-pty`, `sugar-spring`) — and every transitive edge upstream also
requests `dev-master`, so the whole chain floats with upstream `master`
together. This is a deliberate, measured exception, not drift.

## Why no version pin is possible (measured 2026-10-29)

The upstreams live at `github.com/sugarcraft/*` (mirror of Packagist
`sugarcraft/*`). Read-only survey of all 26 (`git ls-remote --tags`,
ancestor checks against each repository's current `master`):

* **No repository exposes a single semantic-version tag.** None of `vX.Y.Z`,
  `^`, `~`, or `stable` exists anywhere in the chain.
* 23 repositories carry exactly one cosmetic tag each (`candy-*-final`,
  `sugar-*-final`, `honey-bounce-final`); `sugar-gallery`, `sugar-table` and
  `sugar-toast` carry none at all.
* **Every one of those 23 tags is NOT an ancestor of its repository's current
  `master`** (`git merge-base --is-ancestor` fails for all; upstream histories
  were rewritten after the tags were cut). Each tag also lags `master` by
  4–85 commits.
* **Every locked reference in `composer.lock` IS an ancestor of `master`** —
  the suite-validated code is on each upstream's mainline, and only on the
  mainline.

A tag pin is therefore not available: pinning one would freeze code upstream
itself abandoned (off-mainline, 4–85 commits stale) and cannot coherently
interoperate with 25 siblings pinned to moving `dev-master` anyway. The estate
has no push access to create proper tags (a coordinated upstream retag
cascade is out of scope for this repository), so `dev-master` is the only
constraint that can express "the code this test suite actually validated".

## What replaced the missing review point

The exposure used to be doubled: until 2026-10-29 the weekly `Dependencies`
workflow ran `composer update` and, when green, **pushed the refreshed
`composer.lock` straight to `master`** with no human in the loop. The chain
(`chore(deps): refresh composer.lock from dev-master` commits) was an
auto-merge of tens of upstream commits per week into the release history.

Now, in force since 2026-10-29:

1. **`.github/workflows/deps.yml` opens a pull request, never a master push.**
   All gates (phpstan, phpstan-tests, phpunit, security audit) run in-workflow
   against the proposed lock before the PR opens; a human reviews the diff and
   merges on purpose.
2. **`.github/workflows/ci.yml` gained a whole-lock `Security Audit` job**
   (`scripts/security-audit-check.php`, the estate S246 family gate): every
   push and pull request fails loudly if any locked package — runtime or
   development — carries a published advisory, with the audited corpus printed
   and floored. Its guard suite is
   `tests/Unit/SecurityAuditCheckTest.php` (lane token
   `S440CONSOLEGUARDW9T4`). Measured against the committed lock on 2026-10-29:
   0 advisories, 0 abandoned — the gate is live-green from day one, no
   allowlist.
3. **Abandonment or an unactionable advisory goes through the recorded escape
   hatch only:** `composer.json` `config.audit.ignore` with a written reason,
   which the gate reports as a loud `IGNORED` notice. No baselines, no
   in-script ignores.

## Per-package tag-availability table (measured 2026-10-29)

| package | constraint | semver tags? | only tag | tag vs current `master` | decision |
|---|---|---|---|---|---|
| `sugarcraft/*` — direct runtime | | | | | |
| sugar-core | dev-master | no | candy-core-final | diverged, −12 | keep dev-master (no usable tag) |
| sugar-focus | dev-master | no | candy-focus-final | diverged, −5 | keep dev-master (no usable tag) |
| sugar-forms | dev-master | no | candy-forms-final | diverged, −27 | keep dev-master (no usable tag) |
| sugar-fuzzy | dev-master | no | candy-fuzzy-final | diverged, −4 | keep dev-master (no usable tag) |
| sugar-quickpick | dev-master | no | candy-hermit-final | diverged, −11 | keep dev-master (no usable tag) |
| sugar-image | dev-master | no | candy-mosaic-final | diverged, −85 | keep dev-master (no usable tag) |
| sugar-colorprofile | dev-master | no | candy-palette-final | diverged, −12 | keep dev-master (no usable tag) |
| sugar-markdown | dev-master | no | candy-shine-final | diverged, −10 | keep dev-master (no usable tag) |
| sugar-style | dev-master | no | candy-sprinkles-final | diverged, −12 | keep dev-master (no usable tag) |
| sugar-panels | dev-master | no | sugar-boxer-final | diverged, −16 | keep dev-master (no usable tag) |
| sugar-navstack | dev-master | no | sugar-crumbs-final | diverged, −7 | keep dev-master (no usable tag) |
| sugar-gallery | dev-master | no | (none) | — | keep dev-master (no tag at all) |
| sugar-video | dev-master | no | sugar-reel-final | diverged, −16 | keep dev-master (no usable tag) |
| sugar-table | dev-master | no | (none) | — | keep dev-master (no tag at all) |
| sugar-toast | dev-master | no | (none) | — | keep dev-master (no tag at all) |
| sugar-overlay | dev-master | no | sugar-veil-final | diverged, −12 | keep dev-master (no usable tag) |
| require-dev, direct | | | | | |
| sugar-testing | dev-master | no | candy-testing-final | diverged, −7 | keep dev-master (no usable tag) |
| transitive (themselves dev-master-required by the direct packages) | | | | | |
| sugar-animation | dev-master | no | candy-flip-final | diverged, −15 | keep dev-master (no usable tag) |
| sugar-ansi-parser | dev-master | no | candy-ansi-final | diverged, −6 | keep dev-master (no usable tag) |
| sugar-async | dev-master | no | candy-async-final | diverged, −4 | keep dev-master (no usable tag) |
| sugar-cellbuffer | dev-master | no | candy-buffer-final | diverged, −6 | keep dev-master (no usable tag) |
| sugar-constraints | dev-master | no | candy-layout-final | diverged, −8 | keep dev-master (no usable tag) |
| sugar-input | dev-master | no | candy-input-final | diverged, −4 | keep dev-master (no usable tag) |
| sugar-mouse | dev-master | no | candy-mouse-final | diverged, −11 | keep dev-master (no usable tag) |
| sugar-pty | dev-master | no | candy-pty-final | diverged, −5 | keep dev-master (no usable tag) |
| sugar-spring | dev-master | no | honey-bounce-final | diverged, −6 | keep dev-master (no usable tag) |

(Commit counts are upstream `git rev-list --count <tag>..master`; "diverged"
means the tag commit is not an ancestor of `master`. Locked refs are all
on-`master` as of 2026-10-29; re-verified whenever the refresh PR lands.)

## Re-evaluation trigger

If upstream ever publishes real version tags, each package's constraint should
be tightened to the newest compatible tag in a reviewed PR, package by package,
with the full suite green against the resolved code. Until then this file,
the gate, and the PR-only refresh are the review points the `dev-master`
posture requires.
