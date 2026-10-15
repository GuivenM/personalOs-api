<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET /api/consistency?days=30   → streaks (blocks/reading/spiritual/merged)
 *                                    + a day-by-day heatmap over the window
 *
 * NEW CONTROLLER — no existing route/table assumptions beyond what
 * TemplateController and SpiritualController already use. Register it in
 * router.php:
 *
 *     'consistency' => fn() => (new Controllers\ConsistencyController)->dispatch($method, $id, $action, $body),
 *
 * ASSUMPTION: the daily reading block is identified by
 * `m_w_template_blocks.name = 'Lecture'` (see READING_BLOCK_NAME below).
 * If your schedule stores it differently, that's the one line to change.
 *
 * "Done" semantics deliberately match TemplateController::computeStreak's
 * existing definition (already surfaced via Recap's `maxStreak`): a day
 * counts for Blocks/Reading if AT LEAST ONE matching block log is
 * "done" that day — not that every block that day is done. Spiritual
 * follows the same at-least-one-practice-done rule for consistency
 * across the three. If you'd rather require full completion, tell me
 * and I'll switch the HAVING clauses from `> 0` to `= total`.
 */
class ConsistencyController
{
    private const READING_BLOCK_NAME = 'Lecture';

    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET' => $this->consistency($user),
            default            => Response::error('Route consistency inconnue.', 404),
        };
    }

    private function consistency(array $user): void
    {
        $days = isset($_GET['days']) ? max(1, min(365, (int)$_GET['days'])) : 30;
        $db   = Database::get();
        $userId = $user['id'];

        $blocksDates    = $this->doneDates($db, $userId, 'blocks');
        $readingDates   = $this->doneDates($db, $userId, 'reading');
        $spiritualDates = $this->doneDates($db, $userId, 'spiritual');

        $blocksSet    = array_flip($blocksDates);
        $readingSet   = array_flip($readingDates);
        $spiritualSet = array_flip($spiritualDates);
        $mergedDates  = array_keys(array_intersect_key($blocksSet, $readingSet, $spiritualSet));
        rsort($mergedDates);

        $windowDays = $this->buildWindow($days, $blocksSet, $readingSet, $spiritualSet);

        Response::json([
            'window_days' => $days,
            'blocks'      => $this->streakSummary($blocksDates),
            'reading'     => $this->streakSummary($readingDates),
            'spiritual'   => $this->streakSummary($spiritualDates),
            'merged'      => [
                ...$this->streakSummary($mergedDates),
                // Softer companion to the strict AND-streak above: average
                // fraction of the 3 domains hit per day over the window,
                // as a 0–100 score, so an off day on one track doesn't
                // reset the whole picture to zero.
                'consistency_score' => $this->blendedScore($windowDays),
            ],
            'days' => $windowDays,
        ]);
    }

    /**
     * Distinct dates (any order) where the given track counts as "done"
     * that day, per the at-least-one-done rule described above.
     *
     * @return string[] Y-m-d dates
     */
    private function doneDates(\PDO $db, int $userId, string $track): array
    {
        switch ($track) {
            case 'blocks':
                $stmt = $db->prepare(
                    'SELECT DISTINCT l.log_date
                     FROM m_w_template_logs l
                     JOIN m_w_template_blocks b ON b.id = l.template_block_id
                     JOIN m_w_week_templates t  ON t.id = b.template_id
                     WHERE t.user_id = ? AND l.status = "done"'
                );
                $stmt->execute([$userId]);
                break;

            case 'reading':
                $stmt = $db->prepare(
                    'SELECT DISTINCT l.log_date
                     FROM m_w_template_logs l
                     JOIN m_w_template_blocks b ON b.id = l.template_block_id
                     JOIN m_w_week_templates t  ON t.id = b.template_id
                     WHERE t.user_id = ? AND l.status = "done" AND b.name = ?'
                );
                $stmt->execute([$userId, self::READING_BLOCK_NAME]);
                break;

            case 'spiritual':
                $stmt = $db->prepare(
                    'SELECT DISTINCT log_date FROM m_w_spiritual_logs WHERE user_id = ? AND done = 1'
                );
                $stmt->execute([$userId]);
                break;

            default:
                return [];
        }

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @param string[] $dates */
    private function streakSummary(array $dates): array
    {
        return [
            'current_streak' => $this->currentStreak($dates),
            'best_streak'    => $this->bestStreak($dates),
        ];
    }

    /**
     * Consecutive days ending today (or yesterday, if today isn't logged
     * yet). Same algorithm as TemplateController::computeStreak.
     *
     * @param string[] $dates Unsorted Y-m-d dates.
     */
    private function currentStreak(array $dates): int
    {
        if (!$dates) return 0;

        rsort($dates); // descending
        $streak   = 0;
        $expected = new \DateTime('today');

        foreach ($dates as $dateStr) {
            $d = new \DateTime($dateStr);
            if ($streak === 0 && $d < (clone $expected)->modify('-1 day')) break;
            if ($d->format('Y-m-d') === $expected->format('Y-m-d')) {
                $streak++;
                $expected->modify('-1 day');
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Longest run of consecutive days anywhere in the history. Same
     * algorithm as RecapController::computeMaxStreak.
     *
     * @param string[] $dates Unsorted Y-m-d dates.
     */
    private function bestStreak(array $dates): int
    {
        if (!$dates) return 0;

        sort($dates); // ascending
        $max     = 1;
        $current = 1;

        for ($i = 1; $i < count($dates); $i++) {
            $prev = new \DateTime($dates[$i - 1]);
            $curr = new \DateTime($dates[$i]);
            $diff = (int)$prev->diff($curr)->days;

            if ($diff === 1) {
                $current++;
                $max = max($max, $current);
            } else {
                $current = 1;
            }
        }

        return $max;
    }

    /**
     * Last $days days (oldest first) with a per-track boolean plus a
     * 0–3 score, for a calendar-heatmap UI.
     */
    private function buildWindow(int $days, array $blocksSet, array $readingSet, array $spiritualSet): array
    {
        $out = [];
        $cursor = new \DateTime('today');
        $cursor->modify('-' . ($days - 1) . ' days');

        for ($i = 0; $i < $days; $i++) {
            $date = $cursor->format('Y-m-d');
            $blocksDone    = isset($blocksSet[$date]);
            $readingDone   = isset($readingSet[$date]);
            $spiritualDone = isset($spiritualSet[$date]);

            $out[] = [
                'date'           => $date,
                'blocks_done'    => $blocksDone,
                'reading_done'   => $readingDone,
                'spiritual_done' => $spiritualDone,
                'score'          => (int)$blocksDone + (int)$readingDone + (int)$spiritualDone,
            ];

            $cursor->modify('+1 day');
        }

        return $out;
    }

    private function blendedScore(array $windowDays): float
    {
        if (!$windowDays) return 0.0;
        $total = array_sum(array_column($windowDays, 'score'));
        return round(($total / (count($windowDays) * 3)) * 100, 1);
    }
}
