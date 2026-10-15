<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/finances                → liste des entrées (filtrable par type/mois)
 * POST   /api/finances                → créer une entrée
 * DELETE /api/finances/{id}           → supprimer une entrée
 * GET    /api/finances/summary        → résumé du mois en cours
 *
 * GET    /api/finances/goals          → objectifs financiers
 * POST   /api/finances/goals          → créer un objectif
 * PUT    /api/finances/goals/{id}     → modifier un objectif
 */
class FinanceController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET'    && $action === 'summary'          => $this->summary($user),
            $method === 'GET'    && $action === 'goals' && !$id    => $this->goalsList($user),
            $method === 'POST'   && $action === 'goals'            => $this->goalCreate($user, $body),
            $method === 'PUT'    && $action === 'goals' && $id     => $this->goalUpdate($user, $id, $body),

            $method === 'GET'    && !$id                           => $this->index($user),
            $method === 'POST'   && !$id                           => $this->create($user, $body),
            $method === 'DELETE' && $id                            => $this->delete($user, $id),

            default => Response::error('Route finances inconnue.', 404),
        };
    }

    // GET /api/finances?type=expense|income&month=YYYY-MM&category=xxx
    private function index(array $user): void
    {
        $where  = ['user_id = ?'];
        $params = [$user['id']];

        if (!empty($_GET['type'])) {
            $where[]  = 'type = ?';
            $params[] = $_GET['type'];
        }
        if (!empty($_GET['month'])) {
            $where[]  = 'DATE_FORMAT(entry_date, "%Y-%m") = ?';
            $params[] = $_GET['month'];
        }
        if (!empty($_GET['category'])) {
            $where[]  = 'category = ?';
            $params[] = $_GET['category'];
        }

        $stmt = Database::get()->prepare(
            'SELECT id, type, amount, category, note, entry_date, created_at
             FROM m_w_finance_entries
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY entry_date DESC, created_at DESC'
        );
        $stmt->execute($params);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // POST /api/finances
    private function create(array $user, array $body): void
    {
        $errors = [];
        if (empty($body['amount']))                             $errors[] = 'Montant requis.';
        if (!in_array($body['type'] ?? '', ['expense', 'income'], true)) $errors[] = 'Type invalide (expense ou income).';
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_finance_entries (user_id, type, amount, category, note, entry_date)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            $body['type'],
            (float)$body['amount'],
            $body['category']   ?? null,
            $body['note']       ?? null,
            $body['entry_date'] ?? date('Y-m-d'),
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_finance_entries WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // DELETE /api/finances/{id}
    private function delete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_finance_entries WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Entrée introuvable.', 404);

        $db->prepare('DELETE FROM m_w_finance_entries WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Entrée supprimée.']);
    }

    // GET /api/finances/summary?month=YYYY-MM
    private function summary(array $user): void
    {
        $month  = $_GET['month'] ?? date('Y-m');
        $db     = Database::get();

        $stmt = $db->prepare(
            'SELECT type, category,
                    SUM(amount)  AS total,
                    COUNT(*)     AS count
             FROM m_w_finance_entries
             WHERE user_id = ? AND DATE_FORMAT(entry_date, "%Y-%m") = ?
             GROUP BY type, category
             ORDER BY type, total DESC'
        );
        $stmt->execute([$user['id'], $month]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $income   = 0;
        $expenses = 0;
        $byCategory = [];

        foreach ($rows as $r) {
            if ($r['type'] === 'income')  $income   += (float)$r['total'];
            if ($r['type'] === 'expense') $expenses += (float)$r['total'];
            $byCategory[] = $r;
        }

        Response::json([
            'month'       => $month,
            'income'      => $income,
            'expenses'    => $expenses,
            'balance'     => $income - $expenses,
            'by_category' => $byCategory,
        ]);
    }

    // GET /api/finances/goals
    private function goalsList(array $user): void
    {
        $stmt = Database::get()->prepare(
            'SELECT id, title, target, current,
                    ROUND((current / target) * 100) AS progress_pct,
                    created_at
             FROM m_w_finance_goals WHERE user_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$user['id']]);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // POST /api/finances/goals
    private function goalCreate(array $user, array $body): void
    {
        $errors = [];
        if (empty($body['title']))  $errors[] = 'Titre requis.';
        if (empty($body['target'])) $errors[] = 'Montant cible requis.';
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_finance_goals (user_id, title, target, current)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $user['id'],
            trim($body['title']),
            (float)$body['target'],
            (float)($body['current'] ?? 0),
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_finance_goals WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // PUT /api/finances/goals/{id}
    private function goalUpdate(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_finance_goals WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Objectif introuvable.', 404);

        $allowed = ['title', 'target', 'current'];
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
        $db->prepare('UPDATE m_w_finance_goals SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_finance_goals WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }
}
