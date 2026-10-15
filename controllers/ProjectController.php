<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/projects                        → liste des projets
 * POST   /api/projects                        → créer un projet
 * GET    /api/projects/{id}                   → détail + tâches + jalons
 * PUT    /api/projects/{id}                   → modifier un projet
 * DELETE /api/projects/{id}                   → supprimer un projet
 *
 * GET    /api/projects/{id}/tasks             → tâches du projet
 * POST   /api/projects/{id}/tasks             → ajouter une tâche
 * PUT    /api/tasks/{id}                      → modifier une tâche
 * DELETE /api/tasks/{id}                      → supprimer une tâche
 *
 * GET    /api/projects/{id}/milestones        → jalons du projet
 * POST   /api/projects/{id}/milestones        → ajouter un jalon
 * PUT    /api/milestones/{id}                 → modifier un jalon
 */
class ProjectController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user     = Auth::check();
        $resource = $this->resource();

        match (true) {
            // ── /api/tasks/{id} ───────────────────────────────────────────
            $resource === 'tasks' && $method === 'PUT'    && $id => $this->taskUpdate($user, $id, $body),
            $resource === 'tasks' && $method === 'DELETE' && $id => $this->taskDelete($user, $id),

            // ── /api/milestones/{id} ──────────────────────────────────────
            $resource === 'milestones' && $method === 'PUT' && $id => $this->milestoneUpdate($user, $id, $body),

            // ── /api/projects ─────────────────────────────────────────────
            $method === 'GET'    && !$id                            => $this->index($user),
            $method === 'POST'   && !$id                            => $this->create($user, $body),
            $method === 'GET'    && $id && !$action                 => $this->show($user, $id),
            $method === 'PUT'    && $id && !$action                 => $this->update($user, $id, $body),
            $method === 'DELETE' && $id && !$action                 => $this->delete($user, $id),

            // ── /api/projects/{id}/tasks ──────────────────────────────────
            $method === 'GET'    && $id && $action === 'tasks'      => $this->tasksList($user, $id),
            $method === 'POST'   && $id && $action === 'tasks'      => $this->taskCreate($user, $id, $body),

            // ── /api/projects/{id}/milestones ─────────────────────────────
            $method === 'GET'    && $id && $action === 'milestones' => $this->milestonesList($user, $id),
            $method === 'POST'   && $id && $action === 'milestones' => $this->milestoneCreate($user, $id, $body),

            default => Response::error('Route projects inconnue.', 404),
        };
    }

    private function resource(): string
    {
        $uri      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $uri      = preg_replace('#^/api#', '', $uri) ?: '/';
        $segments = array_values(array_filter(explode('/', $uri)));
        return $segments[0] ?? 'projects';
    }

    // ── Projets ────────────────────────────────────────────────────────────────

    private function index(array $user): void
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT p.id, p.name, p.domain, p.status, p.description, p.vision,
                    p.created_at, p.updated_at,
                    COUNT(t.id)                AS tasks_total,
                    SUM(t.status = "done")     AS tasks_done
             FROM m_w_projects p
             LEFT JOIN m_w_tasks t ON t.project_id = p.id
             WHERE p.user_id = ?
             GROUP BY p.id
             ORDER BY p.status = "active" DESC, p.updated_at DESC'
        );
        $stmt->execute([$user['id']]);
        $projects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($projects as &$p) {
            $total        = (int)$p['tasks_total'];
            $done         = (int)$p['tasks_done'];
            $p['progress']     = $total > 0 ? (int)round(($done / $total) * 100) : 0;
            $p['tasks_total']  = $total;
            $p['tasks_done']   = $done;
        }
        unset($p);

        Response::json($projects);
    }

    private function create(array $user, array $body): void
    {
        $errors = [];
        if (empty($body['name'])) $errors[] = 'Nom requis.';
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $validStatuses = ['idea', 'active', 'paused', 'done'];
        $status = in_array($body['status'] ?? '', $validStatuses, true) ? $body['status'] : 'idea';

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_projects (user_id, name, domain, status, description, vision)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            trim($body['name']),
            $body['domain']      ?? null,
            $status,
            $body['description'] ?? null,
            $body['vision']      ?? null,
        ]);

        $id = (int)$db->lastInsertId();
        $this->show($user, $id);
    }

    private function show(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM m_w_projects WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $user['id']]);
        $project = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$project) Response::error('Projet introuvable.', 404);

        // Tâches
        $stmt = $db->prepare(
            'SELECT id, title, status, priority, deadline, created_at
             FROM m_w_tasks WHERE project_id = ? ORDER BY priority = "high" DESC, deadline ASC'
        );
        $stmt->execute([$id]);
        $project['tasks'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Jalons
        $stmt = $db->prepare(
            'SELECT id, title, status, due_date, created_at
             FROM m_w_milestones WHERE project_id = ? ORDER BY due_date ASC'
        );
        $stmt->execute([$id]);
        $project['milestones'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Progression
        $total = count($project['tasks']);
        $done  = count(array_filter($project['tasks'], fn($t) => $t['status'] === 'done'));
        $project['progress'] = $total > 0 ? (int)round(($done / $total) * 100) : 0;

        Response::json($project);
    }

    private function update(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_projects WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Projet introuvable.', 404);

        $allowed = ['name', 'domain', 'status', 'description', 'vision'];
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
        $db->prepare('UPDATE m_w_projects SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $this->show($user, $id);
    }

    private function delete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_projects WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Projet introuvable.', 404);

        $db->prepare('DELETE FROM m_w_projects WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Projet supprimé.']);
    }

    // ── Tâches ─────────────────────────────────────────────────────────────────

    private function tasksList(array $user, int $projectId): void
    {
        $this->assertProjectOwner($user['id'], $projectId);

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT id, title, status, priority, deadline, created_at
             FROM m_w_tasks WHERE project_id = ?
             ORDER BY status = "todo" DESC, priority = "high" DESC, deadline ASC'
        );
        $stmt->execute([$projectId]);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function taskCreate(array $user, int $projectId, array $body): void
    {
        $this->assertProjectOwner($user['id'], $projectId);

        if (empty($body['title'])) Response::error('Titre requis.', 422);

        $validPriorities = ['low', 'medium', 'high'];
        $priority = in_array($body['priority'] ?? '', $validPriorities, true)
            ? $body['priority'] : 'medium';

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_tasks (project_id, user_id, title, priority, deadline)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $projectId,
            $user['id'],
            trim($body['title']),
            $priority,
            $body['deadline'] ?? null,
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_tasks WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // ── Jalons ─────────────────────────────────────────────────────────────────

    private function milestonesList(array $user, int $projectId): void
    {
        $this->assertProjectOwner($user['id'], $projectId);

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT id, title, status, due_date, created_at
             FROM m_w_milestones WHERE project_id = ? ORDER BY due_date ASC'
        );
        $stmt->execute([$projectId]);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function milestoneCreate(array $user, int $projectId, array $body): void
    {
        $this->assertProjectOwner($user['id'], $projectId);

        if (empty($body['title'])) Response::error('Titre requis.', 422);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_milestones (project_id, user_id, title, due_date)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $projectId,
            $user['id'],
            trim($body['title']),
            $body['due_date'] ?? null,
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_milestones WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // ── Tâches update/delete (via /api/tasks/{id}) ────────────────────────────

    private function taskUpdate(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_tasks WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Tâche introuvable.', 404);

        $allowed = ['title', 'status', 'priority', 'deadline'];
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
        $db->prepare('UPDATE m_w_tasks SET ' . implode(', ', $fields) . ' WHERE id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_tasks WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    private function taskDelete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_tasks WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Tâche introuvable.', 404);

        $db->prepare('DELETE FROM m_w_tasks WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Tâche supprimée.']);
    }

    // ── Jalons update (via /api/milestones/{id}) ───────────────────────────────

    private function milestoneUpdate(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_milestones WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Jalon introuvable.', 404);

        $allowed = ['title', 'status', 'due_date'];
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
        $db->prepare('UPDATE m_w_milestones SET ' . implode(', ', $fields) . ' WHERE id = ?')
           ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_milestones WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function assertProjectOwner(int $userId, int $projectId): void
    {
        $stmt = Database::get()->prepare(
            'SELECT id FROM m_w_projects WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $userId]);
        if (!$stmt->fetch()) Response::error('Projet introuvable.', 404);
    }
}
