<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/learning              → liste des sessions (filtrable par domain/date)
 * POST   /api/learning              → créer une session
 * PUT    /api/learning/{id}         → modifier une session
 * DELETE /api/learning/{id}         → supprimer une session
 * GET    /api/learning/stats        → heures cumulées par domaine + graphe activité
 */
class LearningController
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
            default => Response::error('Route learning inconnue.', 404),
        };
    }

    // GET /api/learning?domain=xxx&from=YYYY-MM-DD&to=YYYY-MM-DD
    private function index(array $user): void
    {
        $where  = ['user_id = ?'];
        $params = [$user['id']];

        if (!empty($_GET['domain'])) {
            $where[]  = 'domain = ?';
            $params[] = $_GET['domain'];
        }
        if (!empty($_GET['from'])) {
            $where[]  = 'session_date >= ?';
            $params[] = $_GET['from'];
        }
        if (!empty($_GET['to'])) {
            $where[]  = 'session_date <= ?';
            $params[] = $_GET['to'];
        }

        $stmt = Database::get()->prepare(
            'SELECT id, domain, subdomain, duration_min, content, resource, level, session_date, created_at
             FROM m_w_learning_sessions
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY session_date DESC, created_at DESC'
        );
        $stmt->execute($params);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // POST /api/learning
    private function create(array $user, array $body): void
    {
        $errors = [];
        if (empty($body['domain']))       $errors[] = 'Domaine requis.';
        if (empty($body['duration_min'])) $errors[] = 'Durée requise.';
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_learning_sessions
                (user_id, domain, subdomain, duration_min, content, resource, level, session_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            trim($body['domain']),
            $body['subdomain']    ?? null,
            (int)$body['duration_min'],
            $body['content']      ?? null,
            $body['resource']     ?? null,
            (int)($body['level']  ?? 3),
            $body['session_date'] ?? date('Y-m-d'),
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_learning_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // PUT /api/learning/{id}
    private function update(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_learning_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Session introuvable.', 404);

        $allowed = ['domain', 'subdomain', 'duration_min', 'content', 'resource', 'level', 'session_date'];
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
        $db->prepare('UPDATE m_w_learning_sessions SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_learning_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // DELETE /api/learning/{id}
    private function delete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_learning_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Session introuvable.', 404);

        $db->prepare('DELETE FROM m_w_learning_sessions WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Session supprimée.']);
    }

    // GET /api/learning/stats
    private function stats(array $user): void
    {
        $db = Database::get();

        // Heures cumulées par domaine
        $stmt = $db->prepare(
            'SELECT domain,
                    COUNT(*)              AS sessions,
                    SUM(duration_min)     AS total_min,
                    AVG(level)            AS avg_level,
                    MAX(session_date)     AS last_session
             FROM m_w_learning_sessions
             WHERE user_id = ?
             GROUP BY domain
             ORDER BY total_min DESC'
        );
        $stmt->execute([$user['id']]);
        $byDomain = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Activité sur 365 jours (style GitHub)
        $stmt = $db->prepare(
            'SELECT session_date, SUM(duration_min) AS total_min
             FROM m_w_learning_sessions
             WHERE user_id = ?
               AND session_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
             GROUP BY session_date
             ORDER BY session_date ASC'
        );
        $stmt->execute([$user['id']]);
        $activity = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Total global
        $stmt = $db->prepare(
            'SELECT SUM(duration_min) AS total_min, COUNT(*) AS total_sessions
             FROM m_w_learning_sessions WHERE user_id = ?'
        );
        $stmt->execute([$user['id']]);
        $totals = $stmt->fetch(\PDO::FETCH_ASSOC);

        Response::json([
            'total_min'      => (int)($totals['total_min'] ?? 0),
            'total_sessions' => (int)($totals['total_sessions'] ?? 0),
            'by_domain'      => $byDomain,
            'activity'       => $activity,
        ]);
    }
}
