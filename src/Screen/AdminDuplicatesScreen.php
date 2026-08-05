<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminDuplicatesFailedMsg;
use Phlix\Console\Msg\AdminDuplicatesLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin duplicate-groups surface: a scrollable list of duplicate groups
 * for a selected library. Each group shows the canonical item and how many
 * duplicates it has.
 *
 * `Enter` opens the group to show all duplicate items and arm a merge with
 * typed confirmation. `r` refetches; Esc/q go back. A fetch failure shows a
 * line plus a retry hint; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data + flags are private mutable view state set via clone-mutate (the
 * established screen idiom).
 */
final class AdminDuplicatesScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load duplicate groups.';
    private const HINT = 'Enter view   r refresh   Esc back';
    private const GROUP_HINT = 'm arm-merge   Esc close';

    /** @var list<array{canonical_key:string,type:string,library_id:string,primary:array<string,mixed>,duplicates:list<array<string,mixed>>}> */
    private array $groups = [];
    private bool $loading = true;
    private ?string $error = null;
    private int $selectedIndex = 0;
    /** @var list<string> */
    private array $crumbs = [];

    /** Whether the group detail sub-view is open. */
    private bool $groupOpen = false;

    /** The index of the group being examined in the detail sub-view. */
    private int $groupIndex = 0;

    /** An armed merge awaiting typed confirmation, or null. */
    private ?DuplicatePendingAction $pendingMerge = null;

    public function __construct(
        private readonly AdminClient $admin,
        private readonly string $libraryId,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd();
    }

    private function fetchCmd(): \Closure
    {
        $libraryId = $this->libraryId;

        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->admin->duplicates($libraryId)
            ->then(
                static fn (array $groups) => new AdminDuplicatesLoadedMsg($libraryId, $groups),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : new AdminDuplicatesFailedMsg(self::LOAD_FAILED),
            ));
    }

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminDuplicatesLoadedMsg) {
            $next = clone $this;
            $next->groups = $msg->groups;
            $next->loading = false;
            $next->error = null;
            $next->selectedIndex = 0;
            $next->groupOpen = false;
            $next->pendingMerge = null;

            return [$next, null];
        }
        if ($msg instanceof AdminDuplicatesFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->groupOpen) {
            return Chrome::frame(
                'Admin · Duplicates · Group',
                $this->groupBody(),
                self::GROUP_HINT,
                $this->cols,
                $this->rows,
                $this->crumbs,
                $this->theme(),
            );
        }

        return Chrome::frame(
            'Admin · Duplicates',
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // Armed merge captures all input
        if ($this->pendingMerge !== null) {
            return $this->handleMergeKey($msg);
        }
        // Group sub-view captures input before main list
        if ($this->groupOpen) {
            return $this->handleGroupKey($msg);
        }

        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reloading(), $this->fetchCmd()];
        }
        if ($msg->type === KeyType::Enter) {
            return $this->openGroup();
        }
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            return $this->selectPrev();
        }
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            return $this->selectNext();
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleGroupKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this->closingGroup(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'm') {
            return $this->armMerge();
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleMergeKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape) {
            return [$this->cancelMerge(), null];
        }
        if ($msg->type === KeyType::Char && $msg->rune !== '') {
            $action = $this->pendingMerge;
            if ($action === null) {
                return [$this, null];
            }
            $next = $action->withTyped($msg->rune);
            if ($next->isConfirmed()) {
                return [$this->working(), $this->mergeCmd($action)];
            }

            return [$this->withPendingMerge($next), null];
        }

        return [$this, null];
    }

    // ---- merge command -------------------------------------------------

    private function mergeCmd(DuplicatePendingAction $action): \Closure
    {
        return Cmd::promise(fn (): \React\Promise\PromiseInterface => $this->admin->merge($action->primaryId, $action->duplicateIds)
            ->then(
                /** @param array{moved: int, deleted: int} $result */
                static fn (array $result): Msg => ShowToastMsg::success(sprintf('Merge complete: %d moved, %d deleted.', $result['moved'], $result['deleted'])),
                static fn (\Throwable $e): Msg => $e instanceof AuthError
                    ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                    : ShowToastMsg::error('Merge failed: ' . $e->getMessage()),
            ));
    }

    // ---- selection -----------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function selectPrev(): array
    {
        if ($this->selectedIndex > 0) {
            return [$this->withSelectedIndex($this->selectedIndex - 1), null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function selectNext(): array
    {
        if ($this->selectedIndex < count($this->groups) - 1) {
            return [$this->withSelectedIndex($this->selectedIndex + 1), null];
        }

        return [$this, null];
    }

    private function withSelectedIndex(int $index): self
    {
        $next = clone $this;
        $next->selectedIndex = $index;

        return $next;
    }

    // ---- group sub-view ------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function openGroup(): array
    {
        if ($this->groups === []) {
            return [$this, null];
        }

        return [$this->openingGroup(), null];
    }

    private function openingGroup(): self
    {
        $next = clone $this;
        $next->groupOpen = true;
        $next->groupIndex = $this->selectedIndex;
        $next->pendingMerge = null;

        return $next;
    }

    private function closingGroup(): self
    {
        $next = clone $this;
        $next->groupOpen = false;
        $next->pendingMerge = null;

        return $next;
    }

    /** @return array{canonical_key:string,type:string,library_id:string,primary:array<string,mixed>,duplicates:list<array<string,mixed>>}|null */
    private function selectedGroup(): ?array
    {
        return $this->groups[$this->groupIndex] ?? null;
    }

    // ---- merge ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function armMerge(): array
    {
        $group = $this->selectedGroup();
        if ($group === null) {
            return [$this, null];
        }
        $primary = $group['primary'];
        $primaryId = \Phlix\Console\Api\Dto\Coerce::str($primary['id'] ?? null);
        $primaryName = \Phlix\Console\Api\Dto\Coerce::str($primary['name'] ?? null, $primaryId);
        /** @var list<string> $duplicateIds */
        $duplicateIds = \Phlix\Console\Api\Dto\Coerce::stringList(array_column($group['duplicates'], 'id'));

        $library = new \Phlix\Console\Api\Dto\Library(
            id: $this->libraryId,
            name: '',
            type: '',
            paths: [],
            options: [],
            displayOrder: 0,
            itemCount: 0,
            createdAt: null,
            updatedAt: null,
        );

        return [$this->withPendingMerge(new DuplicatePendingAction($library, $primaryId, $primaryName, $duplicateIds)), null];
    }

    private function withPendingMerge(DuplicatePendingAction $action): self
    {
        $next = clone $this;
        $next->pendingMerge = $action;

        return $next;
    }

    private function cancelMerge(): self
    {
        $next = clone $this;
        $next->pendingMerge = null;

        return $next;
    }

    private function working(): self
    {
        $next = clone $this;
        $next->pendingMerge = null;
        $next->groupOpen = false;

        return $next;
    }

    // ---- error ---------------------------------------------------------

    private function withError(string $reason): self
    {
        $next = clone $this;
        $next->error = $reason;
        $next->loading = false;

        return $next;
    }

    private function reloading(): self
    {
        $next = clone $this;
        $next->loading = true;
        $next->error = null;

        return $next;
    }

    // ---- clone-mutate --------------------------------------------------

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
        if ($this->loading) {
            return "\n\n  Loading duplicate groups…";
        }
        if ($this->error !== null) {
            return "\n\n  {$this->error}\n  Press r to retry.";
        }
        if ($this->groups === []) {
            return "\n\n  No duplicate groups found.";
        }

        return "\n" . Table::render(
            [
                ['title' => 'Canonical', 'width' => 0],
                ['title' => 'Type', 'width' => 12],
                ['title' => 'Duplicates', 'width' => 11, 'align' => 'right'],
            ],
            $this->tableRows(),
            $this->selectedIndex,
            $this->cols - 4,
            Chrome::bodyHeight($this->rows),
        );
    }

    /** @return list<array{string}> */
    private function tableRows(): array
    {
        $rows = [];
        foreach ($this->groups as $group) {
            $primary = $group['primary'];
            $primaryId = \Phlix\Console\Api\Dto\Coerce::str($primary['id'] ?? null);
            $primaryName = \Phlix\Console\Api\Dto\Coerce::str($primary['name'] ?? null, $primaryId);
            if ($primaryName === '') {
                $primaryName = '(unknown)';
            }
            $type = $group['type'] ?: '—';
            $dupCount = count($group['duplicates']);
            $dupLabel = $dupCount === 1 ? '1 item' : "{$dupCount} items";

            $rows[] = [
                $this->truncate($primaryName, $this->cols - 32),
                $type,
                $dupLabel,
            ];
        }

        return $rows;
    }

    private function groupBody(): string
    {
        $group = $this->selectedGroup();
        if ($group === null) {
            return "\n\n  Group not found.";
        }

        $primary = $group['primary'];
        $primaryId = \Phlix\Console\Api\Dto\Coerce::str($primary['id'] ?? null);
        $primaryName = \Phlix\Console\Api\Dto\Coerce::str($primary['name'] ?? null, $primaryId);
        if ($primaryName === '') {
            $primaryName = '(unknown)';
        }
        $duplicates = $group['duplicates'];
        $dupCount = count($duplicates);
        $dupLabel = $dupCount === 1 ? '1 duplicate' : "{$dupCount} duplicates";
        $type = $group['type'] ?: '—';

        $lines = [];
        $lines[] = "  Canonical: {$primaryName}";
        $lines[] = "  Type: {$type}";
        $lines[] = "  {$dupLabel}";
        $lines[] = '';

        if ($duplicates !== []) {
            $rows = [];
            foreach ($duplicates as $dup) {
                $dupId = \Phlix\Console\Api\Dto\Coerce::str($dup['id'] ?? null);
                $dupName = \Phlix\Console\Api\Dto\Coerce::str($dup['name'] ?? null, $dupId);
                if ($dupName === '') {
                    $dupName = '(unknown)';
                }
                $rows[] = [$this->truncate($dupName, $this->cols - 8)];
            }

            $table = Table::render(
                [['title' => 'Duplicate', 'width' => 0]],
                $rows,
                -1,
                $this->cols - 4,
                max(1, Chrome::bodyHeight($this->rows) - 7),
            );
            $lines[] = $table;
        }

        $lines[] = '';
        $lines[] = '  Press m to arm merge, Esc to close.';

        // Show merge confirmation prompt if armed
        if ($this->pendingMerge !== null) {
            $lines[] = '';
            $lines[] = '  ' . $this->pendingMerge->prompt();
        }

        return "\n" . implode("\n", $lines);
    }

    private function truncate(string $text, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $maxWidth) {
            return $text;
        }

        return mb_substr($text, 0, $maxWidth - 1, 'UTF-8') . '…';
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Duplicates';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    // ---- accessors -----------------------------------------------------

    /** @return list<array{canonical_key:string,type:string,library_id:string,primary:array<string,mixed>,duplicates:list<array<string,mixed>>}> */
    public function groups(): array
    {
        return $this->groups;
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
}