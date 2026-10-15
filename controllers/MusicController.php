<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/music              → liste des sessions (filtrable par type/date)
 * POST   /api/music              → créer une session
 * PUT    /api/music/{id}         → modifier une session
 * DELETE /api/music/{id}         → supprimer une session
 * GET    /api/music/stats        → heures par mois, streaks, sessions par type
 */
class MusicController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET'    && $action === 'stats' => $this->stats($user),
            $method === 'GET'    && !$id                => $this->index($user),
            $method === 'POST'   && !$id                => $this->create($user, $body),
            $method === 'PUT'    && $id                 => $this->update($user, $id, $body),
            $method === 'DELETE' && $id                 => $this->delete($user, $id),
            default => Response::error('Route music inconnue.', 404),
        };
    }

    // GET /api/music?type=writing&from=YYYY-MM-DD&project=xxx
    private function index(array $user): void
    {
        $where  = ['user_id = ?'];
        $params = [$user['id']];

        if (!empty($_GET['type'])) {
            $where[]  = 'session_type = ?';
            $params[] = $_GET['type'];
        }
        if (!empty($_GET['from'])) {
            $where[]  = 'session_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[]  = 'session_date <= ?';
            $params[] = $_GET['to'];
        }
        if (!empty($_GET['project'])) {
            $where[]  = 'project_name = ?';
            $params[] = $_GET['project'];
        }

        $stmt = Database::get()->prepare(
            'SELECT id, session_type, duration_min, notes, project_name, session_date, created_at
             FROM m_w_music_sessions
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY session_date DESC, created_at DESC'
        );
        $stmt->execute($params);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // POST /api/music
    private function create(array $user, array $body): void
    {
        $validTypes = ['writing', 'production', 'listening', 'recording', 'other'];
        $type = in_array($body['session_type'] ?? '', $validTypes, true)
            ? $body['session_type'] : 'other';

        if (empty($body['duration_min'])) Response::error('Durée requise.', 422);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_music_sessions
                (user_id, session_type, duration_min, notes, project_name, session_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            $type,
            (int)$body['duration_min'],
            $body['notes']        ?? null,
            $body['project_name'] ?? null,
            $body['session_date'] ?? date('Y-m-d'),
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_music_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // PUT /api/music/{id}
    private function update(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_music_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Session introuvable.', 404);

        $allowed = ['session_type', 'duration_min', 'notes', 'project_name', 'session_date'];
        $fields  = [];
        $params  = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $params[] = is_string($body[$f]) ? trim($body[$f]) : $body[$f];
            }
        }

        if (!$fields) Response::error('Aucun champ à modifier.', 422);

        $params[] = $id;
        $params[] = $user['id'];
        $db->prepare('UPDATE m_w_music_sessions SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_music_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // DELETE /api/music/{id}
    private function delete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_music_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Session introuvable.', 404);

        $db->prepare('DELETE FROM m_w_music_sessions WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Session supprimée.']);
    }

    // GET /api/music/stats
    private function stats(array $user): void
    {
        $db = Database::get();

        // Heures par mois (12 derniers mois)
        $stmt = $db->prepare(
            'SELECT DATE_FORMAT(session_date, "%Y-%m") AS month,
                    SUM(duration_min)                   AS total_min,
                    COUNT(*)                            AS sessions
             FROM m_w_music_sessions
             WHERE user_id = ?
               AND session_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
             GROUP BY month
             ORDER BY month ASC'
        );
        $stmt->execute([$user['id']]);
        $byMonth = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Répartition par type
        $stmt = $db->prepare(
            'SELECT session_type,
                    COUNT(*)          AS sessions,
                    SUM(duration_min) AS total_min
             FROM m_w_music_sessions
             WHERE user_id = ?
             GROUP BY session_type
             ORDER BY total_min DESC'
        );
        $stmt->execute([$user['id']]);
        $byType = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Projets actifs (noms distincts des 30 derniers jours)
        $stmt = $db->prepare(
            'SELECT DISTINCT project_name
             FROM m_w_music_sessions
             WHERE user_id = ?
               AND project_name IS NOT NULL
               AND session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             ORDER BY project_name ASC'
        );
        $stmt->execute([$user['id']]);
        $recentProjects = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Total global
        $stmt = $db->prepare(
            'SELECT SUM(duration_min) AS total_min, COUNT(*) AS total_sessions
             FROM m_w_music_sessions WHERE user_id = ?'
        );
        $stmt->execute([$user['id']]);
        $totals = $stmt->fetch(\PDO::FETCH_ASSOC);

        Response::json([
            'total_min'       => (int)($totals['total_min'] ?? 0),
            'total_sessions'  => (int)($totals['total_sessions'] ?? 0),
            'by_month'        => $byMonth,
            'by_type'         => $byType,
            'recent_projects' => $recentProjects,
        ]);
    }
}
