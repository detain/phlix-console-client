<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\PortForwardCandidate;
use Phlix\Console\Api\Dto\Admin\RemoteAccessStatus;
use Phlix\Console\Msg\AdminPortForwardCandidatesFailedMsg;
use Phlix\Console\Msg\AdminPortForwardCandidatesLoadedMsg;
use Phlix\Console\Msg\AdminRemoteActionDoneMsg;
use Phlix\Console\Msg\AdminRemoteActionFailedMsg;
use Phlix\Console\Msg\AdminRemoteFailedMsg;
use Phlix\Console\Msg\AdminRemoteStatusLoadedMsg;
use Phlix\Console\Msg\HubPairingDoneMsg;
use Phlix\Console\Msg\HubPairingExpiredMsg;
use Phlix\Console\Msg\HubPairingPollSucceededMsg;
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
 * The admin remote-access surface: four stacked, individually-selectable status
 * panels — Hub pairing, managed Subdomain, Relay tunnel, and Port forwarding —
 * each fetched together (via {@see AdminClient::remoteStatus()}) on init.
 *
 * ↑/↓ moves the selection between the four panels (clamped); the selected panel
 * is accented and its available actions appear in the hint. Action keys are
 * interpreted PER selected panel:
 *  - Hub: `u` unenroll (only when paired — inline `y/n` confirm; removes the
 *    pairing).
 *  - Subdomain: `c` claim (when not claimed), `x` release (when claimed — `y/n`).
 *  - Relay: `e` enable, `d` disable, `p` ping (toasts the latency).
 *  - Port Forward: `e` enable, `d` disable, `c` candidates (a read-only sub-view
 *    listing the discovered reachable hostname URLs · IP · Port).
 *
 * The Port Forward panel's `c` opens a read-only, PANEL-SCOPED candidates
 * sub-view: a windowed {@see Table} of the discovered {@see PortForwardCandidate}s
 * (Hostname · IP · Port) with its own loading / empty / error state. `c`/Esc close
 * the sub-view back to the panels WITHOUT popping the screen or changing the panel
 * selection. Keys are panel-scoped, so `c` here never collides with the Subdomain
 * panel's claim key. The sub-view is read-only — opening it leaves the four-panel
 * status untouched.
 *
 * After any successful action the confirmation is toasted and ALL four statuses
 * are refetched (no live poll — statuses are point-in-time). A failure toasts the
 * friendly server `message` (the failure bodies use `message`, not `error`; the
 * {@see AdminClient} re-surfaces it) and leaves the statuses unchanged. An auth
 * failure surfaces a session expiry. `r` refreshes.
 *
 * Stable collaborators are readonly; the loaded status, selected panel, busy
 * flag, pending confirm, and error are private mutable view state set via
 * clone-mutate (the established screen idiom).
 */
final class AdminRemoteAccessScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the remote-access status.';
    private const CANDIDATES_FAILED = 'Could not load the port-forward candidates.';
    private const CANDIDATES_HINT = '↑/↓ scroll   r refresh   c/Esc close';

    private const PANEL_HUB = 0;
    private const PANEL_SUBDOMAIN = 1;
    private const PANEL_RELAY = 2;
    private const PANEL_PORTFORWARD = 3;
    private const PANEL_COUNT = 4;

    private const MAX_POLLS = 10;

    private ?RemoteAccessStatus $status = null;
    private bool $loaded = false;
    private ?string $error = null;

    /** Which of the four panels is selected (PANEL_* constant). */
    private int $panel = self::PANEL_HUB;

    /** A fetch / action is in flight (input that mutates is ignored while busy). */
    private bool $busy = false;

    /**
     * The armed confirm: 'unenroll' (hub) or 'release' (subdomain) when a y/n
     * confirm is pending, else null.
     */
    private ?string $pendingConfirm = null;

    /** Whether the read-only port-forward candidates sub-view is overlaid. */
    private bool $candidatesOpen = false;

    /** Whether a candidates fetch has resolved (false = still loading). */
    private bool $candidatesLoaded = false;

    /** A candidates fetch error, or null. */
    private ?string $candidatesError = null;

    /** @var list<PortForwardCandidate> The discovered candidates. */
    private array $candidates = [];

    /** The cursor row within the candidates list. */
    private int $candidatesSelected = 0;

    /** Hub pairing wizard state machine. */
    private PairingWizard $wizard;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private int $cols = 80,
        private int $rows = 24,
    ) {
        $this->wizard = PairingWizard::idle();
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    // ---- fetch ---------------------------------------------------------

    private function fetchCmd(): \Closure
    {
        $admin = $this->admin;

        return Cmd::promise(static fn () => $admin->remoteStatus()->then(
            static fn (RemoteAccessStatus $status): Msg => new AdminRemoteStatusLoadedMsg($status),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminRemoteFailedMsg(self::LOAD_FAILED),
        ));
    }

    /**
     * Build the command for a fired action: the action's promise mapped to a
     * done/failed Msg. On rejection the friendly server `message` (re-surfaced by
     * the client) is toasted; an auth failure becomes a session expiry.
     *
     * @param PromiseInterface<string> $promise
     */
    private function actionCmd(PromiseInterface $promise): \Closure
    {
        return Cmd::promise(static fn () => $promise->then(
            static fn (string $message): Msg => new AdminRemoteActionDoneMsg($message),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminRemoteActionFailedMsg($e->getMessage()),
        ));
    }

    /**
     * Fetch the discovered port-forward candidates for the read-only sub-view. A
     * failure surfaces an in-sub-view error (auth → session expiry); it never
     * touches the four-panel status.
     */
    private function candidatesFetchCmd(): \Closure
    {
        $admin = $this->admin;

        return Cmd::promise(static fn () => $admin->portForwardCandidates()->then(
            /** @param list<PortForwardCandidate> $candidates */
            static fn (array $candidates): Msg => new AdminPortForwardCandidatesLoadedMsg($candidates),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminPortForwardCandidatesFailedMsg(self::CANDIDATES_FAILED),
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
        if ($msg instanceof AdminRemoteStatusLoadedMsg) {
            return [$this->withStatus($msg->status), null];
        }
        if ($msg instanceof AdminRemoteFailedMsg) {
            return [$this->withError($msg->message), null];
        }
        if ($msg instanceof AdminRemoteActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof AdminRemoteActionFailedMsg) {
            return [$this->idle(), Cmd::send(ShowToastMsg::error($msg->message))];
        }
        if ($msg instanceof AdminPortForwardCandidatesLoadedMsg) {
            return $this->onCandidatesLoaded($msg->candidates);
        }
        if ($msg instanceof AdminPortForwardCandidatesFailedMsg) {
            return [$this->withCandidatesError($msg->message), null];
        }
        if ($msg instanceof HubPairingDoneMsg) {
            // Claim code received — wizard is in POLLING phase. Start bounded poll.
            return [$this, $this->hubPairingPollCmd($msg->claimId, $msg->hubUrl)];
        }
        if ($msg instanceof HubPairingPollSucceededMsg) {
            // Pairing completed — refetch all statuses to show paired state.
            return [$this->cancelPairingWizard(), $this->fetchCmd()];
        }
        if ($msg instanceof HubPairingExpiredMsg) {
            // Pairing failed (claim expired or hub unreachable after retries) —
            // cancel the wizard and toast the failure reason.
            $reason = $this->wizard->errorMessage() ?? 'Pairing failed.';

            return [$this->cancelPairingWizard(), Cmd::send(ShowToastMsg::error($reason))];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->candidatesOpen) {
            return Chrome::frame('Admin · Port-forward candidates', $this->candidatesBody(), self::CANDIDATES_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame('Admin · Remote Access', $this->body(), $this->hint(), $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // The read-only candidates sub-view owns ALL input while open (it never
        // coexists with an armed confirm), so it is checked before anything else —
        // `c`/Esc here close the sub-view rather than navigating back.
        if ($this->candidatesOpen) {
            return $this->handleCandidatesKey($msg);
        }
        // The pairing wizard captures all keys while active (URL input or poll).
        if (!$this->wizard->isIdle()) {
            return $this->handlePairingWizardKey($msg);
        }
        // A pending y/n confirm captures every key until it is answered.
        if ($this->pendingConfirm !== null) {
            return $this->handleConfirmKey($msg, $this->pendingConfirm);
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->movePanel(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->movePanel(1), null];
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

        $status = $this->status;
        if ($status === null) {
            return [$this, null];
        }

        return match ($this->panel) {
            self::PANEL_HUB => $this->hubAction($rune, $status),
            self::PANEL_SUBDOMAIN => $this->subdomainAction($rune, $status),
            self::PANEL_RELAY => $this->relayAction($rune),
            self::PANEL_PORTFORWARD => $this->portForwardAction($rune),
            default => [$this, null],
        };
    }

    /** @return array{self, ?\Closure} */
    private function hubAction(string $rune, RemoteAccessStatus $status): array
    {
        // `u` unenroll is offered only when paired, and arms a y/n confirm.
        if ($rune === 'u' && $status->hub->paired) {
            return [$this->armConfirm('unenroll'), null];
        }
        // `p` starts the hub pairing wizard when not paired.
        if ($rune === 'p' && !$status->hub->paired) {
            return [$this->startPairingWizard(), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function subdomainAction(string $rune, RemoteAccessStatus $status): array
    {
        if ($rune === 'c' && !$status->subdomain->claimed) {
            return [$this->working(), $this->actionCmd($this->admin->subdomainClaim())];
        }
        if ($rune === 'x' && $status->subdomain->claimed) {
            return [$this->armConfirm('release'), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function relayAction(string $rune): array
    {
        if ($rune === 'e') {
            return [$this->working(), $this->actionCmd($this->admin->relayEnable())];
        }
        if ($rune === 'd') {
            return [$this->working(), $this->actionCmd($this->admin->relayDisable())];
        }
        if ($rune === 'p') {
            return [$this->working(), $this->actionCmd($this->admin->relayPing())];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function portForwardAction(string $rune): array
    {
        if ($rune === 'e') {
            return [$this->working(), $this->actionCmd($this->admin->portForwardEnable())];
        }
        if ($rune === 'd') {
            return [$this->working(), $this->actionCmd($this->admin->portForwardDisable())];
        }
        // `c` opens the read-only candidates sub-view (panel-scoped: `c` only means
        // "claim" on the Subdomain panel, never here).
        if ($rune === 'c') {
            return [$this->openingCandidates(), $this->candidatesFetchCmd()];
        }

        return [$this, null];
    }

    /**
     * Resolve an armed y/n confirm: `y` performs the action, any other key cancels.
     *
     * @return array{self, ?\Closure}
     */
    private function handleConfirmKey(KeyMsg $msg, string $confirm): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            $promise = $confirm === 'unenroll'
                ? $this->admin->hubUnenroll()
                : $this->admin->subdomainRelease();

            return [$this->disarmAndWork(), $this->actionCmd($promise)];
        }

        // n / Esc / anything else cancels — no request issued.
        return [$this->disarm(), null];
    }

    /**
     * Handle a key while the read-only candidates sub-view is open: ↑/↓ scroll the
     * list, `r` refetches it, `c`/Esc close the sub-view back to the panels (NEVER
     * popping the whole screen, NEVER changing the panel selection). All other keys
     * are no-ops — the underlying four-panel status is untouched.
     *
     * @return array{self, ?\Closure}
     */
    private function handleCandidatesKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'c')) {
            return [$this->closingCandidates(), null];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->scrollCandidates(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->scrollCandidates(1), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->openingCandidates(), $this->candidatesFetchCmd()];
        }

        return [$this, null];
    }

    // ---- action results ------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(AdminRemoteActionDoneMsg $msg): array
    {
        // Actions are immediate; just refetch all four statuses so the change shows.
        return [$this->working(), Cmd::batch(Cmd::send(ShowToastMsg::success($msg->message)), $this->fetchCmd())];
    }

    /**
     * The candidates resolved. Drop them unless the sub-view is still open (a
     * candidates fetch that resolves after `c`/Esc closed it is ignored). Otherwise
     * store the list, clamp the cursor, and mark the sub-view loaded.
     *
     * @param list<PortForwardCandidate> $candidates
     * @return array{self, ?\Closure}
     */
    private function onCandidatesLoaded(array $candidates): array
    {
        if (!$this->candidatesOpen) {
            return [$this, null];
        }

        return [$this->withCandidates($candidates), null];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;
        $next->busy = true;
        $next->pendingConfirm = null;

        return [$next, $next->fetchCmd()];
    }

    // ---- clone-mutate copies -------------------------------------------

    private function withStatus(RemoteAccessStatus $status): self
    {
        $next = clone $this;
        $next->status = $status;
        $next->loaded = true;
        $next->busy = false;
        $next->error = null;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = false;
        $next->busy = false;

        return $next;
    }

    /** Move the panel selection by $delta, clamped to the four panels. */
    private function movePanel(int $delta): self
    {
        $panel = max(0, min(self::PANEL_COUNT - 1, $this->panel + $delta));
        if ($panel === $this->panel) {
            return $this;
        }
        $next = clone $this;
        $next->panel = $panel;

        return $next;
    }

    /** Arm a y/n confirm for the given action. */
    private function armConfirm(string $action): self
    {
        $next = clone $this;
        $next->pendingConfirm = $action;

        return $next;
    }

    /** Clear an armed confirm without acting. */
    private function disarm(): self
    {
        $next = clone $this;
        $next->pendingConfirm = null;

        return $next;
    }

    /** Clear the confirm and enter the busy state (a confirmed action fired). */
    private function disarmAndWork(): self
    {
        $next = clone $this;
        $next->pendingConfirm = null;
        $next->busy = true;

        return $next;
    }

    /** Enter the busy (in-flight) state. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;

        return $next;
    }

    /** Leave the busy state (after a failed action) without touching the status. */
    private function idle(): self
    {
        $next = clone $this;
        $next->busy = false;

        return $next;
    }

    /** Open (or re-open for a refresh) the candidates sub-view in its loading state. */
    private function openingCandidates(): self
    {
        $next = clone $this;
        $next->candidatesOpen = true;
        $next->candidatesLoaded = false;
        $next->candidatesError = null;
        $next->candidates = [];
        $next->candidatesSelected = 0;

        return $next;
    }

    /** Close the candidates sub-view back to the panels, discarding its view state. */
    private function closingCandidates(): self
    {
        $next = clone $this;
        $next->candidatesOpen = false;
        $next->candidatesLoaded = false;
        $next->candidatesError = null;
        $next->candidates = [];
        $next->candidatesSelected = 0;

        return $next;
    }

    /** @param list<PortForwardCandidate> $candidates */
    private function withCandidates(array $candidates): self
    {
        $next = clone $this;
        $next->candidates = $candidates;
        $next->candidatesLoaded = true;
        $next->candidatesError = null;
        $next->candidatesSelected = $candidates === [] ? 0 : min($this->candidatesSelected, count($candidates) - 1);

        return $next;
    }

    private function withCandidatesError(string $error): self
    {
        $next = clone $this;
        $next->candidatesError = $error;
        $next->candidatesLoaded = false;

        return $next;
    }

    /** Move the candidates cursor by $delta, clamped into range (same instance when unchanged). */
    private function scrollCandidates(int $delta): self
    {
        $count = count($this->candidates);
        if ($count === 0) {
            return $this;
        }
        $selected = max(0, min($count - 1, $this->candidatesSelected + $delta));
        if ($selected === $this->candidatesSelected) {
            return $this;
        }
        $next = clone $this;
        $next->candidatesSelected = $selected;

        return $next;
    }

    /**
     * Handle a keypress while the pairing wizard is active. When the wizard is
     * in WAITING_FOR_URL phase, Esc cancels the wizard and Enter submits the
     * buffered hub URL. Any other key appends to the URL buffer.
     *
     * @return array{self, ?\Closure}
     */
    private function handlePairingWizardKey(KeyMsg $msg): array
    {
        if ($this->wizard->isWaitingForUrl()) {
            if ($msg->type === KeyType::Escape) {
                return [$this->cancelPairingWizard(), null];
            }
            if ($msg->type === KeyType::Enter) {
                $url = $this->wizard->inputBuffer();
                if ($url === '') {
                    // Empty URL — stay in the wizard, no-op.
                    return [$this, null];
                }

                return [$this->working(), $this->hubPairingRequestCmd($url)];
            }
            if ($msg->type === KeyType::Char) {
                return [$this->wizardAppendChar($msg->rune), null];
            }

            return [$this, null];
        }

        // In any other wizard phase (SHOWING_CODE, POLLING, ERROR), only Esc
        // is accepted — it cancels the wizard and returns to idle.
        if ($msg->type === KeyType::Escape) {
            return [$this->cancelPairingWizard(), null];
        }

        return [$this, null];
    }

    /** Start the pairing wizard — user presses `p` on an unpaired hub. */
    private function startPairingWizard(): self
    {
        $next = clone $this;
        $next->wizard = PairingWizard::waitingForUrl();

        return $next;
    }

    /** Cancel the pairing wizard (Esc during any wizard phase). */
    private function cancelPairingWizard(): self
    {
        $next = clone $this;
        $next->wizard = PairingWizard::idle();

        return $next;
    }

    /** Append a character to the wizard's URL input buffer. */
    private function wizardAppendChar(string $char): self
    {
        $next = clone $this;
        $next->wizard = $this->wizard->appendChar($char);

        return $next;
    }

    /**
     * Build the command for the hub pairing request (Enter was pressed with a
     * URL in the buffer). On success the server returns {claimCode, claimId,
     * expiresIn} and the wizard moves to SHOWING_CODE + starts polling. On
     * failure the wizard enters ERROR and the friendly message is toasted.
     */
    private function hubPairingRequestCmd(string $hubUrl): \Closure
    {
        $admin = $this->admin;
        $screen = $this;

        return Cmd::promise(static function () use ($admin, $hubUrl, $screen): PromiseInterface {
            return $admin->hubPair($hubUrl)->then(
                static function (array $result) use ($screen, $hubUrl): Msg {
                    // Move wizard to SHOWING_CODE phase with the claim details.
                    $screen->wizard = PairingWizard::showingCode(
                        $result['claimCode'],
                        $result['claimId'],
                        $hubUrl,
                        $result['expiresIn'],
                    );

                    return new HubPairingDoneMsg(
                        $result['claimCode'],
                        $result['claimId'],
                        $hubUrl,
                        $result['expiresIn'],
                        self::MAX_POLLS,
                    );
                },
                static function (\Throwable $e) use ($screen): Msg {
                    if ($e instanceof AuthError) {
                        return new SessionExpiredMsg(self::SESSION_EXPIRED);
                    }

                    // Pairing request failed (hub unreachable, etc.).
                    $screen->wizard = PairingWizard::error($e->getMessage() ?: 'Pairing request failed.');

                    return new HubPairingExpiredMsg();
                },
            );
        });
    }

    /**
     * Build the bounded polling command for hub pairing. Polls up to MAX_POLLS
     * times with a 3-second gap between each attempt. Resolves to
     * HubPairingPollSucceededMsg on success, HubPairingExpiredMsg on expiry,
     * or AdminRemoteActionFailedMsg on final network failure.
     */
    private function hubPairingPollCmd(string $claimId, string $hubUrl): \Closure
    {
        $admin = $this->admin;
        $screen = $this;

        // Recursive inner that captures the remaining poll count.
        $poll = static function (int $remaining) use ($admin, $claimId, $hubUrl, $screen, &$poll): PromiseInterface {
            if ($remaining <= 0) {
                $screen->wizard = PairingWizard::error('Pairing timed out.');

                return \React\Promise\Timer\sleep(0)->then(
                    static fn (): Msg => new AdminRemoteActionFailedMsg('Pairing timed out.')
                );
            }

            // Update wizard countdown so view() shows it.
            $screen->wizard = $screen->wizard->withPollCountdown($remaining - 1);

            return $admin->hubPoll($claimId, $hubUrl)->then(
                static function (array $result) use ($screen, $hubUrl, $remaining, $poll): PromiseInterface {
                    if ($result['paired']) {
                        $screen->wizard = PairingWizard::idle();

                        return \React\Promise\Timer\sleep(0)->then(
                            static fn (): Msg => new HubPairingPollSucceededMsg($result['serverId'] ?? '', $hubUrl)
                        );
                    }

                    // Still pending — poll again after 3 seconds.
                    return \React\Promise\Timer\sleep(3)->then(
                        static function () use ($remaining, $poll): PromiseInterface {
                            return $poll($remaining - 1);
                        }
                    );
                },
                static function (\Throwable $e) use ($screen, $remaining, $poll): PromiseInterface {
                    if (str_contains($e->getMessage(), 'expired')) {
                        $screen->wizard = PairingWizard::error('Claim has expired.');

                        return \React\Promise\Timer\sleep(0)->then(
                            static fn (): Msg => new HubPairingExpiredMsg()
                        );
                    }

                    // Transient failure (hub unreachable, etc.) — retry.
                    if ($remaining > 1) {
                        return \React\Promise\Timer\sleep(3)->then(
                            static function () use ($remaining, $poll): PromiseInterface {
                                return $poll($remaining - 1);
                            }
                        );
                    }

                    $screen->wizard = PairingWizard::error($e->getMessage());

                    return \React\Promise\Timer\sleep(0)->then(
                        static fn (): Msg => new AdminRemoteActionFailedMsg($e->getMessage())
                    );
                },
            );
        };

        return static fn (): PromiseInterface => $poll(self::MAX_POLLS);
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }

        $status = $this->status;
        if ($status === null) {
            return "\n  Loading remote-access status…";
        }

        $lines = [''];
        foreach ($this->panelLines($status) as $line) {
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = '  ' . $this->statusLine();

        return implode("\n", $lines);
    }

    /**
     * Render the four panels in order, each headed by its (optionally accented)
     * title and followed by its detail lines.
     *
     * @return list<string>
     */
    private function panelLines(RemoteAccessStatus $status): array
    {
        $out = [];
        foreach ($this->hubPanel($status) as $line) {
            $out[] = $line;
        }
        $out[] = '';
        foreach ($this->subdomainPanel($status) as $line) {
            $out[] = $line;
        }
        $out[] = '';
        foreach ($this->relayPanel($status) as $line) {
            $out[] = $line;
        }
        $out[] = '';
        foreach ($this->portForwardPanel($status) as $line) {
            $out[] = $line;
        }

        return $out;
    }

    /** @return list<string> */
    private function hubPanel(RemoteAccessStatus $status): array
    {
        $hub = $status->hub;
        $lines = [
            $this->panelTitle(self::PANEL_HUB, 'Hub Pairing', $hub->stateLabel()),
        ];
        if ($hub->paired) {
            $lines[] = '    Server ID:   ' . ($hub->serverId ?? '—');
            $lines[] = '    Hub URL:     ' . ($hub->hubUrl ?? '—');
            $lines[] = '    Enrolled at: ' . ($hub->enrolledAt ?? '—');
        } else {
            $lines[] = '    Press p to pair with a hub.';
        }

        return $lines;
    }

    /** @return list<string> */
    private function subdomainPanel(RemoteAccessStatus $status): array
    {
        $sub = $status->subdomain;
        $lines = [
            $this->panelTitle(self::PANEL_SUBDOMAIN, 'Subdomain', $sub->stateLabel()),
        ];
        if ($sub->claimed) {
            $lines[] = '    Subdomain:   ' . ($sub->subdomain ?? '—');
            $lines[] = '    FQDN:        ' . ($sub->fqdn ?? '—');
        } else {
            $lines[] = '    No subdomain claimed.';
        }

        return $lines;
    }

    /** @return list<string> */
    private function relayPanel(RemoteAccessStatus $status): array
    {
        $relay = $status->relay;

        return [
            $this->panelTitle(self::PANEL_RELAY, 'Relay Tunnel', $relay->stateLabel()),
            '    Connected:   ' . ($relay->connected ? 'yes' : 'no'),
            '    Active:      ' . ($relay->active ? 'yes' : 'no'),
            '    Since:       ' . ($relay->establishedAt ?? '—'),
        ];
    }

    /** @return list<string> */
    private function portForwardPanel(RemoteAccessStatus $status): array
    {
        $pf = $status->portForward;
        $lines = [
            $this->panelTitle(self::PANEL_PORTFORWARD, 'Port Forward', $pf->stateLabel()),
        ];
        if ($pf->enabled) {
            $lines[] = '    Method:      ' . ($pf->method ?? '—');
            $lines[] = '    External IP: ' . ($pf->externalIp ?? '—');
            $lines[] = '    Port:        ' . ($pf->externalPort !== null ? (string) $pf->externalPort : '—');
            $lines[] = '    Hostname:    ' . ($pf->hostname ?? '—');
        } else {
            $lines[] = '    Port forwarding disabled.';
        }

        return $lines;
    }

    /**
     * The read-only candidates sub-view body: a loading / error / empty state, or a
     * windowed table of the discovered candidates (Hostname · IP · Port).
     */
    private function candidatesBody(): string
    {
        if (!$this->candidatesLoaded && $this->candidatesError === null) {
            return "\n  Loading port-forward candidates…";
        }
        if ($this->candidatesError !== null) {
            return "\n  {$this->candidatesError}\n\n  Press r to retry, c to close.";
        }
        if ($this->candidates === []) {
            return "\n  No candidates discovered.\n\n  Press c to close.";
        }

        $rows = [];
        foreach ($this->candidates as $candidate) {
            $rows[] = [
                $candidate->hostname === '' ? '—' : $candidate->hostname,
                $candidate->externalIp === '' ? '—' : $candidate->externalIp,
                (string) $candidate->port,
            ];
        }

        $table = Table::render([
            ['title' => 'Hostname', 'width' => 0],
            ['title' => 'IP', 'width' => 24],
            ['title' => 'Port', 'width' => 8, 'align' => 'right'],
        ], $rows, $this->candidatesSelected, $this->cols - 4, $this->candidatesViewportRows());

        return "\n" . $this->candidatesHeaderLine() . "\n" . $table;
    }

    private function candidatesHeaderLine(): string
    {
        $count = count($this->candidates);
        $label = $count === 1 ? '1 candidate' : "{$count} candidates";

        return '  Discovered: ' . $label;
    }

    private function candidatesViewportRows(): int
    {
        // The body holds the header line, then the table (header + rule = 2 extra
        // rows). Window the data rows to the body height less that chrome.
        return max(1, Chrome::bodyHeight($this->rows) - 3);
    }

    /** The panel header, marked with a ▸ caret when it is the selected panel. */
    private function panelTitle(int $panel, string $label, string $state): string
    {
        $caret = $panel === $this->panel ? '▸ ' : '  ';

        return $caret . $label . ' — ' . $state;
    }

    /** A line under the panels: the busy note, the confirm prompt, or a hint. */
    private function statusLine(): string
    {
        if ($this->pendingConfirm !== null) {
            return $this->pendingConfirm === 'unenroll'
                ? 'Unenroll from the hub? (y/n)'
                : 'Release the subdomain? (y/n)';
        }
        $phase = $this->wizard->phase();
        if ($this->wizard->isWaitingForUrl()) {
            return 'Hub URL: ' . $this->wizard->inputBuffer() . '_';
        }
        if ($this->wizard->isPolling()) {
            return 'Claim code: ' . $this->wizard->claimCode() . '   (Esc to cancel)';
        }
        if ($this->wizard->isError()) {
            $pollLeft = $this->wizard->pollLeft();
            $attempts = $pollLeft === 1 ? '1 attempt left' : ($pollLeft > 0 ? $pollLeft . ' attempts left' : '');

            return 'Waiting for hub… ' . $attempts . '   (Esc to cancel)';
        }
        if ($this->busy) {
            return 'Working…';
        }

        return '↑↓ select panel · acting on: ' . $this->panelName();
    }

    private function panelName(): string
    {
        return match ($this->panel) {
            self::PANEL_HUB => 'Hub Pairing',
            self::PANEL_SUBDOMAIN => 'Subdomain',
            self::PANEL_RELAY => 'Relay Tunnel',
            self::PANEL_PORTFORWARD => 'Port Forward',
            default => '',
        };
    }

    /** The frame hint reflects the selected panel's available actions. */
    private function hint(): string
    {
        $status = $this->status;
        if ($status === null || $this->error !== null) {
            return 'r refresh   Esc back';
        }
        if ($this->pendingConfirm !== null) {
            return 'y confirm   n cancel';
        }

        return $this->panelActionHint($status) . '   ↑↓ panel   r refresh   Esc back';
    }

    private function panelActionHint(RemoteAccessStatus $status): string
    {
        return match ($this->panel) {
            self::PANEL_HUB => $status->hub->paired ? 'u unenroll' : 'p pair',
            self::PANEL_SUBDOMAIN => $status->subdomain->claimed ? 'x release' : 'c claim',
            self::PANEL_RELAY => 'e enable   d disable   p ping',
            self::PANEL_PORTFORWARD => 'e enable   d disable   c candidates',
            default => '',
        };
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Remote Access';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors (for tests) ----------------------------------------

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    public function status(): ?RemoteAccessStatus
    {
        return $this->status;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function selectedPanel(): int
    {
        return $this->panel;
    }

    public function pendingConfirm(): ?string
    {
        return $this->pendingConfirm;
    }

    public function isCandidatesOpen(): bool
    {
        return $this->candidatesOpen;
    }

    public function isCandidatesLoaded(): bool
    {
        return $this->candidatesLoaded;
    }

    public function candidatesError(): ?string
    {
        return $this->candidatesError;
    }

    /** @return list<PortForwardCandidate> */
    public function candidatesList(): array
    {
        return $this->candidates;
    }

    public function candidatesSelectedIndex(): int
    {
        return $this->candidatesSelected;
    }

}
