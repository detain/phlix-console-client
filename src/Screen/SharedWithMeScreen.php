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
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SharedWithMeActionDoneMsg;
use Phlix\Console\Msg\SharedWithMeFailedMsg;
use Phlix\Console\Msg\SharedWithMeLoadedMsg;
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
 * Lists the incoming federation share offers — libraries other hubs have
 * offered to this account (hub `GET /api/v1/me/federation/library-shares/
 * incoming`) — and answers them: `a` accepts the selected offer, `r` rejects
 * it, `↑`/`↓` move the cursor.
 *
 * The list rides the SAME endpoint family as the accept/reject verbs on
 * purpose [review console__uxdesign C1]: the first cut listed
 * `/api/v1/me/shares` (already-materialized shares) but answered
 * `/library-shares/incoming/{id}` (pending offers), so no id it displayed
 * could ever be accepted. Offers and answers now share one resource.
 *
 * [review console__uxdesign C1, console__srphp M1] The first cut also shipped
 * `view()` returning '' — the palette frame vanished into a blank terminal —
 * and its update() dropped both the payload and the failure sentence, so
 * every state of this screen looked dead. It now renders the canonical
 * {@see Chrome::frame} loading/error/empty/list body, mutates through clones,
 * and every action result rides a toast plus a server re-read (the hub owns
 * the truth of which offers remain pending).
 *
 * The screen implements the canonical {@see Themed}/{@see Breadcrumbed}
 * contracts — the dead `Phlix\Console\SugarCraft\Screen` twin interfaces it
 * once carried are retired [review console__srphp m3], which is what re-hooks
 * it into {@see \Phlix\Console\App::baseView()}'s theme/breadcrumb gates.
 */
final class SharedWithMeScreen implements Model, Breadcrumbed, Themed, Loadable, Shimmering
{
    use SubscriptionCapable;
    use ThemedScreen;
    use ShimmeringScreen;
    use ResizesTo;
    use SelectsNext;
    use SelectsPrevious;

    private const HINT = 'q/Esc  back      ↑↓  navigate      a  accept      r  reject';
    /** Failed-load hint: names only keys the error state arms [CON-44]. */
    private const ERROR_HINT = 'r  retry      q/Esc  back';

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

        if ($msg instanceof SharedWithMeLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->shares;
            $next->loading = false;
            $next->error = null;
            // A re-read may have shrunk the list (an answered offer leaves
            // it); keep the cursor on a row that still exists.
            $next->selectedIndex = max(0, min($this->selectedIndex, count($next->items) - 1));

            return [$next, null];
        }

        if ($msg instanceof SharedWithMeFailedMsg) {
            $next = clone $this;
            $next->error = $msg->message;
            $next->loading = false;

            return [$next, null];
        }

        if ($msg instanceof SharedWithMeActionDoneMsg) {
            // The accept/reject landed server-side: say so, then re-read the
            // offers from the hub — never hand-patch the list locally.
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
            'Shared With Me',
            $this->body(),
            $this->error !== null ? self::ERROR_HINT : self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    public function crumbLabel(): string
    {
        return 'Shared With Me';
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
        // [review console__docmaint m4] the old screen keyed 'Escape' off
        // $msg->rune — the vendored KeyMsg carries rune '' for Escape, so the
        // arm was dead code. Route on the KEY TYPE, exactly like every other
        // screen's back arm.
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

        if ($msg->type === KeyType::Char && $msg->rune === 'a') {
            return $this->answerSelected('accept');
        }

        if ($this->error !== null && $msg->type === KeyType::Char && $msg->rune === 'r') {
            // Failed load: 'r' is the retry the error hint publishes [CON-44].
            return [$this->pending(), $this->fetchCmd()];
        }

        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return $this->answerSelected('reject');
        }

        return [$this, null];
    }

    /**
     * Accept or reject the selected offer. The id walk covers the wire: the
     * federation offer row carries `id`; a `share_id` twin keeps the verb alive
     * if a payload shape ever lands here instead.
     *
     * @param 'accept'|'reject' $verb
     *
     * @return array{self, ?\Closure}
     */
    private function answerSelected(string $verb): array
    {
        if ($this->selectedIndex < 0 || $this->selectedIndex >= count($this->items)) {
            return [$this, null];
        }

        $item = $this->items[$this->selectedIndex];
        $id = Coerce::str($item['id'] ?? $item['share_id'] ?? '');
        if ($id === '') {
            return [$this, null];
        }

        $promise = ($verb === 'accept' ? $this->hub->acceptShare($id) : $this->hub->rejectShare($id))
            ->then(
                static fn (): Msg => new SharedWithMeActionDoneMsg($verb === 'accept' ? 'accepted' : 'rejected'),
                static fn (\Throwable $e): Msg => new SharedWithMeFailedMsg(
                    ExceptionSentences::sentence($e, 'shared-with-me-screen'),
                ),
            );

        return [$this, Cmd::promise(static fn (): PromiseInterface => $promise)];
    }

    // ---- rendering ---------------------------------------------------------

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading shared libraries…\n\n" . Skeleton::bars($this->cols - 4, max(1, Chrome::bodyHeight($this->rows) - 4), $this->shimmerPhase(), $this->theme());
        }

        if ($this->error !== null) {
            return "\n\n  " . Chrome::errorLine($this->error, $this->cols, $this->theme());
        }

        if ($this->items === []) {
            return "\n\n  Nothing is waiting for you here.\n"
                . '  When another hub offers you a library, it lands here to accept or reject.';
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
        $name = Coerce::str($item['library_name'] ?? $item['server_name'] ?? $item['id'] ?? '');
        $owner = Coerce::str($item['peer_id'] ?? $item['owner_name'] ?? '');
        $permission = Coerce::str($item['permission'] ?? $item['permission_level'] ?? '');

        $detail = [];
        if ($owner !== '') {
            $detail[] = "from {$owner}";
        }
        if ($permission !== '') {
            $detail[] = $permission;
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
            'accepted' => 'Share accepted',
            'rejected' => 'Share rejected',
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
        $promise = $this->hub->sharedWithMe()->then(
            static fn (array $items): Msg => new SharedWithMeLoadedMsg($items),
            static fn (\Throwable $e): Msg => new SharedWithMeFailedMsg(
                ExceptionSentences::sentence($e, 'shared-with-me-screen'),
            ),
        );

        return Cmd::promise(static fn (): PromiseInterface => $promise);
    }
}
