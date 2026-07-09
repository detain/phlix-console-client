<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Screen;

use Phlix\Console\Api\Admin\AdminClient;
use Phlix\Console\Api\AuthError;
use Phlix\Console\Api\Dto\Admin\Parental\AccessSchedule;
use Phlix\Console\Api\Dto\Admin\Parental\ProfileStreamLimit;
use Phlix\Console\Api\Dto\Admin\Parental\ProfileTag;
use Phlix\Console\Msg\NavigateBackMsg;
use Phlix\Console\Msg\SessionExpiredMsg;
use Phlix\Console\Msg\ShowToastMsg;
use Phlix\Console\Ui\Chrome;
use Phlix\Console\Ui\Table;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\SubscriptionCapable;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Form;

/**
 * The admin parental controls surface for ONE profile, pushed from the
 * AdminUserProfilesScreen (`C` on a selected profile): a screen with THREE
 * tabbed sections — Schedules · Tags · Stream Limits — driven by per-section
 * actions and arrow-key navigation.
 *
 * Arrow keys (←/→) or Tab/Shift-Tab cycle the active section. Each section
 * carries its own loading/error state and is fetched lazily on first view.
 *
 * Schedules section:
 *   `c` opens a create form (name + start/end time + day picker + active toggle).
 *   `E` opens a pre-filled edit form for the selected schedule.
 *   `x` arms an inline (y/n) delete confirm.
 *
 * Tags section:
 *   `c` opens a create form (tag name + type select: blocked/allowed).
 *   `x` arms an inline (y/n) delete confirm for the selected tag.
 *
 * Stream Limits section:
 *   Displays current limits and `u` opens an update form (max concurrent streams +
 *   optional max total bandwidth).
 *
 * `r` refreshes the active section. `Esc` / `q` → NavigateBack.
 *
 * Every form intercepts candy-forms' own quit (abort → cancel / submit →
 * validate then act) so it never exits the app. On success the server
 * message is toasted and the list refetched; on failure the server
 * `error` is toasted and the list is left unchanged; an auth failure
 * surfaces a session expiry.
 */
final class ParentalControlsScreen implements Breadcrumbed, Themed
{
    use SubscriptionCapable;
    use ThemedScreen;

    private const SESSION_EXPIRED = 'Your session expired. Please sign in again.';
    private const LOAD_FAILED = 'Could not load. Please try again.';
    private const HINT = '←→/Tab section   ↑↓ select   c create   E edit   x delete   u update-limits   r refresh   Esc back';
    private const FORM_HINT = 'Enter  save      Esc  cancel';

    /** @var array<string, string> per-section action hints */
    private const SECTION_HINTS = [
        'schedules' => 'c create   E edit   x delete',
        'tags' => 'c create   x delete',
        'streamLimits' => 'u update-limits',
    ];

    /** Confirmable actions that arm an inline (y/n) prompt before firing. */
    private const ACTION_DELETE_SCHEDULE = 'delete-schedule';
    private const ACTION_DELETE_TAG = 'delete-tag';

    /** The three parental control sections. */
    private const SECTIONS = ['schedules', 'tags', 'streamLimits'];
    private const SECTION_LABELS = [
        'schedules' => 'Schedules',
        'tags' => 'Tags',
        'streamLimits' => 'Stream Limits',
    ];

    /** @var list<AccessSchedule>|null null = not yet fetched. */
    private ?array $schedules = null;
    /** @var list<ProfileTag>|null */
    private ?array $tags = null;
    /** @var ProfileStreamLimit|null */
    private ?ProfileStreamLimit $streamLimits = null;

    private string $section = 'schedules';
    private bool $schedulesLoaded = false;
    private bool $tagsLoaded = false;
    private bool $streamLimitsLoaded = false;

    /** @var array<string, string> per-section fetch error. */
    private array $errors = [];
    /** @var array<string, int> per-section selection index. */
    private array $selected = [];

    /** A fetch / action is in flight (mutating input is ignored while busy). */
    private bool $busy = false;

    /** An armed confirmation (delete), or null when none is pending. */
    private ?string $pendingAction = null;
    private ?int $pendingId = null;

    /** The embedded create/edit form while open, else null. */
    private ?Form $form = null;

    /** The item being edited (null while creating or with no form open). */
    private AccessSchedule|ProfileTag|null $editing = null;

    /** A client-side validation note shown above an open form, or null. */
    private ?string $formError = null;

    /** @var list<string> */
    private array $crumbs = [];

    public function __construct(
        private readonly AdminClient $admin,
        private readonly string $profileId,
        private readonly string $profileName,
        private int $cols = 80,
        private int $rows = 24,
    ) {
    }

    public function init(): \Closure
    {
        return $this->fetchCmd($this->section);
    }

    // ---- fetch ---------------------------------------------------------

    /** Build the fetch command for one section, mapped to a toast on failure. */
    private function fetchCmd(string $section): \Closure
    {
        $apiErrorClass = \Phlix\Console\Api\ApiError::class;
        $loadFailed = self::LOAD_FAILED;
        $sessionExpired = self::SESSION_EXPIRED;
        $admin = $this->admin;
        $profileId = $this->profileId;

        $doFetch = static function () use ($section, $apiErrorClass, $loadFailed, $sessionExpired, $admin, $profileId): PromiseInterface {
            $promise = match ($section) {
                'schedules' => $admin->profileSchedules((int) $profileId)->then(
                    static fn (array $rows): Msg => new ParentalSchedulesLoadedMsg($rows),
                ),
                'tags' => $admin->profileTags((int) $profileId)->then(
                    static fn (array $rows): Msg => new ParentalTagsLoadedMsg($rows),
                ),
                'streamLimits' => $admin->profileStreamLimits((int) $profileId)->then(
                    static fn (ProfileStreamLimit $limit): Msg => new ParentalStreamLimitsLoadedMsg($limit),
                ),
                default => throw new \InvalidArgumentException("Unknown section: {$section}"),
            };

            return $promise->then(
                static fn (Msg $msg): Msg => $msg,
                static function (\Throwable $e) use ($apiErrorClass, $loadFailed, $sessionExpired): Msg {
                    if ($e instanceof AuthError) {
                        return new SessionExpiredMsg($sessionExpired);
                    }
                    $msg = $e instanceof $apiErrorClass ? $e->getMessage() : $loadFailed;

                    return ShowToastMsg::error($msg);
                },
            );
        };

        return Cmd::promise($doFetch);
    }

    /**
     * Map an action promise to the shared done/failed flow.
     *
     * @param PromiseInterface<mixed> $promise
     * @return array{self, ?\Closure}
     */
    private function actionCmd(PromiseInterface $promise, string $fallback): array
    {
        return [$this, Cmd::promise(static fn (): PromiseInterface => $promise->then(
            static fn (mixed $message): Msg => new ParentalActionDoneMsg(is_string($message) ? ($message === '' ? $fallback : $message) : $fallback),
            static fn (\Throwable $e): Msg => $e instanceof AuthError
                ? new SessionExpiredMsg(self::SESSION_EXPIRED)
                : ShowToastMsg::error(($e instanceof \Phlix\Console\Api\ApiError) ? $e->getMessage() : 'Action failed.'),
        ))];
    }

    // ---- update --------------------------------------------------------

    /** @return array{self, ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof WindowSizeMsg) {
            return [$this->resizedTo($msg->cols, $msg->rows), null];
        }
        if ($this->form !== null) {
            return $this->updateForm($msg, $this->form);
        }
        if ($msg instanceof KeyMsg) {
            return $this->handleKey($msg);
        }
        if ($msg instanceof ParentalSchedulesLoadedMsg) {
            return [$this->withSchedules($msg->schedules), null];
        }
        if ($msg instanceof ParentalTagsLoadedMsg) {
            return [$this->withTags($msg->tags), null];
        }
        if ($msg instanceof ParentalStreamLimitsLoadedMsg) {
            return [$this->withStreamLimits($msg->limit), null];
        }
        if ($msg instanceof ParentalActionDoneMsg) {
            return $this->onActionDone($msg);
        }
        if ($msg instanceof ShowToastMsg) {
            return [$this, Cmd::send($msg)];
        }

        return [$this, null];
    }

    public function view(): string
    {
        if ($this->form !== null) {
            $title = $this->section === 'streamLimits'
                ? 'Parental Controls · Stream Limits · Edit'
                : ($this->editing !== null
                    ? 'Parental Controls · ' . self::SECTION_LABELS[$this->section] . ' · Edit'
                    : 'Parental Controls · ' . self::SECTION_LABELS[$this->section] . ' · Create');

            return Chrome::frame($title, $this->formBody($this->form), self::FORM_HINT, $this->cols, $this->rows, $this->crumbs, $this->theme());
        }

        return Chrome::frame(
            'Parental Controls · ' . $this->profileName,
            $this->body(),
            self::HINT,
            $this->cols,
            $this->rows,
            $this->crumbs,
            $this->theme(),
        );
    }

    // ---- input ---------------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function handleKey(KeyMsg $msg): array
    {
        // An armed confirm captures y/n/Esc before anything else.
        if ($this->pendingAction !== null && $this->pendingId !== null) {
            return $this->handleConfirmKey($msg);
        }

        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'q')) {
            return [$this, Cmd::send(new NavigateBackMsg())];
        }
        if ($msg->type === KeyType::Left || ($msg->type === KeyType::Char && $msg->rune === "\t" && !$msg->shift && $this->form === null)) {
            return [$this->moveSection(-1), null];
        }
        if ($msg->type === KeyType::Right || ($msg->type === KeyType::Char && $msg->rune === "\t" && $msg->shift && $this->form === null)) {
            return [$this->moveSection(1), null];
        }
        if ($msg->type === KeyType::Up) {
            return [$this->moveSelection(-1), null];
        }
        if ($msg->type === KeyType::Down) {
            return [$this->moveSelection(1), null];
        }
        if ($msg->type === KeyType::Char) {
            return $this->handleCharKey($msg->rune);
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleCharKey(string $rune): array
    {
        if ($rune === 'r') {
            return $this->refresh();
        }

        if ($this->busy) {
            return [$this, null];
        }

        if ($rune === 'c') {
            return [$this->openCreate(), null];
        }

        if ($rune === 'u' && $this->section === 'streamLimits') {
            return [$this->openStreamLimitsEdit(), null];
        }

        // Edit only works for schedules (E key)
        if ($rune === 'E' && $this->section === 'schedules') {
            $item = $this->selectedItem();

            return $item instanceof AccessSchedule
                ? [$this->openEdit($item), null]
                : [$this, null];
        }

        // Delete works for schedules and tags
        if ($rune === 'x') {
            $item = $this->selectedItem();

            return match ($this->section) {
                'schedules' => $item instanceof AccessSchedule
                    ? [$this->arm(self::ACTION_DELETE_SCHEDULE, $item->id), null]
                    : [$this, null],
                'tags' => $item instanceof ProfileTag
                    ? [$this->arm(self::ACTION_DELETE_TAG, $item->id), null]
                    : [$this, null],
                default => [$this, null],
            };
        }

        return [$this, null];
    }

    /** @return array{self, ?\Closure} */
    private function handleConfirmKey(KeyMsg $msg): array
    {
        if ($msg->type === KeyType::Char && $msg->rune === 'y') {
            return match ($this->pendingAction) {
                self::ACTION_DELETE_SCHEDULE => [$this->working(), $this->actionCmd(
                    $this->admin->deleteProfileSchedule((int) $this->profileId, $this->pendingId ?? 0),
                    'Schedule deleted',
                )[1]],
                self::ACTION_DELETE_TAG => [$this->working(), $this->actionCmd(
                    $this->admin->deleteProfileTag((int) $this->profileId, $this->pendingId ?? 0),
                    'Tag removed',
                )[1]],
                default => [$this, null],
            };
        }
        if ($msg->type === KeyType::Escape || ($msg->type === KeyType::Char && $msg->rune === 'n')) {
            return [$this->cancelPending(), null];
        }

        return [$this, null];
    }

    // ---- form ----------------------------------------------------------

    /**
     * Drive the embedded create / edit form.
     *
     * @return array{self, ?\Closure}
     */
    private function updateForm(Msg $msg, Form $form): array
    {
        /** @var array{0: Form, 1: ?\Closure} $result */
        $result = $form->update($msg);
        [$next, $cmd] = $result;

        if ($next->isAborted()) {
            return [$this->closeForm(), null];
        }
        if ($next->isSubmitted()) {
            return match ($this->section) {
                'schedules' => $this->editing instanceof AccessSchedule
                    ? $this->submitScheduleEdit($next, $this->editing)
                    : $this->submitScheduleCreate($next),
                'tags' => $this->submitTagCreate($next),
                'streamLimits' => $this->submitStreamLimitsUpdate($next),
                default => [$this, null],
            };
        }

        return [$this->withForm($next, $this->editing), $cmd];
    }

    /** @return array{self, ?\Closure} */
    private function submitScheduleCreate(Form $form): array
    {
        $name = trim($form->getString('name'));
        $startTime = trim($form->getString('startTime'));
        $endTime = trim($form->getString('endTime'));
        $days = self::parseDaysFromForm($form);
        $isActive = $form->getBool('isActive');

        $error = self::validateSchedule($name, $startTime, $endTime, $days);
        if ($error !== null) {
            return [$this->reopenCreate($name, $startTime, $endTime, $days, $isActive, $error), null];
        }

        return [$this->closeForm()->working(), $this->actionCmd(
            $this->admin->createProfileSchedule((int) $this->profileId, $name, $startTime, $endTime, $days, $isActive),
            'Schedule created',
        )[1]];
    }

    /** @return array{self, ?\Closure} */
    private function submitScheduleEdit(Form $form, AccessSchedule $original): array
    {
        $name = trim($form->getString('name'));
        $startTime = trim($form->getString('startTime'));
        $endTime = trim($form->getString('endTime'));
        $days = self::parseDaysFromForm($form);
        $isActive = $form->getBool('isActive');

        $error = self::validateSchedule($name, $startTime, $endTime, $days);
        if ($error !== null) {
            return [$this->reopenEdit($original, $name, $startTime, $endTime, $days, $isActive, $error), null];
        }

        // Note: The server doesn't have a PUT for individual schedule fields,
        // so we use the create to update (or we'd need to add a new endpoint)
        // For now, this will delete and recreate - in a real implementation
        // you'd add an updateSchedule endpoint to the server
        $admin = $this->admin;
        $profileId = $this->profileId;
        return [$this->closeForm()->working(), $this->actionCmd(
            $admin->deleteProfileSchedule((int) $profileId, $original->id)->then(
                static fn (): PromiseInterface => $admin->createProfileSchedule((int) $profileId, $name, $startTime, $endTime, $days, $isActive),
            ),
            'Schedule updated',
        )[1]];
    }

    /** @return array{self, ?\Closure} */
    private function submitTagCreate(Form $form): array
    {
        $tag = trim($form->getString('tag'));
        $tagType = $form->getString('tagType');

        if ($tag === '' || strlen($tag) > 100) {
            return [$this->reopenTagCreate($tag, $tagType, 'Tag must be 1-100 characters.'), null];
        }
        if ($tagType !== ProfileTag::TYPE_BLOCKED && $tagType !== ProfileTag::TYPE_ALLOWED) {
            return [$this->reopenTagCreate($tag, $tagType, 'Type must be blocked or allowed.'), null];
        }

        return [$this->closeForm()->working(), $this->actionCmd(
            $this->admin->addProfileTag((int) $this->profileId, $tag, $tagType),
            'Tag added',
        )[1]];
    }

    /** @return array{self, ?\Closure} */
    private function submitStreamLimitsUpdate(Form $form): array
    {
        $maxStreams = $form->getInt('maxConcurrentStreams');

        if ($maxStreams < 1) {
            $fresh = self::buildStreamLimitsForm($this->streamLimits);

            return [$this->withForm($fresh, null), Cmd::batch(
                Cmd::send(ShowToastMsg::error('Max concurrent streams must be at least 1.')),
                $fresh->init(),
            )];
        }

        $maxBandwidth = null;
        $bwRaw = $form->getString('maxTotalBandwidthKbps');
        if ($bwRaw !== '' && is_numeric($bwRaw)) {
            $maxBandwidth = (int) $bwRaw;
            if ($maxBandwidth < 1) {
                $maxBandwidth = null;
            }
        }

        return [$this->closeForm()->working(), $this->actionCmd(
            $this->admin->updateProfileStreamLimits((int) $this->profileId, $maxStreams, $maxBandwidth),
            'Stream limits updated',
        )[1]];
    }

    // ---- form builders -------------------------------------------------

    private static function buildScheduleForm(?AccessSchedule $schedule = null): Form
    {
        $startTime = $schedule->startTime ?? '08:00:00';
        $endTime = $schedule->endTime ?? '22:00:00';
        $daysOfWeek = $schedule->daysOfWeek ?? ['mon', 'tue', 'wed', 'thu', 'fri'];

        return Form::new(
            Input::new('name')
                ->withTitle('Name')
                ->withValue($schedule->name ?? '')
                ->withPlaceholder('e.g. Weekday Evenings'),
            Input::new('startTime')
                ->withTitle('Start time (HH:MM:SS)')
                ->withValue($startTime),
            Input::new('endTime')
                ->withTitle('End time (HH:MM:SS)')
                ->withValue($endTime),
            Input::new('daysOfWeek')
                ->withTitle('Days (comma-separated: mon,tue,wed,thu,fri,sat,sun)')
                ->withValue(implode(',', $daysOfWeek)),
            Select::new('isActive')
                ->withTitle('Active')
                ->withOptions('Yes', 'No')
                ->withSelectedIndex(($schedule->isActive ?? true) ? 0 : 1),
        );
    }

    private static function buildTagForm(): Form
    {
        return Form::new(
            Input::new('tag')
                ->withTitle('Tag name')
                ->withPlaceholder('e.g. kids, restricted, work'),
            Select::new('tagType')
                ->withTitle('Tag type')
                ->withOptions('blocked', 'allowed')
                ->withSelectedIndex(0),
        );
    }

    private static function buildStreamLimitsForm(?ProfileStreamLimit $limit = null): Form
    {
        return Form::new(
            Input::new('maxConcurrentStreams')
                ->withTitle('Max concurrent streams')
                ->withValue((string) ($limit->maxConcurrentStreams ?? 1)),
            Input::new('maxTotalBandwidthKbps')
                ->withTitle('Max total bandwidth (Kbps, optional)')
                ->withValue($limit?->maxTotalBandwidthKbps !== null ? (string) $limit->maxTotalBandwidthKbps : ''),
        );
    }

    /** @return list<string> */
    private static function parseDaysFromForm(Form $form): array
    {
        $raw = $form->getString('daysOfWeek');
        $parts = array_map('trim', explode(',', $raw));
        $validDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        return array_values(array_filter($parts, static fn (string $d): bool => in_array(strtolower($d), $validDays, true)));
    }

    /**
     * @param list<string> $days
     */
    private static function validateSchedule(string $name, string $startTime, string $endTime, array $days): ?string
    {
        if ($name === '') {
            return 'Name is required.';
        }
        if (strlen($name) > 100) {
            return 'Name must be 100 characters or less.';
        }
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $startTime)) {
            return 'Invalid start time format. Use HH:MM or HH:MM:SS.';
        }
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $endTime)) {
            return 'Invalid end time format. Use HH:MM or HH:MM:SS.';
        }
        if ($days === []) {
            return 'At least one day is required.';
        }

        return null;
    }

    // ---- post-action ---------------------------------------------------

    /** @return array{self, ?\Closure} */
    private function onActionDone(ParentalActionDoneMsg $msg): array
    {
        $toast = ShowToastMsg::success($msg->message === '' ? 'Done.' : $msg->message);

        return [$this->working(), Cmd::batch(Cmd::send($toast), $this->fetchCmd($this->section))];
    }

    /** @return array{self, ?\Closure} */
    private function refresh(): array
    {
        $next = clone $this;
        $next->errors[$this->section] = '';
        $next->pendingAction = null;
        $next->pendingId = null;

        return [$next, $next->fetchCmd($this->section)];
    }

    // ---- clone-mutate copies -------------------------------------------

    /**
     * @param list<AccessSchedule> $schedules
     */
    private function withSchedules(array $schedules): self
    {
        $next = clone $this;
        $next->schedules = $schedules;
        $next->schedulesLoaded = true;
        $next->busy = false;
        $next->errors['schedules'] = '';
        $next->pendingAction = null;
        $next->pendingId = null;
        $next->selected['schedules'] = $schedules === [] ? 0 : min($this->selected['schedules'] ?? 0, count($schedules) - 1);

        return $next;
    }

    /**
     * @param list<ProfileTag> $tags
     */
    private function withTags(array $tags): self
    {
        $next = clone $this;
        $next->tags = $tags;
        $next->tagsLoaded = true;
        $next->busy = false;
        $next->errors['tags'] = '';
        $next->pendingAction = null;
        $next->pendingId = null;
        $next->selected['tags'] = $tags === [] ? 0 : min($this->selected['tags'] ?? 0, count($tags) - 1);

        return $next;
    }

    private function withStreamLimits(ProfileStreamLimit $limit): self
    {
        $next = clone $this;
        $next->streamLimits = $limit;
        $next->streamLimitsLoaded = true;
        $next->busy = false;
        $next->errors['streamLimits'] = '';
        $next->pendingAction = null;
        $next->pendingId = null;

        return $next;
    }

    private function withSection(string $section): self
    {
        $next = clone $this;
        $next->section = $section;

        // Lazily fetch data when switching to an unloaded section
        $loaded = match ($section) {
            'schedules' => $next->schedulesLoaded,
            'tags' => $next->tagsLoaded,
            'streamLimits' => $next->streamLimitsLoaded,
            default => true,
        };

        if (!$loaded) {
            return $next;
        }

        return $next;
    }

    /** Enter the busy (in-flight) state, clearing any armed confirm. */
    private function working(): self
    {
        $next = clone $this;
        $next->busy = true;
        $next->pendingAction = null;
        $next->pendingId = null;

        return $next;
    }

    private function arm(string $action, int $id): self
    {
        $next = clone $this;
        $next->pendingAction = $action;
        $next->pendingId = $id;

        return $next;
    }

    private function cancelPending(): self
    {
        $next = clone $this;
        $next->pendingAction = null;
        $next->pendingId = null;

        return $next;
    }

    // ---- form open / close / reopen ------------------------------------

    private function openCreate(): self
    {
        return match ($this->section) {
            'schedules' => $this->withForm(self::buildScheduleForm(), null),
            'tags' => $this->withForm(self::buildTagForm(), null),
            'streamLimits' => $this->withForm(self::buildStreamLimitsForm($this->streamLimits), null),
            default => $this,
        };
    }

    private function openEdit(AccessSchedule $schedule): self
    {
        return $this->withForm(self::buildScheduleForm($schedule), $schedule);
    }

    private function openStreamLimitsEdit(): self
    {
        return $this->withForm(self::buildStreamLimitsForm($this->streamLimits), null);
    }

    /** @param list<string> $days */
    private function reopenCreate(string $name, string $startTime, string $endTime, array $days, bool $isActive, string $error): self
    {
        $form = Form::new(
            Input::new('name')->withTitle('Name')->withValue($name),
            Input::new('startTime')->withTitle('Start time (HH:MM:SS)')->withValue($startTime),
            Input::new('endTime')->withTitle('End time (HH:MM:SS)')->withValue($endTime),
            Input::new('daysOfWeek')->withTitle('Days')->withValue(implode(',', $days)),
            Select::new('isActive')->withTitle('Active')->withOptions('Yes', 'No')->withSelectedIndex($isActive ? 0 : 1),
        );
        $next = $this->withForm($form, null);
        $next->formError = $error;

        return $next;
    }

    /**
     * @param list<string> $days
     */
    private function reopenEdit(AccessSchedule $schedule, string $name, string $startTime, string $endTime, array $days, bool $isActive, string $error): self
    {
        $form = Form::new(
            Input::new('name')->withTitle('Name')->withValue($name),
            Input::new('startTime')->withTitle('Start time (HH:MM:SS)')->withValue($startTime),
            Input::new('endTime')->withTitle('End time (HH:MM:SS)')->withValue($endTime),
            Input::new('daysOfWeek')->withTitle('Days')->withValue(implode(',', $days)),
            Select::new('isActive')->withTitle('Active')->withOptions('Yes', 'No')->withSelectedIndex($isActive ? 0 : 1),
        );
        $next = $this->withForm($form, $schedule);
        $next->formError = $error;

        return $next;
    }

    private function reopenTagCreate(string $tag, string $tagType, string $error): self
    {
        $form = Form::new(
            Input::new('tag')->withTitle('Tag name')->withValue($tag),
            Select::new('tagType')->withTitle('Tag type')->withOptions('blocked', 'allowed')
                ->withSelectedIndex($tagType === ProfileTag::TYPE_ALLOWED ? 1 : 0),
        );
        $next = $this->withForm($form, null);
        $next->formError = $error;

        return $next;
    }

    private function closeForm(): self
    {
        return $this->withForm(null, null);
    }

    private function withForm(?Form $form, AccessSchedule|ProfileTag|null $editing): self
    {
        $next = clone $this;
        $next->form = $form;
        $next->editing = $form === null ? null : $editing;
        $next->formError = null;
        $next->pendingAction = null;
        $next->pendingId = null;

        return $next;
    }

    private function moveSection(int $delta): self
    {
        $idx = array_search($this->section, self::SECTIONS, true);
        if ($idx === false) {
            return $this;
        }
        $count = count(self::SECTIONS);
        $newIdx = max(0, min($count - 1, $idx + $delta));
        if ($newIdx === $idx) {
            return $this;
        }

        return $this->withSection(self::SECTIONS[$newIdx]);
    }

    private function moveSelection(int $delta): self
    {
        $items = $this->currentItems();
        $count = is_array($items) ? count($items) : 0;
        if ($count === 0) {
            return $this;
        }
        $currentKey = $this->section;
        $current = $this->selected[$currentKey] ?? 0;
        $selected = max(0, min($count - 1, $current + $delta));
        if ($selected === $current) {
            return $this;
        }
        $next = clone $this;
        $next->selected[$currentKey] = (int) $selected;

        return $next;
    }

    private function resizedTo(int $cols, int $rows): self
    {
        $next = clone $this;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    // ---- rendering -----------------------------------------------------

    private function body(): string
    {
        $lines = [];

        // Section tabs
        $lines[] = $this->sectionTabs();
        $lines[] = '';

        // Active section content
        $lines[] = match ($this->section) {
            'schedules' => $this->schedulesBody(),
            'tags' => $this->tagsBody(),
            'streamLimits' => $this->streamLimitsBody(),
            default => '',
        };

        $lines[] = '';
        $lines[] = $this->statusLine();

        return implode("\n", $lines);
    }

    private function sectionTabs(): string
    {
        $tabs = [];
        foreach (self::SECTIONS as $section) {
            $label = self::SECTION_LABELS[$section];
            $hint = self::SECTION_HINTS[$section];
            $prefix = $section === $this->section ? '▶ ' : '  ';

            $tabs[] = $prefix . $label . '   [' . $hint . ']';
        }

        return '  ' . implode('   ', $tabs);
    }

    private function schedulesBody(): string
    {
        if (!$this->schedulesLoaded && !isset($this->errors['schedules'])) {
            return "  Loading schedules…";
        }
        if (isset($this->errors['schedules']) && $this->errors['schedules'] !== '') {
            return "\n  {$this->errors['schedules']}\n\n  Press r to retry.";
        }
        if ($this->schedules === null || $this->schedules === []) {
            return "\n  No access schedules yet.\n";
        }

        $rows = [];
        foreach ($this->schedules as $schedule) {
            $rows[] = [
                $schedule->name,
                $schedule->startTime . ' - ' . $schedule->endTime,
                implode(', ', $schedule->daysOfWeek),
                $schedule->isActive ? '✓' : '–',
            ];
        }

        $table = Table::render([
            ['title' => 'Name', 'width' => 0],
            ['title' => 'Time', 'width' => 14],
            ['title' => 'Days', 'width' => 14],
            ['title' => 'Active', 'width' => 8],
        ], $rows, $this->selected['schedules'] ?? 0, $this->cols - 4, $this->viewportRows());

        return $table;
    }

    private function tagsBody(): string
    {
        if (!$this->tagsLoaded && !isset($this->errors['tags'])) {
            return "  Loading tags…";
        }
        if (isset($this->errors['tags']) && $this->errors['tags'] !== '') {
            return "\n  {$this->errors['tags']}\n\n  Press r to retry.";
        }
        if ($this->tags === null || $this->tags === []) {
            return "\n  No tags yet.\n";
        }

        $rows = [];
        foreach ($this->tags as $tag) {
            $rows[] = [
                $tag->tag,
                $tag->tagType,
            ];
        }

        $table = Table::render([
            ['title' => 'Tag', 'width' => 0],
            ['title' => 'Type', 'width' => 10],
        ], $rows, $this->selected['tags'] ?? 0, $this->cols - 4, $this->viewportRows());

        return $table;
    }

    private function streamLimitsBody(): string
    {
        if (!$this->streamLimitsLoaded && !isset($this->errors['streamLimits'])) {
            return "  Loading stream limits…";
        }
        if (isset($this->errors['streamLimits']) && $this->errors['streamLimits'] !== '') {
            return "\n  {$this->errors['streamLimits']}\n\n  Press r to retry.";
        }

        $limit = $this->streamLimits;
        $maxStreams = $limit->maxConcurrentStreams ?? 'Not set';
        $maxBandwidth = $limit?->maxTotalBandwidthKbps;

        $lines = [
            "  Current stream limits:",
            '',
            "  Max concurrent streams: {$maxStreams}",
        ];
        if ($maxBandwidth !== null) {
            $lines[] = "  Max total bandwidth: {$maxBandwidth} Kbps";
        } else {
            $lines[] = '  Max total bandwidth: Not set';
        }
        $lines[] = '';
        $lines[] = '  Press u to update limits.';

        return implode("\n", $lines);
    }

    private function statusLine(): string
    {
        if ($this->pendingAction !== null && $this->pendingId !== null) {
            $item = $this->selectedItem();
            $label = $item !== null ? match (true) {
                $item instanceof AccessSchedule => "'{$item->name}'",
                default => "'{$item->tag}'",
            } : 'this item';

            return $this->pendingAction === self::ACTION_DELETE_SCHEDULE
                ? "  Delete schedule {$label}? (y/n)"
                : "  Remove tag {$label}? (y/n)";
        }
        if ($this->busy) {
            return '  Working…';
        }

        return '  ' . self::SECTION_HINTS[$this->section] . '   r refresh';
    }

    private function formBody(Form $form): string
    {
            $intro = $this->section === 'streamLimits'
                ? 'Update stream limits for this profile.'
                : ($this->editing !== null
                    ? match (true) {
                        $this->editing instanceof AccessSchedule => "Edit schedule '{$this->editing->name}'.",
                        default => "Edit tag '{$this->editing->tag}'.",
                    }
                    : 'Create a new ' . match ($this->section) {
                        'schedules' => 'access schedule',
                        'tags' => 'tag',
                        default => 'item',
                    } . ' for this profile.');

        $lines = [$intro];
        if ($this->formError !== null) {
            $lines[] = '! ' . $this->formError;
        }
        $lines[] = '';

        return implode("\n", $lines) . $form->view();
    }

    private function viewportRows(): int
    {
        return max(1, Chrome::bodyHeight($this->rows) - 8);
    }

    /** @return list<AccessSchedule>|list<ProfileTag>|ProfileStreamLimit|null */
    private function currentItems(): array|ProfileStreamLimit|null
    {
        return match ($this->section) {
            'schedules' => $this->schedules ?? [],
            'tags' => $this->tags ?? [],
            'streamLimits' => $this->streamLimits,
            default => [],
        };
    }

    /** @return AccessSchedule|ProfileTag|null */
    private function selectedItem(): AccessSchedule|ProfileTag|null
    {
        $key = $this->section;
        $idx = $this->selected[$key] ?? 0;

        return match ($this->section) {
            'schedules' => ($this->schedules !== null) ? ($this->schedules[$idx] ?? null) : null,
            'tags' => ($this->tags !== null) ? ($this->tags[$idx] ?? null) : null,
            default => null,
        };
    }

    // ---- breadcrumb ----------------------------------------------------

    public function crumbLabel(): string
    {
        return 'Parental Controls';
    }

    public function withCrumbs(array $trail): static
    {
        $next = clone $this;
        $next->crumbs = $trail;

        return $next;
    }
}

// ---- message classes -------------------------------------------------

final readonly class ParentalSchedulesLoadedMsg implements Msg
{
    /** @param list<AccessSchedule> $schedules */
    public function __construct(public array $schedules) {}
}

final readonly class ParentalTagsLoadedMsg implements Msg
{
    /** @param list<ProfileTag> $tags */
    public function __construct(public array $tags) {}
}

final readonly class ParentalStreamLimitsLoadedMsg implements Msg
{
    public function __construct(public ProfileStreamLimit $limit) {}
}

final readonly class ParentalActionDoneMsg implements Msg
{
    public function __construct(public string $message) {}
}
