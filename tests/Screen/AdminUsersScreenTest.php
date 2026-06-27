<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\AdminUser;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminUserActionDoneMsg;
use Phlix\Console\Msg\AdminUserActionFailedMsg;
use Phlix\Console\Msg\AdminUsersFailedMsg;
use Phlix\Console\Msg\AdminUsersLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminUsersScreen;
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

final class AdminUsersScreenTest extends TestCase
{
    /**
     * The real `GET /api/v1/admin/users` shape: the list at the TOP LEVEL, with
     * NO `{success, data}` envelope (the AdminUserController is unenveloped).
     *
     * Two users: bob (admin/active) and amy (pending).
     */
    private function usersPayload(): array
    {
        return [
            'users' => [
                ['id' => 'u-1', 'username' => 'bob', 'email' => 'bob@x', 'is_admin' => 1, 'status' => 'active', 'last_login' => '2026-06-26'],
                ['id' => 'u-2', 'username' => 'amy', 'email' => 'amy@x', 'is_admin' => 0, 'status' => 'pending'],
            ],
        ];
    }

    /** An empty user list in the real top-level shape. */
    private function emptyUsers(): array
    {
        return ['users' => []];
    }

    private function screenWith(FakeTransport $transport): AdminUsersScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminUsersScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminUsersScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminUsersLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- list / loading / error ----------------------------------------

    public function testInitFetchesTheUserList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminUsersLoadedMsg::class, $msg);
        self::assertCount(2, $msg->users);
        self::assertContainsOnlyInstancesOf(AdminUser::class, $msg->users);
    }

    public function testLoadingStateBeforeUsers(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->usersPayload()));

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading users', $screen->view());
    }

    public function testRendersTheUserTable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        self::assertTrue($screen->isLoaded());
        self::assertCount(2, $screen->userList());

        $view = $screen->view();
        self::assertStringContainsString('bob', $view);
        self::assertStringContainsString('amy', $view);
        self::assertStringContainsString('Admin', $view);
        self::assertStringContainsString('Pending', $view);
        self::assertStringContainsString('Filter: All', $view);
        self::assertStringContainsString('2 users', $view);
    }

    public function testEmptyListShowsAPlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyUsers()));

        self::assertSame([], $screen->userList());
        self::assertStringContainsString('No all users', $screen->view());
    }

    public function testFetchFailureShowsTheErrorAndRetry(): void
    {
        $transport = (new FakeTransport())->json(500, ['error' => 'boom']);
        $screen = $this->screenWith($transport);
        [$failed] = $screen->update($this->runCmd($screen->init()) ?? new AdminUsersFailedMsg('x'));

        self::assertFalse($failed->isLoaded());
        self::assertNotNull($failed->error());
        $view = $failed->view();
        self::assertStringContainsString('Could not load the users', $view);
        self::assertStringContainsString('Press r to retry', $view);
    }

    public function testAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminUsersScreen(new AdminClient($api), cols: 120, rows: 40);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- selection -----------------------------------------------------

    public function testUpAndDownMoveTheSelectionAndClamp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));
        self::assertSame(0, $screen->selectedIndex());

        [$d1] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $d1->selectedIndex());
        // Down at the bottom clamps (same instance).
        [$d2] = $d1->update(new KeyMsg(KeyType::Down));
        self::assertSame($d1, $d2);

        [$up] = $d1->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->selectedIndex());
    }

    public function testSelectionMoveOnEmptyListIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyUsers()));

        [$next] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame($screen, $next);
    }

    // ---- filter cycle --------------------------------------------------

    public function testFCyclesTheStatusFilterAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init: All
            ->json(200, $this->emptyUsers())   // Pending
            ->json(200, $this->emptyUsers())   // Active
            ->json(200, $this->emptyUsers())   // Disabled
            ->json(200, $this->usersPayload());  // back to All
        $screen = $this->loaded($transport);
        self::assertSame('All', $screen->filterLabel());

        [$pending, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'f'));
        self::assertSame('Pending', $pending->filterLabel());
        self::assertFalse($pending->isLoaded(), 'cycling enters the loading state');
        self::assertInstanceOf(\Closure::class, $cmd, 'cycling refetches');
        $this->runCmd($cmd);
        self::assertStringContainsString('status=pending', $transport->requestAt(1)['url']);

        [$active] = $pending->update(new KeyMsg(KeyType::Char, 'f'));
        self::assertSame('Active', $active->filterLabel());
        [$disabled] = $active->update(new KeyMsg(KeyType::Char, 'f'));
        self::assertSame('Disabled', $disabled->filterLabel());
        [$all] = $disabled->update(new KeyMsg(KeyType::Char, 'f'));
        self::assertSame('All', $all->filterLabel(), 'the cycle wraps back to All');
    }

    // ---- approve (non-destructive, immediate) --------------------------

    public function testApproveFiresImmediatelyTheToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(200, ['message' => 'User approved successfully'])  // approve
            ->json(200, $this->usersPayload()); // refetch
        $screen = $this->loaded($transport);
        // Select amy (pending) and approve.
        [$onAmy] = $screen->update(new KeyMsg(KeyType::Down));

        [$busy, $cmd] = $onAmy->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($busy->isBusy(), 'an action enters the busy state');
        self::assertStringContainsString('Working', $busy->view());

        // The action resolves to a done Msg (no confirm for approve).
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUserActionDoneMsg::class, $done);
        self::assertSame('User approved successfully', $done->message);
        self::assertNull($done->revealedPassword);

        // Applying it toasts the message and refetches.
        $msgs = $this->collectCmd($busy->update($done)[1]);
        $toast = $this->firstToast($msgs);
        self::assertSame(ToastType::Success, $toast->type);
        self::assertStringContainsString('approved', $toast->message);
        self::assertContainsOnlyInstancesOf(Msg::class, $msgs);
        self::assertTrue($this->containsLoaded($msgs), 'the list is refetched after a success');
    }

    // ---- destructive confirm flow --------------------------------------

    public function testDeleteArmsAConfirmThenYPerformsIt(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(200, ['message' => 'User deleted successfully'])  // delete
            ->json(200, $this->emptyUsers()); // refetch
        $screen = $this->loaded($transport);

        // x arms the confirm — no command yet.
        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($cmd, 'arming a destructive action fires no command');
        self::assertNotNull($armed->pendingAction());
        self::assertSame('delete', $armed->pendingAction()?->action);
        $view = $armed->view();
        self::assertStringContainsString("Delete user 'bob'?", $view);
        self::assertStringContainsString('(y/n)', $view);

        // y performs it.
        [$busy, $performCmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        self::assertNull($busy->pendingAction(), 'performing clears the confirm');
        $done = $this->runCmd($performCmd);
        self::assertInstanceOf(AdminUserActionDoneMsg::class, $done);
        self::assertSame('User deleted successfully', $done->message);
    }

    public function testRejectPerformsViaTheRejectEndpoint(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(200, ['message' => 'User rejected successfully'])  // reject
            ->json(200, $this->usersPayload()); // refetch
        $screen = $this->loaded($transport);
        // Reject amy (index 1, pending).
        [$onAmy] = $screen->update(new KeyMsg(KeyType::Down));
        [$armed] = $onAmy->update(new KeyMsg(KeyType::Char, 'j'));
        [, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUserActionDoneMsg::class, $done);
        self::assertSame('User rejected successfully', $done->message);
        self::assertStringContainsString('/api/v1/admin/users/u-2/reject', $transport->requestAt(1)['url']);
    }

    public function testANonActionKeyWithNoConfirmIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        // Enter is neither an arrow, a char action, Esc, nor a confirm key.
        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testConfirmNCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNotNull($armed->pendingAction());

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingAction(), 'n cancels the confirm');
    }

    public function testConfirmEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertNotNull($armed->pendingAction());

        [$cancelled] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cancelled->pendingAction(), 'Esc cancels the confirm');
    }

    public function testAnUnrelatedKeyDuringAConfirmIsIgnored(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($armed, $still, 'an unrelated key during a confirm is a no-op');
        self::assertNull($cmd);
    }

    public function testDisableRejectAndSetAdminEachArmAConfirm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        [$disable] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        self::assertSame('disable', $disable->pendingAction()?->action);
        self::assertStringContainsString("Disable user 'bob'?", $disable->view());

        // Reject on amy (index 1).
        [$onAmy] = $screen->update(new KeyMsg(KeyType::Down));
        [$reject] = $onAmy->update(new KeyMsg(KeyType::Char, 'j'));
        self::assertSame('reject', $reject->pendingAction()?->action);
        self::assertStringContainsString("Reject (delete) pending user 'amy'?", $reject->view());

        // Toggle admin on bob (currently admin) → "Remove admin".
        [$setAdmin] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertSame('set-admin', $setAdmin->pendingAction()?->action);
        self::assertStringContainsString("Remove admin from 'bob'?", $setAdmin->view());

        // Toggle admin on amy (not admin) → "Make … an admin".
        [$makeAdmin] = $onAmy->update(new KeyMsg(KeyType::Char, 'm'));
        self::assertStringContainsString("Make 'amy' an admin?", $makeAdmin->view());
    }

    public function testSetAdminSendsTheToggledFlag(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(200, ['message' => 'User admin status updated successfully'])  // set-admin
            ->json(200, $this->usersPayload()); // refetch
        $screen = $this->loaded($transport);

        // bob is admin → toggling sends is_admin=false.
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'm'));
        [, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $this->runCmd($cmd);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertFalse($body['is_admin'], 'toggling an admin demotes them');
    }

    // ---- reset password reveal -----------------------------------------

    public function testResetPasswordRevealsTheNewPassword(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(200, ['message' => 'Password reset successfully', 'new_password' => 'Hunter2!xyz'])  // reset
            ->json(200, $this->usersPayload()); // refetch
        $screen = $this->loaded($transport);

        // p resets immediately (no confirm).
        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'p'));
        self::assertTrue($busy->isBusy());
        self::assertNull($busy->pendingAction());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUserActionDoneMsg::class, $done);
        self::assertSame('Hunter2!xyz', $done->revealedPassword);
        self::assertStringContainsString('New password for bob: Hunter2!xyz', $done->message);

        // Applying it reveals the password on the status line and toasts it.
        [$revealed, $batch] = $busy->update($done);
        self::assertSame('New password for bob: Hunter2!xyz', $revealed->note());
        self::assertStringContainsString('New password for bob: Hunter2!xyz', $revealed->view());

        $toast = $this->firstToast($this->collectCmd($batch));
        self::assertSame(ToastType::Success, $toast->type);
        self::assertStringContainsString('Hunter2!xyz', $toast->message);
    }

    // ---- action failure ------------------------------------------------

    public function testActionFailureTostsTheServerErrorAndLeavesTheListUnchanged(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(400, ['error' => 'Cannot disable the last admin']); // disable
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'd'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUserActionFailedMsg::class, $failed);
        self::assertSame('Cannot disable the last admin', $failed->message);

        [$idle, $batch] = $busy->update($failed);
        self::assertFalse($idle->isBusy(), 'a failed action leaves the busy state');
        self::assertCount(2, $idle->userList(), 'the list is unchanged on failure');

        $toast = $this->firstToast($this->collectCmd($batch));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Cannot disable the last admin', $toast->message);
    }

    public function testActionAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())
            ->json(200, $this->usersPayload())
            ->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminUsersScreen(new AdminClient($api), cols: 120, rows: 40);
        [$loaded] = $screen->update($this->runCmd($screen->init()) ?? new AdminUsersFailedMsg('x'));

        [$busy, $cmd] = $loaded->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($busy->isBusy());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- busy / guards -------------------------------------------------

    public function testActionKeysAreIgnoredWhileBusy(): void
    {
        // Firing an action enters the busy state synchronously (regardless of when
        // its command resolves), so the screen is busy as soon as 'a' is pressed.
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));
        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($busy->isBusy());

        [$still, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($busy, $still, 'a second action is ignored while busy');
        self::assertNull($cmd);
    }

    public function testActionsOnAnEmptyListAreNoOps(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyUsers()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertSame($screen, $next, 'no selected user → no action');
        self::assertNull($cmd);
    }

    public function testAnUnhandledActionKeyIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- refresh / nav / misc ------------------------------------------

    public function testRRefetchesTheList(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())
            ->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        [$reloading, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($reloading->isLoaded());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUsersLoadedMsg::class, $msg);
    }

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testResizeReflowsTheScreen(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(80, 24));

        self::assertNull($cmd);
        self::assertStringContainsString('bob', $resized->view());
    }

    public function testCrumbLabelAndImmutability(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->usersPayload()));
        self::assertSame('Users', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Users']);
        self::assertNotSame($screen, $crumbed);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->usersPayload()));

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    // ---- create form ---------------------------------------------------

    public function testCOpensTheCreateForm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        [$creating, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($creating->isCreating());
        self::assertFalse($creating->isEditing());
        self::assertStringContainsString('Create a user', $creating->view());
    }

    public function testCOpensTheCreateFormEvenOnAnEmptyList(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyUsers()));

        [$creating] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($creating->isCreating(), 'create needs no selected user');
    }

    public function testCreateSubmitValidPostsTheUserToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(201, ['user_id' => 'u-9', 'message' => 'User created successfully'])  // create
            ->json(200, $this->usersPayload()); // refetch
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'alice_99');         // username
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'alice@example.com'); // email
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'sup3rsecret');       // password
        $creating = $this->tab($creating);                       // onto the is_admin Select (default No)

        [$busy, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->isBusy(), 'a valid submit enters the busy state');
        self::assertFalse($busy->isCreating(), 'a valid submit closes the form');

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUserActionDoneMsg::class, $done);
        self::assertSame('User created successfully', $done->message);

        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame('alice_99', $body['username']);
        self::assertSame('alice@example.com', $body['email']);
        self::assertSame('sup3rsecret', $body['password']);
        self::assertFalse($body['is_admin'], 'the default is_admin choice is No');

        $msgs = $this->collectCmd($busy->update($done)[1]);
        $toast = $this->firstToast($msgs);
        self::assertSame(ToastType::Success, $toast->type);
        self::assertTrue($this->containsLoaded($msgs), 'the list is refetched after a create');
    }

    public function testCreateSubmitWithAShortUsernameKeepsTheFormOpenAndSendsNoRequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'ab');                // too short (< 3)
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'ok@example.com');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'sup3rsecret');
        $creating = $this->tab($creating);

        [$still, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd, 'an invalid submit issues no command');
        self::assertTrue($still->isCreating(), 'the form stays open');
        self::assertNotNull($still->formError());
        self::assertStringContainsString('Username', (string) $still->formError());
        self::assertSame(1, $transport->requestCount(), 'no request beyond the initial fetch');
    }

    public function testCreateSubmitWithABadEmailKeepsTheFormOpen(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'alice_99');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'not-an-email');      // invalid
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'sup3rsecret');
        $creating = $this->tab($creating);

        [$still, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd);
        self::assertTrue($still->isCreating());
        self::assertStringContainsString('email', (string) $still->formError());
        self::assertSame(1, $transport->requestCount());
    }

    public function testCreateSubmitWithAShortPasswordKeepsTheFormOpen(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'alice_99');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'alice@example.com');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'short');             // < 8
        $creating = $this->tab($creating);

        [$still, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd);
        self::assertTrue($still->isCreating());
        self::assertStringContainsString('Password', (string) $still->formError());
        self::assertSame(1, $transport->requestCount());
    }

    public function testCreateFailureToastsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(400, ['error' => 'Email already in use']); // create
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'alice_99');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'taken@example.com');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'sup3rsecret');
        $creating = $this->tab($creating);

        [$busy, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUserActionFailedMsg::class, $failed);
        self::assertSame('Email already in use', $failed->message);

        $toast = $this->firstToast($this->collectCmd($busy->update($failed)[1]));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Email already in use', $toast->message);
    }

    public function testCreateAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())
            ->json(200, $this->usersPayload())
            ->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminUsersScreen(new AdminClient($api), cols: 120, rows: 40);
        $loaded = $screen->update($this->runCmd($screen->init()) ?? new AdminUsersFailedMsg('x'))[0];

        $creating = $loaded->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'alice_99');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'alice@example.com');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'sup3rsecret');
        $creating = $this->tab($creating);

        [, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    public function testTheReopenedFormRendersTheValidationError(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        $creating = $this->type($creating, 'ab');   // too short
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'ok@example.com');
        $creating = $this->tab($creating);
        $creating = $this->type($creating, 'sup3rsecret');
        $creating = $this->tab($creating);

        $reopened = $creating->update(new KeyMsg(KeyType::Enter))[0];
        self::assertNotNull($reopened->formError());
        // The error is shown in the rendered form body, with the entered values
        // pre-filled so they're not lost.
        $view = $reopened->view();
        self::assertStringContainsString('! ', $view);
        self::assertStringContainsString((string) $reopened->formError(), $view);
        self::assertStringContainsString('ok@example.com', $view, 'the entered email survives the reopen');
    }

    public function testCreateEscAbortsWithoutARequest(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        $creating = $screen->update(new KeyMsg(KeyType::Char, 'c'))[0];
        self::assertTrue($creating->isCreating());

        [$closed, $cmd] = $creating->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
        self::assertFalse($closed->isCreating(), 'Esc aborts the form');
        self::assertSame(1, $transport->requestCount(), 'aborting issues no request');
    }

    // ---- edit form -----------------------------------------------------

    public function testEOpensAPreFilledEditForm(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->usersPayload()));

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertTrue($editing->isEditing());
        self::assertFalse($editing->isCreating());
        $view = $editing->view();
        self::assertStringContainsString("Edit 'bob'", $view);
        self::assertStringContainsString('bob', $view, 'the username is pre-filled');
        self::assertStringContainsString('bob@x', $view, 'the email is pre-filled');
    }

    public function testEWithNoSelectedUserIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyUsers()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'E'));
        self::assertSame($screen, $next, 'no selected user → no edit form');
        self::assertNull($cmd);
    }

    public function testEditChangingOnlyTheEmailPutsThatFieldThenToastsAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(200, ['message' => 'User updated successfully'])  // update
            ->json(200, $this->usersPayload()); // refetch
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        // Leave the username (bob) as-is; change the email. Move to the email field
        // and clear it, then type the new value.
        $editing = $this->tab($editing);             // onto email
        $editing = $this->clear($editing, 'bob@x');  // wipe the pre-filled email
        $editing = $this->type($editing, 'bob@new.com');
        $editing = $this->tab($editing);             // onto password (left blank)

        [$busy, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
        self::assertTrue($busy->isBusy());
        self::assertFalse($busy->isEditing());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminUserActionDoneMsg::class, $done);
        self::assertSame('User updated successfully', $done->message);

        self::assertSame('PUT', $transport->requestAt(1)['method']);
        self::assertStringContainsString('/api/v1/admin/users/u-1', $transport->requestAt(1)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(1)['body'], true);
        self::assertSame(['email' => 'bob@new.com'], $body, 'only the changed email is sent; a blank password is omitted');

        $msgs = $this->collectCmd($busy->update($done)[1]);
        self::assertTrue($this->containsLoaded($msgs), 'the list is refetched after an edit');
    }

    public function testEditWithNoChangesSendsNoFieldsButStillUpdates(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->usersPayload())  // init
            ->json(200, ['message' => 'User updated successfully'])  // update
            ->json(200, $this->usersPayload()); // refetch
        $screen = $this->loaded($transport);

        // Open the edit form and submit without changing anything.
        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        $editing = $this->tab($editing);  // email
        $editing = $this->tab($editing);  // password (blank)

        [$busy, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
        $this->runCmd($cmd);

        self::assertSame('', $transport->requestAt(1)['body'], 'no changed fields → no JSON body');
    }

    public function testEditInvalidChangedFieldKeepsTheFormOpen(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        // Change the username to something invalid, then advance to the last field
        // so Enter submits (rather than just advancing focus).
        $editing = $this->clear($editing, 'bob');
        $editing = $this->type($editing, '!!');   // invalid + too short
        $editing = $this->tab($editing);          // email
        $editing = $this->tab($editing);          // password (last field)

        [$still, $cmd] = $editing->update(new KeyMsg(KeyType::Enter));
        self::assertNull($cmd, 'an invalid changed field issues no command');
        self::assertTrue($still->isEditing());
        self::assertNotNull($still->formError());
        self::assertSame(1, $transport->requestCount(), 'no request beyond the initial fetch');
    }

    public function testEditEscAborts(): void
    {
        $transport = (new FakeTransport())->json(200, $this->usersPayload());
        $screen = $this->loaded($transport);

        $editing = $screen->update(new KeyMsg(KeyType::Char, 'E'))[0];
        self::assertTrue($editing->isEditing());

        [$closed, $cmd] = $editing->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd);
        self::assertFalse($closed->isEditing());
        self::assertSame(1, $transport->requestCount());
    }

    // ---- helpers -------------------------------------------------------

    /** Type each character of $text into the form's focused field. */
    private function type(AdminUsersScreen $screen, string $text): AdminUsersScreen
    {
        foreach (str_split($text) as $char) {
            $screen = $screen->update(new KeyMsg(KeyType::Char, $char))[0];
        }

        return $screen;
    }

    /** Backspace away the given pre-filled value from the focused field. */
    private function clear(AdminUsersScreen $screen, string $current): AdminUsersScreen
    {
        for ($i = 0, $n = strlen($current); $i < $n; $i++) {
            $screen = $screen->update(new KeyMsg(KeyType::Backspace))[0];
        }

        return $screen;
    }

    /** Advance the form focus to the next field (Tab). */
    private function tab(AdminUsersScreen $screen): AdminUsersScreen
    {
        return $screen->update(new KeyMsg(KeyType::Tab))[0];
    }

    private function firstToast(array $msgs): ShowToastMsg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof ShowToastMsg) {
                return $msg;
            }
        }

        self::fail('expected a ShowToastMsg in the batch');
    }

    /** @param list<Msg> $msgs */
    private function containsLoaded(array $msgs): bool
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof AdminUsersLoadedMsg) {
                return true;
            }
        }

        return false;
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

    /**
     * Run a command and collect EVERY resolved Msg (flattening a batch and
     * awaiting its async legs), so a batch of toast + refetch can be asserted in
     * full.
     *
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

    private function await(PromiseInterface $promise, float $timeout = 2.0): mixed
    {
        $state = ['done' => false, 'value' => null];
        $promise->then(function ($value) use (&$state): void {
            $state['value'] = $value;
            $state['done'] = true;
            Loop::stop();
        });

        if (!$state['done']) {
            $timer = Loop::addTimer($timeout, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
        }

        return $state['value'];
    }
}
