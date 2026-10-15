<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/books                    → liste des livres (filtrable par status/phase)
 * POST   /api/books                    → ajouter un livre
 * GET    /api/books/{id}               → détail + citations
 * PUT    /api/books/{id}               → modifier un livre
 * DELETE /api/books/{id}               → supprimer un livre
 *
 * GET    /api/books/{id}/quotes        → citations du livre
 * POST   /api/books/{id}/quotes        → ajouter une citation
 * DELETE /api/quotes/{id}              → supprimer une citation
 */
class BookController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user     = Auth::check();
        $resource = $this->resource();

        match (true) {
            // ── /api/quotes/{id} ──────────────────────────────────────────
            $resource === 'quotes' && $method === 'DELETE' && $id => $this->quoteDelete($user, $id),

            // ── /api/books ────────────────────────────────────────────────
            $method === 'GET'    && !$id                          => $this->index($user),
            $method === 'POST'   && !$id                          => $this->create($user, $body),
            $method === 'GET'    && $id && !$action               => $this->show($user, $id),
            $method === 'PUT'    && $id && !$action               => $this->update($user, $id, $body),
            $method === 'DELETE' && $id && !$action               => $this->delete($user, $id),

            // ── /api/books/{id}/quotes ────────────────────────────────────
            $method === 'GET'    && $id && $action === 'quotes'   => $this->quotesList($user, $id),
            $method === 'POST'   && $id && $action === 'quotes'   => $this->quoteCreate($user, $id, $body),

            default => Response::error('Route books inconnue.', 404),
        };
    }

    // ── Livres ─────────────────────────────────────────────────────────────────

    private function index(array $user): void
    {
        $db     = Database::get();
        $where  = ['b.user_id = ?'];
        $params = [$user['id']];

        // Filtres optionnels : ?status=reading&phase=2
        if (!empty($_GET['status'])) {
            $where[]  = 'b.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['phase']) && is_numeric($_GET['phase'])) {
            $where[]  = 'b.phase = ?';
            $params[] = (int)$_GET['phase'];
        }

        $sql = 'SELECT b.id, b.title, b.author, b.phase, b.status, b.rating,
                       b.start_date, b.end_date, b.summary,
                       COUNT(q.id) AS quotes_count
                FROM m_w_books b
                LEFT JOIN m_w_book_quotes q ON q.book_id = b.id
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY b.id
                ORDER BY
                    b.status = "reading" DESC,
                    b.status = "to_read" DESC,
                    b.updated_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function create(array $user, array $body): void
    {
        if (empty($body['title'])) Response::error('Titre requis.', 422);

        $validStatuses = ['to_read', 'reading', 'done', 'paused', 'abandoned'];
        $status = in_array($body['status'] ?? '', $validStatuses, true)
            ? $body['status'] : 'to_read';

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_books
                (user_id, title, author, phase, status, rating, start_date, end_date, summary, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['id'],
            trim($body['title']),
            $body['author']     ?? null,
            $body['phase']      ?? null,
            $status,
            $body['rating']     ?? null,
            $body['start_date'] ?? null,
            $body['end_date']   ?? null,
            $body['summary']    ?? null,
            $body['notes']      ?? null,
        ]);

        $id = (int)$db->lastInsertId();
        $this->show($user, $id);
    }

    private function show(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM m_w_books WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $user['id']]);
        $book = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$book) Response::error('Livre introuvable.', 404);

        // Citations
        $stmt = $db->prepare(
            'SELECT id, content, source, created_at
             FROM m_w_book_quotes WHERE book_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$id]);
        $book['quotes'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json($book);
    }

    private function update(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_books WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Livre introuvable.', 404);

        $allowed = ['title', 'author', 'phase', 'status', 'rating', 'start_date', 'end_date', 'summary', 'notes'];
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
        $db->prepare('UPDATE m_w_books SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?')
           ->execute($params);

        $this->show($user, $id);
    }

    private function delete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_books WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Livre introuvable.', 404);

        $db->prepare('DELETE FROM m_w_books WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Livre supprimé.']);
    }

    // ── Citations ──────────────────────────────────────────────────────────────

    private function quotesList(array $user, int $bookId): void
    {
        $this->assertBookOwner($user['id'], $bookId);

        $stmt = Database::get()->prepare(
            'SELECT id, content, source, created_at
             FROM m_w_book_quotes WHERE book_id = ? ORDER BY created_at ASC'
        );
        $stmt->execute([$bookId]);
        Response::json($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function quoteCreate(array $user, int $bookId, array $body): void
    {
        $this->assertBookOwner($user['id'], $bookId);

        if (empty($body['content'])) Response::error('Contenu de la citation requis.', 422);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO m_w_book_quotes (user_id, book_id, content, source)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $user['id'],
            $bookId,
            trim($body['content']),
            $body['source'] ?? null,
        ]);

        $id   = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM m_w_book_quotes WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    private function quoteDelete(array $user, int $id): void
    {
        $db   = Database::get();
        $stmt = $db->prepare('SELECT id FROM m_w_book_quotes WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Citation introuvable.', 404);

        $db->prepare('DELETE FROM m_w_book_quotes WHERE id = ?')->execute([$id]);
        Response::json(['message' => 'Citation supprimée.']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function assertBookOwner(int $userId, int $bookId): void
    {
        $stmt = Database::get()->prepare(
            'SELECT id FROM m_w_books WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$bookId, $userId]);
        if (!$stmt->fetch()) Response::error('Livre introuvable.', 404);
    }

    private function resource(): string
    {
        $uri      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $uri      = preg_replace('#^/api#', '', $uri) ?: '/';
        $segments = array_values(array_filter(explode('/', $uri)));
        return $segments[0] ?? 'books';
    }
}
