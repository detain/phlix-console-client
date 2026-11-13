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
    /**
     * Every shape a screen could bind a Char ' ' in: comparison either way
     * round, a switch case, in_array() over the rune, and a match arm whose
     * subject is the rune [CON-S10 hardening].
     */
    private const PATTERN = '/rune\s*(?:===|==|!==|!=)\s*[\'"] [\'"]'
        . '|[\'"] [\'"]\s*(?:===|==|!==|!=)\s*\$[\w>?-]*rune\b'
        . '|case\s+[\'"] [\'"]\s*:'
        . '|in_array\(\s*\$[\w>?-]*rune\s*,\s*\[[^\]]*[\'"] [\'"]'
        . '|match\s*\(\s*\$[\w>?-]*rune\s*\)\s*\{[^}]*[\'"] [\'"][^}]*=>/s';

    public function testNoScreenBindsCharSpace(): void
    {
        $offenders = [];
        foreach (glob(dirname(__DIR__, 2) . '/src/Screen/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match(self::PATTERN, $src) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'Bind KeyType::Space, not Char \' \'.');
    }

    /** The tripwire must itself catch each binding shape it claims to. */
    public function testThePatternCatchesEveryBindingShape(): void
    {
        $shapes = [
            "if (\$msg->rune === ' ') {}",
            "if (' ' === \$msg->rune) {}",
            "case ' ':",
            "if (in_array(\$msg->rune, [' '], true)) {}",
            "if (in_array(\$msg->rune, ['x', ' '], true)) {}",
            "match (\$msg->rune) { ' ' => 1, default => 0 };",
            "match (\$msg->rune) { 'a', ' ' => 1, default => 0 };",
        ];
        foreach ($shapes as $shape) {
            self::assertSame(1, preg_match(self::PATTERN, $shape), "tripwire misses: $shape");
        }
        self::assertSame(0, preg_match(self::PATTERN, "if (\$msg->type === KeyType::Space) {}"));
    }
}
