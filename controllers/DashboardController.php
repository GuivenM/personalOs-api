<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET /api/dashboard
 */
class DashboardController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET' && !$id => $this->snapshot($user),
            default => Response::error('Route dashboard inconnue.', 404),
        };
    }

    private function snapshot(array $user): void
    {
        $db    = Database::get();
        $uid   = $user['id'];
        $today = date('Y-m-d');
        $nowH  = (int)date('H');

        // ── 1. Vision ─────────────────────────────────────────────────────
        $stmt = $db->prepare('SELECT vision_text FROM m_w_user_settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $vision = $stmt->fetchColumn() ?: null;

        // ── 2. Template actif ─────────────────────────────────────────────
        $stmt = $db->prepare('SELECT id FROM m_w_week_templates WHERE user_id = ? AND active = 1 LIMIT 1');
        $stmt->execute([$uid]);
        $templateId = $stmt->fetchColumn();

        // ── 3. Blocs du jour ──────────────────────────────────────────────
        $blocks     = [];
        $activeBlock = null;
        $blocksDone  = 0;
        $blocksTotal = 0;

        if ($templateId) {
            $dayOfWeek = (int)date('N') - 1; // 0=Lundi, 6=Dimanche
            $nowTime   = date('H:i:s');

            $stmt = $db->prepare(
                'SELECT
                    b.id          AS block_id,
                    b.name,
                    b.description,
                    b.time_start,
                    b.time_end,
                    b.order_index,
                    l.id          AS log_id,
                    COALESCE(l.status, "pending") AS status
                 FROM m_w_template_blocks b
                 LEFT JOIN m_w_template_logs l
                        ON l.template_block_id = b.id AND l.log_date = ?
                 WHERE b.template_id = ? AND b.day_of_week = ?
                 ORDER BY b.order_index ASC'
            );
            $stmt->execute([$today, $templateId, $dayOfWeek]);
            $blocks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($blocks as &$b) {
                $isCurrent    = $nowTime >= $b['time_start'] && $nowTime < $b['time_end'];
                $b['is_current'] = $isCurrent;
                if ($isCurrent) $activeBlock = $b;
            }
            unset($b);

            $blocksDone  = count(array_filter($blocks, fn($b) => $b['status'] === 'done'));
            $blocksTotal = count($blocks);
        }

        // ── 4. Streak ─────────────────────────────────────────────────────
        $stmt = $db->prepare(
            'SELECT DISTINCT l.log_date
             FROM m_w_template_logs l
             JOIN m_w_template_blocks b ON b.id = l.template_block_id
             JOIN m_w_week_templates t  ON t.id = b.template_id
             WHERE t.user_id = ? AND l.status = "done"
             ORDER BY l.log_date DESC
             LIMIT 60'
        );
        $stmt->execute([$uid]);
        $streak = $this->computeStreak($stmt->fetchAll(\PDO::FETCH_COLUMN));

        // ── 5. Projets actifs ─────────────────────────────────────────────
        $stmt = $db->prepare(
            'SELECT p.id, p.name, p.domain, p.status,
                    COUNT(t.id)            AS tasks_total,
                    SUM(t.status = "done") AS tasks_done
             FROM m_w_projects p
             LEFT JOIN m_w_tasks t ON t.project_id = p.id
             WHERE p.user_id = ? AND p.status IN ("active", "paused")
             GROUP BY p.id
             ORDER BY p.status = "active" DESC, p.updated_at DESC
             LIMIT 5'
        );
        $stmt->execute([$uid]);
        $projects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($projects as &$p) {
            $total = (int)$p['tasks_total'];
            $done  = (int)$p['tasks_done'];
            $p['progress'] = $total > 0 ? (int)round(($done / $total) * 100) : 0;
        }
        unset($p);

        $stmt = $db->prepare('SELECT COUNT(*) FROM m_w_projects WHERE user_id = ? AND status = "active"');
        $stmt->execute([$uid]);
        $projectsCount = (int)$stmt->fetchColumn();

        // ── 6. Livres ─────────────────────────────────────────────────────
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM m_w_books WHERE user_id = ? AND status IN ("reading","done")'
        );
        $stmt->execute([$uid]);
        $booksCount = (int)$stmt->fetchColumn();

        // ── 7. Santé ──────────────────────────────────────────────────────
        $stmt = $db->prepare(
            'SELECT sleep_ok, exercise_ok, food_ok
             FROM m_w_health_logs WHERE user_id = ? AND log_date = ? LIMIT 1'
        );
        $stmt->execute([$uid, $today]);
        $health = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'sleep_ok' => 0, 'exercise_ok' => 0, 'food_ok' => 0,
        ];
        $health = array_map(fn($v) => (bool)$v, $health);

        // ── 8. Citation aléatoire ─────────────────────────────────────────
        $usePersonal = $nowH % 2;
       $quote = null;
        // Alterner entre citation perso et externe selon l'heure (pour varier les plaisirs)
        if ($usePersonal) {
            $stmt = $db->prepare(
                'SELECT q.content AS text, b.title AS source
         FROM m_w_book_quotes q
         JOIN m_w_books b ON b.id = q.book_id
         WHERE q.user_id = ?
         ORDER BY RAND() LIMIT 1'
            );
            $stmt->execute([$uid]);
            $quote = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
 
        if (!$quote) {
            try {
                $raw = @file_get_contents('https://zenquotes.io/api/random');
                if ($raw) {
                    $data = json_decode($raw, true);
                    if (!empty($data[0]['q'])) {
                        $quote = ['text' => $data[0]['q'], 'source' => $data[0]['a']];
                    }
                }
            } catch (\Exception $e) {
                // En cas d'erreur (ex: timeout), on garde la citation perso
                $stmt = $db->prepare(
                    'SELECT q.content AS text, b.title AS source
                     FROM m_w_book_quotes q
                     JOIN m_w_books b ON b.id = q.book_id
                     WHERE q.user_id = ?
                     ORDER BY RAND() LIMIT 1'
                );
                $stmt->execute([$uid]);
                $quote = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
        }

        // ── 9. Greeting ───────────────────────────────────────────────────
        $greeting = match (true) {
            $nowH < 12  => 'Bonjour',
            $nowH < 18  => 'Bon après-midi',
            default     => 'Bonsoir',
        };

        Response::json([
            'date'           => $today,
            'greeting'       => $greeting,
            'vision'         => $vision,
            'streak'         => $streak,
            'blocks_done'    => $blocksDone,
            'blocks_total'   => $blocksTotal,
            'blocks'         => $blocks,
            'active_block'   => $activeBlock,
            'projects_count' => $projectsCount,
            'projects'       => $projects,
            'books_count'    => $booksCount,
            'health'         => $health,
            'quote'          => $quote,
        ]);
    }

    private function computeStreak(array $dates): int
    {
        if (!$dates) return 0;

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
}