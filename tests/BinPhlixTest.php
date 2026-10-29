<?php

declare(strict_types=1);

namespace Phlix\Console\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the bin/phlix CLI entry point.
 *
 * Verifies:
 * - `help` exits 0 and prints help text
 * - `bogus-command` exits 2 and prints error to STDERR
 * - `--version` exits 0 and prints "phlix <version>"
 * - `version` exits 0 and prints "phlix <version>"
 */
final class BinPhlixTest extends TestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        $this->binPath = __DIR__ . '/../bin/phlix';
    }

    public function testHelpExitsZero(): void
    {
        $output = $this->runPhlix(['help']);
        $this->assertSame(0, $output['exitCode'], 'help should exit with code 0');
    }

    public function testHelpPrintsUsage(): void
    {
        $output = $this->runPhlix(['help']);
        $this->assertStringContainsString('phlix — Phlix console client', $output['stdout']);
        $this->assertStringContainsString('phlix doctor', $output['stdout']);
        $this->assertStringContainsString('phlix poster', $output['stdout']);
        $this->assertStringContainsString('phlix frame', $output['stdout']);
        $this->assertStringContainsString('phlix run', $output['stdout']);
        $this->assertStringContainsString('phlix version', $output['stdout']);
        $this->assertStringContainsString('phlix help', $output['stdout']);
    }

    public function testNoArgumentExitsZeroAndPrintsHelp(): void
    {
        $output = $this->runPhlix([]);
        $this->assertSame(0, $output['exitCode'], 'no argument should exit with code 0');
        $this->assertStringContainsString('phlix — Phlix console client', $output['stdout']);
    }

    public function testUnknownCommandExitsTwo(): void
    {
        $output = $this->runPhlix(['bogus-command']);
        $this->assertSame(2, $output['exitCode'], 'unknown command should exit with code 2');
    }

    public function testUnknownCommandPrintsErrorToStderr(): void
    {
        $output = $this->runPhlix(['bogus-command']);
        $this->assertStringContainsString('Unknown command: bogus-command', $output['stderr']);
    }

    public function testUnknownCommandPrintsHelpToStderr(): void
    {
        $output = $this->runPhlix(['bogus-command']);
        $this->assertStringContainsString('phlix — Phlix console client', $output['stderr']);
    }

    public function testVersionFlagExitsZero(): void
    {
        $output = $this->runPhlix(['--version']);
        $this->assertSame(0, $output['exitCode'], '--version should exit with code 0');
    }

    public function testVersionFlagPrintsOneLine(): void
    {
        $output = $this->runPhlix(['--version']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    public function testVersionFlagMatchesExpectedPattern(): void
    {
        $output = $this->runPhlix(['--version']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    public function testVersionShortFlagExitsZero(): void
    {
        $output = $this->runPhlix(['-V']);
        $this->assertSame(0, $output['exitCode'], '-V should exit with code 0');
    }

    public function testVersionShortFlagPrintsOneLine(): void
    {
        $output = $this->runPhlix(['-V']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    public function testVersionCommandExitsZero(): void
    {
        $output = $this->runPhlix(['version']);
        $this->assertSame(0, $output['exitCode'], 'version should exit with code 0');
    }

    public function testVersionCommandPrintsOneLine(): void
    {
        $output = $this->runPhlix(['version']);
        $this->assertMatchesRegularExpression('/^phlix .+$/', $output['stdout']);
    }

    public function testWatchWithoutHubAndServerIdExitsTwo(): void
    {
        $output = $this->runPhlix(['watch']);
        $this->assertSame(2, $output['exitCode'], 'watch without --hub/--server-id should exit with code 2');
        $this->assertStringContainsString('watch needs --hub and --server-id', $output['stderr']);
    }

    public function testWatchWithoutTokenExitsTwo(): void
    {
        $output = $this->runPhlix(['watch', '--hub', 'http://hub.example.com', '--server-id', 'srv-123']);
        $this->assertSame(2, $output['exitCode'], 'watch without a token source should exit with code 2');
        $this->assertStringContainsString('watch needs --relay-token or --hub-token', $output['stderr']);
    }

    public function testWatchUnknownOptionExitsTwo(): void
    {
        $output = $this->runPhlix(['watch', '--bogus']);
        $this->assertSame(2, $output['exitCode'], 'watch with an unknown option should exit with code 2');
        $this->assertStringContainsString('Unknown option: --bogus', $output['stderr']);
    }

    public function testWatchValueOptionWithoutValueExitsTwo(): void
    {
        $output = $this->runPhlix(['watch', '--timeout']);
        $this->assertSame(2, $output['exitCode'], 'value option without value should exit 2');
        $this->assertStringContainsString('Option --timeout needs a value', $output['stderr']);
    }

    public function testWatchMalformedTimeoutExitsTwo(): void
    {
        $output = $this->runPhlix(['watch', '--timeout', 'abc']);
        $this->assertSame(2, $output['exitCode'], 'watch with a non-numeric --timeout should exit with code 2');
        $this->assertStringContainsString('--timeout needs a positive integer', $output['stderr']);
    }

    public function testWatchFlagWithValueExitsTwo(): void
    {
        $output = $this->runPhlix(['watch', '--once=false']);
        $this->assertSame(2, $output['exitCode'], 'watch with a value on a flag should exit with code 2');
        $this->assertStringContainsString('Option --once does not take a value', $output['stderr']);
    }

    /**
     * Regression (M-CONSOLE-32): `phlix run` on non-TTY stdin used to render
     * the full TUI (escape sequences included) into the pipe and spin forever
     * against the dead input stream — only an external kill ended it (exit
     * 124 under `timeout`). Any CI script, pipe, or nohup launch of `run`
     * hung. The command boundary now refuses with a clear "needs a TTY"
     * error on STDERR and exits 2, the usage-error code this entrypoint
     * already reserves for unknown commands and bad watch flags.
     *
     * The proc_open pipe here is exactly what a CI runner presents, so this
     * test reproduces the finding's vector honestly: if the guard regresses,
     * the deadline path reports the freeze as a red assertion — the suite
     * itself never hangs.
     */
    public function testRunOnNonTtyStdinRefusesWithExitTwoInsteadOfFreezing(): void
    {
        $result = $this->runPhlixWithDeadline(['run'], 10.0);

        $this->assertFalse(
            $result['timedOut'],
            'phlix run on non-TTY stdin must exit on its own; it was still '
                . 'running after the 10s deadline (the M-CONSOLE-32 freeze)',
        );
        $this->assertSame(
            2,
            $result['exitCode'],
            'the non-TTY refusal must exit 2 (usage-error doctrine)',
        );
        $this->assertStringContainsString(
            'needs a TTY',
            $result['stderr'],
            'the refusal must name the TTY requirement the help text promises',
        );
        $this->assertStringStartsWith(
            'Error:',
            $result['stderr'],
            'refusal goes to STDERR with the repo-standard Error: prefix',
        );
        $this->assertSame(
            '',
            $result['stdout'],
            'the refusal must not paint TUI escape sequences into stdout',
        );
    }

    /**
     * The freeze cure must not take the headless selftest down with it:
     * docs/dev/client-console.md states `run --selftest` "Runs without a
     * TTY", and CI leans on it. Pinned to an empty scratch HOME it must
     * still run the check ladder (config-loaded FAILs, exit 1) and must
     * never reach the exit-2 TTY refusal. The scratch HOME staying empty is
     * part of the contract: the refusal and the short-circuited selftest
     * write no config.
     */
    public function testRunSelftestStaysHeadlessUnderNonTtyStdin(): void
    {
        $fakeHome = sys_get_temp_dir() . '/phlix-mcon32-' . bin2hex(random_bytes(4));
        mkdir($fakeHome, 0700);

        try {
            $result = $this->runPhlixWithDeadline(['run', '--selftest'], 15.0, $fakeHome);

            $this->assertFalse($result['timedOut'], 'run --selftest must stay headless-terminating');
            $this->assertStringNotContainsString(
                'needs a TTY',
                $result['stderr'],
                'the TTY refusal must not gate --selftest',
            );
            $this->assertNotSame(2, $result['exitCode'], '--selftest is not a usage error on non-TTY stdin');
            $this->assertStringContainsString(
                'config-loaded',
                $result['stdout'],
                '--selftest must still print its check ladder',
            );
            $this->assertSame(
                array_diff(scandir($fakeHome), ['.', '..']),
                [],
                'headless run --selftest with no config must write nothing into HOME',
            );
        } finally {
            if (is_dir($fakeHome)) {
                rmdir($fakeHome);
            }
        }
    }

    /**
     * Code and help must agree verbatim: the published help line for `run`
     * promises "(needs a TTY)" — that promise is what the refusal now
     * enforces. If either side drifts, this goes red and the finding is
     * reopened.
     */
    public function testHelpTextPromisesTheTtyRequirementTheRefusalEnforces(): void
    {
        $result = $this->runPhlix(['help']);

        $this->assertMatchesRegularExpression('/phlix run.*\(needs a TTY\)/', $result['stdout']);
    }

    // ------------------------------------------------------------------
    // M-CONSOLE-35: bearer/JWT secrets must not be silent argv residents
    // (CWE-214: /proc/<pid>/cmdline is world-readable for the process
    // lifetime; shell history keeps the flag line forever). The carrier
    // ladder is env → stdin → (loud, deprecated) argv literal. Doctrine
    // sibling: `phlix run` refuses a non-TTY with exit 2 instead of
    // pretending (1b7dd9e exit-code honesty).
    //
    // Venue law baked into these shapes: the hub-token carrier MINTS a
    // relay token via curl on every (re)connect, and this venue's PHP
    // 8.3.6 + curl 8.5.0 segfaults at process shutdown after ANY
    // curl_exec (reproduced with bare `php -r`, zero repo code — an env
    // artifact, not ours). No test here may execute mintRelayToken. The
    // dial is always host:8804 regardless of the hub URL, so every
    // hub-token-path test below supplies a relay token through a NON-
    // minting carrier (relay literal/flag/env), which the token provider
    // prefers before minting.
    // ------------------------------------------------------------------

    /**
     * Hub URL at 127.0.0.1:9 (discard, closed); the relay socket dials the
     * fixed :8804 (also closed), so watch fails connect instantly, runs the
     * `--once --timeout 1` ladder, and exits 1 — long enough to prove the
     * carrier RESOLVED, never reaching a real hub.
     */
    private const WATCH_UNREACHABLE_HUB = 'http://127.0.0.1:9';

    private function scratchHome(): string
    {
        $dir = sys_get_temp_dir() . '/opencode/fix-M-CONSOLE-35/phpunit-home-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("scratch HOME {$dir} could not be created");
        }

        return $dir;
    }

    public function testWatchArgvSecretFlagsStillWorkButWarnLoudly(): void
    {
        // The relay literal shadows the hub token in the provider, so the run
        // dials without ever minting (venue curl law above).
        $result = $this->runPhlixWithDeadline(
            ['watch', '--hub', self::WATCH_UNREACHABLE_HUB, '--server-id', 'srv-1',
                '--hub-token', 'SECRENT-IN-ARGV-9c1f', '--relay-token', 'RELAY-SECRET-4b7a',
                '--once', '--timeout', '1'],
            8.0,
            $this->scratchHome(),
        );

        $this->assertFalse($result['timedOut']);
        // Exit 1 = "carriers accepted, no frame within timeout" — NOT 2: the
        // deprecated carrier keeps working (no silent hard-break of scripts).
        $this->assertSame(1, $result['exitCode']);
        $this->assertMatchesRegularExpression('/^warning: --hub-token/m', $result['stderr']);
        $this->assertMatchesRegularExpression('/^warning: --relay-token/m', $result['stderr']);
        // The warnings name the carriers, never echo the secrets themselves.
        $this->assertStringNotContainsString('SECRENT-IN-ARGV-9c1f', $result['stderr']);
        $this->assertStringNotContainsString('SECRENT-IN-ARGV-9c1f', $result['stdout']);
        $this->assertStringNotContainsString('RELAY-SECRET-4b7a', $result['stderr']);
        $this->assertStringNotContainsString('RELAY-SECRET-4b7a', $result['stdout']);
    }

    public function testWatchArgvSecretWarningIsNotSilentEvenOnUsageError(): void
    {
        $result = $this->runPhlix([
            'watch', '--hub-token', 'LEAKED-JWT-77aa',
        ]);

        $this->assertSame(2, $result['exitCode']);
        $this->assertMatchesRegularExpression('/^warning: --hub-token/m', $result['stderr']);
        $this->assertStringContainsString('PHLIX_HUB_TOKEN', $result['stderr']);
        $this->assertStringContainsString('--hub-token-stdin', $result['stderr']);
        $this->assertStringNotContainsString('LEAKED-JWT-77aa', $result['stderr']);
    }

    public function testWatchEnvTokenCarrierProducesNoWarning(): void
    {
        $result = $this->runPhlixWithDeadline(
            ['watch', '--once', '--timeout', '1'],
            8.0,
            $this->scratchHome(),
            [
                'PHLIX_HUB_URL' => self::WATCH_UNREACHABLE_HUB,
                'PHLIX_HUB_SERVER_ID' => 'srv-1',
                'PHLIX_RELAY_TOKEN' => 'ENV-CARRIER-c3d2',
            ],
        );

        $this->assertFalse($result['timedOut']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertDoesNotMatchRegularExpression('/^warning: /m', $result['stderr']);
        $this->assertStringNotContainsString('ENV-CARRIER-c3d2', $result['stderr']);
    }

    public function testWatchRelayTokenFromStdinIsAcceptedSilently(): void
    {
        $result = $this->runPhlixWithDeadline(
            ['watch', '--hub', self::WATCH_UNREACHABLE_HUB, '--server-id', 'srv-1',
                '--relay-token-stdin', '--once', '--timeout', '1'],
            8.0,
            $this->scratchHome(),
            [],
            "STDIN-RELAY-f0aa\n",
        );

        $this->assertFalse($result['timedOut']);
        $this->assertSame(
            1,
            $result['exitCode'],
            'the stdin carrier resolved the token; the run reached the dial ladder',
        );
        $this->assertDoesNotMatchRegularExpression('/^warning: /m', $result['stderr']);
        $this->assertStringContainsString('hub relay:', $result['stderr']);
        $this->assertStringNotContainsString('STDIN-RELAY-f0aa', $result['stderr']);
    }

    public function testWatchHubTokenFromStdinIsAcceptedSilently(): void
    {
        // PHLIX_RELAY_TOKEN shadows minting (venue curl law); the hub token
        // must still be READ from stdin and resolve — an empty stdin makes
        // this exact invocation exit 2 instead (next test's twin).
        $result = $this->runPhlixWithDeadline(
            ['watch', '--hub', self::WATCH_UNREACHABLE_HUB, '--server-id', 'srv-1',
                '--hub-token-stdin', '--once', '--timeout', '1'],
            8.0,
            $this->scratchHome(),
            ['PHLIX_RELAY_TOKEN' => 'shadow-relay-2f2f'],
            "STDIN-HUB-e8b1\n",
        );

        $this->assertFalse($result['timedOut']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertDoesNotMatchRegularExpression('/^warning: /m', $result['stderr']);
        $this->assertStringContainsString('hub relay:', $result['stderr']);
        $this->assertStringNotContainsString('STDIN-HUB-e8b1', $result['stderr']);
    }

    public function testWatchStdinCarrierWithEmptyStdinExitsTwo(): void
    {
        $result = $this->runPhlixWithDeadline(
            ['watch', '--hub', self::WATCH_UNREACHABLE_HUB, '--server-id', 'srv-1',
                '--relay-token-stdin', '--once', '--timeout', '1'],
            8.0,
            $this->scratchHome(),
            [],
            '',
        );

        $this->assertFalse($result['timedOut']);
        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('token on STDIN', $result['stderr']);
    }

    public function testWatchBothStdinCarriersRefusedAtParse(): void
    {
        $result = $this->runPhlix(['watch', '--hub', self::WATCH_UNREACHABLE_HUB, '--server-id', 'srv-1',
            '--hub-token-stdin', '--relay-token-stdin']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('Only one', $result['stderr']);
    }

    public function testWatchStdinCarriersRejectInlineValues(): void
    {
        $result = $this->runPhlix(['watch', '--hub', self::WATCH_UNREACHABLE_HUB, '--server-id', 'srv-1',
            '--hub-token-stdin=x']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('does not take a value', $result['stderr']);
    }

    public function testHelpTextDocumentsTheSecretCarrierLadder(): void
    {
        $result = $this->runPhlix(['help']);

        $this->assertStringContainsString('--hub-token-stdin', $result['stdout']);
        $this->assertStringContainsString('--relay-token-stdin', $result['stdout']);
        $this->assertStringContainsString('DEPRECATED', $result['stdout']);
    }

    /**
     * Run the phlix binary with given arguments and capture output.
     *
     * @param array<string> $args
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runPhlix(array $args): array
    {
        $binPath = $this->binPath;
        $command = array_merge([$binPath], $args);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to open process');
        }

        // Close stdin
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Spawn bin/phlix with a piped (never a terminal) stdin and drain both
     * output streams under a wall-clock deadline. Unlike runPhlix(), whose
     * blocking reads would hang the suite on a regressed freeze, this
     * returns `timedOut: true` after the deadline with the child killed, so
     * the freeze becomes a red assertion instead of a stuck CI job.
     *
     * @param list<string>  $args      argv after the script name.
     * @param float         $deadline  seconds to wait before declaring the child frozen.
     * @param string|null   $homeOverride when set, the child runs with HOME/XDG
     *                                    pinned to this scratch directory (a full
     *                                    environment replacement — no host config
     *                                    or PHLIX_* variables leak in).
     * @param array<string,string> $extraEnv additional variables merged into the
     *                                    replacement environment (secret-carrier
     *                                    tests pin what the child actually reads).
     * @param string|null   $stdinContent when set, written to the child's stdin
     *                                    before the close (the stdin token carrier).
     *
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}
     */
    private function runPhlixWithDeadline(
        array $args,
        float $deadline,
        ?string $homeOverride = null,
        array $extraEnv = [],
        ?string $stdinContent = null,
    ): array {
        $env = null;
        if ($homeOverride !== null) {
            $env = [
                'HOME' => $homeOverride,
                'XDG_CONFIG_HOME' => $homeOverride . '/.config',
                'PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin'),
            ] + $extraEnv;
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin — a pipe: stream_isatty(STDIN) is false
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(array_merge([PHP_BINARY, $this->binPath], $args), $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to open process');
        }
        if ($stdinContent !== null) {
            // The stdin token carrier: write the secret line, then close so
            // a reader draining php://stdin sees EOF (tokens never carry
            // internal newlines, so the close also terminates the read).
            fwrite($pipes[0], $stdinContent);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadlineAt = microtime(true) + $deadline;
        $timedOut = true;
        while (microtime(true) < $deadlineAt) {
            $stdout .= (string) fread($pipes[1], 65536);
            $stderr .= (string) fread($pipes[2], 65536);
            // EOF on both streams can only mean the child closed its stdio,
            // i.e. it exited on its own (a frozen child holds the write ends
            // open forever).
            if (feof($pipes[1]) && feof($pipes[2])) {
                $timedOut = false;
                break;
            }
            usleep(20000);
        }

        if ($timedOut) {
            proc_terminate($process, SIGKILL);
        }
        // Final drain of whatever raced in just before the exit.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timedOut' => $timedOut,
        ];
    }
}
