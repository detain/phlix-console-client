<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit\Screen;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * [review console__qa C1/m3] a screen must never emit a bare Cmd::quit().
 *
 * Cmd::quit() closes the Program WITHOUT running App::requestQuit()'s
 * teardownForExit(), so any live ffplay/mpv/ffmpeg subprocess survives the
 * exit — the ghost-audio leak the three root/auth quit arms shipped. The quit
 * verb for a screen is `Cmd::send(new RequestQuitMsg())`: every screen then
 * exits through the same App-owned chokepoint Ctrl-C, the signal arms and the
 * palette Quit already share.
 *
 * The scan is comment-stripped (token_get_all): the embedded sugar-forms
 * screens legitimately discuss the vendor's `Cmd::quit()` in prose — their
 * intercepts are why that vendor cmd never reaches the loop — so only live
 * code is the law. Red-on-parent: at the review commit the scan flagged
 * BrowseScreen/LoginScreen/ServerScreen (one arm each).
 */
final class ScreensNeverQuitBypassTeardownTest extends TestCase
{
    public function testNoScreenEmitsABareQuitCommand(): void
    {
        $root = dirname(__DIR__, 3) . '/src/Screen';
        if (!is_dir($root)) {
            throw new RuntimeException('quit-path guard expects a src/Screen/ tree at ' . $root);
        }

        $offenders = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = self::codeOnly((string) file_get_contents($file->getPathname()));
            if (str_contains($code, 'Cmd::quit(')) {
                $offenders[] = substr($file->getPathname(), strlen(dirname($root, 2)) + 1);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'a screen quit-bypassed the App teardown chokepoint — return Cmd::send(new RequestQuitMsg()) '
            . 'so teardownForExit() releases every player/audio subprocess before the process leaves',
        );
    }

    /** Strip comments so docblock prose about the vendor's Cmd::quit() can't pose as code. */
    private static function codeOnly(string $phpSource): string
    {
        $out = '';
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
