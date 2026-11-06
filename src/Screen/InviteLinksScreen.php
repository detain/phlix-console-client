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
use Phlix\Console\Msg\InviteLinkCreatedMsg;
use Phlix\Console\Msg\InviteLinkRevokedMsg;
use Phlix\Console\Msg\InviteLinksFailedMsg;
use Phlix\Console\Msg\InviteLinksLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
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
 * Lists the invite links the current user created on the hub
 * (`GET /api/v1/me/invite-links`): `c` creates one, `r` revokes the selected
 * link, `↑`/`↓` move the cursor.
 *
 * [review console__uxdesign C1, console__srphp M1] The first cut shipped
 * `view()` returning '' — the palette frame vanished into a blank terminal —
 * discarded the payload and the failure sentence, and FABRICATED an empty
 * `{id,code,url}` link out of the create call instead of the row the server
 * returns. It now renders the canonical {@see Chrome::frame} loading/error/
 * empty/list body, parses the create response at the boundary, mutates through
 * clones, and re-reads the list from the hub after every mutation (the server
 * owns the truth of which links remain).
 *
 * The screen implements the canonical {@see Themed}/{@see Breadcrumbed}
 * contracts — the dead `Phlix\Console\SugarCraft\Screen` twin interfaces it
 * once carried are retired [review console__srphp m3], which is what re-hooks
 * it into {@see \Phlix\Console\App::baseView()}'s theme/breadcrumb gates.
 */
final class InviteLinksScreen implements Model, Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;
    use ResizesTo;
    use SelectsNext;
    use SelectsPrevious;

    private const HINT = 'q/Esc  back      ↑↓  navigate      c  create      r  revoke';

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

        if ($msg instanceof InviteLinksLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->links;
            $next->loading = false;
            $next->error = null;
            // A re-read may have shrunk the list (a revoked link leaves it);
            // keep the cursor on a row that still exists.
            $next->selectedIndex = max(0, min($this->selectedIndex, count($next->items) - 1));

            return [$next, null];
        }

        if ($msg instanceof InviteLinksFailedMsg) {
            $next = clone $this;
            $next->error = $msg->message;
            $next->loading = false;

            return [$next, null];
        }

        if ($msg instanceof InviteLinkCreatedMsg) {
            // The 201 row crossed the boundary parsed; the toast says what
            // happened and the re-read shows the server's own list.
            return [
                $this->pending(),
                Cmd::batch(
                    Cmd::send(ShowToastMsg::success('Invite link created.')),
                    $this->fetchCmd(),
                ),
            ];
        }

        if ($msg instanceof InviteLinkRevokedMsg) {
            return [
                $this->pending(),
                Cmd::batch(
                    Cmd::send(ShowToastMsg::info('Invite link revoked.')),
                    $this->fetchCmd(),
                ),
            ];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame(
            'Invite Links',
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
        return 'Invite Links';
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
        // KeyMsg carries rune '' for it, so the old rune-match arm never fired.
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

        if ($msg->type === KeyType::Char && $msg->rune === 'c') {
            return [$this, $this->createCmd()];
        }

        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return $this->revokeSelected();
        }

        return [$this, null];
    }

    // ---- mutations ---------------------------------------------------------

    /**
     * Create a link and parse the 201 response at the boundary — the created
     * row rides the Msg instead of being discarded or fabricated [review
     * console__uxdesign C1].
     */
    private function createCmd(): \Closure
    {
        $promise = $this->hub->createInvite('Console invite')
            ->then(
                static fn (array $link): Msg => new InviteLinkCreatedMsg($link),
                static fn (\Throwable $e): Msg => new InviteLinksFailedMsg(
                    ExceptionSentences::sentence($e, 'invite-links-screen'),
                ),
            );

        return Cmd::promise(static fn (): PromiseInterface => $promise);
    }

    /** @return array{self, ?\Closure} */
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

        $promise = $this->hub->revokeInvite($id)
            ->then(
                static fn (): Msg => new InviteLinkRevokedMsg(),
                static fn (\Throwable $e): Msg => new InviteLinksFailedMsg(
                    ExceptionSentences::sentence($e, 'invite-links-screen'),
                ),
            );

        return [$this, Cmd::promise(static fn (): PromiseInterface => $promise)];
    }

    // ---- rendering ---------------------------------------------------------

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading invite links…";
        }

        if ($this->error !== null) {
            return "\n\n  {$this->error}";
        }

        if ($this->items === []) {
            return "\n\n  No invite links yet.\n"
                . '  Press c to create one — anyone with its URL can join the shared library.';
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
        $url = Coerce::str($item['url'] ?? $item['id'] ?? '');
        $permission = Coerce::str($item['permission'] ?? '');
        $uses = Coerce::nint($item['use_count'] ?? null);
        $maxUses = Coerce::nint($item['max_uses'] ?? null);

        $detail = [];
        if ($permission !== '') {
            $detail[] = $permission;
        }
        if ($uses !== null && $maxUses !== null) {
            $detail[] = "{$uses}/{$maxUses} used";
        }
        $suffix = $detail === [] ? '' : '  (' . implode(', ', $detail) . ')';

        return Width::truncate($url . $suffix, $this->cols - 10);
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

    // ---- fetch (M-CONSOLE-36: ride the OUTER loop via Cmd::promise) --------

    /**
     * The hub fetch as an AsyncCmd closure. M-CONSOLE-36 law: NEVER call
     * Loop::get()->run() here — Program::scheduleCmd() invokes this closure
     * INSIDE the already-running global loop, whose shared StreamSelectLoop has
     * no re-entry guard (the nested drain's stop() kills the app pump).
     */
    private function fetchCmd(): \Closure
    {
        $promise = $this->hub->inviteLinks()->then(
            static fn (array $links): Msg => new InviteLinksLoadedMsg($links),
            static fn (\Throwable $e): Msg => new InviteLinksFailedMsg(
                ExceptionSentences::sentence($e, 'invite-links-screen'),
            ),
        );

        return Cmd::promise(static fn (): PromiseInterface => $promise);
    }
}
