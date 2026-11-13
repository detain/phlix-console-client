<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire [CON-10]: the key parser emits a real KeyType::Space for the space
 * bar, never KeyType::Char with rune ' '. A screen that binds Char ' ' is dead
 * code on a real terminal, so no file in src/Screen may compare a rune to ' '.
 */
final class SpaceKeyTripwireTest extends TestCase
{
    public function testNoScreenBindsCharSpace(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Screen/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            $pattern = '/rune\s*(?:===|==|!==|!=)\s*[\'"] [\'"]'
                . '|[\'"] [\'"]\s*(?:===|==)\s*\$\w+->rune'
                . '|case\s+[\'"] [\'"]\s*:/';
            if (preg_match($pattern, $src) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'Bind KeyType::Space, not Char \' \'.');
    }
}
