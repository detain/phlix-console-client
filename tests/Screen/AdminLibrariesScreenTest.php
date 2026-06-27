<?php

declare(strict_types=1);

namespace Phlix\Console\Tests\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\ApiClient;
use Phlix\Console\Api\Dto\Admin\ScanJob;
use Phlix\Console\Api\Dto\Library;
use Phlix\Console\Config\TokenBundle;
use Phlix\Console\Msg\AdminLibrariesFailedMsg;
use Phlix\Console\Msg\AdminLibrariesLoadedMsg;
use Phlix\Console\Msg\AdminLibraryActionDoneMsg;
use Phlix\Console\Msg\AdminLibraryActionFailedMsg;
use Phlix\Console\Msg\AdminLibraryScanHistoryFailedMsg;
use Phlix\Console\Msg\AdminLibraryScanHistoryLoadedMsg;
use Phlix\Console\Msg\AdminScanStatusLoadedMsg;
use Phlix\Console\Msg\AdminScanStatusTickMsg;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Screen\AdminLibrariesScreen;
use Phlix\Console\Tests\Api\FakeTransport;
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

final class AdminLibrariesScreenTest extends TestCase
{
    /** The real top-level `GET /libraries` shape. Two libraries. */
    private function librariesPayload(): array
    {
        return [
            'libraries' => [
                ['id' => 'lib-1', 'name' => 'Movies', 'type' => 'movie', 'item_count' => 42],
                ['id' => 'lib-2', 'name' => 'Shows', 'type' => 'series', 'item_count' => 7],
            ],
        ];
    }

    private function emptyLibraries(): array
    {
        return ['libraries' => []];
    }

    /** @param array<string,mixed>|null $job */
    private function scanStatus(?array $job): array
    {
        return ['scan_status' => $job];
    }

    private function runningJob(string $libraryId = 'lib-1'): array
    {
        return [
            'id' => 'job-1', 'library_id' => $libraryId, 'type' => 'scan', 'status' => 'running',
            'items_found' => 12, 'items_added' => 3, 'items_updated' => 1, 'items_removed' => 0,
            'current_path' => '/media/movies/a.mkv',
        ];
    }

    private function completedJob(string $libraryId = 'lib-1'): array
    {
        return [
            'id' => 'job-1', 'library_id' => $libraryId, 'type' => 'scan', 'status' => 'completed',
            'items_found' => 12, 'items_added' => 3, 'items_updated' => 1, 'items_removed' => 0,
        ];
    }

    private function failedJob(string $libraryId = 'lib-1'): array
    {
        return [
            'id' => 'job-1', 'library_id' => $libraryId, 'type' => 'scan', 'status' => 'failed',
            'items_found' => 0, 'items_added' => 0, 'items_updated' => 0, 'items_removed' => 0,
            'error' => 'Permission denied on /media',
        ];
    }

    /** The real top-level `GET /scan-history` shape: two jobs, newest first. */
    private function historyPayload(): array
    {
        return [
            'history' => [
                ['id' => 'job-2', 'library_id' => 'lib-1', 'type' => 'scan', 'status' => 'completed',
                 'items_found' => 5, 'items_added' => 2, 'items_updated' => 0, 'items_removed' => 0,
                 'completed_at' => '2026-06-26 10:00:00'],
                ['id' => 'job-1', 'library_id' => 'lib-1', 'type' => 'rescan', 'status' => 'failed',
                 'items_found' => 0, 'items_added' => 0, 'items_updated' => 0, 'items_removed' => 0,
                 'error' => 'boom', 'queued_at' => '2026-06-25 09:00:00'],
            ],
        ];
    }

    private function emptyHistory(): array
    {
        return ['history' => []];
    }

    private function screenWith(FakeTransport $transport): AdminLibrariesScreen
    {
        $api = new ApiClient('https://srv', $transport);
        $api->setToken(new TokenBundle('access-1', 'refresh-1', 'Bearer', time() + 3600));

        return new AdminLibrariesScreen(new AdminClient($api), cols: 120, rows: 40);
    }

    /**
     * Drive init → the loaded Msg, apply it (its follow-up status fetch is left to
     * the caller). Returns the loaded screen.
     */
    private function loaded(FakeTransport $transport): AdminLibrariesScreen
    {
        $screen = $this->screenWith($transport);
        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminLibrariesLoadedMsg::class, $msg);

        return $screen->update($msg)[0];
    }

    // ---- list / loading / error ----------------------------------------

    public function testInitFetchesTheLibraryList(): void
    {
        $transport = (new FakeTransport())->json(200, $this->librariesPayload());
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminLibrariesLoadedMsg::class, $msg);
        self::assertCount(2, $msg->libraries);
        self::assertContainsOnlyInstancesOf(Library::class, $msg->libraries);
    }

    public function testLoadingStateBeforeLibraries(): void
    {
        $screen = $this->screenWith((new FakeTransport())->json(200, $this->librariesPayload()));

        self::assertFalse($screen->isLoaded());
        self::assertStringContainsString('Loading libraries', $screen->view());
    }

    public function testLoadedFetchesTheSelectedLibrarysStatus(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->scanStatus($this->runningJob()));
        $screen = $this->screenWith($transport);

        [$loaded, $cmd] = $screen->update($this->runCmd($screen->init()) ?? new AdminLibrariesFailedMsg('x'));

        self::assertTrue($loaded->isLoaded());
        // The follow-up is a status fetch for lib-1.
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminScanStatusLoadedMsg::class, $msg);
        self::assertSame('lib-1', $msg->libraryId);
        self::assertInstanceOf(ScanJob::class, $msg->job);
        self::assertStringContainsString('/api/v1/libraries/lib-1/scan-status', $transport->requestAt(1)['url']);
    }

    public function testRendersTheLibraryTable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        $view = $screen->view();
        self::assertStringContainsString('Movies', $view);
        self::assertStringContainsString('Shows', $view);
        self::assertStringContainsString('Name', $view);
        self::assertStringContainsString('Items', $view);
        self::assertStringContainsString('2 libraries', $view);
    }

    public function testEmptyListPlaceholder(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyLibraries()));

        self::assertSame([], $screen->libraryList());
        self::assertStringContainsString('No libraries configured', $screen->view());
    }

    public function testLoadFailureShowsErrorAndRetry(): void
    {
        $transport = (new FakeTransport())->json(500, ['error' => 'boom']);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());
        self::assertInstanceOf(AdminLibrariesFailedMsg::class, $msg);
        [$failed] = $screen->update($msg);

        self::assertNotNull($failed->error());
        self::assertStringContainsString('Press r to retry', $failed->view());
    }

    public function testLoadFailureRetryRefetches(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyLibraries()));

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertFalse($next->isLoaded());
        self::assertInstanceOf(\Closure::class, $cmd);
    }

    public function testAuthErrorOnLoadSurfacesSessionExpiry(): void
    {
        $transport = (new FakeTransport())
            ->json(401, ['error' => 'expired'])
            ->json(401, ['error' => 'expired']);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(SessionExpiredMsg::class, $msg);
    }

    // ---- top-level envelope-regression guard ---------------------------

    public function testEnvelopeWrappedListYieldsEmpty(): void
    {
        $transport = (new FakeTransport())->json(200, ['data' => $this->librariesPayload()]);
        $screen = $this->screenWith($transport);

        $msg = $this->runCmd($screen->init());

        self::assertInstanceOf(AdminLibrariesLoadedMsg::class, $msg);
        self::assertSame([], $msg->libraries);
    }

    // ---- selection -----------------------------------------------------

    public function testDownMovesSelectionAndRefetchesStatusAndResetsEpoch(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->scanStatus($this->runningJob('lib-2')));
        $screen = $this->loaded($transport);
        $epoch = $screen->pollEpoch();

        [$moved, $cmd] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertSame(1, $moved->selectedIndex());
        self::assertNull($moved->scanStatus(), 'the old status is cleared on a selection move');
        self::assertSame($epoch + 1, $moved->pollEpoch(), 'the poll epoch is bumped');
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminScanStatusLoadedMsg::class, $msg);
        self::assertSame('lib-2', $msg->libraryId);
    }

    public function testUpAtTopIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Up));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testSelectionMoveOnEmptyListIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyLibraries()));

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    // ---- live status poll ----------------------------------------------

    public function testStatusLoadedForActiveJobArmsTheTick(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        $epoch = $screen->pollEpoch();

        [$next, $cmd] = $screen->update(new AdminScanStatusLoadedMsg('lib-1', ScanJob::fromArray($this->runningJob())));

        self::assertInstanceOf(ScanJob::class, $next->scanStatus());
        self::assertTrue($next->scanStatus()?->isActive());
        // The follow-up is the re-armed tick under the same epoch.
        $tick = $cmd === null ? null : $cmd();
        self::assertNotNull($tick, 'an active job arms a status tick');
        self::assertSame($epoch, $next->pollEpoch(), 'the epoch is unchanged while polling');
    }

    public function testStatusLoadedForInactiveJobStopsPolling(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        [$next, $cmd] = $screen->update(new AdminScanStatusLoadedMsg('lib-1', ScanJob::fromArray($this->completedJob())));

        self::assertFalse($next->scanStatus()?->isActive());
        self::assertNull($cmd, 'a completed job stops the poll');
    }

    public function testStatusLoadedForANonCurrentLibraryIsDropped(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        // A status for lib-2 while lib-1 is selected is dropped.
        [$same, $cmd] = $screen->update(new AdminScanStatusLoadedMsg('lib-2', ScanJob::fromArray($this->runningJob('lib-2'))));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
        self::assertNull($same->scanStatus());
    }

    public function testStatusTickRefetchesStatusForTheCurrentSelection(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->scanStatus($this->runningJob()));
        $screen = $this->loaded($transport);
        $epoch = $screen->pollEpoch();

        [$same, $cmd] = $screen->update(new AdminScanStatusTickMsg($epoch));

        self::assertSame($screen, $same);
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminScanStatusLoadedMsg::class, $msg);
        self::assertSame('lib-1', $msg->libraryId);
    }

    public function testStaleStatusTickIsDropped(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        [$same, $cmd] = $screen->update(new AdminScanStatusTickMsg($screen->pollEpoch() - 1));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testStatusTickOnEmptyListIsDropped(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyLibraries()));

        [$same, $cmd] = $screen->update(new AdminScanStatusTickMsg($screen->pollEpoch()));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    // ---- actions -------------------------------------------------------

    public function testScanQueuesAndTogglesBusy(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(202, ['job_id' => 'job-1', 'status' => 'queued', 'message' => 'Library scan queued']);
        $screen = $this->loaded($transport);

        [$busy, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertTrue($busy->isBusy());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryActionDoneMsg::class, $msg);
        self::assertSame('Library scan queued', $msg->message);
        self::assertStringContainsString('/api/v1/libraries/lib-1/scan', $transport->requestAt(1)['url']);
    }

    public function testMatchQueues(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(202, ['message' => 'Metadata match queued']);
        $screen = $this->loaded($transport);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'm'));

        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryActionDoneMsg::class, $msg);
        self::assertStringContainsString('/api/v1/libraries/lib-1/match-metadata', $transport->requestAt(1)['url']);
    }

    public function testRescanArmsAConfirmThenYPerforms(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(202, ['message' => 'Library rescan queued']);
        $screen = $this->loaded($transport);

        // R arms the confirm — no request yet.
        [$armed, $armCmd] = $screen->update(new KeyMsg(KeyType::Char, 'R'));
        self::assertNull($armCmd);
        self::assertNotNull($armed->pendingRescan());
        self::assertStringContainsString('purges then rescans', $armed->view());
        self::assertSame(1, $transport->requestCount(), 'arming makes no request');

        // y performs the rescan.
        [$busy, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        self::assertTrue($busy->isBusy());
        self::assertNull($busy->pendingRescan());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryActionDoneMsg::class, $msg);
        self::assertStringContainsString('/api/v1/libraries/lib-1/rescan', $transport->requestAt(1)['url']);
    }

    public function testRescanConfirmNCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'n'));

        self::assertNull($cancelled->pendingRescan());
        self::assertNull($cmd);
    }

    public function testRescanConfirmEscCancels(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));

        [$cancelled, $cmd] = $armed->update(new KeyMsg(KeyType::Escape));

        self::assertNull($cancelled->pendingRescan());
        self::assertNull($cmd);
    }

    public function testUnrelatedKeyDuringConfirmIsIgnored(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        [$armed] = $screen->update(new KeyMsg(KeyType::Char, 'R'));

        [$same, $cmd] = $armed->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($armed, $same);
        self::assertNull($cmd);
        self::assertNotNull($same->pendingRescan());
    }

    public function testActionDoneToastsAndFetchesStatus(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->scanStatus($this->runningJob()));
        $screen = $this->loaded($transport);

        [$next, $cmd] = $screen->update(new AdminLibraryActionDoneMsg('Library scan queued'));

        self::assertFalse($next->isBusy());
        $msgs = $this->collectCmd($cmd);
        $toast = $this->firstToast($msgs);
        self::assertNotNull($toast);
        self::assertSame(ToastType::Success, $toast->type);
        self::assertSame('Library scan queued', $toast->message);
        self::assertTrue($this->containsStatusLoaded($msgs), 'the status is fetched after the action');
    }

    public function testActionDoneBumpsThePollEpochSoOnlyOneChainSurvives(): void
    {
        // Reproduce the doubling-poll bug: a scan is already running (a tick is armed
        // under epoch N), then the user fires another action. onActionDone must bump
        // the epoch so the OLD tick(N) is stranded — only the new chain polls.
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->scanStatus($this->runningJob()));
        $screen = $this->loaded($transport);

        // An active-job status arms a tick under the current epoch N.
        [$polling] = $screen->update(new AdminScanStatusLoadedMsg('lib-1', ScanJob::fromArray($this->runningJob())));
        $oldEpoch = $polling->pollEpoch();

        // Fire an action; onActionDone bumps the epoch.
        [$afterAction] = $polling->update(new AdminLibraryActionDoneMsg('Library scan queued'));
        self::assertSame($oldEpoch + 1, $afterAction->pollEpoch(), 'the action path bumps the poll epoch');

        // The OLD tick(N) is now stale — it must be dropped (no fetch / no re-arm).
        [$same, $staleCmd] = $afterAction->update(new AdminScanStatusTickMsg($oldEpoch));
        self::assertSame($afterAction, $same);
        self::assertNull($staleCmd, 'the previously-armed tick is stranded — only one live chain');

        // The NEW epoch still polls.
        [, $freshCmd] = $afterAction->update(new AdminScanStatusTickMsg($afterAction->pollEpoch()));
        self::assertInstanceOf(\Closure::class, $freshCmd, 'the new chain continues to poll');
    }

    public function testActionDoneFallsBackToADefaultMessageWhenEmpty(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->scanStatus(null));
        $screen = $this->loaded($transport);

        [, $cmd] = $screen->update(new AdminLibraryActionDoneMsg(''));

        $toast = $this->firstToast($this->collectCmd($cmd));
        self::assertNotNull($toast);
        self::assertSame('Library job queued', $toast->message);
    }

    public function testActionFailureToastsTheErrorAndLeavesTheListUnchanged(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(404, ['error' => 'Library not found']);
        $screen = $this->loaded($transport);

        [$busy, $busyCmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));
        $failMsg = $this->runCmd($busyCmd);
        self::assertInstanceOf(AdminLibraryActionFailedMsg::class, $failMsg);

        [$idle, $cmd] = $busy->update($failMsg);
        self::assertFalse($idle->isBusy());
        self::assertCount(2, $idle->libraryList(), 'the list is untouched');
        $toast = $this->firstToast($this->collectCmd($cmd));
        self::assertNotNull($toast);
        self::assertSame(ToastType::Error, $toast->type);
        self::assertSame('Library not found', $toast->message);
    }

    public function testActionAuthErrorSurfacesSessionExpiry(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(401, ['error' => 'expired'])
            ->json(401, ['error' => 'expired']);
        $screen = $this->loaded($transport);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testBusyGuardIgnoresActionKeys(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(202, ['message' => 'queued']);
        $screen = $this->loaded($transport);
        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        // A second action key while busy is ignored (no new request, same model).
        [$same, $cmd] = $busy->update(new KeyMsg(KeyType::Char, 'm'));

        self::assertSame($busy, $same);
        self::assertNull($cmd);
    }

    public function testActionKeyWithNoSelectionIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyLibraries()));

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testStatusPollAuthErrorSurfacesSessionExpiry(): void
    {
        // The status fetch (init's follow-up) hits a 401 → SessionExpired.
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(401, ['error' => 'expired'])
            ->json(401, ['error' => 'expired']);
        $screen = $this->screenWith($transport);

        [$loaded, $cmd] = $screen->update($this->runCmd($screen->init()) ?? new AdminLibrariesFailedMsg('x'));

        self::assertTrue($loaded->isLoaded());
        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testStatusPollFailureIsBestEffortAndDropped(): void
    {
        // A non-auth status-fetch failure resolves to no Msg (last-known readout kept).
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(500, ['error' => 'boom']);
        $screen = $this->screenWith($transport);

        [, $cmd] = $screen->update($this->runCmd($screen->init()) ?? new AdminLibrariesFailedMsg('x'));

        self::assertNull($this->runCmd($cmd), 'a failed status poll drops silently');
    }

    public function testActionDoneWithAnEmptyListToastsWithoutAStatusFetch(): void
    {
        // An action-done arriving while the list is empty toasts only (no selection
        // to fetch status for).
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyLibraries()));

        [$next, $cmd] = $screen->update(new AdminLibraryActionDoneMsg('queued'));

        $msgs = $this->collectCmd($cmd);
        self::assertNotNull($this->firstToast($msgs));
        self::assertFalse($this->containsStatusLoaded($msgs), 'no status is fetched with no selection');
        self::assertFalse($next->isBusy());
    }

    // ---- status panel render -------------------------------------------

    public function testStatusPanelNoJob(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        self::assertStringContainsString('No scan run yet', $screen->view());
    }

    public function testStatusPanelRunningJobShowsCountersAndPath(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        [$with] = $screen->update(new AdminScanStatusLoadedMsg('lib-1', ScanJob::fromArray($this->runningJob())));

        $view = $with->view();
        self::assertStringContainsString('running', $view);
        self::assertStringContainsString('found 12', $view);
        self::assertStringContainsString('/media/movies/a.mkv', $view);
        self::assertStringNotContainsString('%', $view, 'no fake percentage is shown');
    }

    public function testStatusPanelFailedJobShowsTheError(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        [$with] = $screen->update(new AdminScanStatusLoadedMsg('lib-1', ScanJob::fromArray($this->failedJob())));

        $view = $with->view();
        self::assertStringContainsString('failed', $view);
        self::assertStringContainsString('Permission denied', $view);
    }

    public function testStatusPanelCompletedJob(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        [$with] = $screen->update(new AdminScanStatusLoadedMsg('lib-1', ScanJob::fromArray($this->completedJob())));

        self::assertStringContainsString('completed', $with->view());
    }

    public function testBusyPanelShowsWorking(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(202, ['message' => 'queued']);
        $screen = $this->loaded($transport);

        [$busy] = $screen->update(new KeyMsg(KeyType::Char, 's'));

        self::assertStringContainsString('Working', $busy->view());
    }

    // ---- navigation / resize / immutability ----------------------------

    public function testEscapeGoesBackAndStrandsThePoll(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));
        $epoch = $screen->pollEpoch();

        [$next, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($cmd));
        self::assertSame($epoch + 1, $next->pollEpoch(), 'the epoch bump strands the poll on exit');
    }

    public function testQGoesBack(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'q'));

        self::assertInstanceOf(NavigateBackMsg::class, $this->runCmd($cmd));
    }

    public function testResize(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        [$resized, $cmd] = $screen->update(new WindowSizeMsg(100, 30));

        self::assertNull($cmd);
        self::assertNotSame($screen, $resized);
        self::assertStringContainsString('Movies', $resized->view());
    }

    public function testUnhandledKeyAndMsgAreNoOps(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        [$sameKey, $keyCmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));
        self::assertSame($screen, $sameKey);
        self::assertNull($keyCmd);

        // A non-Char, non-arrow key (e.g. Enter) falls through to a no-op.
        [$sameEnter, $enterCmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $sameEnter);
        self::assertNull($enterCmd);

        [$sameMsg, $msgCmd] = $screen->update(new class implements Msg {});
        self::assertSame($screen, $sameMsg);
        self::assertNull($msgCmd);
    }

    public function testCrumbAndThemeAreImmutable(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        self::assertSame('Libraries', $screen->crumbLabel());
        $withCrumbs = $screen->withCrumbs(['Admin', 'Libraries']);
        self::assertNotSame($screen, $withCrumbs);
        self::assertStringContainsString('Movies', $withCrumbs->view());
    }

    // ---- scan-history sub-view -----------------------------------------

    /**
     * Open the history sub-view: drive `h` against a loaded screen and resolve the
     * follow-up history fetch into the loaded sub-view. Returns the post-load screen.
     */
    private function historyOpened(FakeTransport $transport): AdminLibrariesScreen
    {
        $screen = $this->loaded($transport);
        [$opening, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertTrue($opening->isHistoryOpen());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryScanHistoryLoadedMsg::class, $msg);

        return $opening->update($msg)[0];
    }

    public function testHOpensTheHistorySubViewAndFetches(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->loaded($transport);

        [$opening, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertTrue($opening->isHistoryOpen());
        self::assertFalse($opening->isHistoryLoaded(), 'the sub-view starts loading');
        self::assertStringContainsString('Loading scan history', $opening->view());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryScanHistoryLoadedMsg::class, $msg);
        self::assertSame('lib-1', $msg->libraryId);
        self::assertStringContainsString('/api/v1/libraries/lib-1/scan-history', $transport->requestAt(1)['url']);
    }

    public function testHistoryRendersTheJobsTable(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->historyOpened($transport);

        self::assertTrue($screen->isHistoryLoaded());
        self::assertCount(2, $screen->historyList());
        $view = $screen->view();
        self::assertStringContainsString('Scan history', $view);
        self::assertStringContainsString('Movies', $view, 'the selected library name is in the header');
        self::assertStringContainsString('completed', $view);
        self::assertStringContainsString('failed', $view);
        self::assertStringContainsString('2 jobs', $view);
    }

    public function testHistoryEmptyPlaceholder(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->emptyHistory());
        $screen = $this->historyOpened($transport);

        self::assertSame([], $screen->historyList());
        self::assertStringContainsString('No scan history', $screen->view());
    }

    public function testHistoryScrollsWithUpDown(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->historyOpened($transport);

        [$down, $downCmd] = $screen->update(new KeyMsg(KeyType::Down));
        self::assertSame(1, $down->historySelectedIndex());
        self::assertNull($downCmd, 'scrolling the history makes no request');
        self::assertTrue($down->isHistoryOpen(), 'still in the sub-view');

        [$up] = $down->update(new KeyMsg(KeyType::Up));
        self::assertSame(0, $up->historySelectedIndex());
    }

    public function testHistoryRefetchesWithR(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->historyOpened($transport);

        [$reloading, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertTrue($reloading->isHistoryOpen());
        self::assertFalse($reloading->isHistoryLoaded(), 'r resets the sub-view to loading');
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryScanHistoryLoadedMsg::class, $msg);
        self::assertStringContainsString('/api/v1/libraries/lib-1/scan-history', $transport->requestAt(2)['url']);
    }

    public function testHistoryHCloseReturnsToTheListWithoutPoppingTheScreen(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->historyOpened($transport);

        [$closed, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertFalse($closed->isHistoryOpen());
        self::assertNull($cmd, 'closing history does NOT pop the screen (no NavigateBack)');
        self::assertStringContainsString('Movies', $closed->view(), 'back on the main list');
    }

    public function testHistoryEscCloseReturnsToTheListWithoutPoppingTheScreen(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->historyOpened($transport);

        [$closed, $cmd] = $screen->update(new KeyMsg(KeyType::Escape));

        self::assertFalse($closed->isHistoryOpen());
        self::assertNull($cmd, 'Esc closes the sub-view, it does NOT NavigateBack while history is open');
        self::assertStringContainsString('Movies', $closed->view());
    }

    public function testHistoryForANonCurrentLibraryIsDropped(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->historyOpened($transport);

        // A history for lib-2 while lib-1 is the selection is dropped.
        [$same, $cmd] = $screen->update(new AdminLibraryScanHistoryLoadedMsg('lib-2', []));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
        self::assertCount(2, $same->historyList(), 'the current history is untouched');
    }

    public function testHistoryLoadedWhileClosedIsDropped(): void
    {
        // A late history resolving after the sub-view closed is ignored.
        $screen = $this->loaded((new FakeTransport())->json(200, $this->librariesPayload()));

        [$same, $cmd] = $screen->update(new AdminLibraryScanHistoryLoadedMsg(
            'lib-1',
            [ScanJob::fromArray($this->completedJob())],
        ));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
        self::assertFalse($same->isHistoryOpen());
    }

    public function testHistoryFetchFailureShowsAnError(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(500, ['error' => 'boom']);
        $screen = $this->loaded($transport);

        [$opening, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'h'));
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryScanHistoryFailedMsg::class, $msg);

        [$failed] = $opening->update($msg);
        self::assertNotNull($failed->historyError());
        self::assertTrue($failed->isHistoryOpen());
        self::assertStringContainsString('Press r to retry', $failed->view());
    }

    public function testHistoryFetchAuthErrorSurfacesSessionExpiry(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(401, ['error' => 'expired'])
            ->json(401, ['error' => 'expired']);
        $screen = $this->loaded($transport);

        [, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertInstanceOf(SessionExpiredMsg::class, $this->runCmd($cmd));
    }

    public function testHistoryKeyWithNoSelectionIsANoOp(): void
    {
        $screen = $this->loaded((new FakeTransport())->json(200, $this->emptyLibraries()));

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'h'));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
        self::assertFalse($same->isHistoryOpen());
    }

    public function testHistoryScrollOnEmptyHistoryIsANoOp(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->emptyHistory());
        $screen = $this->historyOpened($transport);

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Down));

        self::assertSame($screen, $same);
        self::assertNull($cmd);
    }

    public function testHistoryUnrelatedKeyIsANoOp(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload());
        $screen = $this->historyOpened($transport);

        [$same, $cmd] = $screen->update(new KeyMsg(KeyType::Char, 'z'));

        self::assertSame($screen, $same);
        self::assertNull($cmd);

        [$sameEnter, $enterCmd] = $screen->update(new KeyMsg(KeyType::Enter));
        self::assertSame($screen, $sameEnter);
        self::assertNull($enterCmd);
    }

    public function testClosingHistoryRestoresTheMainListActionsAndPoll(): void
    {
        $transport = (new FakeTransport())
            ->json(200, $this->librariesPayload())
            ->json(200, $this->historyPayload())
            ->json(202, ['message' => 'Library scan queued']);
        $screen = $this->historyOpened($transport);

        // Close history, then the main-list scan action works again.
        [$closed] = $screen->update(new KeyMsg(KeyType::Escape));
        [$busy, $cmd] = $closed->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($busy->isBusy());
        $msg = $this->runCmd($cmd);
        self::assertInstanceOf(AdminLibraryActionDoneMsg::class, $msg);

        // And the scan-status poll still ticks for the current selection.
        [$same, $tickCmd] = $closed->update(new AdminScanStatusTickMsg($closed->pollEpoch()));
        self::assertSame($closed, $same);
        self::assertInstanceOf(\Closure::class, $tickCmd, 'the poll still runs after closing history');
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param list<Msg> $msgs
     */
    private function firstToast(array $msgs): ?ShowToastMsg
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof ShowToastMsg) {
                return $msg;
            }
        }

        return null;
    }

    /** @param list<Msg> $msgs */
    private function containsStatusLoaded(array $msgs): bool
    {
        foreach ($msgs as $msg) {
            if ($msg instanceof AdminScanStatusLoadedMsg) {
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
     * awaiting its async legs). A TickRequest (the re-armed poll tick) is not a
     * Msg, so it is naturally dropped — no infinite loop.
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
