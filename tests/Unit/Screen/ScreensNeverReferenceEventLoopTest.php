<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use PHPUnit\Framework\TestCase;

/**
 * M-CONSOLE-36 class gate: no file under src/Screen/ may reference the React
 * event loop at all.
 *
 * SugarCraft Program::scheduleCmd() runs screen command closures from a tick
 * INSIDE the already-running global loop, and the shared StreamSelectLoop has
 * no re-entry guard: a screen that calls `Loop::get()->run()` to drain a
 * promise re-enters the pump, and its `Loop::stop()` clears the shared
 * running flag — killing the whole app's event pump mid-frame. The estate
 * idiom for async UI work is `Cmd::promise(...)` (an AsyncCmd riding the
 * OUTER loop); comments are the only place the loop may be discussed.
 */
final class ScreensNeverReferenceEventLoopTest extends TestCase
{
    public function testNoScreenFileTouchesTheReactEventLoop(): void
    {
        $files = glob(\dirname(__DIR__, 3) . '/src/Screen/*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'src/Screen/ discovery broke — the gate would pass vacuously');

        $violations = [];
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $tokens = token_get_all($code);
            $line = 1;
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    $line = $token[2];
                    if (in_array($token[0], [T_DOC_COMMENT, T_COMMENT], true)) {
                        continue;
                    }
                    if (str_contains($token[1], 'EventLoop')) {
                        $violations[] = basename($file) . ':' . $line;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'src/Screen files reference the React event loop outside comments '
            . '(nested Loop::run() drains kill the shared pump — use Cmd::promise): '
            . implode(', ', $violations),
        );
    }
}
