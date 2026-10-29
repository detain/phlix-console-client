<?php

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Console\Ui;

use SugarCraft\Style\Style;

/**
 * Renders a recommendation card in the terminal.
 *
 * Shows title, year, "Because You Watched" badge, and match score.
 * All colour comes from the active {@see Theme} (M-CONSOLE-48) — the card
 * itself carries no hexes.
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

    public function render(Theme $theme): string
    {
        $accent = $theme->widgetStyle(Theme::ROLE_STAR);
        $badge = $theme->widgetStyle(Theme::ROLE_BADGE);
        $dim = Style::new()->faint();
        $title = $theme->widgetStyle(Theme::ROLE_TITLE);

        $scorePercent = (int) ($this->score * 100);
        $yearStr = $this->year !== null ? (string) $this->year : '';

        $line1 = $accent->render('★ ') . $title->render(str_pad($this->title, 50));
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
