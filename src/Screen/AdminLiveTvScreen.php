<?php

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\Channel;
use Phlix\Console\Api\Dto\Admin\GuideProgram;
use Phlix\Console\Api\Dto\Admin\Recording;
use Phlix\Console\Api\Dto\Admin\SeriesRule;
use Phlix\Console\Api\Dto\Admin\Tuner;
use Phlix\Console\Msg\AdminLiveTvActionDoneMsg;
use Phlix\Console\Msg\AdminLiveTvActionFailedMsg;
use Phlix\Console\Msg\AdminLiveTvChannelsLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvFailedMsg;
use Phlix\Console\Msg\AdminLiveTvGuideLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvRecordingsLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvSeriesRulesLoadedMsg;
use Phlix\Console\Msg\AdminLiveTvTunersLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin Live-TV surface: a single screen with FIVE tabbed sections — Tuners ·
 * Channels · Guide · Recordings · Series Rules — each a windowed {@see Table} with
 * its own simple actions. A tab bar at the top of the body shows the sections with
 * the active one accented; Tab / Shift-Tab (and ←/→) cycle the active section.
 *
 * Lazy per-section fetch + cache: {@see init()} fetches Tuners; switching to a
 * not-yet-loaded section fetches it; each section's list is cached (switching back
 * does not refetch); `r` force-refetches the active section. Each section carries
 * its own loading / empty / error state.
 *
 * Per-section actions act on the active section and a selected row:
 * - Tuners: `s` scan (re-discover → toast "Found N tuners" + refetch), `e` toggle
 *   enabled (PUT + refetch), `x` delete (inline y/n confirm).
 * - Channels: `e` toggle enabled (refetch).
 * - Guide: `g` refresh the EPG (POST → toast "Imported N programs" + refetch).
 * - Recordings: `x` delete (y/n confirm), `u` toggle an upcoming-only view ↔ all.
 * - Series Rules: `x` delete (y/n confirm).
 *
 * On success the action toasts and refetches the active section; on failure it
 * toasts the (friendly, per LT1) server message and leaves the list unchanged; an
 * auth failure surfaces a session expiry.
 *
 * CREATE / EDIT forms are DEFERRED (recording-create, series-rule create/update,
 * tuner/channel rename involve complex bodies / program pickers) — do those from
 * the web admin. This screen ships read + the simple actions only.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * active section, per-section caches, selection, busy flag, the pending delete
 * confirm, and the upcoming-only toggle are private mutable view state set via
 * clone-mutate (the established screen idiom). No live poll.
 */
final class AdminLiveTvScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load this section.';
    private const HINT = 'Tab/←→ section   ↑↓ select   action keys below   r refresh   Esc back';
    private const DEFERRED_NOTE = 'Create / edit recordings + rules from the web admin.';

    /** @var array<value-of<LiveTvSection>, string> per-section action hints. */
    private const SECTION_HINTS = [
        'tuners' => 's scan   e toggle-enabled   x delete',
        'channels' => 'e toggle-enabled',
        'guide' => 'g refresh-guide',
        'recordings' => 'x delete   u upcoming/all',
        'rules' => 'x delete',
    ];

    private LiveTvSection $section = LiveTvSection::Tuners;

    /** @var list<Tuner>|null null = not yet fetched. */
    private ?array $tuners = null;
    /** @var list<Channel>|null */
    private ?array $channels = null;
    /** @var list<GuideProgram>|null */
    private ?array $programs = null;
    /** @var list<Recording>|null */
    private ?array $recordings = null;
    /** @var list<SeriesRule>|null */
    private ?array $rules = null;

    /** @var array<value-of<LiveTvSection>, bool> per-section "a fetch is in flight". */
    private array $loading = [];
    /** @var array<value-of<LiveTvSection>, string> per-section fetch error. */
    private array $errors = [];
    /** @var array<value-of<LiveTvSection>, int> per-section selection index. */
    private array $selected = [];

    /** An action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /** The id armed for a y/n delete confirm in the active section, or null. */
    private ?string $pendingDeleteId = null;
    private ?string $pendingDeleteLabel = null;

    /** Recordings: show only upcoming when true (the `u` toggle). */
    private bool $upcomingOnly = false;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd($this->section);
    }

    // ---- fetch ---------------------------------------------------------

    /** Build the fetch command for one section, mapped to that section's loaded Msg. */
    private function fetchCmd(LiveTvSection $section): \Closure
    {
        $admin = $this->admin;
        $upcoming = $this->upcomingOnly;

        return Cmd::promise(static fn (): PromiseInterface => self::fetchPromise($admin, $section, $upcoming)->then(
            static fn (Msg $msg): Msg => $msg,
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminLiveTvFailedMsg(self::LOAD_FAILED),
        ));
    }

    /** @return PromiseInterface<Msg> */
    private static function fetchPromise(AdminClient $admin, LiveTvSection $section, bool $upcoming): PromiseInterface
    {
        return match ($section) {
            LiveTvSection::Tuners => $admin->liveTvTuners()->then(
                /** @param list<Tuner> $rows */
                static fn (array $rows): Msg => new AdminLiveTvTunersLoadedMsg($rows),
            ),
            LiveTvSection::Channels => $admin->liveTvChannels()->then(
                /** @param list<Channel> $rows */
                static fn (array $rows): Msg => new AdminLiveTvChannelsLoadedMsg($rows),
            ),
            LiveTvSection::Guide => $admin->liveTvGuide()->then(
                /** @param list<GuideProgram> $rows */
                static fn (array $rows): Msg => new AdminLiveTvGuideLoadedMsg($rows),
            ),
            LiveTvSection::Recordings => ($upcoming ? $admin->upcomingRecordings() : $admin->recordings())->then(
                /** @param list<Recording> $rows */
                static fn (array $rows): Msg => new AdminLiveTvRecordingsLoadedMsg($rows),
            ),
            LiveTvSection::Rules => $admin->seriesRules()->then(
                /** @param list<SeriesRule> $rows */
                static fn (array $rows): Msg => new AdminLiveTvSeriesRulesLoadedMsg($rows),
            ),
        };
    }

    /**
     * Build the command for a fired action: the promise mapped to a done/failed
     * Msg, the done message defaulting to $fallback when the action resolves no
     * text of its own.
     *
     * @param PromiseInterface<mixed> $promise
     */
    private function actionCmd(PromiseInterface $promise, string $fallback): \Closure
    {
        return Cmd::promise(static fn (): PromiseInterface => $promise->then(
            static fn (mixed $_): Msg => new AdminLiveTvActionDoneMsg($fallback),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminLiveTvActionFailedMsg($e->getMessage()),
        ));
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminLiveTvTunersLoadedMsg) {
            $next = $this->loadedInto(LiveTvSection::Tuners, count($msg->tuners));
            $next->tuners = $msg->tuners;

            return [$next, null];
        }
        if ($msg instanceof AdminLiveTvChannelsLoadedMsg) {
            $next = $this->loadedInto(LiveTvSection::Channels, count($msg->channels));
            $next->channels = $msg->channels;

            return [$next, null];
        }
        if ($msg instanceof AdminLiveTvGuideLoadedMsg) {
            $next = $this->loadedInto(LiveTvSection::Guide, count($msg->programs));
            $next->programs = $msg->programs;

            return [$next, null];
        }
        if ($msg instanceof AdminLiveTvRecordingsLoadedMsg) {
            $next = $this->loadedInto(LiveTvSection::Recordings, count($msg->recordings));
            $next->recordings = $msg->recordings;

            return [$next, null];
        }
        if ($msg instanceof AdminLiveTvSeriesRulesLoadedMsg) {
            $next = $this->loadedInto(LiveTvSection::Rules, count($msg->rules));
            $next->rules = $msg->rules;

            return [$next, null];
        }
        if ($msg instanceof AdminLiveTvFailedMsg) {
            return [$this->withError($this->section, $msg->message), null];
        }
        if ($msg instanceof AdminLiveTvActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminLiveTvActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Live TV', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // An armed delete confirm captures y/n/Esc before anything else.
        if ($this->pendingDeleteId !== null) {
            return $this->handleConfirmKey($msg, $this->pendingDeleteId);
        }

        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Tab) {
            return $this->cycleSection($msg->shift ? -1 : 1);
        }
        if ($msg->type === KeyType::Right) {
            return $this->cycleSection(1);
        }
        if ($msg->type === KeyType::Left) {
            return $this->cycleSection(-1);
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }
        if ($msg->type === KeyType::Char) {
            return $this->handleCharKey($msg->rune);
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleCharKey(string $rune): array
    {
        if ($rune === 'r') {
            return $this->refresh();
        }
        if ($this->busy) {
            return [$this, null];
        }

        return match ($this->section) {
            LiveTvSection::Tuners => $this->tunerAction($rune),
            LiveTvSection::Channels => $this->channelAction($rune),
            LiveTvSection::Guide => $this->guideAction($rune),
            LiveTvSection::Recordings => $this->recordingAction($rune),
            LiveTvSection::Rules => $this->ruleAction($rune),
        };
    }

    /** @return array{self, ?\Closure} */
    private function tunerAction(string $rune): array
    {
        if ($rune === 's') {
            return [$this->working(), $this->actionCmd($this->admin->scanTuners(), 'Tuners rescanned')];
        }
        $tuner = $this->selectedTuner();
        if ($tuner === null) {
            return [$this, null];
        }
        if ($rune === 'e') {
            return [$this->working(), $this->actionCmd($this->admin->setTunerEnabled($tuner->id, !$tuner->enabled), $tuner->enabled ? 'Tuner disabled' : 'Tuner enabled')];
        }
        if ($rune === 'x') {
            return [$this->arm($tuner->id, $tuner->name), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function channelAction(string $rune): array
    {
        $channel = $this->selectedChannel();
        if ($channel === null) {
            return [$this, null];
        }
        if ($rune === 'e') {
            return [$this->working(), $this->actionCmd($this->admin->setChannelEnabled($channel->id, !$channel->enabled), $channel->enabled ? 'Channel disabled' : 'Channel enabled')];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function guideAction(string $rune): array
    {
        if ($rune === 'g') {
            return [$this->working(), Cmd::promise(fn (): PromiseInterface => $this->admin->refreshGuide()->then(
                static fn (int $count): Msg => new AdminLiveTvActionDoneMsg("Imported {$count} programs"),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminLiveTvActionFailedMsg($e->getMessage()),
            ))];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function recordingAction(string $rune): array
    {
        if ($rune === 'u') {
            return $this->toggleUpcoming();
        }
        $recording = $this->selectedRecording();
        if ($recording === null) {
            return [$this, null];
        }
        if ($rune === 'x') {
            return [$this->arm($recording->recordingId, $recording->title), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function ruleAction(string $rune): array
    {
        $rule = $this->selectedRule();
        if ($rule === null) {
            return [$this, null];
        }
        if ($rune === 'x') {
            return [$this->arm($rule->ruleId, $rule->title), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg, string $id): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return [$this->working(), $this->actionCmd($this->deletePromise($id), 'Deleted')];
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    /** @return PromiseInterface<null> */
    private function deletePromise(string $id): PromiseInterface
    {
        return match ($this->section) {
            LiveTvSection::Tuners => $this->admin->deleteTuner($id),
            LiveTvSection::Recordings => $this->admin->deleteRecording($id),
            default => $this->admin->deleteSeriesRule($id),
        };
    }

    // ---- action results ------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminLiveTvActionDoneMsg $msg): array
    {
        // Drop the active section's cache and refetch so the change shows.
        $next = $this->working();
        $next->clearSection($this->section);

        return [$next, Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $next->fetchCmd($this->section))];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = $this->idle();
        $next->clearSection($this->section);
        $next->loading[$this->section->value] = true;

        return [$next, $next->fetchCmd($this->section)];
    }

    // ---- section switching --------------------------------------------

    /** @return array{self, ?\Closure} */
    private function cycleSection(int $delta): array
    {
        $cases = LiveTvSection::cases();
        $count = count($cases);
        $index = (int) array_search($this->section, $cases, true);
        $target = $cases[(($index + $delta) % $count + $count) % $count];

        $next = clone $this;
        $next->section = $target;
        $next->pendingDeleteId = null;
        $next->pendingDeleteLabel = null;

        // Lazy-fetch the section the first time it is shown.
        if (!$next->isLoaded($target) && !($next->loading[$target->value] ?? false)) {
            $next->loading[$target->value] = true;

            return [$next, $next->fetchCmd($target)];
        }

        return [$next, null];
    }

    // ---- clone-mutate copies -------------------------------------------

    /**
     * Clone with one section's loading/error/busy state cleared and its selection
     * clamped to $count rows. The caller assigns the precisely-typed cache field on
     * the returned clone (keeping each field's element type intact).
     */
    private function loadedInto(LiveTvSection $section, int $count): self
    {
        $next = clone $this;
        unset($next->loading[$section->value], $next->errors[$section->value]);
        $next->busy = false;
        $next->pendingDeleteId = null;
        $next->pendingDeleteLabel = null;
        $next->selected[$section->value] = $count === 0 ? 0 : min($next->selected[$section->value] ?? 0, $count - 1);

        return $next;
    }

    private function withError(LiveTvSection $section, string $error): self
    {
        $next = clone $this;
        $next->errors[$section->value] = $error;
        unset($next->loading[$section->value]);
        $next->busy = false;
        $next->pendingDeleteId = null;
        $next->pendingDeleteLabel = null;

        return $next;
    }

    /** Enter the busy (in-flight) state, clearing any armed confirm. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;
        $next->pendingDeleteId = null;
        $next->pendingDeleteLabel = null;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the list. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;
        $next->pendingDeleteId = null;
        $next->pendingDeleteLabel = null;

        return $next;
    }

    private function arm(string $id, string $label): self
    {
        $next = clone $this;
        $next->pendingDeleteId = $id;
        $next->pendingDeleteLabel = $label;

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pendingDeleteId = null;
        $next->pendingDeleteLabel = null;

        return $next;
    }

    /** @return array{self, ?\Closure} */
    private function toggleUpcoming(): array
    {
        $next = $this->idle();
        $next->upcomingOnly = !$this->upcomingOnly;
        $next->recordings = null;
        $next->selected[LiveTvSection::Recordings->value] = 0;
        $next->loading[LiveTvSection::Recordings->value] = true;

        return [$next, $next->fetchCmd(LiveTvSection::Recordings)];
    }

    private function moveSelection(int $delta): self
    {
        $count = $this->activeCount();
        if ($count === 0) {
            return $this;
        }
        $current = $this->selected[$this->section->value] ?? 0;
        $selected = max(0, min($count - 1, $current + $delta));
        if ($selected === $current) {
            return $this;
        }
        $next = clone $this;
        $next->selected[$this->section->value] = $selected;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- per-section cache plumbing ------------------------------------

    private function clearSection(LiveTvSection $section): void
    {
        match ($section) {
            LiveTvSection::Tuners => $this->tuners = null,
            LiveTvSection::Channels => $this->channels = null,
            LiveTvSection::Guide => $this->programs = null,
            LiveTvSection::Recordings => $this->recordings = null,
            LiveTvSection::Rules => $this->rules = null,
        };
    }

    private function isLoaded(LiveTvSection $section): bool
    {
        return match ($section) {
            LiveTvSection::Tuners => $this->tuners !== null,
            LiveTvSection::Channels => $this->channels !== null,
            LiveTvSection::Guide => $this->programs !== null,
            LiveTvSection::Recordings => $this->recordings !== null,
            LiveTvSection::Rules => $this->rules !== null,
        };
    }

    private function activeCount(): int
    {
        return match ($this->section) {
            LiveTvSection::Tuners => count($this->tuners ?? []),
            LiveTvSection::Channels => count($this->channels ?? []),
            LiveTvSection::Guide => count($this->programs ?? []),
            LiveTvSection::Recordings => count($this->recordings ?? []),
            LiveTvSection::Rules => count($this->rules ?? []),
        };
    }

    // ---- selected-row accessors ----------------------------------------

    private function selectedTuner(): ?Tuner
    {
        return ($this->tuners ?? [])[$this->selected[LiveTvSection::Tuners->value] ?? 0] ?? null;
    }

    private function selectedChannel(): ?Channel
    {
        return ($this->channels ?? [])[$this->selected[LiveTvSection::Channels->value] ?? 0] ?? null;
    }

    private function selectedRecording(): ?Recording
    {
        return ($this->recordings ?? [])[$this->selected[LiveTvSection::Recordings->value] ?? 0] ?? null;
    }

    private function selectedRule(): ?SeriesRule
    {
        return ($this->rules ?? [])[$this->selected[LiveTvSection::Rules->value] ?? 0] ?? null;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        return "\n" . $this->tabBar() . "\n\n" . $this->sectionBody() . "\n\n" . $this->statusLine();
    }

    /** The section tab bar with the active section accented. */
    private function tabBar(): string
    {
        $parts = [];
        foreach (LiveTvSection::cases() as $case) {
            $label = $case->label();
            $parts[] = $case === $this->section ? "[ {$label} ]" : "  {$label}  ";
        }

        return '  ' . implode('', $parts);
    }

    private function sectionBody(): string
    {
        if (($this->loading[$this->section->value] ?? false) || (!$this->isLoaded($this->section) && !isset($this->errors[$this->section->value]))) {
            return '  Loading…';
        }
        if (isset($this->errors[$this->section->value])) {
            return '  ' . $this->errors[$this->section->value] . "\n\n  Press r to retry.";
        }

        return match ($this->section) {
            LiveTvSection::Tuners => $this->tunersTable(),
            LiveTvSection::Channels => $this->channelsTable(),
            LiveTvSection::Guide => $this->guideTable(),
            LiveTvSection::Recordings => $this->recordingsTable(),
            LiveTvSection::Rules => $this->rulesTable(),
        };
    }

    private function tunersTable(): string
    {
        $tuners = $this->tuners ?? [];
        if ($tuners === []) {
            return '  No tuners configured.';
        }
        $rows = [];
        foreach ($tuners as $tuner) {
            $rows[] = [$tuner->name, $tuner->type, $tuner->status === '' ? '—' : $tuner->status, $tuner->enabled ? '✓' : '–'];
        }

        return Table::render([
            ['title' => 'Name', 'width' => 0],
            ['title' => 'Type', 'width' => 14],
            ['title' => 'Status', 'width' => 14],
            ['title' => 'Enabled', 'width' => 8, 'align' => 'right'],
        ], $rows, $this->selected[LiveTvSection::Tuners->value] ?? 0, $this->cols - 4, $this->viewportRows());
    }

    private function channelsTable(): string
    {
        $channels = $this->channels ?? [];
        if ($channels === []) {
            return '  No channels.';
        }
        $rows = [];
        foreach ($channels as $channel) {
            $rows[] = [(string) $channel->number, $channel->name, $channel->callsign ?? '—', $channel->enabled ? '✓' : '–'];
        }

        return Table::render([
            ['title' => 'Number', 'width' => 8],
            ['title' => 'Name', 'width' => 0],
            ['title' => 'Callsign', 'width' => 14],
            ['title' => 'Enabled', 'width' => 8, 'align' => 'right'],
        ], $rows, $this->selected[LiveTvSection::Channels->value] ?? 0, $this->cols - 4, $this->viewportRows());
    }

    private function guideTable(): string
    {
        $programs = $this->programs ?? [];
        if ($programs === []) {
            return '  No guide data.';
        }
        $rows = [];
        foreach ($programs as $program) {
            $rows[] = [date('H:i', $program->startTime), $this->shortId($program->channelId), $program->title];
        }

        return Table::render([
            ['title' => 'Time', 'width' => 8],
            ['title' => 'Channel', 'width' => 12],
            ['title' => 'Title', 'width' => 0],
        ], $rows, $this->selected[LiveTvSection::Guide->value] ?? 0, $this->cols - 4, $this->viewportRows());
    }

    private function recordingsTable(): string
    {
        $recordings = $this->recordings ?? [];
        if ($recordings === []) {
            return $this->upcomingOnly ? '  No upcoming recordings.' : '  No recordings.';
        }
        $rows = [];
        foreach ($recordings as $recording) {
            $rows[] = [
                $recording->title,
                $recording->statusLabel(),
                date('m/d H:i', $recording->startTime),
                $recording->storageSize === null ? '—' : self::humanBytes($recording->storageSize),
            ];
        }

        return Table::render([
            ['title' => 'Title', 'width' => 0],
            ['title' => 'Status', 'width' => 12],
            ['title' => 'Start', 'width' => 14],
            ['title' => 'Size', 'width' => 10, 'align' => 'right'],
        ], $rows, $this->selected[LiveTvSection::Recordings->value] ?? 0, $this->cols - 4, $this->viewportRows());
    }

    private function rulesTable(): string
    {
        $rules = $this->rules ?? [];
        if ($rules === []) {
            return '  No series rules.';
        }
        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [$rule->title, (string) $rule->priority, $rule->isActive ? '✓' : '–'];
        }

        return Table::render([
            ['title' => 'Title', 'width' => 0],
            ['title' => 'Priority', 'width' => 10, 'align' => 'right'],
            ['title' => 'Active', 'width' => 8, 'align' => 'right'],
        ], $rows, $this->selected[LiveTvSection::Rules->value] ?? 0, $this->cols - 4, $this->viewportRows());
    }

    /**
     * The status line under the section: the armed delete confirm when one is
     * pending, the busy note while in flight, else the per-section action hint
     * plus the deferred-create note.
     */
    private function statusLine(): string
    {
        if ($this->pendingDeleteLabel !== null) {
            return "  Delete '{$this->pendingDeleteLabel}'? (y/n)";
        }
        if ($this->busy) {
            return '  Working…';
        }

        $hint = self::SECTION_HINTS[$this->section->value];

        return "  {$hint}\n  " . self::DEFERRED_NOTE;
    }

    private function shortId(string $id): string
    {
        return strlen($id) <= 12 ? $id : substr($id, 0, 11) . '…';
    }

    private function viewportRows(): int
    {
        // The frame body holds the tab bar (blank + bar + blank = 3), the table
        // (header + rule = 2), then a blank + the two-line status. Window the data
        // rows to the body height less those chrome rows.
        return max(1, Chrome::bodyHeight($this->rows) - 8);
    }

    /**
     * Humanize a byte count to a binary KiB/MiB/GiB string (mirrors the
     * Backup/Dashboard helper — a small local copy avoids a cross-screen dependency).
     */
    private static function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024.0 && $unit < count($units) - 1) {
            $size /= 1024.0;
            ++$unit;
        }

        return $unit === 0
            ? $bytes . ' B'
            : number_format($size, 1) . ' ' . $units[$unit];
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Live TV';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function activeSection(): LiveTvSection
    {
        return $this->section;
    }

    /** @return list<Tuner>|null */
    public function tunerList(): ?array
    {
        return $this->tuners;
    }

    /** @return list<Channel>|null */
    public function channelList(): ?array
    {
        return $this->channels;
    }

    /** @return list<GuideProgram>|null */
    public function guideList(): ?array
    {
        return $this->programs;
    }

    /** @return list<Recording>|null */
    public function recordingList(): ?array
    {
        return $this->recordings;
    }

    /** @return list<SeriesRule>|null */
    public function ruleList(): ?array
    {
        return $this->rules;
    }

    public function isSectionLoaded(LiveTvSection $section): bool
    {
        return $this->isLoaded($section);
    }

    public function sectionError(LiveTvSection $section): ?string
    {
        return $this->errors[$section->value] ?? null;
    }

    public function selectedIndex(): int
    {
        return $this->selected[$this->section->value] ?? 0;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function pendingDeleteId(): ?string
    {
        return $this->pendingDeleteId;
    }

    public function isUpcomingOnly(): bool
    {
        return $this->upcomingOnly;
    }
}
