<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/network              → liste des contacts (avec alerte si en retard)
 * POST   /api/network              → ajouter un contact
 * PUT    /api/network/{id}         → modifier un contact
 * DELETE /api/network/{id}         → supprimer un contact
 * POST   /api/network/{id}/touch   → marquer comme contacté aujourd'hui
 */
class NetworkController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'POST'   && $id && $action === 'touch' => $this->touch($user, $id),
            $method === 'GET'    && !$id                       => $this->index($user),
            $method === 'POST'   && !$id                       => $this->create($user, $body),
            $method === 'PUT'    && $id                        => $this->update($user, $id, $body),
            $method === 'DELETE' && $id                        => $this->delete($user, $id),
            default => Response::error('Route network inconnue.', 404),
        };
    }

    // GET /api/network
    private function index(array $user): void
    {
        $stmt = Database::get()->prepare(
            'SELECT id, name, note, frequency_days, last_contact, created_at,
                    CASE
                        WHEN last_contact IS NULL THEN 1
                        WHEN DATEDIFF(CURDATE(), last_contact) >= frequency_days THEN 1
                        ELSE 0
                    END AS overdue,
                    CASE
                        WHEN last_contact IS NULL THEN NULL
                        ELSE DATEDIFF(CURDATE(), last_contact)
                    END AS days_since
             FROM m_w_contacts
             WHERE user_id = ?
             ORDER BY overdue DESC, days_since DESC'
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json(array_map(fn($r) => [
            ...$r,
            'overdue' => (bool)$r['overdue'],
        ], $rows));
    }

    // POST /api/network
    private function create(array $user, array $body): void
    {
        if (empty($body['name'])) Response::error('Nom requis.', 422);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_contacts (user_id, name, note, frequency_days, last_contact)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            trim($body['name']),
            $body['note']           ?? null,
            (int)($body['frequency_days'] ?? 30),
            $body['last_contact']   ?? null,
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_contacts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // PUT /api/network/{id}
    private function update(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_contacts WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Contact introuvable.', 404);

        $allowed = ['name', 'note', 'frequency_days', 'last_contact'];
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
        $db->prepare('UPDATE m_w_contacts SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_contacts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // DELETE /api/network/{id}
    private function delete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_contacts WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Contact introuvable.', 404);

        $db->prepare('DELETE FROM m_w_contacts WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Contact supprimé.']);
    }

    // POST /api/network/{id}/touch — marquer comme contacté aujourd'hui
    private function touch(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_contacts WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Contact introuvable.', 404);

        $db->prepare('UPDATE m_w_contacts SET last_contact = CURDATE() WHERE id = ?')
           ->execute([$id]);

        $stmt = $db->prepare('SELECT * FROM m_w_contacts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }
}
