<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\Backup;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminBackupActionDoneMsg;
use Phlix\Console\Msg\AdminBackupActionFailedMsg;
use Phlix\Console\Msg\AdminBackupFailedMsg;
use Phlix\Console\Msg\AdminBackupScheduleUpdatedMsg;
use Phlix\Console\Msg\AdminBackupsLoadedMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminBackupScreen;
use Phlix\Console\Tests\Api\FakeTransport;
use Phlix\Console\Ui\Theme;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Toast\ToastType;

final class AdminBackupScreenTest extends TestCase
{
    /** The enveloped `GET .../list` shape — list under `data`. */
    private function backupsEnvelope(): array
    {
        return ['success' => true, 'count' => 2, 'data' => [
            ['id' => 'b-1', 'label' => 'nightly', 'size_bytes' => 1048576, 'is_s3' => 0, 'created_at' => '2026-06-26 12:00:00'],
            ['id' => 'b-2', 'label' => '', 'size_bytes' => 4096, 'is_s3' => 1, 'created_at' => '2026-06-25 12:00:00'],
        ]];
    }

    private function emptyBackups(): array
    {
        return ['success' => true, 'count' => 0, 'data' => []];
    }

    private function scheduleEnvelope(): array
    {
        return ['success' => true, 'data' => [
            'auto_backup_interval_days' => 7,
            'retention_count' => 5,
            'next_scheduled_backup_iso' => '2030-01-01T00:00:00+00:00',
        ]];
    }

    /** A transport scripted with the list + schedule legs the init fans out. */
    private function loadTransport(): FakeTransport
    {
        return (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope());
    }

    private function screenWith(FakeTransport $transport): AdminBackupScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminBackupScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /** Drive init → the loaded Msg, then apply it. */
    private function loaded(FakeTransport $transport): AdminBackupScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminBackupsLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    private function type(Model $model, string $text): Model
    {
        foreach (mb_str_split($text) as $ch) {
            [$model] = $model->update(new KeyMsg(KeyType::Char, $ch));
        }

        return $model;
    }

    // ---- list + schedule render ----------------------------------------

    public function testInitFetchesTheListAndSchedule(): void
    {
        $transport = $this->loadTransport();
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminBackupsLoadedMsg::class, $msg);
        self::assertCount(2, $msg->backups);
        self::assertContainsOnlyInstancesOf(Backup::class, $msg->backups);
        self::assertSame(7, $msg->schedule->autoBackupIntervalDays);
        self::assertSame(2, $transport->requestCount(), 'both list and schedule are fetched');
    }

    public function testLoadingStateBeforeData(): void
    {
        $screen = $this->screenWith($this->loadTransport());

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading backups', $screen->view());
    }

    public function testRendersTheBackupTableAndScheduleLine(): void
    {
        $screen = $this->loaded($this->loadTransport());

        self::assertTrue($screen->isLoaded());
        self::assertCount(2, $screen->backupList());

        $view = $screen->view();
        self::assertStringContainsString('nightly', $view);
        self::assertStringContainsString('Created', $view);
        self::assertStringContainsString('Size', $view);
        // Humanized size: 1 MiB.
        self::assertStringContainsString('1.0 MiB', $view);
        // Schedule line.
        self::assertStringContainsString('Auto-backup every 7 days, keep 5', $view);
        self::assertStringContainsString('next: 2030-01-01T00:00:00+00:00', $view);
        // An unlabelled backup falls back to its id.
        self::assertStringContainsString('b-2', $view);
    }

    public function testEmptyListShowsAPlaceholderWithTheScheduleLine(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->emptyBackups())
            ->json(200, $this->scheduleEnvelope());
        $screen = $this->loaded($transport);

        self::assertSame([], $screen->backupList());
        $view = $screen->view();
        self::assertStringContainsString('No backups yet', $view);
        self::assertStringContainsString('Auto-backup every 7 days', $view);
    }

    public function testFetchFailureShowsTheErrorAndRetry(): void
    {
        $transport = (new FakeTransport())->json(500, ['error' => 'boom']);
        $screen = $this->screenWith($transport);
        [$failed] = $screen->update($this->runCmd($screen->init()) ?? new AdminBackupFailedMsg('x'));

        self::assertFalse($failed->isLoaded());
        self::assertNotNull($failed->error());
        $view = $failed->view();
        self::assertStringContainsString('Could not load the backups', $view);
        self::assertStringContainsString('Press r to retry', $view);
    }

    public function testAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminBackupScreen(new AdminClient($api), cols: 120, rows: 40);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- selection -----------------------------------------------------

    public function testUpAndDownMoveTheSelectionAndClamp(): void
    {
        $screen = $this->loaded($this->loadTransport());
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
        $transport = (new FakeTransport())
            ->json(200, $this->emptyBackups())
            ->json(200, $this->scheduleEnvelope());
        $screen = $this->loaded($transport);

        [$next] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame($screen, $next);
    }

    // ---- create --------------------------------------------------------

    public function testCOpensTheLabelInput(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$creating, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($creating->isCreating());
        self::assertNull($cmd);
        self::assertStringContainsString('Backup label', $creating->view());
    }

    public function testCreateWithALabelCreatesAndRefetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())  // init list
            ->json(200, $this->scheduleEnvelope()) // init schedule
            ->json(200, ['success' => true, 'message' => 'Backup created successfully', 'data' => []]) // create
            ->json(200, $this->backupsEnvelope())  // refetch list
            ->json(200, $this->scheduleEnvelope()); // refetch schedule
        $screen = $this->loaded($transport);

        [$creating] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        $typed = $this->type($creating, 'pre-upgrade');
        [$submitted, $cmd] = $typed->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($submitted->isCreating(), 'the input closes on submit');
        self::assertTrue($submitted->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionDoneMsg::class, $done);
        self::assertStringContainsString('created', $done->message);
        self::assertStringContainsString('/api/v1/admin/backup/create', $transport->requestAt(2)['url']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(2)['body'], true);
        self::assertSame('pre-upgrade', $body['label']);

        $msgs = $this->collectCmd($submitted->update($done)[1]);
        self::assertSame(ToastType::Success, $this->firstToast($msgs)->type);
        self::assertTrue($this->containsLoaded($msgs), 'the list is refetched after create');
    }

    public function testCreateWithoutALabelSendsNoLabel(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, ['success' => true, 'message' => 'Backup created successfully']);
        $screen = $this->loaded($transport);

        [$creating] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        // Submit with no typed label — empty is allowed.
        [$submitted, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($submitted->isCreating());
        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionDoneMsg::class, $done);
        self::assertSame('', $transport->requestAt(2)['body'], 'no label → no JSON body');
    }

    public function testCreateFailureTostsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(500, ['success' => false, 'error' => 'Backup creation failed']);
        $screen = $this->loaded($transport);

        [$creating] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        [$busy, $cmd] = $creating->update(new KeyMsg(KeyType::Enter));

        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionFailedMsg::class, $failed);
        self::assertSame('Backup creation failed', $failed->message);

        [$idle, $batch] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        self::assertCount(2, $idle->backupList(), 'the list is unchanged on failure');
        $toast = $this->firstToast($this->collectCmd($batch));
        self::assertSame(ToastType::Error, $toast->type);
        self::assertStringContainsString('Backup creation failed', $toast->message);
    }

    public function testCreateEscCancels(): void
    {
        $screen = $this->loaded($this->loadTransport());
        [$creating] = $screen->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($creating->isCreating());

        [$cancelled, $cmd] = $creating->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($cancelled->isCreating(), 'Esc cancels the label input');
        self::assertNull($cmd);
    }

    // ---- delete confirm ------------------------------------------------

    public function testXArmsAConfirmThenYDeletes(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, ['success' => true, 'message' => 'Backup deleted successfully']) // delete
            ->json(200, $this->emptyBackups())     // refetch list
            ->json(200, $this->scheduleEnvelope()); // refetch schedule
        $screen = $this->loaded($transport);

        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNull($cmd, 'arming fires no command');
        self::assertNotNull($armed->pendingAction());
        self::assertSame('delete', $armed->pendingAction()?->action);
        self::assertStringContainsString("Delete 'nightly'?", $armed->view());

        [$busy, $performCmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        self::assertNull($busy->pendingAction(), 'performing clears the confirm');
        $done = $this->runCmd($performCmd);
        self::assertInstanceOf(AdminBackupActionDoneMsg::class, $done);
        self::assertStringContainsString('deleted', $done->message);
        self::assertSame('DELETE', $transport->requestAt(2)['method']);
        self::assertStringContainsString('/api/v1/admin/backup/b-1', $transport->requestAt(2)['url']);
    }

    public function testDeleteConfirmNCancels(): void
    {
        $screen = $this->loaded($this->loadTransport());
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertNotNull($armed->pendingAction());

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd);
        self::assertNull($cancelled->pendingAction(), 'n cancels');
    }

    public function testDeleteFailureTostsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(404, ['success' => false, 'error' => 'Backup not found']);
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionFailedMsg::class, $failed);

        [$idle, $batch] = $busy->update($failed);
        self::assertCount(2, $idle->backupList(), 'list unchanged on failure');
        self::assertSame(ToastType::Error, $this->firstToast($this->collectCmd($batch))->type);
    }

    // ---- restore STRONG confirm ----------------------------------------

    public function testRArmsAStrongRestoreConfirmThatWarnsOfOverwrite(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        self::assertNull($cmd, 'arming restore fires no command');
        self::assertSame('restore', $armed->pendingAction()?->action);
        $view = $armed->view();
        self::assertStringContainsString("Restore 'nightly'?", $view);
        self::assertStringContainsString('OVERWRITES current data', $view);
        self::assertStringContainsString('(y/n)', $view);
    }

    public function testRestoreYPerformsIt(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, ['success' => true, 'message' => 'Restore completed']) // restore
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope());
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());

        $done = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionDoneMsg::class, $done);
        self::assertSame('POST', $transport->requestAt(2)['method']);
        self::assertStringContainsString('/api/v1/admin/backup/b-1/restore', $transport->requestAt(2)['url']);
    }

    public function testRestoreNCancelsWithoutFiring(): void
    {
        // n must cancel and never hit the restore endpoint.
        $screen = $this->loaded($this->loadTransport());
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        self::assertNull($cmd, 'n on a restore confirm fires nothing');
        self::assertNull($cancelled->pendingAction());
    }

    public function testRestoreEscCancelsWithoutFiring(): void
    {
        $screen = $this->loaded($this->loadTransport());
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Escape));
        self::assertNull($cmd, 'Esc on a restore confirm fires nothing');
        self::assertNull($cancelled->pendingAction());
    }

    public function testRestoreDoesNotFireWithoutAConfirm(): void
    {
        // Pressing R only arms; no request is made until y.
        $transport = $this->loadTransport();
        $screen = $this->loaded($transport);
        self::assertSame(2, $transport->requestCount(), 'init made two requests');

        $screen->update(new KeyMsg(KeyType::Char, 'R'));
        self::assertSame(2, $transport->requestCount(), 'arming restore makes no request');
    }

    public function testRestoreFailureTostsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(500, ['success' => false, 'error' => 'Checksum mismatch']);
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionFailedMsg::class, $failed);
        self::assertSame('Checksum mismatch', $failed->message);

        [, $batch] = $busy->update($failed);
        self::assertSame(ToastType::Error, $this->firstToast($this->collectCmd($batch))->type);
    }

    // ---- S3 upload -----------------------------------------------------

    public function testSArmsAConfirmThenYUploadsToS3(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, ['success' => true, 'message' => 'Backup uploaded to S3 successfully'])
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope());
        $screen = $this->loaded($transport);

        [$armed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        self::assertNull($cmd);
        self::assertSame('upload-s3', $armed->pendingAction()?->action);
        self::assertStringContainsString("Upload 'nightly' to S3?", $armed->view());

        [$busy, $performCmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        $done = $this->runCmd($performCmd);
        self::assertInstanceOf(AdminBackupActionDoneMsg::class, $done);
        self::assertStringContainsString('/api/v1/admin/backup/b-1/upload-s3', $transport->requestAt(2)['url']);
    }

    public function testS3UploadFailureTostsTheServerError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(500, ['success' => false, 'error' => 'S3 upload failed']);
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionFailedMsg::class, $failed);

        [, $batch] = $busy->update($failed);
        self::assertSame(ToastType::Error, $this->firstToast($this->collectCmd($batch))->type);
    }

    // ---- schedule edit -------------------------------------------------

    public function testEOpensTheScheduleFormPrefilled(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$editing, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($editing->isEditingSchedule());
        self::assertNull($cmd);
        $view = $editing->view();
        self::assertStringContainsString('interval', $view);
        // Pre-filled with the current schedule's values.
        self::assertStringContainsString('7', $view);
    }

    public function testScheduleEditWithValidValuesUpdatesAndRefreshesTheLine(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, ['success' => true, 'message' => 'Schedule updated successfully', 'data' => [
                'auto_backup_interval_days' => 14,
                'retention_count' => 3,
            ]]);
        $screen = $this->loaded($transport);

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        // Clear the pre-filled interval (7) then type 14; Enter advances to retention.
        $editing = $this->retypeField($editing, '14');
        [$onRetention] = $editing->update(new KeyMsg(KeyType::Enter));
        $onRetention = $this->retypeField($onRetention, '3');
        [$submitted, $cmd] = $onRetention->update(new KeyMsg(KeyType::Enter));

        self::assertFalse($submitted->isEditingSchedule(), 'the form closes on submit');
        self::assertTrue($submitted->isBusy());

        $updated = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupScheduleUpdatedMsg::class, $updated);
        self::assertSame(14, $updated->schedule->autoBackupIntervalDays);
        self::assertSame('PUT', $transport->requestAt(2)['method']);
        /** @var array<string,mixed> $body */
        $body = json_decode($transport->requestAt(2)['body'], true);
        self::assertSame(14, $body['auto_backup_interval_days']);
        self::assertSame(3, $body['retention_count']);

        [$applied, $toastCmd] = $submitted->update($updated);
        self::assertFalse($applied->isBusy());
        self::assertSame(14, $applied->schedule()?->autoBackupIntervalDays);
        self::assertStringContainsString('Auto-backup every 14 days, keep 3', $applied->view());
        self::assertSame(ToastType::Success, $this->firstToast($this->collectCmd($toastCmd))->type);
    }

    public function testScheduleEditRejectsAnInvalidZeroValueAtTheBoundary(): void
    {
        // candy-forms gates submit on each field's validator: interval = 0 fails
        // isPositiveInt, so Enter on the last field does NOT submit — the form
        // stays open showing the field's inline error, and no request is made.
        $transport = $this->loadTransport();
        $screen = $this->loaded($transport);

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        // interval = 0 (invalid), advance, retention = 5, submit.
        $editing = $this->retypeField($editing, '0');
        [$onRetention] = $editing->update(new KeyMsg(KeyType::Enter));
        $onRetention = $this->retypeField($onRetention, '5');
        [$next, $cmd] = $onRetention->update(new KeyMsg(KeyType::Enter));

        self::assertTrue($next->isEditingSchedule(), 'an invalid value keeps the form open');
        self::assertSame(2, $transport->requestCount(), 'no update request was made');
        self::assertStringContainsString(
            '! Enter a whole number greater than 0',
            $next->view(),
            'the interval field surfaces its inline validation error',
        );
        foreach ($this->collectCmd($cmd) as $msg) {
            self::assertNotInstanceOf(ShowToastMsg::class, $msg, 'a blocked submit shows an inline error, not a toast');
        }
    }

    public function testScheduleEditServerRejectionTostsTheError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(400, ['success' => false, 'error' => 'Invalid retention count']);
        $screen = $this->loaded($transport);

        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        $editing = $this->retypeField($editing, '14');
        [$onRetention] = $editing->update(new KeyMsg(KeyType::Enter));
        $onRetention = $this->retypeField($onRetention, '9');
        [$busy, $cmd] = $onRetention->update(new KeyMsg(KeyType::Enter));

        $failed = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupActionFailedMsg::class, $failed);
        self::assertSame('Invalid retention count', $failed->message);

        [$idle, $batch] = $busy->update($failed);
        self::assertFalse($idle->isBusy());
        self::assertSame(ToastType::Error, $this->firstToast($this->collectCmd($batch))->type);
    }

    public function testScheduleEditEscCancels(): void
    {
        $screen = $this->loaded($this->loadTransport());
        [$editing] = $screen->update(new KeyMsg(KeyType::Char, 'e'));
        self::assertTrue($editing->isEditingSchedule());

        [$cancelled, $cmd] = $editing->update(new KeyMsg(KeyType::Escape));
        self::assertFalse($cancelled->isEditingSchedule(), 'Esc cancels the schedule form');
        self::assertNull($cmd);
    }

    // ---- busy / guards / nav -------------------------------------------

    public function testActionKeysAreIgnoredWhileBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, ['success' => true, 'message' => 'Restore completed']);
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        [$busy] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());

        [$still, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($busy, $still, 'a second action is ignored while busy');
        self::assertNull($cmd);
    }

    public function testActionsOnAnEmptyListAreNoOps(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->emptyBackups())
            ->json(200, $this->scheduleEnvelope());
        $screen = $this->loaded($transport);

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'x'));
        self::assertSame($screen, $next, 'no selected backup → no action');
        self::assertNull($cmd);
    }

    public function testAnUnhandledActionKeyIsANoOp(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testANonActionKeyWithNoConfirmIsANoOp(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testAnUnrelatedKeyDuringAConfirmIsIgnored(): void
    {
        $screen = $this->loaded($this->loadTransport());
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'x'));

        [$still, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($armed, $still);
        self::assertNull($cmd);
    }

    public function testRRefetchesTheList(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope());
        $screen = $this->loaded($transport);

        [$reloading, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertFalse($reloading->isLoaded());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminBackupsLoadedMsg::class, $msg);
    }

    public function testEscapeAndQGoBack(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [, $escCmd] = $screen->update(new KeyMsg(KeyType::Escape));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($escCmd));

        [, $qCmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));
        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($qCmd));
    }

    public function testResizeReflowsTheScreen(): void
    {
        $screen = $this->loaded($this->loadTransport());

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(80, 24));

        self::assertNull($cmd);
        self::assertStringContainsString('nightly', $resized->view());
    }

    public function testCrumbLabelAndImmutability(): void
    {
        $screen = $this->screenWith($this->loadTransport());
        self::assertSame('Backup', $screen->crumbLabel());

        $crumbed = $screen->withCrumbs(['Admin', 'Backup']);
        self::assertNotSame($screen, $crumbed);

        $themed = $screen->withTheme(Theme::midnight());
        self::assertNotSame($screen, $themed);
    }

    public function testAnUnhandledMessageIsANoOp(): void
    {
        $screen = $this->screenWith($this->loadTransport());

        [$next, $cmd] = $screen->update(new class implements Msg {});

        self::assertSame($screen, $next);
        self::assertNull($cmd);
    }

    public function testScheduleLineIsADashWhenNoScheduleIsLoaded(): void
    {
        // A loaded list with a null next-run + small/zero sizes exercises the
        // "not scheduled" branch and the humanBytes small/zero branches.
        $transport = (new FakeTransport())
            ->json(200, ['success' => true, 'data' => [
                ['id' => 'b-0', 'label' => 'empty', 'size_bytes' => 0, 'is_s3' => 0, 'created_at' => '2026-06-26'],
                ['id' => 'b-9', 'label' => 'tiny', 'size_bytes' => 512, 'is_s3' => 0, 'created_at' => '2026-06-26'],
            ]])
            ->json(200, ['success' => true, 'data' => [
                'auto_backup_interval_days' => 7,
                'retention_count' => 5,
                // no next_scheduled_backup_iso → "not scheduled"
            ]]);
        $screen = $this->loaded($transport);

        $view = $screen->view();
        self::assertStringContainsString('next: not scheduled', $view);
        self::assertStringContainsString('0 B', $view, 'a zero-byte backup humanizes to 0 B');
        self::assertStringContainsString('512 B', $view, 'a sub-KiB backup keeps plain bytes');
    }

    public function testTheBusyStatusLineShowsWhileAnActionIsInFlight(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(200, ['success' => true, 'message' => 'Restore completed']);
        $screen = $this->loaded($transport);

        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        [$busy] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertTrue($busy->isBusy());
        self::assertStringContainsString('Working', $busy->view());
    }

    public function testAnActionAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(401, ['error' => 'expired'])); // delete → 401
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminBackupScreen(new AdminClient($api), cols: 120, rows: 40);
        [$loaded] = $screen->update($this->runCmd($screen->init()) ?? new AdminBackupFailedMsg('x'));

        [$armed] = $loaded->update(new KeyMsg(KeyType::Char, 'x'));
        [, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testScheduleEditAuthErrorMapsToSessionExpired(): void
    {
        $api = new ApiClient('https://srv', (new FakeTransport())
            ->json(200, $this->backupsEnvelope())
            ->json(200, $this->scheduleEnvelope())
            ->json(401, ['error' => 'expired']));
        $api->setToken(new TokenBundle('access-1', '', 'Bearer', time() + 3600));
        $screen = new AdminBackupScreen(new AdminClient($api), cols: 120, rows: 40);
        [$loaded] = $screen->update($this->runCmd($screen->init()) ?? new AdminBackupFailedMsg('x'));

        [$editing] = $loaded->update(new KeyMsg(KeyType::Char, 'e'));
        $editing = $this->retypeField($editing, '14');
        [$onRetention] = $editing->update(new KeyMsg(KeyType::Enter));
        $onRetention = $this->retypeField($onRetention, '3');
        [, $cmd] = $onRetention->update(new KeyMsg(KeyType::Enter));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    // ---- helpers -------------------------------------------------------

    /** Clear a focused field's pre-filled value with backspaces, then type $text. */
    private function retypeField(Model $model, string $text): Model
    {
        for ($i = 0; $i < 12; ++$i) {
            [$model] = $model->update(new KeyMsg(KeyType::Backspace));
        }

        return $this->type($model, $text);
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
            if ($msg instanceof AdminBackupsLoadedMsg) {
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
     * awaiting its async legs).
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
