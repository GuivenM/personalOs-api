<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/spiritual                    → logs du jour pour toutes les pratiques actives
 * POST   /api/spiritual                    → toggler une pratique (done/not done) + notes
 *
 * GET    /api/spiritual/practices          → liste des pratiques
 * POST   /api/spiritual/practices          → créer une pratique
 * PUT    /api/spiritual/practices/{id}     → modifier / désactiver une pratique
 */
class SpiritualController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET'  && $action === 'practices' && !$id => $this->practicesList($user),
            $method === 'POST' && $action === 'practices' && !$id => $this->practiceCreate($user, $body),
            $method === 'PUT'  && $action === 'practices' && $id  => $this->practiceUpdate($user, $id, $body),

            $method === 'GET'  && !$id                            => $this->today($user),
            $method === 'POST' && !$id                            => $this->toggle($user, $body),

            default => Response::error('Route spiritual inconnue.', 404),
        };
    }

    // GET /api/spiritual?date=YYYY-MM-DD
    private function today(array $user): void
    {
        $date = $this->parseDate($_GET['date'] ?? null);
        $db   = Database::get();

        $stmt = $db->prepare(
            'SELECT p.id AS practice_id, p.name,
                    COALESCE(l.done, 0)  AS done,
                    l.notes,
                    l.id                 AS log_id
             FROM m_w_spiritual_practices p
             LEFT JOIN m_w_spiritual_logs l
                    ON l.practice_id = p.id AND l.log_date = ?
             WHERE p.user_id = ? AND p.active = 1
             ORDER BY p.created_at ASC'
        );
        $stmt->execute([$date, $user['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'date'      => $date,
            'practices' => array_map(fn($r) => [
                ...$r,
                'done' => (bool)$r['done'],
            ], $rows),
        ]);
    }

    // POST /api/spiritual — toggle done + notes
    // Body: { practice_id, done, notes, date }
    private function toggle(array $user, array $body): void
    {
        if (empty($body['practice_id'])) Response::error('practice_id requis.', 422);

        $practiceId = (int)$body['practice_id'];
        $date       = $this->parseDate($body['date'] ?? null);
        $done       = (int)(bool)($body['done'] ?? true);
        $notes      = $body['notes'] ?? null;

        // Vérifier que la pratique appartient à l'user
        $stmt = Database::get()->prepare(
            'SELECT id FROM m_w_spiritual_practices WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$practiceId, $user['id']]);
        if (!$stmt->fetch()) Response::error('Pratique introuvable.', 404);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_spiritual_logs (user_id, practice_id, log_date, done, notes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE done = VALUES(done), notes = VALUES(notes)'
        )->execute([$user['id'], $practiceId, $date, $done, $notes]);

        // Retourner l'état du jour
        $_GET['date'] = $date;
        $this->today($user);
    }

    // GET /api/spiritual/practices
    private function practicesList(array $user): void
    {
        $stmt = Database::get()->prepare(
            'SELECT id, name, active, created_at
             FROM m_w_spiritual_practices
             WHERE user_id = ?
             ORDER BY active DESC, created_at ASC'
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json(array_map(fn($r) => [...$r, 'active' => (bool)$r['active']], $rows));
    }

    // POST /api/spiritual/practices
    private function practiceCreate(array $user, array $body): void
    {
        if (empty($body['name'])) Response::error('Nom requis.', 422);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_spiritual_practices (user_id, name) VALUES (?, ?)'
        )->execute([$user['id'], trim($body['name'])]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_spiritual_practices WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        Response::json([...$row, 'active' => (bool)$row['active']], 201);
    }

    // PUT /api/spiritual/practices/{id}
    private function practiceUpdate(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT id FROM m_w_spiritual_practices WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Pratique introuvable.', 404);

        $allowed = ['name', 'active'];
        $fields  = [];
        $params  = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $params[] = $f === 'active' ? (int)(bool)$body[$f] : trim($body[$f]);
            }
        }

        if (!$fields) Response::error('Aucun champ à modifier.', 422);

        $params[] = $id;
        $params[] = $user['id'];
        $db->prepare('UPDATE m_w_spiritual_practices SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_spiritual_practices WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        Response::json([...$row, 'active' => (bool)$row['active']]);
    }

    private function parseDate(?string $raw): string
    {
        if ($raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        return date('Y-m-d');
    }
}
