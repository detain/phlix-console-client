<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests;

use Phlix\Console\Api\ApiClient;
use Phlix\Console\App;
use Phlix\Console\Config\Config;
use Phlix\Console\Config\ServerEntry;
use Phlix\Console\Config\TokenStore;
use Phlix\Console\Media\PosterLoader;
use Phlix\Console\Route;
use Phlix\Console\Screen\TakesModifierChords;
use Phlix\Console\Store\AuthStore;
use Phlix\Console\Store\LibrariesStore;
use Phlix\Console\Store\MediaStore;
use Phlix\Console\Tests\Api\FakeTransport;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Image\Mosaic;

/**
 * CON-3: a ctrl/alt-held Char key must never reach a screen's rune-only key
 * arms (Ctrl-Y must not confirm a destructive prompt) unless the top screen
 * opted in via {@see TakesModifierChords}. Mutation probe: drop the choke in
 * App::dispatch and testCtrlYNeverReachesAConfirmArm goes red.
 */
final class AppModifierChordChokeTest extends TestCase
{
    private function app(Model $screen): App
    {
        $api = new ApiClient('https://srv', new FakeTransport());

        return new App(
            new Config(servers: [new ServerEntry(id: 's1', label: 'S', url: 'https://srv')], activeServerId: 's1'),
            new AuthStore($api, TokenStore::default()),
            $api,
            new LibrariesStore($api),
            new MediaStore($api),
            new PosterLoader(Mosaic::halfBlock()),
            [['route' => Route::Browse, 'screen' => $screen]],
        );
    }

    /** @return list<array{string, bool, bool}> */
    public static function chords(): array
    {
        return [['y', true, false], ['y', false, true], ['q', true, false], ['q', false, true], ['n', true, true]];
    }

    /** @dataProvider chords */
    public function testChordNeverReachesAConfirmArm(string $rune, bool $ctrl, bool $alt): void
    {
        $screen = new ChordProbeScreen();
        $app = $this->app($screen);
        $app->update(new KeyMsg(KeyType::Char, $rune, alt: $alt, ctrl: $ctrl));
        self::assertSame(0, $screen->seen, 'modifier chord leaked to a rune-only key arm');
    }

    public function testPlainKeyStillDispatches(): void
    {
        $screen = new ChordProbeScreen();
        $this->app($screen)->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertSame(1, $screen->seen);
    }

    public function testOptedInTypedBufferScreenReceivesChords(): void
    {
        $screen = new ChordProbeTypedScreen();
        $this->app($screen)->update(new KeyMsg(KeyType::Char, 'u', ctrl: true));
        self::assertSame(1, $screen->seen);
    }
}

/** Counts every KeyMsg it receives. */
class ChordProbeScreen implements Model
{
    public int $seen = 0;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            $this->seen++;
        }

        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

final class ChordProbeTypedScreen extends ChordProbeScreen implements TakesModifierChords
{
}
