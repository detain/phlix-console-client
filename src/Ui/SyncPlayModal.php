<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

namespace Phlix\Console\Ui;

use Phlix\Console\Api\Dto\SyncPlayRoom;
use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * The SyncPlay room creation/join modal. A multi-section form that allows:
 *   - Viewing the list of public rooms
 *   - Creating a new room
 *   - Joining an existing room
 *
 * Uses sugar-veil's composite() for a dimmed backdrop and sugar-boxer's
 * bordered box for the form. Immutable (clone-mutate).
 *
 * Modal states:
 *   0 = initial (show rooms list + create option)
 *   1 = room name input for creation
 *   2 = joining room (loading)
 *   3 = error state
 */
final class SyncPlayModal
{
    private const MAX_WIDTH = 50;
    private const MIN_WIDTH = 30;
    private const BACKDROP_DIM = 40;
    private const MAX_ROOMS_SHOWN = 8;

    private function __construct(
        private int $state,
        private int $cursor,
        private int $winWidth,
        private int $winHeight,
        private ?string $roomName,
        private bool $isPublic,
        /** @var list<SyncPlayRoom> */
        private array $rooms,
        private ?string $error,
        private int $cols,
        private int $rows,
    ) {
    }

    /**
     * Open the modal in initial state, optionally pre-loaded with public rooms.
     *
     * @param list<SyncPlayRoom> $rooms
     */
    public static function open(array $rooms, int $cols, int $rows): self
    {
        [$w, $h] = self::calculateDims($cols, $rows, count($rooms) + 2);

        return new self(
            state: 0,
            cursor: 0,
            winWidth: $w,
            winHeight: $h,
            roomName: null,
            isPublic: true,
            rooms: $rooms,
            error: null,
            cols: $cols,
            rows: $rows,
        );
    }

    // ---- State transitions ---------------------------------------------

    /** Move cursor up in the rooms list. */
    public function up(): self
    {
        if ($this->state !== 0) {
            return $this;
        }

        $maxCursor = count($this->rooms) + 1; // +1 for "Create new room" option
        $next = clone $this;
        $next->cursor = max(0, $this->cursor - 1);

        return $next;
    }

    /** Move cursor down in the rooms list. */
    public function down(): self
    {
        if ($this->state !== 0) {
            return $this;
        }

        $maxCursor = count($this->rooms) + 1;
        $next = clone $this;
        $next->cursor = min($maxCursor, $this->cursor + 1);

        return $next;
    }

    /**
     * Select the current item (room to join, or enter room creation).
     *
     * @return array{self, ?string} (modal, roomId to join or 'create' for new room)
     */
    public function select(): array
    {
        if ($this->state === 1) {
            // In room name input - confirm creation
            if ($this->roomName !== null && trim($this->roomName) !== '') {
                return [$this, 'create'];
            }

            return [$this, null];
        }

        if ($this->state !== 0) {
            return [$this, null];
        }

        $roomCount = count($this->rooms);

        if ($this->cursor === 0) {
            // "Create new room" selected → enter room name input state
            $next = clone $this;
            $next->state = 1;
            $next->roomName = '';

            return [$next, null];
        }

        // A room was selected
        $index = $this->cursor - 1;
        if ($index >= 0 && $index < $roomCount) {
            /** @var SyncPlayRoom */
            $room = $this->rooms[$index];

            return [$this, $room->id];
        }

        return [$this, null];
    }

    /**
     * Append a character to the room name being typed.
     */
    public function appendChar(string $char): self
    {
        if ($this->state !== 1) {
            return $this;
        }

        $next = clone $this;
        $next->roomName = ($this->roomName ?? '') . $char;

        return $next;
    }

    /**
     * Remove the last character from the room name.
     */
    public function backspace(): self
    {
        if ($this->state !== 1) {
            return $this;
        }

        $next = clone $this;
        $next->roomName = $this->roomName !== null && strlen($this->roomName) > 0
            ? substr($this->roomName, 0, -1)
            : '';

        return $next;
    }

    /** Toggle public/private for room creation. */
    public function togglePublic(): self
    {
        if ($this->state !== 1) {
            return $this;
        }

        $next = clone $this;
        $next->isPublic = !$this->isPublic;

        return $next;
    }

    /** Go back from room creation to room list. */
    public function cancel(): self
    {
        if ($this->state !== 1) {
            return $this;
        }

        $next = clone $this;
        $next->state = 0;
        $next->roomName = null;

        return $next;
    }

    /** Enter loading state when joining. */
    public function joining(): self
    {
        $next = clone $this;
        $next->state = 2;

        return $next;
    }

    /** Set an error state. */
    public function withError(string $error): self
    {
        $next = clone $this;
        $next->state = 3;
        $next->error = $error;

        return $next;
    }

    // ---- Rendering -----------------------------------------------------

    /**
     * Render the modal overlaying the given background.
     */
    public function render(string $background): string
    {
        $box = match ($this->state) {
            1 => $this->renderCreateForm(),
            2 => $this->renderLoading(),
            3 => $this->renderError(),
            default => $this->renderRoomsList(),
        };

        return Veil::new()
            ->withBackdrop(self::BACKDROP_DIM)
            ->composite($box, $background, Position::CENTER, Position::CENTER);
    }

    /** @return array{width:int, height:int} */
    public function dims(): array
    {
        return ['width' => $this->winWidth, 'height' => $this->winHeight];
    }

    /**
     * Recalculate dimensions for new terminal size.
     */
    public function resizedTo(int $cols, int $rows): self
    {
        [$w, $h] = self::calculateDims($cols, $rows, count($this->rooms) + 2);

        $next = clone $this;
        $next->winWidth = $w;
        $next->winHeight = $h;
        $next->cols = $cols;
        $next->rows = $rows;

        return $next;
    }

    private function renderRoomsList(): string
    {
        $lines = [];

        // Header
        $lines[] = Style::new()->bold()->render('  SyncPlay');

        // Public rooms section
        $lines[] = '';
        $lines[] = '  Public Rooms:';

        $roomCount = count($this->rooms);
        $maxItems = min($roomCount, self::MAX_ROOMS_SHOWN);

        if ($roomCount === 0) {
            $lines[] = '    (none available)';
        } else {
            for ($i = 0; $i < $maxItems; $i++) {
                $room = $this->rooms[$i];
                $listIndex = $i + 1; // 0 is "Create new room"
                $isSelected = $this->cursor === $listIndex;
                $label = sprintf(
                    '  %s  %s (%d)',
                    $isSelected ? '▶' : ' ',
                    $room->name,
                    $room->memberCount,
                );
                $lines[] = $isSelected
                    ? Style::new()->reverse()->render($label)
                    : $label;
            }

            if ($roomCount > self::MAX_ROOMS_SHOWN) {
                $lines[] = sprintf('    ... and %d more', $roomCount - self::MAX_ROOMS_SHOWN);
            }
        }

        // Create new room option
        $listIndex = 0;
        $isSelected = $this->cursor === $listIndex;
        $label = '  + Create new room';
        $lines[] = $isSelected
            ? Style::new()->reverse()->render($label)
            : $label;

        $lines[] = '';
        $lines[] = '  ↑↓ select  Enter confirm  Esc cancel';

        $body = implode("\n", $lines);

        return SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($body)->withBorder(true)->withPadding(0)->withTitle(' SyncPlay '),
            $this->winWidth,
            $this->winHeight,
        );
    }

    private function renderCreateForm(): string
    {
        $lines = [];

        $lines[] = Style::new()->bold()->render('  Create Room');
        $lines[] = '';

        // Room name input
        $nameDisplay = ($this->roomName ?? '') . '█';
        $nameLabel = '  Name: ' . $nameDisplay;
        $lines[] = Style::new()->reverse()->render($nameDisplay === '█' ? '  Name: █' : $nameLabel);

        $lines[] = '';

        // Public/private toggle
        $pubLabel = $this->isPublic ? '◉ Public' : '○ Private';
        $lines[] = '  [P] ' . $pubLabel;

        $lines[] = '';
        $lines[] = '  Enter create  ← back  type to edit name';
        $lines[] = '  Esc cancel';

        $body = implode("\n", $lines);

        return SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($body)->withBorder(true)->withPadding(0)->withTitle(' Create Room '),
            $this->winWidth,
            $this->winHeight,
        );
    }

    private function renderLoading(): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = '  Joining room...';
        $lines[] = '';

        $body = implode("\n", $lines);

        return SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($body)->withBorder(true)->withPadding(0)->withTitle(' SyncPlay '),
            $this->winWidth,
            $this->winHeight,
        );
    }

    private function renderError(): string
    {
        $lines = [];
        $lines[] = Style::new()->bold()->render('  Error');
        $lines[] = '';
        $lines[] = '  ' . ($this->error ?? 'An error occurred');
        $lines[] = '';
        $lines[] = '  Press any key to continue';

        $body = implode("\n", $lines);

        return SugarBoxer::new()->render(
            SugarBoxer::new()->leaf($body)->withBorder(true)->withPadding(0)->withTitle(' SyncPlay '),
            $this->winWidth,
            $this->winHeight,
        );
    }

    /**
     * @return array{int, int} [winWidth, winHeight]
     */
    private static function calculateDims(int $cols, int $rows, int $itemCount): array
    {
        $w = max(self::MIN_WIDTH, min($cols - 8, self::MAX_WIDTH));
        // Account for header, separator, footer
        $contentLines = 3 + min($itemCount, self::MAX_ROOMS_SHOWN) + 4;
        $h = max(6, min($rows - 4, $contentLines + 2));

        return [$w, $h];
    }

    // ---- accessors -----------------------------------------------------

    public function state(): int
    {
        return $this->state;
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function roomName(): ?string
    {
        return $this->roomName;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /** @return list<SyncPlayRoom> */
    public function rooms(): array
    {
        return $this->rooms;
    }
}
