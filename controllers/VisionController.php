<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET  /api/vision                  → vision text + objectifs groupés par horizon
 * PUT  /api/vision                  → mettre à jour le texte de vision
 *
 * GET  /api/vision/goals            → liste des objectifs
 * POST /api/vision/goals            → créer un objectif
 * PUT  /api/vision/goals/{id}       → modifier un objectif
 * DELETE /api/vision/goals/{id}     → supprimer un objectif
 */
class VisionController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET'    && $action === 'goals' && !$id => $this->goalsList($user),
            $method === 'POST'   && $action === 'goals' && !$id => $this->goalCreate($user, $body),
            $method === 'PUT'    && $action === 'goals' && $id  => $this->goalUpdate($user, $id, $body),
            $method === 'DELETE' && $action === 'goals' && $id  => $this->goalDelete($user, $id),

            $method === 'GET' && !$id                           => $this->get($user),
            $method === 'PUT' && !$id                           => $this->updateText($user, $body),

            default => Response::error('Route vision inconnue.', 404),
        };
    }

    // GET /api/vision
    private function get(array $user): void
    {
        $db   = Database::get();

        $stmt = $db->prepare('SELECT vision_text FROM m_w_user_settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $vision = $stmt->fetchColumn();

        $stmt = $db->prepare(
            'SELECT id, horizon, title, status, created_at
             FROM m_w_goals WHERE user_id = ?
             ORDER BY
                FIELD(horizon, "3months", "1year", "3years", "5years"),
                status = "active" DESC'
        );
        $stmt->execute([$user['id']]);
        $goals = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Grouper par horizon
        $grouped = [];
        foreach ($goals as $g) {
            $grouped[$g['horizon']][] = $g;
        }

        Response::json([
            'vision_text' => $vision ?: null,
            'goals'       => $grouped,
        ]);
    }

    // PUT /api/vision
    private function updateText(array $user, array $body): void
    {
        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_user_settings (user_id, vision_text)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE vision_text = VALUES(vision_text)'
        )->execute([$user['id'], trim($body['vision_text'] ?? '')]);

        $this->get($user);
    }

    // GET /api/vision/goals
    private function goalsList(array $user): void
    {
        $stmt = Database::get()->prepare(
            'SELECT id, horizon, title, status, created_at
             FROM m_w_goals WHERE user_id = ?
             ORDER BY FIELD(horizon, "3months", "1year", "3years", "5years"), created_at ASC'
        );
        $stmt->execute([$user['id']]);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // POST /api/vision/goals
    private function goalCreate(array $user, array $body): void
    {
        $validHorizons = ['3months', '1year', '3years', '5years'];
        $errors = [];
        if (empty($body['title']))                                          $errors[] = 'Titre requis.';
        if (!in_array($body['horizon'] ?? '', $validHorizons, true))       $errors[] = 'Horizon invalide.';
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_goals (user_id, horizon, title) VALUES (?, ?, ?)'
        )->execute([$user['id'], $body['horizon'], trim($body['title'])]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_goals WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // PUT /api/vision/goals/{id}
    private function goalUpdate(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_goals WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Objectif introuvable.', 404);

        $allowed = ['title', 'horizon', 'status'];
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
        $db->prepare('UPDATE m_w_goals SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_goals WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // DELETE /api/vision/goals/{id}
    private function goalDelete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_goals WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Objectif introuvable.', 404);

        $db->prepare('DELETE FROM m_w_goals WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Objectif supprimé.']);
    }
}
