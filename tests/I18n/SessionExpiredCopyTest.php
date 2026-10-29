<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Regression gate for M-CONSOLE-06: the session-expiry sentence must exist
 * exactly once in the catalog (key `auth.session_expired`), every producer in
 * src/ must translate it through Lang::t(), and no English wording — neither
 * the canonical sentence nor the old em-dash ApiClient variant — may survive
 * as a hardcoded literal. Before this fix the same event rendered three
 * divergent wordings and stayed English on 44 screens under non-en locales.
 */
final class SessionExpiredCopyTest extends TestCase
{
    /** Absolute repo root (tests/I18n/this-file → two levels up). */
    private const ROOT = __DIR__ . '/../../';

    /** The single canonical English wording the catalog must carry. */
    private const CANON = 'Your session expired. Please sign in again.';

    /** The retired ApiClient variant (em-dash) that must never reappear. */
    private const RETIRED_EMDASH = 'Session expired — please log in again.';

    public function testCatalogCarriesExactlyOneSessionExpiredKey(): void
    {
        /** @var array<string,string> $en */
        $en = require self::ROOT . 'resources/lang/en.php';
        $keys = array_values(array_filter(
            array_keys($en),
            static fn (string $key): bool => str_ends_with($key, '.session_expired'),
        ));

        self::assertSame(['auth.session_expired'], $keys);
        self::assertSame(self::CANON, $en['auth.session_expired']);
    }

    public function testNoHardcodedSessionExpiryWordingRemainsInSrc(): void
    {
        foreach ($this->phpFiles(self::ROOT . 'src') as $file) {
            $content = (string) file_get_contents($file);
            self::assertStringNotContainsString(
                self::CANON,
                $content,
                "$file: session-expiry prose must come from Lang::t('auth.session_expired'), not a literal"
            );
            self::assertStringNotContainsString(
                self::RETIRED_EMDASH,
                $content,
                "$file: the retired em-dash variant diverges from the catalog wording"
            );
        }
    }

    public function testEverySessionExpiredMsgDispatchIsTranslated(): void
    {
        $pattern = '/new SessionExpiredMsg\(\s*([^\n)]{0,80})/';
        foreach ($this->phpFiles(self::ROOT . 'src') as $file) {
            $content = (string) file_get_contents($file);
            if (!preg_match_all($pattern, $content, $m)) {
                continue;
            }
            foreach ($m[1] as $argument) {
                self::assertStringStartsWith(
                    'Lang::t(',
                    $argument,
                    "$file: new SessionExpiredMsg(...) must carry a Lang::t() translation, got '$argument'"
                );
            }
        }
    }

    /**
     * @return list<string> every .php file under $dir
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
