<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Msg\AdminMetricsFailedMsg;
use Phlix\Console\Msg\AdminMetricsLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Ui\Chrome;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;

/**
 * The admin Metrics & Traffic screen: read-only panels showing server metrics
 * from the four {@see MetricsController} endpoints (snapshot, history, connections,
 * routes). `r` refetches; Esc/q go back. A fetch failure shows a line plus a
 * retry hint; an auth failure surfaces a session expiry.
 *
 * The client is injected (built locally by the App from its shared ApiClient, so
 * the App holds no AdminClient field). Stable collaborators are readonly; the
 * loaded data + flags are private mutable view state set via clone-mutate (the
 * established screen idiom).
 */
final class AdminMetricsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load the metrics.';
    private const HINT = 'r  refresh      Esc  back';

    /** @var array<string,mixed>|null */
    private ?array $snapshot = null;
    /** @var list<array<string,mixed>> */
    private array $history = [];
    /** @var list<array<string,mixed>> */
    private array $connections = [];
    /** @var list<array<string,mixed>> */
    private array $routes = [];
    private bool $loaded = false;
    private ?string $error = null;
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
        return $this->fetchCmd();
    }

    private function fetchCmd(): \Closure
    {
        return Cmd::promise(fn () => $this->admin->metricsSnapshot()->then(
            static fn (array $snapshot): Msg => new AdminMetricsLoadedMsg($snapshot, [], [], []),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : new AdminMetricsFailedMsg(self::LOAD_FAILED),
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
        if ($msg instanceof AdminMetricsLoadedMsg) {
            return [$this->withMetrics($msg->snapshot, $msg->history, $msg->connections, $msg->routes), null];
        }
        if ($msg instanceof AdminMetricsFailedMsg) {
            return [$this->withError($msg->message), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return Chrome::frame('Admin · Metrics', $this->body(), self::HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'r') {
            return [$this->reloading(), $this->fetchCmd()];
        }

        return [$this, null];
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        if ($this->error !== null) {
            return "\n  {$this->error}\n\n  Press r to retry.";
        }
        if (!$this->loaded) {
            return "\n  Loading metrics…";
        }

        $sections = [
            $this->snapshotPanel(),
            $this->historyPanel(),
            $this->connectionsPanel(),
            $this->routesPanel(),
        ];

        return "\n" . implode("\n\n", array_filter($sections));
    }

    private function snapshotPanel(): string
    {
        $snapshot = $this->snapshot;
        if ($snapshot === null) {
            return '';
        }

        /** @var float $bytesIn */
        $bytesIn = $snapshot['bytes_in_per_sec'] ?? 0.0;
        /** @var float $bytesOut */
        $bytesOut = $snapshot['bytes_out_per_sec'] ?? 0.0;
        /** @var int $connections */
        $connections = $snapshot['active_connections'] ?? 0;
        /** @var float $requestsPerSec */
        $requestsPerSec = $snapshot['requests_per_sec'] ?? 0.0;
        /** @var float $errorRate */
        $errorRate = $snapshot['error_rate'] ?? 0.0;
        /** @var float $p50 */
        $p50 = $snapshot['p50_ms'] ?? 0.0;
        /** @var float $p95 */
        $p95 = $snapshot['p95_ms'] ?? 0.0;
        /** @var float $p99 */
        $p99 = $snapshot['p99_ms'] ?? 0.0;

        $lines = [
            'Bytes in/sec    ' . $this->formatFloat($bytesIn),
            'Bytes out/sec   ' . $this->formatFloat($bytesOut),
            'Active conns    ' . $connections,
            'Requests/sec    ' . $this->formatFloat($requestsPerSec),
            'Error rate      ' . $this->formatFloat($errorRate) . '%',
            'Latency p50     ' . $this->formatMs($p50),
            'Latency p95     ' . $this->formatMs($p95),
            'Latency p99     ' . $this->formatMs($p99),
        ];

        return self::panel('Snapshot', $lines);
    }

    private function historyPanel(): string
    {
        if ($this->history === []) {
            return self::panel('History', ['No history data.']);
        }

        $lines = [];
        foreach (array_slice($this->history, 0, 8) as $bucket) {
            $bucketVal = $bucket['bucket'] ?? null;
            $requestsVal = $bucket['requests'] ?? null;
            $errorsVal = $bucket['errors'] ?? null;
            $p95Val = $bucket['p95_ms'] ?? null;
            /** @var int $timestamp */
            $timestamp = is_numeric($bucketVal) ? (int) $bucketVal : 0;
            /** @var int $req */
            $req = is_numeric($requestsVal) ? (int) $requestsVal : 0;
            /** @var int $err */
            $err = is_numeric($errorsVal) ? (int) $errorsVal : 0;
            /** @var float $p95 */
            $p95 = is_numeric($p95Val) ? (float) $p95Val : 0.0;
            $time = date('H:i', $timestamp);
            $lines[] = "{$time}  req:{$req}  err:{$err}  p95:" . $this->formatMs($p95);
        }

        return self::panel('History (recent)', $lines);
    }

    private function connectionsPanel(): string
    {
        if ($this->connections === []) {
            return self::panel('Live Connections', ['No active connections.']);
        }

        $lines = [];
        foreach (array_slice($this->connections, 0, 10) as $conn) {
            $kindVal = $conn['kind'] ?? null;
            $userVal = $conn['user_id'] ?? null;
            $ipVal = $conn['remote_ip'] ?? null;
            /** @var string $kind */
            $kind = is_string($kindVal) ? $kindVal : '?';
            /** @var string $user */
            $user = is_string($userVal) ? $userVal : 'anon';
            /** @var string $ip */
            $ip = is_string($ipVal) ? $ipVal : '?';
            $lines[] = "{$kind}  user:{$user}  ip:{$ip}";
        }

        return self::panel('Live Connections', $lines);
    }

    private function routesPanel(): string
    {
        if ($this->routes === []) {
            return self::panel('Top Routes', ['No route data.']);
        }

        $lines = [];
        foreach (array_slice($this->routes, 0, 10) as $route) {
            $methodVal = $route['method'] ?? null;
            $pathVal = $route['route'] ?? null;
            $countVal = $route['request_count'] ?? null;
            $p95Val = $route['p95_ms'] ?? null;
            /** @var string $method */
            $method = is_string($methodVal) ? $methodVal : '?';
            /** @var string $path */
            $path = is_string($pathVal) ? $pathVal : '/?';
            /** @var int $count */
            $count = is_numeric($countVal) ? (int) $countVal : 0;
            /** @var float $p95 */
            $p95 = is_numeric($p95Val) ? (float) $p95Val : 0.0;
            // Truncate long routes
            if (strlen($path) > 30) {
                $path = '...' . substr($path, -27);
            }
            $lines[] = "{$method} {$path}  {$count}  p95:" . $this->formatMs($p95);
        }

        return self::panel('Top Routes', $lines);
    }

    /**
     * A titled panel: the heading then its lines, each indented two spaces.
     *
     * @param list<string> $lines
     */
    private static function panel(string $title, array $lines): string
    {
        if ($lines === []) {
            return '';
        }
        $out = '  ' . $title;
        foreach ($lines as $line) {
            $out .= "\n    " . $line;
        }

        return $out;
    }

    private function formatFloat(float $value): string
    {
        if ($value >= 1000) {
            return number_format($value, 0);
        }
        if ($value >= 1) {
            return number_format($value, 1);
        }

        return number_format($value, 2);
    }

    private function formatMs(float $value): string
    {
        if ($value >= 1000) {
            return number_format($value / 1000, 1) . 's';
        }

        return number_format($value, 0) . 'ms';
    }

    // ---- immutable copies (clone-mutate) -------------------------------

    /**
     * @param array<string,mixed>       $snapshot
     * @param list<array<string,mixed>>  $history
     * @param list<array<string,mixed>>  $connections
     * @param list<array<string,mixed>>  $routes
     */
    private function withMetrics(array $snapshot, array $history, array $connections, array $routes): self
    {
        $next = clone $this;
        $next->snapshot = $snapshot;
        $next->history = $history;
        $next->connections = $connections;
        $next->routes = $routes;
        $next->loaded = true;
        $next->error = null;

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
        return 'Metrics';
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

    /** @return array<string,mixed>|null */
    public function snapshot(): ?array
    {
        return $this->snapshot;
    }
}
