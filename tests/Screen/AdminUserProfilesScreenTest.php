<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\Profile;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminProfileActionDoneMsg;
use Phlix\Console\Msg\AdminProfileActionFailedMsg;
use Phlix\Console\Msg\AdminProfilesFailedMsg;
use Phlix\Console\Msg\AdminProfilesLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminUserProfilesScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

final class AdminUserProfilesScreenTest extends TestCase
{
    /**
     * The real `GET /api/v1/admin/users/{id}/profiles` shape: the list at the TOP
     * LEVEL, with NO `{success, data}` envelope. Two profiles: Owner (R, active,
     * PIN-for-admin) and Kids (PG, active, no PIN).
     */
    private function profilesPayload(): array
    {
        return [
            'profiles' => [
                ['id' => 'p-1', 'name' => 'Owner', 'content_rating' => 'R', 'is_active' => 1, 'pin_required_for_admin' => 1],
                ['id' => 'p-2', 'name' => 'Kids', 'content_rating' => 'PG', 'is_active' => 1, 'pin_required_for_admin' => 0],
            ],
        ];
    }

    private function emptyProfiles(): array
    {
        return ['profiles' => []];
    }

    private function screenWith(FakeTransport $transport): AdminUserProfilesScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminUserProfilesScreen(new AdminClient($api), 'u-1', 'bob', cols: 120, rows: 40);
    }

    private function loaded(FakeTransport $transport): AdminUserProfilesScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminProfilesLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- list / loading / error ----------------------------------------

    public function testInitFetchesTheProfileList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminProfilesLoadedMsg::class, $msg);
        self::assertCount(2, $msg->profiles);
        self::assertContainsOnlyInstancesOf(Profile::class, $msg->profiles);
        self::assertStringContainsString('/api/v1/admin/users/u-1/profiles', $transport->requestAt(0)['url']);
    }

    public function testLoadingStateBeforeProfiles(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->profilesPayload()));

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading profiles', $screen->view());
    }

    public function testRendersTheProfileTableWithRatingActiveAndPinFlags(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        self::assertTrue($screen->isLoaded());
        self::assertCount(2, $screen->profileList());

        $view = $screen->view();
        self::assertStringContainsString('Owner', $view);
        self::assertStringContainsString('Kids', $view);
        self::assertStringContainsString('R', $view);
        self::assertStringContainsString('PG', $view);
        self::assertStringContainsString('User: bob', $view);
        self::assertStringContainsString('2 profiles', $view);
    }

    public function testEmptyListShowsAPlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyProfiles()));

        self::assertSame([], $screen->profileList());
        self::assertStringContainsString('No profiles yet', $screen->view());
    }

    public function testFetchFailureShowsTheErrorAndRetry(): void
    {
        $transport = (new FakeTransport())->json(500, ['error' => 'boom']);
        $screen = $this->screenWith($transport);
        [$failed] = $screen->update($this->runCmd($screen->init()) ?? new AdminProfilesFailedMsg('x'));

        self::assertFalse($failed->isLoaded());
        self::assertNotNull($failed->error());
        $view = $failed->view();
        self::assertStringContainsString('Could not load the profiles', $view);
        self::assertStringContainsString('Press r to retry', $view);
    }

    public function testAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminUserProfilesScreen(new AdminClient($api), 'u-1', 'bob', cols: 120, rows: 40);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- selection -----------------------------------------------------

    public function testUpAndDownMoveTheSelectionAndClamp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));
        self::assertSame(0, $screen->selectedIndex());

        [$d1] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $d1->selectedIndex());
        [$d2] = $d1->update(new KeyMsg(KeyType::Down));
        self::assertSame($d1, $d2, 'down at the bottom clamps');

        [$up] = $d1->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
    }

    public function testSelectionMoveOnEmptyListIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyProfiles()));

        [$next] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame($screen, $next);
    }

    // ---- create form ---------------------------------------------------

    public function testCOpensACreateForm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyProfiles()));

        [$creating] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($creating->isCreating(), 'create needs no selected profile');
        self::assertFalse($creating->isEditing());
        self::assertStringContainsString('Create a profile for bob', $creating->view());
    }

    public function testCreateWithAValidNamePostsTheNameAndRatingThenToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())  // init
            ->json(201, ['profile_id' => 9, 'message' => 'Profile created'])  // create
            ->json(200, $this->profilesPayload()); // refetch
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'Guests');  // name
        $creating = $this->tab($creating);              // onto the rating Select (default R, index 3)

        [$busy, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->isBusy(), 'a valid submit enters the busy state');
        self::assertFalse($busy->isCreating(), 'a valid submit closes the form');

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminProfileActionDoneMsg::class, $done);
        self::assertSame('Profile created', $done->message);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('Guests', $body['name']);
        self::assertSame(3, $body['rating'], 'the default rating R is index 3');

        $msgs = $this->collectCmd($busy->update($done)[1]);
        self::assertSame(ToastType::Success, $this->firstToast($msgs)->type);
        self::assertTrue($this->containsLoaded($msgs), 'the list is refetched after a create');
    }

    public function testCreateSubmitWithABlankNameKeepsTheFormOpenAndSendsNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        // No name typed.
        $creating = $this->tab($creating);

        [$still, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd, 'an invalid submit issues no command');
        self::assertTrue($still->isCreating(), 'the form stays open');
        self::assertNotNull($still->formError());
        self::assertStringContainsString('Name', (string) $still->formError());
        self::assertSame(1, $transport->requestCount(), 'no request beyond the initial fetch');
    }

    public function testCreateSubmitWithAnOverlongNameKeepsTheFormOpen(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, str_repeat('a', 51)); // > 50
        $creating = $this->tab($creating);

        [$still, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd);
        self::assertTrue($still->isCreating());
        self::assertStringContainsString('1–50', (string) $still->formError());
        self::assertSame(1, $transport->requestCount());
    }

    public function testTheReopenedCreateFormRendersTheValidationErrorAndKeepsTheName(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, str_repeat('z', 51));
        $creating = $this->tab($creating);

        $reopened = $creating->update(new KeyMsg(KeyType::Enter))[0];
        self::assertNotNull($reopened->formError());
        $view = $reopened->view();
        self::assertStringContainsString('! ', $view);
        self::assertStringContainsString((string) $reopened->formError(), $view);
    }

    public function testCreateFailureToastsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())
            ->json(400, ['error' => 'Maximum profiles reached']);
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'Extra');
        $creating = $this->tab($creating);

        [$busy, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminProfileActionFailedMsg::class, $failed);
        self::assertSame('Maximum profiles reached', $failed->message);

        $toast = $this->firstToast($this->collectCmd($busy->update($failed)[1]));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Maximum profiles reached', $toast->message);
    }

    public function testCreateEscAbortsWithoutARequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        self::assertTrue($creating->isCreating());

        [$closed, $cmd] = $creating->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd, 'Esc aborts the form');
        self::assertFalse($closed->isCreating());
        self::assertSame(1, $transport->requestCount());
    }

    // ---- edit form -----------------------------------------------------

    public function testEOpensAPreFilledEditForm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertTrue($editing->isEditing());
        self::assertFalse($editing->isCreating());
        $view = $editing->view();
        self::assertStringContainsString("Edit 'Owner'", $view);
        self::assertStringContainsString('Owner', $view, 'the name is pre-filled');
    }

    public function testEWithNoSelectedProfileIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyProfiles()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testEditChangingOnlyTheNamePutsThatFieldThenToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())  // init
            ->json(200, ['message' => 'Profile updated'])  // update
            ->json(200, $this->profilesPayload()); // refetch
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        // Clear the pre-filled 'Owner' (5 backspaces) and type a new name; leave
        // the rating at its pre-selected R (unchanged → omitted).
        $editing = $this->backspace($editing, 5);
        $editing = $this->type($editing, 'Boss');
        $editing = $this->tab($editing);

        [$busy, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isEditing());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminProfileActionDoneMsg::class, $done);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('Boss', $body['name']);
        self::assertArrayNotHasKey('rating', $body, 'the unchanged rating is omitted');
        self::assertSame('PUT', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/profiles/p-1', $transport->requestAt(1)['url']);
    }

    public function testEditWithNoChangesSendsAnEmptyBody(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())
            ->json(200, ['message' => 'Profile updated'])
            ->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        $editing = $this->tab($editing);  // touch nothing

        [, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
        $this->runCmd($cmd);

        self::assertSame('', $transport->requestAt(1)['body'], 'no changed field → no body');
    }

    public function testEditChangingTheRatingOnlyPutsThatField(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())  // init (Owner is R / index 3)
            ->json(200, ['message' => 'Profile updated'])  // update
            ->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        $editing = $this->tab($editing);                  // onto the rating Select (pre-selected R)
        $editing = $editing->update(new KeyMsg(KeyType::Up))[0];   // R (3) → PG-13 (2)

        [, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertArrayNotHasKey('name', $body, 'the unchanged name is omitted');
        self::assertSame(2, $body['rating'], 'the new rating index PG-13 is sent');
    }

    public function testEditSubmitWithAnInvalidNameKeepsTheEditFormOpenAndSendsNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        $editing = $this->backspace($editing, 5);  // clear the pre-filled 'Owner' → blank name
        $editing = $this->tab($editing);

        [$still, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd, 'an invalid edit issues no command');
        self::assertTrue($still->isEditing(), 'the edit form stays open');
        self::assertNotNull($still->formError());
        self::assertStringContainsString('Name', (string) $still->formError());
        self::assertSame(1, $transport->requestCount());
        // The reopened edit form renders the error.
        self::assertStringContainsString('! ', $still->view());
    }

    public function testEditEscAbortsWithoutARequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        self::assertTrue($editing->isEditing());

        [$closed, $cmd] = $editing->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
        self::assertFalse($closed->isEditing());
        self::assertSame(1, $transport->requestCount());
    }

    // ---- delete --------------------------------------------------------

    public function testXArmsADeleteConfirmThenYDeletes(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())  // init
            ->json(200, ['message' => 'Profile deleted'])  // delete
            ->json(200, $this->emptyProfiles()); // refetch
        $screen = $this->loaded($transport);

        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($cmd, 'arming fires no command');
        self::assertSame('delete', $armed->pendingActionLabel());
        self::assertStringContainsString("Delete 'Owner'?", $armed->view());

        [$busy, $performCmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($performCmd);
        self::assertInstanceOf(AdminProfileActionDoneMsg::class, $done);
        self::assertSame('DELETE', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/profiles/p-1', $transport->requestAt(1)['url']);
    }

    public function testNCancelsADeleteConfirm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingActionLabel());
    }

    public function testEscCancelsADeleteConfirm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$cancelled] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cancelled->pendingActionLabel());
    }

    public function testAnUnrelatedKeyDuringAConfirmIsIgnored(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertNull($cmd);
        self::assertSame('delete', $still->pendingActionLabel());
    }

    // ---- set PIN -------------------------------------------------------

    public function testPOpensThePinInput(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$pin] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertTrue($pin->isSettingPin());
        self::assertStringContainsString('Set the admin PIN', $pin->view());
    }

    public function testSettingAFourDigitPinPostsItThenToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())  // init
            ->json(200, ['message' => 'PIN updated'])  // set-pin
            ->json(200, $this->profilesPayload()); // refetch
        $screen = $this->loaded($transport);

        $pin = $screen->update(new KeyMsg(KeyType::Char, 'p'))[0];
        $pin = $this->type($pin, '1234');

        [$busy, $cmd] = $pin->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isSettingPin());
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminProfileActionDoneMsg::class, $done);

        self::assertSame('POST', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/profiles/p-1/pin', $transport->requestAt(1)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('1234', $body['pin']);
    }

    public function testSettingASixDigitPinIsValid(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())
            ->json(200, ['message' => 'PIN updated'])
            ->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $pin = $screen->update(new KeyMsg(KeyType::Char, 'p'))[0];
        $pin = $this->type($pin, '123456');

        [$busy, $cmd] = $pin->update(new KeyMsg(KeyType::Enter));
        self::assertFalse($busy->isSettingPin(), 'a 6-digit PIN submits');
        $this->runCmd($cmd);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('123456', $body['pin']);
    }

    public function testAFiveDigitPinIsRejectedClientSideWithNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $pin = $screen->update(new KeyMsg(KeyType::Char, 'p'))[0];
        $pin = $this->type($pin, '12345');

        [$still, $cmd] = $pin->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($still->isSettingPin(), 'the input stays open on an invalid PIN');
        $toast = $this->firstToast($this->collectCmd($cmd));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('4 or 6 digits', $toast->message);
        self::assertSame(1, $transport->requestCount(), 'no request beyond the initial fetch');
    }

    public function testANonDigitPinIsRejectedClientSideWithNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $pin = $screen->update(new KeyMsg(KeyType::Char, 'p'))[0];
        $pin = $this->type($pin, '12a4');

        [$still, $cmd] = $pin->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($still->isSettingPin());
        self::assertSame(ToastType::Error, $this->firstToast($this->collectCmd($cmd))->type);
        self::assertSame(1, $transport->requestCount());
    }

    public function testPinEscCancelsWithoutARequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        $pin = $screen->update(new KeyMsg(KeyType::Char, 'p'))[0];
        [$closed, $cmd] = $pin->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
        self::assertFalse($closed->isSettingPin());
        self::assertSame(1, $transport->requestCount());
    }

    public function testSetPinFailureToastsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())
            ->json(400, ['error' => 'Invalid PIN length']);
        $screen = $this->loaded($transport);

        $pin = $screen->update(new KeyMsg(KeyType::Char, 'p'))[0];
        $pin = $this->type($pin, '1234');
        [$busy, $cmd] = $pin->update(new KeyMsg(KeyType::Enter));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminProfileActionFailedMsg::class, $failed);
        self::assertSame('Invalid PIN length', $failed->message);

        $toast = $this->firstToast($this->collectCmd($busy->update($failed)[1]));
        self::assertSame(ToastType::Error, $toast->type);
    }

    // ---- clear PIN -----------------------------------------------------

    public function testKArmsAClearPinConfirmThenYClears(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())  // init
            ->json(200, ['message' => 'PIN cleared'])  // clear
            ->json(200, $this->profilesPayload()); // refetch
        $screen = $this->loaded($transport);

        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'k'));
        self::assertNull($cmd);
        self::assertSame('clear-pin', $armed->pendingActionLabel());
        self::assertStringContainsString("Clear the PIN for 'Owner'?", $armed->view());

        [$busy, $performCmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($performCmd);
        self::assertInstanceOf(AdminProfileActionDoneMsg::class, $done);
        self::assertSame('DELETE', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/profiles/p-1/pin', $transport->requestAt(1)['url']);
    }

    public function testNCancelsAClearPinConfirm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'k'));
        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingActionLabel());
    }

    // ---- action failure / busy / refresh -------------------------------

    public function testActionAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())
            ->json(200, $this->profilesPayload())
            ->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminUserProfilesScreen(new AdminClient($api), 'u-1', 'bob', cols: 120, rows: 40);
        $loaded = $screen->update($this->runCmd($screen->init()) ?? new AdminProfilesFailedMsg('x'))[0];

        [$armed] = $loaded->update(new KeyMsg(KeyType::Char, 'x'));
        [, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testMutatingKeysAreIgnoredWhileBusy(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));
        // Enter the busy state via a delete.
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$busy] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());

        [$still, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertSame($busy, $still, 'create is ignored while busy');
        self::assertNull($cmd);
        self::assertFalse($still->isCreating());
    }

    public function testRRefetchesTheList(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())
            ->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        [$reloading, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($reloading->isLoaded());
        self::assertInstanceOf(\Closure::class, $cmd);
        self::assertInstanceOf(AdminProfilesLoadedMsg::class, $this->runCmd($cmd));
    }

    public function testActionDoneWithABlankMessageToastsAFallback(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->profilesPayload())
            ->json(200, ['message' => ''])  // blank message
            ->json(200, $this->profilesPayload());
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminProfileActionDoneMsg::class, $done);
        self::assertSame('Profile deleted', $done->message, 'a blank server message falls back');

        $msgs = $this->collectCmd($busy->update($done)[1]);
        self::assertSame(ToastType::Success, $this->firstToast($msgs)->type);
    }

    // ---- navigation / misc ---------------------------------------------

    public function testEscAndQNavigateBack(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testAnUnhandledKeyAndMsgAreNoOps(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$k, $kc] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $k);
        self::assertNull($kc);

        [$m, $mc] = $screen->update(new class implements Msg {});
        self::assertSame($screen, $m);
        self::assertNull($mc);
    }

    public function testANonActionKeyOnTheListIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        // Enter (a non-Char, non-arrow, non-Escape key) on the list does nothing.
        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testTheBusyStateRendersAWorkingNote(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$busy] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertTrue($busy->isBusy());
        self::assertStringContainsString('Working', $busy->view());
    }

    public function testResizeReflowsTheView(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        [$resized] = $screen->update(new WindowSizeMsg(60, 20));
        self::assertStringContainsString('Owner', $resized->view());
    }

    public function testCrumbAndThemeAreImmutable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->profilesPayload()));

        self::assertSame('Profiles', $screen->crumbLabel());

        $withCrumbs = $screen->withCrumbs(['Admin', 'Users', 'Profiles']);
        self::assertNotSame($screen, $withCrumbs);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    // ---- helpers -------------------------------------------------------

    private function type(AdminUserProfilesScreen $screen, string $text): AdminUserProfilesScreen
    {
        foreach (str_split($text) as $char) {
            $screen = $screen->update(new KeyMsg(KeyType::Char, $char))[0];
        }

        return $screen;
    }

    private function backspace(AdminUserProfilesScreen $screen, int $count): AdminUserProfilesScreen
    {
        for ($i = 0; $i < $count; $i++) {
            $screen = $screen->update(new KeyMsg(KeyType::Backspace))[0];
        }

        return $screen;
    }

    private function tab(AdminUserProfilesScreen $screen): AdminUserProfilesScreen
    {
        return $screen->update(new KeyMsg(KeyType::Tab))[0];
    }

    /**
     * @param list<Msg> $msgs
     */
    private function firstToast(array $msgs): ShowToastMsg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof ShowToastMsg) {
                return $msg;
            }
        }

        self::fail('expected a ShowToastMsg in the batch');
    }

    /**
     * @param list<Msg> $msgs
     */
    private function containsLoaded(array $msgs): bool
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof AdminProfilesLoadedMsg) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Msg>
     */
    private function collectCmd(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            $out = [];
            foreach ($result->cmds as $child) {
                foreach ($this->collectCmd($child) as $msg) {
                    $out[] = $msg;
                }
            }

            return $out;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? [$msg] : [];
        }

        return $result instanceof Msg ? [$result] : [];
    }

    private function runCmd(?\Closure $cmd): ?Msg
    {
        if ($cmd === null) {
            return null;
        }
        $result = $cmd();
        if ($result instanceof BatchMsg) {
            foreach ($result->cmds as $child) {
                $msg = $this->runCmd($child);
                if ($msg !== null) {
                    return $msg;
                }
            }

            return null;
        }
        if ($result instanceof AsyncCmd) {
            $msg = $this->await($result->promise);

            return $msg instanceof Msg ? $msg : null;
        }

        return $result instanceof Msg ? $result : null;
    }

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null, 'error' => null];
        $promise->then(
            function ($value) use (&$state): void {
                $state['value'] = $value;
                $state['done'] = true;
                Loop::stop();
            },
            function ($error) use (&$state): void {
                $state['error'] = $error;
                $state['done'] = true;
                Loop::stop();
            },
        );

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        if ($state['error'] !== null) {
            throw $state['error'];
        }

        return $state['value'];
    }
}
