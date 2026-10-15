<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * Gère la configuration des blocs quotidiens (m_w_daily_blocks).
 *
 * GET    /api/schedule          → liste des blocs de l'user, triés par order_index
 * PUT    /api/schedule          → remplacer toute la config (tableau de 7 blocs)
 * PUT    /api/schedule/{id}     → modifier un seul bloc
 * POST   /api/schedule/reorder  → réordonner les blocs (tableau d'ids ordonnés)
 */
class ScheduleController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET'  && !$id                       => $this->index($user),
            $method === 'PUT'  && !$id                       => $this->replaceAll($user, $body),
            $method === 'PUT'  && $id !== null               => $this->updateOne($user, $id, $body),
            $method === 'POST' && $action === 'reorder'      => $this->reorder($user, $body),
            default => Response::error('Route schedule inconnue.', 404),
        };
    }

    // ------------------------------------------------------------------ //
    // GET /api/schedule
    // ------------------------------------------------------------------ //
    private function index(array $user): void
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT id, name, description, time_start, time_end, order_index
             FROM m_w_daily_blocks
             WHERE user_id = ?
             ORDER BY order_index ASC'
        );
        $stmt->execute([$user['id']]);
        $blocks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json($blocks);
    }

    // ------------------------------------------------------------------ //
    // PUT /api/schedule  — remplacer toute la config
    // Body: { blocks: [ { name, description, time_start, time_end, order_index } ] }
    // ------------------------------------------------------------------ //
    private function replaceAll(array $user, array $body): void
    {
        $blocks = $body['blocks'] ?? [];

        if (!is_array($blocks) || count($blocks) < 1) {
            Response::error('Tableau de blocs requis.', 422);
        }

        $errors = $this->validateBlocks($blocks);
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db = Database::get();
        $db->beginTransaction();

        try {
            // Supprimer les anciens
            $db->prepare('DELETE FROM m_w_daily_blocks WHERE user_id = ?')
               ->execute([$user['id']]);

            $stmt = $db->prepare(
                'INSERT INTO m_w_daily_blocks (user_id, name, description, time_start, time_end, order_index)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($blocks as $i => $b) {
                $stmt->execute([
                    $user['id'],
                    trim($b['name']),
                    trim($b['description'] ?? ''),
                    $b['time_start'],
                    $b['time_end'],
                    $b['order_index'] ?? ($i + 1),
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $this->index($user);
    }

    // ------------------------------------------------------------------ //
    // PUT /api/schedule/{id}  — modifier un seul bloc
    // ------------------------------------------------------------------ //
    private function updateOne(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_daily_blocks WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Bloc introuvable.', 404);

        $fields = [];
        $params = [];

        $allowed = ['name', 'description', 'time_start', 'time_end', 'order_index'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $params[] = is_string($body[$f]) ? trim($body[$f]) : $body[$f];
            }
        }

        if (!$fields) Response::error('Aucun champ à modifier.', 422);

        $params[] = $id;
        $params[] = $user['id'];

        $db->prepare("UPDATE m_w_daily_blocks SET " . implode(', ', $fields) . " WHERE id = ? AND user_id = ?")
           ->execute($params);

        $stmt = $db->prepare(
            'SELECT id, name, description, time_start, time_end, order_index
             FROM m_w_daily_blocks WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------------ //
    // POST /api/schedule/reorder
    // Body: { order: [id1, id2, id3, ...] }  — tableau d'ids dans le nouvel ordre
    // ------------------------------------------------------------------ //
    private function reorder(array $user, array $body): void
    {
        $order = $body['order'] ?? [];
        if (!is_array($order) || !$order) Response::error('Tableau order requis.', 422);

        $db   = Database::get();
        $stmt = $db->prepare(
            'UPDATE m_w_daily_blocks SET order_index = ? WHERE id = ? AND user_id = ?'
        );

        foreach ($order as $index => $blockId) {
            $stmt->execute([$index + 1, (int)$blockId, $user['id']]);
        }

        $this->index($user);
    }

    // ------------------------------------------------------------------ //
    private function validateBlocks(array $blocks): array
    {
        $errors = [];
        foreach ($blocks as $i => $b) {
            $n = $i + 1;
            if (empty($b['name']))       $errors[] = "Bloc $n : name requis.";
            if (empty($b['time_start'])) $errors[] = "Bloc $n : time_start requis (HH:MM:SS).";
            if (empty($b['time_end']))   $errors[] = "Bloc $n : time_end requis (HH:MM:SS).";
        }
        return $errors;
    }
}
