<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Msg\AddServerMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\OpenServersMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\CapturesSlash;
use Phlix\Console\Screen\ServersScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * The `a` key must reach a real add-server path, not a dead end. Historical
 * defect (review M-CONSOLE-40): the handler toasted "Use the palette
 * 'Add server' action" while no such palette action existed, and the class
 * docblock promised a form that was never wired. The inline form below is the
 * now-documented truth: `a` opens it, submit emits AddServerMsg + a list
 * refresh, Esc cancels back to the list.
 */
final class ServersScreenAddServerTest extends TestCase
{
    /**
     * @param array<\Phlix\Console\Config\ServerEntry> $servers
     */
    private function screen(array $servers = []): ServersScreen
    {
        return ServersScreen::create($servers, null);
    }

    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    private function key(Model $model, KeyType $type): Model
    {
        [$model] = $model->update(new KeyMsg($type));

        return $model;
    }

    public function testAddKeyOpensTheInlineForm(): void
    {
        $screen = $this->screen();

        [$next] = $screen->update(new KeyMsg(KeyType::Char, 'a'));

        self::assertInstanceOf(ServersScreen::class, $next);
        self::assertStringContainsString('Add a server', $next->view(), 'pressing a must open the add-server form');
        self::assertStringContainsString('Server URL', $next->view());
    }

    public function testAddKeyDoesNotDeadEndIntoAPaletteToast(): void
    {
        $screen = $this->screen();

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a'));

        if ($cmd !== null) {
            self::assertNotInstanceOf(
                ShowToastMsg::class,
                $cmd(),
                'a must not defer to a toast — the palette has no Add server action',
            );
        }
    }

    public function testFormSubmitEmitsAddServerThenRefreshesTheList(): void
    {
        $screen = $this->screen()->update(new KeyMsg(KeyType::Char, 'a'))[0];

        $screen = $this->type($screen, 'host.example:8096');
        $screen = $this->key($screen, KeyType::Down); // advance to the label field
        $screen = $this->type($screen, 'Home Lab');

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertNotNull($cmd, 'Enter on the last field submits the form');
        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch);
        self::assertCount(2, $batch->cmds);

        $add = $batch->cmds[0]();
        self::assertInstanceOf(AddServerMsg::class, $add);
        self::assertSame('https://host.example:8096', $add->url, 'URL is normalised');
        self::assertSame('Home Lab', $add->label);

        $refresh = $batch->cmds[1]();
        self::assertInstanceOf(OpenServersMsg::class, $refresh, 'list re-renders from the saved config');
    }

    public function testBlankLabelFallsBackToTheHost(): void
    {
        $screen = $this->screen()->update(new KeyMsg(KeyType::Char, 'a'))[0];
        $screen = $this->type($screen, 'https://lab.phlix.local');
        $screen = $this->key($screen, KeyType::Enter); // advance focus to the label field

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Enter)); // Enter on last field submits

        self::assertNotNull($cmd);
        $batch = $cmd();
        self::assertInstanceOf(BatchMsg::class, $batch);
        $add = $batch->cmds[0]();
        self::assertInstanceOf(AddServerMsg::class, $add);
        self::assertSame('lab.phlix.local', $add->label);
    }

    public function testEmptyUrlSubmitShowsAnErrorAndStaysInFormMode(): void
    {
        $screen = $this->screen()->update(new KeyMsg(KeyType::Char, 'a'))[0];

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));

        self::assertStringContainsString('Add a server', $next->view(), 'the form stays open for a retry');
        self::assertStringContainsString('server URL', $next->view());
        if ($cmd !== null) {
            self::assertNotInstanceOf(BatchMsg::class, $cmd(), 'no AddServerMsg may fire without a URL');
        }
    }

    public function testEscCancelsTheFormWithoutPoppingTheScreen(): void
    {
        $screen = $this->screen()->update(new KeyMsg(KeyType::Char, 'a'))[0];

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertStringNotContainsString('Add a server', $next->view(), 'Esc returns to the list');
        self::assertStringContainsString('Servers', $next->view());
        if ($cmd !== null) {
            self::assertNotInstanceOf(
                NavigateBackMsg::class,
                $cmd(),
                'Esc cancels the form, it does not pop the screen',
            );
        }
    }

    public function testListFooterAdvertisesTheAddKey(): void
    {
        // Chrome::frame collapses the source's column spacing, so assert the
        // rendered words: the footer must advertise `a  add`.
        self::assertMatchesRegularExpression('/\ba\s+add\b/', $this->screen()->view());
    }

    public function testEmptyStatePointsAtTheAddKeyNotThePalette(): void
    {
        $view = $this->screen()->view();

        self::assertStringContainsString('a to add one manually', $view);
        self::assertStringNotContainsString('use the palette to add', $view);
    }

    public function testCapturesSlashSoTheAppNeverHijacksUrlTyping(): void
    {
        // The add-server form is fed URLs full of `:` and `/`; the marker keeps
        // the App from stealing those keys into the palette / global search —
        // the same law ServerScreen pins for the first-run URL form.
        self::assertInstanceOf(CapturesSlash::class, $this->screen());
    }
}
