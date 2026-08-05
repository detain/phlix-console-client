<?php
declare(strict_types=1);
namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Msg\AdminMetadataMatchLoadedMsg;
use Phlix\Console\Msg\AdminMetadataMatchPostersLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\ShowToastMsg;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Toast\ToastType;

/**
 * The admin metadata match screen: shows items needing metadata review
 * and allows selecting alternate posters.
 *
 * `Enter` on an item loads poster candidates. `p` opens poster picker.
 * `r` refetches; Esc/q go back.
 */
final class AdminMetadataMatchScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    /** @var array<int, array{id:string,title:string,type:string,poster_url:?string}> */
    private array $items = [];
    private bool $loading = true;
    private int $selectedIndex = 0;
    private ?string $selectedItemId = null;
    private bool $showPosterPicker = false;
    /** @var array<int, array{url:string,thumb:string,width:int,height:int}> */
    private array $posterCandidates = [];
    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $adminClient,
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
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof AdminMetadataMatchLoadedMsg) {
            $next = clone $this;
            $next->items = $msg->items;
            $next->loading = false;

            return [$next, null];
        }
        if ($msg instanceof AdminMetadataMatchPostersLoadedMsg) {
            $next = clone $this;
            $next->posterCandidates = $msg->posters;

            return [$next, null];
        }

        return [$this, null];
    }

    private function fetchCmd(): \Closure
    {
        $next = clone $this;
        $next->loading = true;

        return Cmd::promise(
            fn (): PromiseInterface => $this->adminClient->metadataMatchSuggestions()
                ->then(
                    /**
                     * @param mixed $result
                     * @return AdminMetadataMatchLoadedMsg
                     */
                    static function ($result): AdminMetadataMatchLoadedMsg {
                        /** @var list<array{id:string,title:string,type:string,poster_url:?string}> $items */
                        $items = is_array($result) ? $result : [];
                        return new AdminMetadataMatchLoadedMsg($items);
                    },
                    static fn (\Throwable $e): Msg => ShowToastMsg::error('Failed: ' . $e->getMessage()),
                ),
        );
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // Escape or q goes back
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        // r refreshes
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this, $this->fetchCmd()];
        }

        // Enter on an item to select it and load poster candidates
        if ($msg->type === KeyType::Enter && !$this->showPosterPicker && isset($this->items[$this->selectedIndex])) {
            $itemId = $this->items[$this->selectedIndex]['id'];
            $next = clone $this;
            $next->selectedItemId = $itemId;

            return [$next, $this->loadPosterCandidatesCmd($itemId)];
        }

        // p opens poster picker
        if ($msg->type === KeyType::Char && $msg->rune === 'p' && $this->selectedItemId !== null && !$this->showPosterPicker) {
            $next = clone $this;
            $next->showPosterPicker = true;

            return [$next, null];
        }

        // Handle poster picker navigation
        if ($this->showPosterPicker) {
            return $this->handlePosterPickerKey($msg);
        }

        // j or down arrow moves selection down
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            $idx = min($this->selectedIndex + 1, count($this->items) - 1);
            $next = clone $this;
            $next->selectedIndex = $idx;

            return [$next, null];
        }

        // k or up arrow moves selection up
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            $idx = max($this->selectedIndex - 1, 0);
            $next = clone $this;
            $next->selectedIndex = $idx;

            return [$next, null];
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handlePosterPickerKey(KeyMsg $msg): array
    {
        // Escape or q closes poster picker
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            $next = clone $this;
            $next->showPosterPicker = false;

            return [$next, null];
        }

        // Enter confirms poster selection
        if ($msg->type === KeyType::Enter && isset($this->posterCandidates[$this->selectedIndex])) {
            $candidate = $this->posterCandidates[$this->selectedIndex];
            $itemId = $this->selectedItemId;
            if ($itemId === null) {
                return [$this, null];
            }
            $next = clone $this;
            $next->showPosterPicker = false;
            $next->posterCandidates = [];

            return [
                $next,
                Cmd::promise(
                    fn (): PromiseInterface => $this->adminClient->setAlternatePoster($itemId, $candidate['url'])
                        ->then(
                            static fn () => ShowToastMsg::success('Poster updated'),
                            static fn (\Throwable $e): Msg => ShowToastMsg::error('Failed: ' . $e->getMessage()),
                        ),
                ),
            ];
        }

        // j or down moves selection down
        if ($msg->type === KeyType::Down || ($msg->type === KeyType::Char && $msg->rune === 'j')) {
            $idx = min($this->selectedIndex + 1, count($this->posterCandidates) - 1);
            $next = clone $this;
            $next->selectedIndex = $idx;

            return [$next, null];
        }

        // k or up moves selection up
        if ($msg->type === KeyType::Up || ($msg->type === KeyType::Char && $msg->rune === 'k')) {
            $idx = max($this->selectedIndex - 1, 0);
            $next = clone $this;
            $next->selectedIndex = $idx;

            return [$next, null];
        }

        return [$this, null];
    }

    private function loadPosterCandidatesCmd(string $itemId): \Closure
    {
        return Cmd::promise(
            fn (): PromiseInterface => $this->adminClient->alternatePosters($itemId)
                ->then(
                    static function (array $result): AdminMetadataMatchPostersLoadedMsg {
                        // Extract posters from the structured response
                        $posters = [];
                        $providers = $result['providers'];
                        foreach ($providers as $provider) {
                            $providerPosters = $provider['posters'];
                            foreach ($providerPosters as $poster) {
                                $posters[] = [
                                    'url' => $poster['url'],
                                    'thumb' => $poster['url'],
                                    'width' => $poster['width'],
                                    'height' => $poster['height'],
                                ];
                            }
                        }

                        return new AdminMetadataMatchPostersLoadedMsg($posters);
                    },
                    static fn (\Throwable $e): Msg => ShowToastMsg::error('Failed: ' . $e->getMessage()),
                ),
        );
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Metadata Match';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    public function view(): string
    {
        $body = '';

        if ($this->loading) {
            $body .= "Loading...\n";
        } elseif ($this->items === []) {
            $body .= "No items need metadata matching.\n";
        } else {
            foreach ($this->items as $idx => $item) {
                $prefix = $idx === $this->selectedIndex ? '>' : ' ';
                $title = $item['title'];
                $type = $item['type'];
                $body .= "{$prefix} [{$type}] {$title}\n";
            }
        }

        if ($this->showPosterPicker && $this->posterCandidates !== []) {
            $body .= "\n--- Poster Candidates ---\n";
            foreach ($this->posterCandidates as $idx => $candidate) {
                $prefix = $idx === $this->selectedIndex ? '>' : ' ';
                $body .= "{$prefix} {$candidate['width']}x{$candidate['height']}\n";
            }
        }

        return $body;
    }
}