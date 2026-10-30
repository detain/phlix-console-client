<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Config\ServerEntry;
use Phlix\Console\Screen\LoginScreen;
use Phlix\Console\Screen\RegisterScreen;
use Phlix\Console\Screen\ServersScreen;
use Phlix\Console\Screen\ServerScreen;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;

/**
 * [review console__secaudit, M-CONSOLE-52] An http:// server is allowed
 * (LAN/dev reality) but the client must never be silent about what it means:
 * credentials cross the wire unencrypted. The cure is one predicate
 * (Config::isInsecureUrl) fed into four surfaces — the setup wizard, the
 * server manager (typed URL + saved-list marker), and both credential forms.
 * These tests pin each surface red-then-green, including the negative arms
 * (https/empty stay quiet) so the warning can never become a permanent shout.
 */
final class InsecureConnectionNoticeTest extends TestCase
{
    private const WARNING = 'http:// sends your password and session tokens unencrypted.';

    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    public function testSetupWizardWarnsWhileAnHttpUrlIsTyped(): void
    {
        $screen = $this->type(ServerScreen::create(), 'http://lan.tld:8096');

        self::assertStringContainsString(self::WARNING, $screen->view(), 'the wizard names the exposure while typing');
    }

    public function testSetupWizardStaysQuietForHttpsAndEmptyInput(): void
    {
        $https = $this->type(ServerScreen::create(), 'https://lan.tld:8096');
        self::assertStringNotContainsString(self::WARNING, $https->view(), 'TLS gets no warning');

        $blank = ServerScreen::create();
        self::assertStringNotContainsString(self::WARNING, $blank->view(), 'nothing typed is not an http choice');
    }

    public function testServerManagerWarnsWhileAnHttpUrlIsTyped(): void
    {
        $screen = $this->keyA(ServersScreen::create([new ServerEntry(id: 's1', label: 'Main', url: 'https://good.tld')], 's1'));
        $screen = $this->type($screen, 'http://lab.local:8096');

        self::assertStringContainsString(self::WARNING, $screen->view(), 'the add-server form names the exposure while typing');
    }

    public function testServerManagerMarksSavedHttpServersAndLeavesTlsUnmarked(): void
    {
        $screen = ServersScreen::create(
            [
                new ServerEntry(id: 's1', label: 'LanBox', url: 'http://192.168.1.50:8096'),
                new ServerEntry(id: 's2', label: 'Cloud', url: 'https://cloud.tld'),
            ],
            's2',
        );

        $view = $screen->view();
        self::assertStringContainsString('LanBox ⚠', $view, 'the plain-http row stays visibly flagged');
        self::assertStringNotContainsString('Cloud ⚠', $view, 'the TLS row is not flagged');
    }

    public function testLoginFormPrintsTheInsecureNoticeOnlyWhenTheServerIsHttp(): void
    {
        $secure = LoginScreen::create(null, 80, 24);
        self::assertStringNotContainsString('insecure connection', $secure->view(), 'TLS login says nothing extra');

        $insecure = LoginScreen::create(null, 80, 24, true);
        self::assertStringContainsString('⚠ This server uses an insecure connection (http)', $insecure->view());
        self::assertStringContainsString('password', $insecure->view(), 'the notice names the secret at risk');
    }

    public function testRegisterFormPrintsTheInsecureNoticeOnlyWhenTheServerIsHttp(): void
    {
        $secure = RegisterScreen::create(null, 80, 24);
        self::assertStringNotContainsString('insecure connection', $secure->view(), 'TLS signup says nothing extra');

        $insecure = RegisterScreen::create(null, 80, 24, true);
        self::assertStringContainsString('⚠ This server uses an insecure connection (http)', $insecure->view());
    }

    public function testTheInsecureFlagSurvivesResizeAndSubmitRebuilds(): void
    {
        $screen = LoginScreen::create(null, 80, 24, true);

        [$typed] = $screen->update(new KeyMsg(KeyType::Char, 'x')); // a form rebuild path
        [$resized] = $typed->update(new WindowSizeMsg(100, 30));

        self::assertInstanceOf(LoginScreen::class, $resized);
        self::assertTrue($resized->insecureServer, 'the notice must not vanish on resize');
    }

    private function keyA(Model $model): Model
    {
        [$model] = $model->update(new KeyMsg(KeyType::Char, 'a'));

        return $model;
    }
}
