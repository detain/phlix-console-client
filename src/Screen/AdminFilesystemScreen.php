<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminFilesystemFailedMsg;
use Phlix\Console\Msg\AdminFilesystemLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;

/**
 * The admin Filesystem Browser: a navigable listing of the server's filesystem.
 * Shows files and directories at the current path; directories can be navigated
 * into with Enter, and .. navigates up. Files show basic metadata.
 *
 * `r` refetches the current directory; Esc/q goes back.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data + flags are private mutable view state set via clone-mutate (the
 * established screen idiom).
 */
final class AdminFilesystemScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LIST_FAILED = 'Could not load the filesystem.';
    private const HINT = '↑↓  select      ⏎  enter dir      ←  parent      r  refresh      Esc  back';

    /** @var list<array{name:string,path:string,type:string,size:int,modified:string}> */
    private array $entries = [];
    private bool $loaded = false;
    private ?string $error = null;
    private string $currentPath = '/';
    /** @var list<string> */
    private array $pathHistory = [];
    private int $selected = 0;
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
        return $this->fetchCmd(null);
    }

    /**
     * @param string|null $path The path to browse, or null for root
     */
    private function fetchCmd(?string $path): \Closure
    {
        return Cmd::promise(fn () => $this->admin->browseFilesystem($path)->then(
            /**
             * @param list<array{name:string,path:string,type:string,size:int,modified:string}> $entries
             * @return AdminFilesystemLoadedMsg
             */
            function (array $entries) use ($path): Msg {
                return new AdminFilesystemLoadedMsg($entries, $path ?? '/');
            },
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminFilesystemFailedMsg(self::LIST_FAILED),
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
        if ($msg instanceof AdminFilesystemLoadedMsg) {
            return [$this->withEntries($msg->entries, $msg->currentPath), null];
        }
        if ($msg instanceof AdminFilesystemFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Filesystem', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reloading(), $this->fetchCmd($this->currentPath)];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }
        if ($msg->type === KeyType::Left) {
            return $this->navigateUp();
        }
        if ($msg->type === KeyType::Enter) {
            return $this->navigateInto();
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function navigateInto(): array
    {
        if ($this->entries === []) {
            return [$this, null];
        }

        $selected = $this->selected;
        if ($selected < 0 || $selected >= count($this->entries)) {
            return [$this, null];
        }

        $entry = $this->entries[$selected];
        if ($entry['type'] !== 'dir') {
            return [$this, null];
        }

        // Save current path to history for back navigation
        $next = clone $this;
        $next->pathHistory[] = $this->currentPath;
        $next->currentPath = $entry['path'];
        $next->selected = 0;
        $next->loaded = false;

        return [$next, $this->fetchCmd($entry['path'])];
    }

    /** @return array{self, ?\Closure} */
    private function navigateUp(): array
    {
        if ($this->pathHistory === []) {
            // At root, navigate to parent is a no-op
            return [$this, null];
        }

        $previousPath = array_pop($this->pathHistory);
        $next = clone $this;
        $next->currentPath = $previousPath;
        $next->selected = 0;
        $next->loaded = false;

        return [$next, $this->fetchCmd($previousPath)];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->error !== null && !$this->loaded) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if (!$this->loaded) {
            return "\n  Loading filesystem…";
        }
        if ($this->entries === []) {
            return "\n  " . Width::truncate('(empty directory)', $this->cols - 4);
        }

        $bodyHeight = Chrome::bodyHeight($this->rows);
        $maxVisible = max(1, $bodyHeight - 2);
        $start = max(0, min($this->selected - (int) floor($maxVisible / 2), count($this->entries) - $maxVisible));
        $visibleEntries = array_slice($this->entries, $start, $maxVisible);

        $accent = Style::new()->bold();
        $dim = Style::new()->dim();
        $out = [];

        // Header with current path
        $pathDisplay = Width::truncate($this->currentPath, $this->cols - 4);
        $out[] = '  ' . $dim->render($pathDisplay);
        $out[] = '';

        foreach ($visibleEntries as $i => $entry) {
            $globalIndex = $start + $i;
            $isSelected = $globalIndex === $this->selected;
            $prefix = $isSelected ? '› ' : '  ';

            $name = $entry['name'];
            if ($entry['type'] === 'dir') {
                $name .= '/';
                $name = $accent->render($name);
            } else {
                $name .= '  ' . $this->formatSize($entry['size']);
            }

            $line = $prefix . Width::truncate($name, $this->cols - 6);
            if ($isSelected) {
                $line = $accent->render($line);
            }

            $out[] = '  ' . $line;
        }

        return "\n" . implode("\n", $out);
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
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

    // ---- immutable copies (clone-mutate) -------------------------------

    /**
     * @param list<array{name:string,path:string,type:string,size:int,modified:string}> $entries
     */
    private function withEntries(array $entries, string $currentPath): self
    {
        $next = clone $this;
        $next->entries = $entries;
        $next->currentPath = $currentPath;
        $next->loaded = true;
        $next->error = null;
        $next->selected = 0;

        return $next;
    }

    private function withError(string $error): self
    {
        $next = clone $this;
        $next->error = $error;
        $next->loaded = true;

        return $next;
    }

    /** A copy back in the loading state (a manual `r` refetch). */
    private function reloading(): self
    {
        $next = clone $this;
        $next->loaded = false;
        $next->error = null;

        return $next;
    }

    private function moveSelection(int $delta): self
    {
        $count = count($this->entries);
        if ($count === 0) {
            return $this;
        }
        $selected = max(0, min($count - 1, $this->selected + $delta));
        if ($selected === $this->selected) {
            return $this;
        }
        $next = clone $this;
        $next->selected = $selected;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Filesystem';
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

    public function error(): ?string
    {
        return $this->error;
    }

    public function selectedIndex(): int
    {
        return $this->selected;
    }

    public function currentPath(): string
    {
        return $this->currentPath;
    }

    /**
     * @return list<array{name:string,path:string,type:string,size:int,modified:string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
