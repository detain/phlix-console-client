<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * Renders a recommendation card in the terminal.
 *
 * Shows title, year, "Because You Watched" badge, and match score.
 * All colour comes from the active {@see Theme} (M-CONSOLE-48) — the card
 * itself carries no hexes. The title line is cell-aware end to end: it is
 * ellipsis-truncated by the shared {@see Truncate} choke and padded by
 * {@see Width} to the caller's width, so a long server title can never
 * push the card past the terminal (M-CONSOLE-68 · 72).
 */
final readonly class RecommendationCard
{
    public function __construct(
        private string $id,
        private string $title,
        private ?int $year,
        private float $score,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = '';
        if (isset($data['id']) && is_string($data['id'])) {
            $id = $data['id'];
        }

        $title = 'Unknown';
        if (isset($data['title']) && is_string($data['title'])) {
            $title = $data['title'];
        }

        $year = null;
        if (isset($data['year']) && is_numeric($data['year'])) {
            $year = (int) $data['year'];
        }

        $score = 0.0;
        if (isset($data['score']) && is_numeric($data['score'])) {
            $score = (float) $data['score'];
        }

        return new self($id, $title, $year, $score);
    }

    public function render(Theme $theme, int $titleWidth = 50): string
    {
        $accent = $theme->widgetStyle(Theme::ROLE_STAR);
        $badge = $theme->widgetStyle(Theme::ROLE_BADGE);
        $dim = Style::new()->faint();
        $title = $theme->widgetStyle(Theme::ROLE_TITLE);

        $scorePercent = (int) ($this->score * 100);
        $yearStr = $this->year !== null ? (string) $this->year : '';

        $line1 = $accent->render('★ ')
            . $title->render(Width::padRight(Truncate::withEllipsis($this->title, $titleWidth), $titleWidth));
        $line2 = '  ' . $dim->render($yearStr);
        $line2 .= '  ' . $badge->render('Because You Watched');
        $line2 .= '  ' . $accent->render($scorePercent . '% match');

        return $line1 . "\n" . $line2;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }
}
