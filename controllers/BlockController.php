<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * Gère les logs quotidiens des blocs (m_w_block_logs).
 *
 * GET    /api/blocks             → logs du jour (date du jour par défaut, ou ?date=YYYY-MM-DD)
 * POST   /api/blocks             → initialiser les logs du jour (génère un log pending par bloc)
 * PUT    /api/blocks/{id}        → mettre à jour le statut d'un log
 * GET    /api/blocks/history     → historique 30 jours avec stats
 */
class BlockController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET'  && $action === 'history' => $this->history($user),
            $method === 'GET'  && !$id                  => $this->index($user),
            $method === 'POST' && !$id                  => $this->initDay($user, $body),
            $method === 'PUT'  && $id !== null           => $this->updateStatus($user, $id, $body),
            default => Response::error('Route blocks inconnue.', 404),
        };
    }

    // ------------------------------------------------------------------ //
    // GET /api/blocks?date=YYYY-MM-DD
    // Retourne les blocs + leur log pour la date demandée.
    // Si aucun log n'existe encore pour ce jour, retourne les blocs avec status null.
    // ------------------------------------------------------------------ //
    private function index(array $user): void
    {
        $date = $this->parseDate($_GET['date'] ?? null);
        $db   = Database::get();

        $stmt = $db->prepare(
            'SELECT
                b.id           AS block_id,
                b.name,
                b.description,
                b.time_start,
                b.time_end,
                b.order_index,
                l.id           AS log_id,
                l.status,
                l.updated_at
             FROM m_w_daily_blocks b
             LEFT JOIN m_w_block_logs l
                    ON l.block_id = b.id AND l.log_date = ?
             WHERE b.user_id = ?
             ORDER BY b.order_index ASC'
        );
        $stmt->execute([$date, $user['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'date'   => $date,
            'blocks' => $rows,
        ]);
    }

    // ------------------------------------------------------------------ //
    // POST /api/blocks
    // Initialise les logs du jour (crée un log "pending" pour chaque bloc
    // qui n'en a pas encore). Idempotent.
    // Body optionnel: { date: "YYYY-MM-DD" }
    // ------------------------------------------------------------------ //
    private function initDay(array $user, array $body): void
    {
        $date = $this->parseDate($body['date'] ?? null);
        $db   = Database::get();

        // Charger les blocs de l'user
        $stmt = $db->prepare('SELECT id FROM m_w_daily_blocks WHERE user_id = ? ORDER BY order_index ASC');
        $stmt->execute([$user['id']]);
        $blocks = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!$blocks) {
            Response::error('Aucun bloc configuré. Configurez votre schedule d\'abord.', 422);
        }

        // INSERT IGNORE : si le log existe déjà, on ne touche pas au statut
        $stmt = $db->prepare(
            'INSERT IGNORE INTO m_w_block_logs (user_id, block_id, log_date, status)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($blocks as $blockId) {
            $stmt->execute([$user['id'], $blockId, $date, 'pending']);
        }

        // Retourner l'état du jour (même format que index)
        $_GET['date'] = $date;
        $this->index($user);
    }

    // ------------------------------------------------------------------ //
    // PUT /api/blocks/{id}
    // Met à jour le statut d'un log.
    // Body: { status: "done" | "skipped" | "partial" | "pending" }
    // ------------------------------------------------------------------ //
    private function updateStatus(array $user, int $logId, array $body): void
    {
        $validStatuses = ['done', 'skipped', 'partial', 'pending'];
        $status = $body['status'] ?? '';

        if (!in_array($status, $validStatuses, true)) {
            Response::error('Statut invalide. Valeurs : ' . implode(', ', $validStatuses) . '.', 422);
        }

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT l.id FROM m_w_block_logs l
             JOIN m_w_daily_blocks b ON b.id = l.block_id
             WHERE l.id = ? AND b.user_id = ? LIMIT 1'
        );
        $stmt->execute([$logId, $user['id']]);
        if (!$stmt->fetch()) Response::error('Log introuvable.', 404);

        $db->prepare('UPDATE m_w_block_logs SET status = ? WHERE id = ?')
           ->execute([$status, $logId]);

        $stmt = $db->prepare(
            'SELECT l.id, l.block_id, l.log_date, l.status, l.updated_at,
                    b.name, b.time_start, b.time_end
             FROM m_w_block_logs l
             JOIN m_w_daily_blocks b ON b.id = l.block_id
             WHERE l.id = ? LIMIT 1'
        );
        $stmt->execute([$logId]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------ //
    // GET /api/blocks/history
    // Historique des 30 derniers jours avec résumé par jour.
    // ------------------------------------------------------------------ //
    private function history(array $user): void
    {
        $db   = Database::get();

        // Stats par jour sur 30 jours
        $stmt = $db->prepare(
            'SELECT
                l.log_date,
                COUNT(*)                                    AS total,
                SUM(l.status = "done")                     AS done,
                SUM(l.status = "partial")                  AS partial,
                SUM(l.status = "skipped")                  AS skipped,
                SUM(l.status = "pending")                  AS pending
             FROM m_w_block_logs l
             JOIN m_w_daily_blocks b ON b.id = l.block_id
             WHERE b.user_id = ?
               AND l.log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY l.log_date
             ORDER BY l.log_date DESC'
        );
        $stmt->execute([$user['id']]);
        $days = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Streak actuel : nombre de jours consécutifs avec au moins 1 bloc done
        $streak = $this->computeStreak($user['id'], $db);

        Response::json([
            'streak' => $streak,
            'days'   => $days,
        ]);
    }

    // ------------------------------------------------------------------ //
    // Calcule le streak actuel (jours consécutifs avec ≥1 bloc done)
    // ------------------------------------------------------------------ //
    private function computeStreak(int $userId, \PDO $db): int
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT l.log_date
             FROM m_w_block_logs l
             JOIN m_w_daily_blocks b ON b.id = l.block_id
             WHERE b.user_id = ? AND l.status = "done"
             ORDER BY l.log_date DESC
             LIMIT 60'
        );
        $stmt->execute([$userId]);
        $dates = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!$dates) return 0;

        $streak   = 0;
        $expected = new \DateTime('today');

        foreach ($dates as $dateStr) {
            $d = new \DateTime($dateStr);
            // Tolérance : aujourd'hui ou hier pour le premier jour
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

    // ------------------------------------------------------------------ //
    private function parseDate(?string $raw): string
    {
        if ($raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        return date('Y-m-d');
    }
}
