<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiError;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Coerce;
use Phlix\Console\I18n\ExceptionSentences;
use Phlix\Console\I18n\Lang;
use Phlix\Console\Msg\InitMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\StatsFailedMsg;
use Phlix\Console\Msg\StatsLoadedMsg;
use Phlix\Console\Ui\Bytes;
use Phlix\Console\Ui\Chrome;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Model;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Server statistics — an ADMIN surface. The data comes from the admin stats
 * API (`/api/v1/admin/stats/*`) through {@see AdminClient}, so the palette
 * offers this screen only to admin accounts, behind the same `is_admin` gate
 * as the Admin menu [CU-021: review console__enduser #3]. The handler for a
 * directly dispatched OpenStatsMsg stays ungated exactly like openAdmin()'s
 * (the gate is on the door, not on the staircase); a mid-session demotion
 * still lands honestly: the API answers 403 and the frame body says the
 * account needs admin rights.
 *
 * CU-021 also retired the old chrome-less raw-string render [review
 * console__uxdesign #9]: view() now goes through the shared
 * {@see Chrome::frame} like its hub siblings (Breadcrumbed + Themed +
 * ResizesTo, WindowSizeMsg respected), the hint line is part of the render
 * (`Esc  back      r  refresh`), storage byte counts print human-readable
 * through the shared {@see Bytes::human} formatter, and a failed fetch shows
 * its reason IN THE FRAME instead of a toast over a spinner that never
 * settles (StatsFailedMsg arms the body; StatsLoadedMsg clears it).
 *
 * Truth notes measured against AdminClient (the previous body read keys the
 * wire never sends — `playback['active_sessions']` and `storage['used_bytes']`
 * on LIST payloads, so both lines silently printed 0): playback is a list of
 * per-date rows and storage is a list of per-library snapshot rows, rendered
 * here as sums.
 */
final class StatsScreen implements Model, Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;
    use ResizesTo;

    private const HINT = 'Esc  back      r  refresh';

    private bool $loading = false;

    /** @var array<string, mixed> */
    private array $stats = [];

    private ?string $error = null;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private AdminClient $adminClient,
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

        return match (true) {
            $msg instanceof InitMsg => [$this, $this->fetchCmd()],
            $msg instanceof KeyMsg => $this->handleKey($msg),
            $msg instanceof StatsLoadedMsg => $this->onLoaded($msg),
            $msg instanceof StatsFailedMsg => $this->onFailed($msg),
            default => [$this, null],
        };
    }

    public function view(): string
    {
        return Chrome::frame(
            'Server Statistics',
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
        return 'Server Statistics';
    }

    /** @param list<string> $trail */
    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }

    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn (): PromiseInterface => $this->doFetchCmd());
    }

    private const SESSION_EXPIRED_KEY = 'auth.session_expired';

    /** @return PromiseInterface<Msg> */
    private function doFetchCmd(): PromiseInterface
    {
        $this->loading = true;

        return $this->adminClient->statsOverview()
            ->then(static fn (array $stats): Msg => new StatsLoadedMsg($stats))
            ->catch(fn (\Throwable $e): Msg => match (true) {
                // 401 already arrives as the typed AuthError (ApiClient maps it);
                // a 403 ApiError means the account lost admin rights mid-session
                // — say so in the frame instead of a generic wire sentence
                // [CU-021: review console__enduser #3].
                $e instanceof AuthError => new SessionExpiredMsg(Lang::t(self::SESSION_EXPIRED_KEY)),
                $e instanceof ApiError && $e->statusCode === 403
                    => new StatsFailedMsg('Server statistics need an admin account.'),
                default => new StatsFailedMsg(
                    ExceptionSentences::sentence($e, 'stats overview', 'Could not load the server statistics.'),
                ),
            });
    }

    /** @return array{self, null} */
    private function onLoaded(StatsLoadedMsg $msg): array
    {
        $this->loading = false;
        $this->error = null;
        $this->stats = $msg->stats;

        return [$this, null];
    }

    /** @return array{self, null} */
    private function onFailed(StatsFailedMsg $msg): array
    {
        $this->loading = false;
        $this->error = $msg->message;

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // Escape is matched by TYPE — the vendored KeyMsg carries rune '' for it.
        if ($msg->type === KeyType::Escape) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }

        if ($msg->rune === 'r') {
            return [$this, $this->fetchCmd()];
        }

        return [$this, null];
    }

    // ---- rendering ---------------------------------------------------------

    private function body(): string
    {
        if ($this->loading) {
            return "\n\n  Loading stats…";
        }

        if ($this->error !== null) {
            return "\n\n  {$this->error}\n\n  Press r to retry.";
        }

        $playback = is_array($this->stats['playback'] ?? null) ? $this->stats['playback'] : [];
        $storage = is_array($this->stats['storage'] ?? null) ? $this->stats['storage'] : [];

        $plays = 0;
        $completed = 0;
        foreach ($playback as $row) {
            if (is_array($row)) {
                $plays += Coerce::int($row['play_count'] ?? 0);
                $completed += Coerce::int($row['completed_count'] ?? 0);
            }
        }

        $items = 0;
        $usedBytes = 0;
        $cacheBytes = 0;
        foreach ($storage as $row) {
            if (is_array($row)) {
                $items += Coerce::int($row['item_count'] ?? 0);
                $usedBytes += Coerce::int($row['total_bytes'] ?? 0);
                $cacheBytes += Coerce::int($row['transcode_cache_bytes'] ?? 0);
            }
        }

        return "\n\n  Playback:\n"
            . "    Plays: {$plays}\n"
            . "    Completed: {$completed}\n"
            . "\n"
            . "  Storage:\n"
            . "    Items: {$items}\n"
            . '    Used: ' . Bytes::human($usedBytes) . "\n"
            . '    Cache: ' . Bytes::human($cacheBytes) . "\n";
    }
}
