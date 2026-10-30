<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Unit;

use Phlix\Console\App;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * [review console__srphp, M-CONSOLE-59] the dispatch delegate ladder was folded
 * into App::msgHandlers(), one map entry per message class. The full AppTest
 * suite already proves the routing is behavior-identical arm-for-arm; this guard
 * pins the STRUCTURE so the ladder cannot creep back:
 *
 *  1. the map is real: keys are Msg class names, values are 2-parameter closures;
 *  2. the conditional arms deliberately stay OUT of the map (KeyMsg subclasses
 *     exist in the vendor tree, and the palette/boot gates are control flow);
 *  3. no folded arm was resurrected as an `instanceof` branch in dispatch().
 */
final class AppDispatchMapTest extends TestCase
{
    /**
     * @return array<class-string<Msg>, \Closure>
     */
    private static function handlers(): array
    {
        $method = new ReflectionMethod(App::class, 'msgHandlers');
        $method->setAccessible(true);

        /** @var array<class-string<Msg>, \Closure> $handlers */
        $handlers = $method->invoke(null);

        return $handlers;
    }

    public function testTheHandlerMapIsBuiltFromRealMsgClasses(): void
    {
        $handlers = self::handlers();

        self::assertGreaterThanOrEqual(60, count($handlers), 'the ladder had 63 delegate arms — the map must carry them');

        foreach ($handlers as $class => $handler) {
            self::assertTrue(class_exists($class), "map key {$class} must resolve to a real class");
            self::assertContains(Msg::class, class_implements($class) ?: [], "map key {$class} must implement Msg");
            self::assertInstanceOf(\Closure::class, $handler);
            self::assertSame(2, (new \ReflectionFunction($handler))->getNumberOfParameters(), "{$class} handler takes (App, Msg)");
        }
    }

    public function testTheConditionalArmsStayOutOfTheExactClassMap(): void
    {
        $handlers = self::handlers();

        // KeyMsg has vendor subclasses and every key arm is conditional; the
        // window/paste/boot arms carry control flow. None may be keyed by class.
        foreach ([KeyMsg::class, \SugarCraft\Core\Msg\WindowSizeMsg::class, \SugarCraft\Core\Msg\PasteMsg::class, \Phlix\Console\Msg\BootResolvedMsg::class] as $kept) {
            self::assertArrayNotHasKey($kept, $handlers, "{$kept} must stay a ladder arm — its dispatch is conditional");
        }
    }

    public function testNoFoldedDelegateArmWasResurrectedInTheLadder(): void
    {
        $lines = file(dirname(__DIR__, 2) . '/src/App.php');
        $method = new ReflectionMethod(App::class, 'dispatch');
        $ladder = implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));

        foreach (array_keys(self::handlers()) as $class) {
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            self::assertStringNotContainsString(
                '$msg instanceof ' . $short,
                $ladder,
                "{$short} is mapped — an instanceof arm in dispatch() would double-route it",
            );
        }
    }

    public function testTheMapIsMemoizedAcrossCalls(): void
    {
        self::assertSame(self::handlers(), self::handlers(), 'the static memo must hand back the identical build');
    }
}
