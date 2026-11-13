<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\Api\Hub\HubClient;
use Phlix\Console\I18n\ExceptionSentences;
use Phlix\Console\Msg\FederationShareActionDoneMsg;
use Phlix\Console\Msg\FederationSharesFailedMsg;
use Phlix\Console\Msg\FederationSharesOutgoingLoadedMsg;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Skeleton;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;

/**
 * Lists the OUTGOING federation library shares — libraries this account's hub
 * has shared with other federation peers (hub `GET
 * /api/v1/me/federation/library-shares/outgoing`, the controller's top-level
 * `outgoing_shares` rows keyed by `id`) — and lets you revoke them: `r`
 * removes the selected share (hub `DELETE …/outgoing/{id}`, success is a body-
 * less 204), `↑`/`↓` (or `j`/`k`) move the cursor, `q`/`Esc` leaves.
 *
 * CU-006 [review console__uxdesign C2, console__docmaint m4] is the cure of
 * the original shape: body() returned '' (a Chrome frame with an empty
 * interior), update() returned [$this, null] for EVERY message — including the
 * Escape NavigateBackMsg needed — so the palette-reachable screen was a modal
 * dead end whose only exit was knowing Ctrl-K → Home existed, and the
 * hardcoded 80x24 geometry ignored WindowSizeMsg. It now renders the canonical
 * {@see Chrome::frame} loading/error/empty/list body, mutates through clones,
 * routes Escape by KEY TYPE (the vendored KeyMsg carries rune '' for Escape),
 * and re-reads the list from the hub after every revoke — the hub owns which
 * shares remain active.
 *
 * The wire read itself is the second cure: the typed client once parsed a
 * stale `$body['outgoing']` key the hub never sends (truth:
 * `outgoing_shares`), so even a rendering list would have stayed forever
 * empty — the body-blind class the createInvite CRIT died of.
 *
 * The screen implements the canonical {@see Model}/{@see Themed}/
 * {@see Breadcrumbed} contracts like its two hub siblings; the `pending()` /
 * hub-frame ctor cohorts it now shares with them stay a named follow-up debt
 * for a concern extraction (see ScreenConcernSingleSourceTest).
 */
final class FederationSharesScreen implements Model, Breadcrumbed, Themed, Loadable, Shimmering
{
    use SubscriptionCapable;
    use ThemedScreen;
    use ShimmeringScreen;
    use ResizesTo;
    use SelectsNext;
    use SelectsPrevious;

    private const HINT = 'q/Esc  back      ↑↓  navigate      r  revoke';

    /** @var list<array<string, mixed>> */
    private array $items = [];

    private int $selectedIndex = 0;

    private bool $loading = true;

    private ?string $error = null;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly HubClient $hub,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }

        if ($msg instanceof InitMsg) {
            return [$this, null];
        }

        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }

        if ($msg instanceof FederationSharesOutgoingLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->shares;
            $next->loading = false;
            $next->error = null;
            // A re-read after a revoke shrinks the list; keep the cursor on a
            // row that still exists.
            $next->selectedIndex = max(0, min($this->selectedIndex, count($next->items) - 1));

            return [$next, null];
        }

        if ($msg instanceof FederationSharesFailedMsg) {
            $next = clone $this;
            $next->error = $msg->message;
            $next->loading = false;

            return [$next, null];
        }

        if ($msg instanceof FederationShareActionDoneMsg) {
            // The revoke landed server-side: say so, then re-read the shares
            // from the hub — never hand-patch the list locally.
            return [
                $this->pending(),
                Cmd::batch(
                    Cmd::send(ShowToastMsg::success($this->actionSentence($msg->message))),
                    $this->fetchCmd(),
                ),
            ];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Federation Shares',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    public function crumbLabel(): string
    {
        return 'Federation Shares';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function selectedIndex(): int
    {
        return $this->selectedIndex;
    }

    public function isLoading(): bool
    {
        return $this->loading;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function withSelectedIndex(int $index): self
    {
        $next = clone $this;
        $next->selectedIndex = $index;

        return $next;
    }

    // ---- keyboard ----------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // [review console__docmaint m4] Escape is keyed by TYPE — the vendored
        // KeyMsg carries rune '' for it, so a rune-match arm could never fire
        // (and the original update() swallowed BOTH arms entirely).
        if (
            $msg->type === KeyType::Escape
            || ($msg->type === KeyType::Char && ($msg->rune === 'q' || $msg->rune === 'Q'))
        ) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->selectPrev();
        }

        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->selectNext();
        }

        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return $this->revokeSelected();
        }

        return [$this, null];
    }

    /**
     * Revoke the selected outgoing share. The verb is addressed by the row's
     * `id` — the exact key hub getOutgoingShares emits for every row.
     *
     * @return array{self, ?\Closure}
     */
    private function revokeSelected(): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->items)) {
            return [$this, null];
        }

        $item = $this->items[$this->selectedIndex];
        $id = Coerce::str($item['id'] ?? '');
        if ($id === '') {
            return [$this, null];
        }

        // Hub truth (revokeOutgoingShare): success is 204 with NO body, so the
        // resolved string is always '' — the screen supplies its own sentence.
        $promise = $this->hub->removeShare($id)->then(
            static fn (): Msg => new FederationShareActionDoneMsg('revoked'),
            static fn (\Throwable $e): Msg => new FederationSharesFailedMsg(
                ExceptionSentences::sentence($e, 'federation-shares-screen'),
            ),
        );

        return [$this, Cmd::promise(static fn (): PromiseInterface => $promise)];
    }

    // ---- rendering ---------------------------------------------------------

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading outgoing shares…\n\n" . Skeleton::bars($this->cols - 4, max(1, Chrome::bodyHeight($this->rows) - 4), $this->shimmerPhase(), $this->theme());
        }

        if ($this->error !== null) {
            return "\n\n  {$this->error}";
        }

        if ($this->items === []) {
            return "\n\n  You have not shared any library with another hub.\n"
                . '  Outgoing shares you create appear here so you can revoke them.';
        }

        $lines = [];
        foreach ($this->items as $i => $item) {
            $prefix = $i === $this->selectedIndex ? '▶ ' : '  ';
            $lines[] = $prefix . $this->renderRow($item);
        }

        return "\n\n" . implode("\n", $lines);
    }

    /** @param array<string, mixed> $item */
    private function renderRow(array $item): string
    {
        $name = Coerce::str($item['library_name'] ?? $item['library_id'] ?? $item['id'] ?? '');
        $peer = Coerce::str($item['peer_id'] ?? '');
        $permission = Coerce::str($item['permission'] ?? '');
        $status = Coerce::str($item['status'] ?? '');

        $detail = [];
        if ($peer !== '') {
            $detail[] = "to {$peer}";
        }
        if ($permission !== '') {
            $detail[] = $permission;
        }
        if ($status !== '') {
            $detail[] = $status;
        }
        $suffix = $detail === [] ? '' : '  (' . implode(', ', $detail) . ')';

        return Width::truncate($name . $suffix, $this->cols - 10);
    }

    /**
     * Clone into the in-flight state a re-read arms: spinner up, last error
     * cleared, stale rows still behind it (the Loaded/Failed arms settle it).
     */
    private function pending(): self
    {
        $next = clone $this;
        $next->loading = true;
        $next->error = null;

        return $next;
    }

    private function actionSentence(string $action): string
    {
        return match ($action) {
            'revoked' => 'Share revoked',
            default => 'Done',
        };
    }

    // ---- fetch (M-CONSOLE-36: ride the OUTER loop via Cmd::promise) --------

    /**
     * The hub fetch as an AsyncCmd closure. M-CONSOLE-36 law: NEVER call
     * Loop::get()->run() here — Program::scheduleCmd() invokes this closure
     * INSIDE the already-running global loop, whose shared StreamSelectLoop has
     * no re-entry guard (the nested drain's stop() kills the app pump).
     */
    private function fetchCmd(): \Closure
    {
        $promise = $this->hub->federationSharesOutgoing()->then(
            static fn (array $items): Msg => new FederationSharesOutgoingLoadedMsg($items),
            static fn (\Throwable $e): Msg => new FederationSharesFailedMsg(
                ExceptionSentences::sentence($e, 'federation-shares-screen'),
            ),
        );

        return Cmd::promise(static fn (): PromiseInterface => $promise);
    }
}
