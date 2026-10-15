<?php

declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET  /api/journal              → entrée du jour (ou ?date=YYYY-MM-DD)
 * POST /api/journal              → créer ou mettre à jour une entrée (upsert)
 *
 * GET  /api/notes                → toutes les notes (stratégiques + idées)
 * POST /api/notes                → créer une note
 * PUT  /api/notes/{id}           → modifier une note
 * DELETE /api/notes/{id}         → supprimer une note
 */
class JournalController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user     = Auth::check();
        $resource = $this->resource();

        match (true) {
            // ── /api/notes ────────────────────────────────────────────────
            $resource === 'notes' && $method === 'GET'    && !$id  => $this->notesList($user),
            $resource === 'notes' && $method === 'POST'   && !$id  => $this->noteCreate($user, $body),
            $resource === 'notes' && $method === 'PUT'    && $id   => $this->noteUpdate($user, $id, $body),
            $resource === 'notes' && $method === 'DELETE' && $id   => $this->noteDelete($user, $id),

            // ── /api/journal ──────────────────────────────────────────────
            $method === 'GET' && $action === 'history' => $this->history($user),
            $method === 'GET'  => $this->get($user),
            $method === 'POST' => $this->save($user, $body),

            default => Response::error('Route journal inconnue.', 404),
        };
    }

    // GET /api/journal/history?limit=30
    private function history(array $user): void
    {
        $limit = min((int)($_GET['limit'] ?? 30), 90);

        $stmt = Database::get()->prepare(
            'SELECT entry_date AS date,
                LENGTH(content)          AS char_count,
                LEFT(content, 120)       AS preview
         FROM m_w_journal_entries
         WHERE user_id = ?
         ORDER BY entry_date DESC
         LIMIT ?'
        );
        $stmt->execute([$user['id'], $limit]);

        Response::json([
            'entries' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
        ]);
    }

    // ── Journal quotidien ──────────────────────────────────────────────────────

    // GET /api/journal?date=YYYY-MM-DD
    private function get(array $user): void
    {
        $date = $this->parseDate($_GET['date'] ?? null);

        $stmt = Database::get()->prepare(
            'SELECT id, entry_date, content, created_at, updated_at
             FROM m_w_journal_entries
             WHERE user_id = ? AND entry_date = ? LIMIT 1'
        );
        $stmt->execute([$user['id'], $date]);
        $entry = $stmt->fetch(\PDO::FETCH_ASSOC);

        Response::json($entry ?: ['entry_date' => $date, 'content' => null, 'exists' => false]);
    }

    // POST /api/journal — upsert (crée ou écrase l'entrée du jour)
    private function save(array $user, array $body): void
    {
        $date    = $this->parseDate($body['date'] ?? null);
        $content = trim($body['content'] ?? '');

        $db = Database::get();

        // INSERT ... ON DUPLICATE KEY UPDATE (clé unique sur user_id + entry_date)
        $db->prepare(
            'INSERT INTO m_w_journal_entries (user_id, entry_date, content)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = NOW()'
        )->execute([$user['id'], $date, $content]);

        $stmt = $db->prepare(
            'SELECT id, entry_date, content, created_at, updated_at
             FROM m_w_journal_entries WHERE user_id = ? AND entry_date = ? LIMIT 1'
        );
        $stmt->execute([$user['id'], $date]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // ── Notes stratégiques & idées ─────────────────────────────────────────────

    // GET /api/notes?type=strategic|idea&theme=xxx
    private function notesList(array $user): void
    {
        $where  = ['user_id = ?'];
        $params = [$user['id']];

        if (!empty($_GET['type'])) {
            $where[]  = 'type = ?';
            $params[] = $_GET['type'];
        }
        if (!empty($_GET['theme'])) {
            $where[]  = 'theme = ?';
            $params[] = $_GET['theme'];
        }

        $stmt = Database::get()->prepare(
            'SELECT id, type, title, content, theme, project_id, created_at, updated_at
             FROM m_w_notes WHERE ' . implode(' AND ', $where) . '
             ORDER BY updated_at DESC'
        );
        $stmt->execute($params);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // POST /api/notes
    private function noteCreate(array $user, array $body): void
    {
        $validTypes = ['strategic', 'idea'];
        $type = in_array($body['type'] ?? '', $validTypes, true) ? $body['type'] : 'idea';

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_notes (user_id, type, title, content, theme, project_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            $type,
            $body['title']      ?? null,
            $body['content']    ?? null,
            $body['theme']      ?? null,
            $body['project_id'] ?? null,
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_notes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // PUT /api/notes/{id}
    private function noteUpdate(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_notes WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Note introuvable.', 404);

        $allowed = ['type', 'title', 'content', 'theme', 'project_id'];
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
        $db->prepare('UPDATE m_w_notes SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
            ->execute($params);

        $stmt = $db->prepare('SELECT * FROM m_w_notes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // DELETE /api/notes/{id}
    private function noteDelete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_notes WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Note introuvable.', 404);

        $db->prepare('DELETE FROM m_w_notes WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Note supprimée.']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function parseDate(?string $raw): string
    {
        if ($raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        return date('Y-m-d');
    }

    private function resource(): string
    {
        $uri      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $uri      = preg_replace('#^/api#', '', $uri) ?: '/';
        $segments = array_values(array_filter(explode('/', $uri)));
        return $segments[0] ?? 'journal';
    }
}
