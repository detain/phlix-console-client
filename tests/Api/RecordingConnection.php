<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Api;

use Workerman\Connection\AsyncTcpConnection;

/**
 * Recording stand-in for the socket OBJECT ONLY (mirrors the S414 wire-shape
 * harness): constructed without touching the network; the ladder tests drive
 * it by invoking the handlers production registered on its public properties.
 */
final class RecordingConnection extends AsyncTcpConnection
{
    /** @var list<string> */
    public array $sent = [];

    public int $closeCalls = 0;

    public function __construct()
    {
        // Deliberately skips parent::__construct — no socket, no DNS,
        // no event loop. Only send()/close()/handlers participate.
    }

    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        $this->sent[] = (string) $sendBuffer;

        return true;
    }

    public function connect(): void
    {
        // The test drives onConnect manually, exactly as Workerman would.
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->closeCalls++;
    }

    public function destroy(): void
    {
    }
}
