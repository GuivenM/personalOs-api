<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET  /api/health          → log du jour
 * POST /api/health          → créer le log du jour
 * PUT  /api/health          → mettre à jour le log du jour
 */
class HealthController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET'  => $this->today($user),
            $method === 'POST' => $this->log($user, $body),
            $method === 'PUT'  => $this->update($user, $body),
            default => Response::error('Route health inconnue.', 404),
        };
    }

    // GET /api/health
    private function today(array $user): void
    {
        $stmt = Database::get()->prepare(
            'SELECT log_date, sleep_ok, exercise_ok, food_ok
             FROM m_w_health_logs WHERE user_id = ? AND log_date = ? LIMIT 1'
        );
        $stmt->execute([$user['id'], date('Y-m-d')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            Response::json([
                'log_date'    => date('Y-m-d'),
                'sleep_ok'    => false,
                'exercise_ok' => false,
                'food_ok'     => false,
                'exists'      => false,
            ]);
        }

        Response::json([...$row,
            'sleep_ok'    => (bool)$row['sleep_ok'],
            'exercise_ok' => (bool)$row['exercise_ok'],
            'food_ok'     => (bool)$row['food_ok'],
            'exists'      => true,
        ]);
    }

    // POST /api/health — créer (INSERT IGNORE)
    private function log(array $user, array $body): void
    {
        $db = Database::get();
        $db->prepare(
            'INSERT IGNORE INTO m_w_health_logs (user_id, log_date, sleep_ok, exercise_ok, food_ok)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            date('Y-m-d'),
            (int)($body['sleep_ok']    ?? 0),
            (int)($body['exercise_ok'] ?? 0),
            (int)($body['food_ok']     ?? 0),
        ]);

        $this->today($user);
    }

    // PUT /api/health — mettre à jour les champs envoyés
    private function update(array $user, array $body): void
    {
        $db    = Database::get();
        $today = date('Y-m-d');

        // Upsert : si pas de log aujourd'hui, on en crée un vide d'abord
        $db->prepare(
            'INSERT IGNORE INTO m_w_health_logs (user_id, log_date) VALUES (?, ?)'
        )->execute([$user['id'], $today]);

        $allowed = ['sleep_ok', 'exercise_ok', 'food_ok'];
        $fields  = [];
        $params  = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $params[] = (int)(bool)$body[$f];
            }
        }

        if (!$fields) Response::error('Aucun champ à modifier.', 422);

        $params[] = $user['id'];
        $params[] = $today;
        $db->prepare('UPDATE m_w_health_logs SET ' . implode(', ', $fields) . ' WHERE user_id = ? AND log_date = ?')
           ->execute($params);

        $this->today($user);
    }
}
