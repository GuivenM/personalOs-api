<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET    /api/schedule                  → blocs du jour (depuis le template actif)
 * GET    /api/schedule/week             → les 7 jours du template actif
 * GET    /api/schedule/history          → historique 30 jours
 * PUT    /api/schedule/{id}             → modifier un bloc du template
 * POST   /api/schedule/reorder          → réordonner les blocs d'un jour
 * POST   /api/schedule                  → créer un bloc (day_of_week, name, description, time_start, time_end)
 * DELETE /api/schedule/{id}             → supprimer un bloc (et ses logs)
 * POST   /api/schedule/copy-day         → copier un jour vers d'autres
 * POST   /api/schedule/import           → importer un planning (lignes de tableur)
 *                                         { rows: [[..]], mode: append|replace, dry_run: bool }
 *                                         { from_day, to_days: [..], mode: append|replace }
 *
 * POST   /api/blocks                    → initialiser les logs du jour
 * PUT    /api/blocks/{id}               → mettre à jour le statut d'un log
 */
class TemplateController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user     = Auth::check();
        $resource = $this->resource();

        match (true) {
            // ── /api/blocks ───────────────────────────────────────────────
            $resource === 'blocks' && $method === 'POST' && !$id  => $this->initDay($user, $body),
            $resource === 'blocks' && $method === 'PUT'  && $id   => $this->updateStatus($user, $id, $body),

            // ── /api/schedule ─────────────────────────────────────────────
            $resource === 'schedule' && $method === 'GET' && $action === 'week'    => $this->week($user),
            $resource === 'schedule' && $method === 'GET' && $action === 'history' => $this->history($user),
            $resource === 'schedule' && $method === 'GET' && !$id                  => $this->today($user),
            $resource === 'schedule' && $method === 'PUT' && $id                   => $this->updateBlock($user, $id, $body),
            $resource === 'schedule' && $method === 'POST' && $action === 'reorder'=> $this->reorder($user, $body),
            $resource === 'schedule' && $method === 'POST' && $action === 'copy-day' => $this->copyDay($user, $body),
            $resource === 'schedule' && $method === 'POST' && $action === 'import'   => $this->importSchedule($user, $body),
            $resource === 'schedule' && $method === 'POST' && !$id && !$action     => $this->createBlock($user, $body),
            $resource === 'schedule' && $method === 'DELETE' && $id                 => $this->deleteBlock($user, $id),

            default => Response::error('Route inconnue.', 404),
        };
    }

    // ── GET /api/schedule — blocs du jour ─────────────────────────────────────

    private function today(array $user): void
    {
        $date      = $_GET['date'] ?? date('Y-m-d');
        $dayOfWeek = $this->getDayOfWeek($date); // 0=Lundi, 6=Dimanche
        $db        = Database::get();

        $templateId = $this->getActiveTemplateId($user['id'], $db);
        if (!$templateId) {
            Response::json(['date' => $date, 'blocks' => []]);
        }

        $nowTime = date('H:i:s');

        $stmt = $db->prepare(
            'SELECT
                b.id           AS block_id,
                b.name,
                b.description,
                b.time_start,
                b.time_end,
                b.order_index,
                b.day_of_week,
                l.id           AS log_id,
                COALESCE(l.status, "pending") AS status
             FROM m_w_template_blocks b
             LEFT JOIN m_w_template_logs l
                    ON l.template_block_id = b.id AND l.log_date = ?
             WHERE b.template_id = ? AND b.day_of_week = ?
             ORDER BY b.order_index ASC'
        );
        $stmt->execute([$date, $templateId, $dayOfWeek]);
        $blocks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($blocks as &$b) {
            $b['is_current'] = $nowTime >= $b['time_start'] && $nowTime < $b['time_end'];
        }
        unset($b);

        Response::json([
            'date'       => $date,
            'day_of_week'=> $dayOfWeek,
            'blocks'     => $blocks,
        ]);
    }

    // ── GET /api/schedule/week — les 7 jours ──────────────────────────────────

    private function week(array $user): void
    {
        $db         = Database::get();
        $templateId = $this->getActiveTemplateId($user['id'], $db);

        if (!$templateId) {
            Response::json(['days' => []]);
        }

        $stmt = $db->prepare(
            'SELECT id AS block_id, day_of_week, name, description, time_start, time_end, order_index
             FROM m_w_template_blocks
             WHERE template_id = ?
             ORDER BY day_of_week ASC, order_index ASC'
        );
        $stmt->execute([$templateId]);
        $blocks = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $days = [];
        $dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

        for ($i = 0; $i <= 6; $i++) {
            $days[] = [
                'day_of_week' => $i,
                'day_name'    => $dayNames[$i],
                'blocks'      => array_values(array_filter($blocks, fn($b) => (int)$b['day_of_week'] === $i)),
            ];
        }

        Response::json([
            'template_id' => $templateId,
            'days'        => $days,
        ]);
    }

    // ── GET /api/schedule/history — historique 30 jours ───────────────────────

    private function history(array $user): void
    {
        $db = Database::get();

        $stmt = $db->prepare(
            'SELECT
                l.log_date,
                COUNT(*)                   AS total,
                SUM(l.status = "done")     AS done,
                SUM(l.status = "partial")  AS partial,
                SUM(l.status = "skipped")  AS skipped,
                SUM(l.status = "pending")  AS pending
             FROM m_w_template_logs l
             JOIN m_w_template_blocks b ON b.id = l.template_block_id
             JOIN m_w_week_templates t  ON t.id = b.template_id
             WHERE t.user_id = ?
               AND l.log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY l.log_date
             ORDER BY l.log_date DESC'
        );
        $stmt->execute([$user['id']]);
        $days = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $streak = $this->computeStreak($user['id'], $db);

        Response::json([
            'streak' => $streak,
            'days'   => $days,
        ]);
    }

    // ── PUT /api/schedule/{id} — modifier un bloc du template ─────────────────

    private function updateBlock(array $user, int $id, array $body): void
    {
        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT b.id FROM m_w_template_blocks b
             JOIN m_w_week_templates t ON t.id = b.template_id
             WHERE b.id = ? AND t.user_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) Response::error('Bloc introuvable.', 404);

        $allowed = ['name', 'description', 'time_start', 'time_end', 'order_index', 'day_of_week'];
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
        $db->prepare('UPDATE m_w_template_blocks SET ' . implode(', ', $fields) . ' WHERE id = ?')
           ->execute($params);

        $stmt = $db->prepare(
            'SELECT id AS block_id, day_of_week, name, description, time_start, time_end, order_index
             FROM m_w_template_blocks WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // ── POST /api/schedule/reorder ────────────────────────────────────────────

    private function reorder(array $user, array $body): void
    {
        $order = $body['order'] ?? [];
        if (!is_array($order) || !$order) Response::error('Tableau order requis.', 422);

        $db   = Database::get();
        $stmt = $db->prepare(
            'UPDATE m_w_template_blocks b
             JOIN m_w_week_templates t ON t.id = b.template_id
             SET b.order_index = ?
             WHERE b.id = ? AND t.user_id = ?'
        );

        foreach ($order as $index => $blockId) {
            $stmt->execute([$index + 1, (int)$blockId, $user['id']]);
        }

        $this->week($user);
    }


    // ── POST /api/schedule — créer un bloc ────────────────────────────────────

    private function createBlock(array $user, array $body): void
    {
        $dayOfWeek = $this->validDay($body['day_of_week'] ?? null);
        $name      = trim((string)($body['name'] ?? ''));
        $desc      = trim((string)($body['description'] ?? ''));
        $start     = $this->normalizeTime($body['time_start'] ?? null);
        $end       = $this->normalizeTime($body['time_end'] ?? null);

        $errors = [];
        if ($dayOfWeek === null) $errors[] = 'day_of_week doit être entre 0 (lundi) et 6 (dimanche).';
        if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) {
            $errors[] = 'name : 1 à 100 caractères.';
        }
        if ($start === null || $end === null) {
            $errors[] = 'time_start et time_end : format HH:MM ou HH:MM:SS.';
        } elseif ($start >= $end) {
            $errors[] = 'time_start doit être avant time_end.';
        }
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db         = Database::get();
        $templateId = $this->getActiveTemplateId($user['id'], $db);
        if (!$templateId) Response::error('Aucun template actif.', 422);

        $stmt = $db->prepare(
            'SELECT COALESCE(MAX(order_index), 0) FROM m_w_template_blocks
             WHERE template_id = ? AND day_of_week = ?'
        );
        $stmt->execute([$templateId, $dayOfWeek]);
        $order = (int)$stmt->fetchColumn() + 1;

        $db->prepare(
            'INSERT INTO m_w_template_blocks
                (template_id, day_of_week, name, description, time_start, time_end, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$templateId, $dayOfWeek, $name, $desc, $start, $end, $order]);

        $stmt = $db->prepare(
            'SELECT id AS block_id, day_of_week, name, description, time_start, time_end, order_index
             FROM m_w_template_blocks WHERE id = ? LIMIT 1'
        );
        $stmt->execute([(int)$db->lastInsertId()]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC), 201);
    }

    // ── DELETE /api/schedule/{id} — supprimer un bloc ─────────────────────────
    // ATTENTION : supprime aussi les logs de ce bloc (historique, streaks).

    private function deleteBlock(array $user, int $id): void
    {
        $db = Database::get();
        if (!$this->ownsBlock($user['id'], $id, $db)) Response::error('Bloc introuvable.', 404);

        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM m_w_template_logs WHERE template_block_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM m_w_template_blocks WHERE id = ?')->execute([$id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        Response::json(['deleted' => $id]);
    }

    // ── POST /api/schedule/copy-day — copier un jour vers d'autres ────────────
    // mode "append"  : ajoute les blocs copiés après ceux déjà présents (défaut)
    // mode "replace" : supprime d'abord les blocs du jour cible (et leurs logs)

    private function copyDay(array $user, array $body): void
    {
        $from = $this->validDay($body['from_day'] ?? null);
        $mode = $body['mode'] ?? 'append';
        $raw  = $body['to_days'] ?? null;

        $errors = [];
        if ($from === null) $errors[] = 'from_day doit être entre 0 (lundi) et 6 (dimanche).';
        if (!in_array($mode, ['append', 'replace'], true)) $errors[] = 'mode : append ou replace.';

        $targets = [];
        if (!is_array($raw) || !$raw) {
            $errors[] = 'to_days : tableau non vide requis.';
        } else {
            foreach ($raw as $d) {
                $day = $this->validDay($d);
                if ($day === null) { $errors[] = 'to_days contient un jour invalide.'; break; }
                if ($day !== $from) $targets[$day] = $day;
            }
            if (!$errors && !$targets) $errors[] = 'Le jour cible doit différer du jour source.';
        }
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db         = Database::get();
        $templateId = $this->getActiveTemplateId($user['id'], $db);
        if (!$templateId) Response::error('Aucun template actif.', 422);

        $stmt = $db->prepare(
            'SELECT name, description, time_start, time_end
             FROM m_w_template_blocks
             WHERE template_id = ? AND day_of_week = ?
             ORDER BY order_index ASC, id ASC'
        );
        $stmt->execute([$templateId, $from]);
        $source = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$source) Response::error('Le jour source ne contient aucun bloc.', 422);

        $db->beginTransaction();
        try {
            $delLogs = $db->prepare(
                'DELETE l FROM m_w_template_logs l
                 JOIN m_w_template_blocks b ON b.id = l.template_block_id
                 WHERE b.template_id = ? AND b.day_of_week = ?'
            );
            $delBlocks = $db->prepare(
                'DELETE FROM m_w_template_blocks WHERE template_id = ? AND day_of_week = ?'
            );
            $maxOrder = $db->prepare(
                'SELECT COALESCE(MAX(order_index), 0) FROM m_w_template_blocks
                 WHERE template_id = ? AND day_of_week = ?'
            );
            $insert = $db->prepare(
                'INSERT INTO m_w_template_blocks
                    (template_id, day_of_week, name, description, time_start, time_end, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($targets as $day) {
                if ($mode === 'replace') {
                    $delLogs->execute([$templateId, $day]);
                    $delBlocks->execute([$templateId, $day]);
                    $order = 0;
                } else {
                    $maxOrder->execute([$templateId, $day]);
                    $order = (int)$maxOrder->fetchColumn();
                }

                foreach ($source as $b) {
                    $insert->execute([
                        $templateId, $day, $b['name'], $b['description'],
                        $b['time_start'], $b['time_end'], ++$order,
                    ]);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $this->week($user); // renvoie la semaine à jour
    }


    // ── POST /api/schedule/import — importer un planning depuis un tableur ────
    // Colonnes (avec ou sans ligne d'en-tête) : day, start, end, name, description.
    // « day » accepte Mon / Monday / Lun / Lundi / 1-7, des listes (Mon/Wed/Fri),
    // des plages (Mon-Fri), et daily / weekdays / weekend (tous les jours, etc.).
    // mode "replace" ne remplace QUE les jours présents dans l'import.
    // dry_run=true : valide et résume sans rien écrire.

    private function importSchedule(array $user, array $body): void
    {
        $rows = $body['rows'] ?? null;
        $mode = $body['mode'] ?? 'append';
        $dry  = !empty($body['dry_run']);

        if (!is_array($rows) || !$rows) Response::error('rows : tableau non vide requis.', 422);
        if (count($rows) > 500) Response::error('Import limité à 500 lignes.', 422);
        if (!in_array($mode, ['append', 'replace'], true)) Response::error('mode : append ou replace.', 422);

        [$blocks, $errors] = $this->parseScheduleRows($rows);
        if ($errors) Response::error('Import invalide.', 422, array_slice($errors, 0, 20));
        if (!$blocks) Response::error('Aucun bloc trouvé dans l\'import.', 422);

        $db         = Database::get();
        $templateId = $this->getActiveTemplateId($user['id'], $db);
        if (!$templateId) Response::error('Aucun template actif.', 422);

        $byDay = [];
        foreach ($blocks as $b) $byDay[$b['day']][] = $b;
        ksort($byDay);

        $toDelete = 0;
        if ($mode === 'replace') {
            $count = $db->prepare(
                'SELECT COUNT(*) FROM m_w_template_blocks WHERE template_id = ? AND day_of_week = ?'
            );
            foreach (array_keys($byDay) as $day) {
                $count->execute([$templateId, $day]);
                $toDelete += (int)$count->fetchColumn();
            }
        }

        $summary = [
            'dry_run'          => $dry,
            'mode'             => $mode,
            'blocks_to_create' => count($blocks),
            'blocks_to_delete' => $toDelete,
            'days'             => (object)array_map('count', $byDay),   // objet JSON { "0": 5, "1": 4, … }
        ];
        if ($dry) Response::json($summary);

        $db->beginTransaction();
        try {
            $delLogs = $db->prepare(
                'DELETE l FROM m_w_template_logs l
                 JOIN m_w_template_blocks b ON b.id = l.template_block_id
                 WHERE b.template_id = ? AND b.day_of_week = ?'
            );
            $delBlocks = $db->prepare(
                'DELETE FROM m_w_template_blocks WHERE template_id = ? AND day_of_week = ?'
            );
            $maxOrder = $db->prepare(
                'SELECT COALESCE(MAX(order_index), 0) FROM m_w_template_blocks
                 WHERE template_id = ? AND day_of_week = ?'
            );
            $insert = $db->prepare(
                'INSERT INTO m_w_template_blocks
                    (template_id, day_of_week, name, description, time_start, time_end, order_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($byDay as $day => $dayBlocks) {
                if ($mode === 'replace') {
                    $delLogs->execute([$templateId, $day]);
                    $delBlocks->execute([$templateId, $day]);
                    $order = 0;
                } else {
                    $maxOrder->execute([$templateId, $day]);
                    $order = (int)$maxOrder->fetchColumn();
                }
                foreach ($dayBlocks as $b) {
                    $insert->execute([
                        $templateId, $day, $b['name'], $b['description'],
                        $b['start'], $b['end'], ++$order,
                    ]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }

        Response::json($summary);
    }

    /**
     * @param  array<int, mixed> $rows lignes brutes (tableaux de cellules)
     * @return array{0: array<int, array>, 1: string[]} [blocs, erreurs]
     */
    private function parseScheduleRows(array $rows): array
    {
        $aliases = [
            'day'         => ['day', 'days', 'jour', 'jours'],
            'start'       => ['start', 'begin', 'from', 'debut', 'début', 'time_start', 'start_time', 'heure_debut'],
            'end'         => ['end', 'fin', 'to', 'until', 'time_end', 'end_time', 'heure_fin'],
            'name'        => ['name', 'nom', 'title', 'titre', 'block', 'bloc', 'activity', 'activite', 'activité'],
            'description' => ['description', 'desc', 'notes', 'note'],
        ];
        $map = ['day' => 0, 'start' => 1, 'end' => 2, 'name' => 3, 'description' => 4];

        // Normalise : chaque ligne → tableau de cellules trimées ; les lignes vides sont ignorées
        $clean = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) return [[], ['Ligne ' . ($i + 1) . ' : doit être un tableau de cellules.']];
            $cells = array_map(fn($c) => trim((string)$c), array_values($row));
            if (implode('', $cells) === '') continue;
            $clean[] = ['n' => $i + 1, 'cells' => $cells];
        }
        if (!$clean) return [[], []];

        // En-tête ? (au moins une cellule reconnue comme nom de colonne)
        $first = array_map('strtolower', $clean[0]['cells']);
        $found = [];
        foreach ($first as $idx => $cell) {
            foreach ($aliases as $key => $names) {
                if (in_array($cell, $names, true)) $found[$key] = $idx;
            }
        }
        if ($found) {
            $missing = array_diff(['day', 'start', 'end', 'name'], array_keys($found));
            if ($missing) {
                return [[], ['En-tête : colonne(s) manquante(s) : ' . implode(', ', $missing) . '.']];
            }
            $map = $found + ['description' => -1];
            array_shift($clean);
        }

        $blocks = [];
        $errors = [];
        foreach ($clean as $r) {
            $c   = $r['cells'];
            $n   = $r['n'];
            $get = fn(string $k) => ($map[$k] >= 0 && isset($c[$map[$k]])) ? $c[$map[$k]] : '';

            $days  = $this->parseDays($get('day'));
            $start = $this->parseFlexibleTime($get('start'));
            $end   = $this->parseFlexibleTime($get('end'));
            $name  = $get('name');
            $desc  = $get('description');

            $rowErrors = [];
            if ($days === null)  $rowErrors[] = 'jour invalide « ' . $get('day') . ' »';
            if ($start === null) $rowErrors[] = 'heure de début invalide « ' . $get('start') . ' »';
            if ($end === null)   $rowErrors[] = 'heure de fin invalide « ' . $get('end') . ' »';
            if ($start !== null && $end !== null && $start >= $end) $rowErrors[] = 'la fin doit être après le début';
            if ($name === '') {
                $rowErrors[] = 'nom manquant';
            } elseif ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) {
                $rowErrors[] = 'nom trop long (100 max)';
            }

            if ($rowErrors) {
                $errors[] = "Ligne $n : " . implode(', ', $rowErrors) . '.';
                continue;
            }
            foreach ($days as $day) {
                $blocks[] = ['day' => $day, 'start' => $start, 'end' => $end, 'name' => $name, 'description' => $desc];
            }
        }

        // Ordre d'insertion : par heure de début au sein de chaque jour
        usort($blocks, fn($a, $b) => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);
        return [$blocks, $errors];
    }

    /** "Mon-Fri", "Lun/Mer/Ven", "daily", "weekdays", "1-5"… → [0..6] ou null. */
    private function parseDays(string $raw): ?array
    {
        static $names = [
            'mon' => 0, 'monday' => 0, 'lun' => 0, 'lundi' => 0, '1' => 0,
            'tue' => 1, 'tues' => 1, 'tuesday' => 1, 'mar' => 1, 'mardi' => 1, '2' => 1,
            'wed' => 2, 'wednesday' => 2, 'mer' => 2, 'mercredi' => 2, '3' => 2,
            'thu' => 3, 'thur' => 3, 'thurs' => 3, 'thursday' => 3, 'jeu' => 3, 'jeudi' => 3, '4' => 3,
            'fri' => 4, 'friday' => 4, 'ven' => 4, 'vendredi' => 4, '5' => 4,
            'sat' => 5, 'saturday' => 5, 'sam' => 5, 'samedi' => 5, '6' => 5,
            'sun' => 6, 'sunday' => 6, 'dim' => 6, 'dimanche' => 6, '7' => 6,
        ];
        static $groups = [
            'daily' => [0, 1, 2, 3, 4, 5, 6], 'everyday' => [0, 1, 2, 3, 4, 5, 6], 'alldays' => [0, 1, 2, 3, 4, 5, 6],
            'all' => [0, 1, 2, 3, 4, 5, 6], 'touslesjours' => [0, 1, 2, 3, 4, 5, 6], 'tous' => [0, 1, 2, 3, 4, 5, 6],
            'weekdays' => [0, 1, 2, 3, 4], 'weekday' => [0, 1, 2, 3, 4], 'semaine' => [0, 1, 2, 3, 4],
            'weekend' => [5, 6], 'weekends' => [5, 6],
        ];

        $s = strtolower(trim($raw));
        if ($s === '') return null;

        $compact = str_replace([' ', '-', '_', '.'], '', $s);
        if (isset($groups[$compact])) return $groups[$compact];

        $out = [];
        foreach (preg_split('/[\/;,&+\s]+/', str_replace('.', '', $s), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $parts = preg_split('/[-–—]/', $token, -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) === 1) {
                if (!isset($names[$parts[0]])) return null;
                $out[$names[$parts[0]]] = true;
            } elseif (count($parts) === 2) {
                if (!isset($names[$parts[0]], $names[$parts[1]])) return null;
                $a = $names[$parts[0]];
                $b = $names[$parts[1]];
                for ($d = $a;; $d = ($d + 1) % 7) {   // plage, avec retour possible (Fri-Mon)
                    $out[$d] = true;
                    if ($d === $b) break;
                }
            } else {
                return null;
            }
        }
        if (!$out) return null;
        $days = array_keys($out);
        sort($days);
        return $days;
    }

    /** "6:30", "06:30:00", "6h30", "6h", "6:30 pm", "6pm" → "HH:MM:SS" ou null. */
    private function parseFlexibleTime(string $raw): ?string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('/^(\d{1,2})h$/', '$1:00', $s);
        if (!preg_match('/^(\d{1,2})(?:[:h.](\d{2}))?(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?$/', (string)$s, $m)) return null;

        $h   = (int)$m[1];
        $min = (int)($m[2] ?? 0);
        $sec = (int)($m[3] ?? 0);
        $ap  = $m[4] ?? '';
        if ($ap !== '') {
            if ($h < 1 || $h > 12) return null;
            $h = $h % 12 + ($ap[0] === 'p' ? 12 : 0);
        }
        if ($h > 23 || $min > 59 || $sec > 59) return null;
        return sprintf('%02d:%02d:%02d', $h, $min, $sec);
    }

    // ── POST /api/blocks — initialiser les logs du jour ───────────────────────

    private function initDay(array $user, array $body): void
    {
        $date      = $body['date'] ?? date('Y-m-d');
        $dayOfWeek = $this->getDayOfWeek($date);
        $db        = Database::get();

        $templateId = $this->getActiveTemplateId($user['id'], $db);
        if (!$templateId) Response::error('Aucun template actif.', 422);

        $stmt = $db->prepare(
            'SELECT id FROM m_w_template_blocks
             WHERE template_id = ? AND day_of_week = ?
             ORDER BY order_index ASC'
        );
        $stmt->execute([$templateId, $dayOfWeek]);
        $blockIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!$blockIds) Response::error('Aucun bloc pour ce jour.', 422);

        $stmt = $db->prepare(
            'INSERT IGNORE INTO m_w_template_logs (user_id, template_block_id, log_date, status)
             VALUES (?, ?, ?, "pending")'
        );
        foreach ($blockIds as $blockId) {
            $stmt->execute([$user['id'], $blockId, $date]);
        }

        // Retourner l'état du jour
        $_GET['date'] = $date;
        $this->today($user);
    }

    // ── PUT /api/blocks/{id} — mettre à jour le statut ────────────────────────

    private function updateStatus(array $user, int $logId, array $body): void
    {
        $validStatuses = ['done', 'skipped', 'partial', 'pending'];
        $status = $body['status'] ?? '';

        if (!in_array($status, $validStatuses, true)) {
            Response::error('Statut invalide.', 422);
        }

        $db   = Database::get();
        $stmt = $db->prepare(
            'SELECT l.id FROM m_w_template_logs l
             JOIN m_w_template_blocks b ON b.id = l.template_block_id
             JOIN m_w_week_templates t  ON t.id = b.template_id
             WHERE l.id = ? AND t.user_id = ? LIMIT 1'
        );
        $stmt->execute([$logId, $user['id']]);
        if (!$stmt->fetch()) Response::error('Log introuvable.', 404);

        $db->prepare('UPDATE m_w_template_logs SET status = ? WHERE id = ?')
           ->execute([$status, $logId]);

        $stmt = $db->prepare(
            'SELECT l.id, l.template_block_id AS block_id, l.log_date, l.status,
                    b.name, b.time_start, b.time_end
             FROM m_w_template_logs l
             JOIN m_w_template_blocks b ON b.id = l.template_block_id
             WHERE l.id = ? LIMIT 1'
        );
        $stmt->execute([$logId]);
        Response::json($stmt->fetch(\PDO::FETCH_ASSOC));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function getActiveTemplateId(int $userId, \PDO $db): ?int
    {
        $stmt = $db->prepare(
            'SELECT id FROM m_w_week_templates WHERE user_id = ? AND active = 1 LIMIT 1'
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function ownsBlock(int $userId, int $blockId, \PDO $db): bool
    {
        $stmt = $db->prepare(
            'SELECT b.id FROM m_w_template_blocks b
             JOIN m_w_week_templates t ON t.id = b.template_id
             WHERE b.id = ? AND t.user_id = ? LIMIT 1'
        );
        $stmt->execute([$blockId, $userId]);
        return (bool)$stmt->fetch();
    }

    /** 0 (lundi) … 6 (dimanche), sinon null. */
    private function validDay(mixed $v): ?int
    {
        if (is_bool($v) || !is_numeric($v)) return null;
        $n = (int)$v;
        return ((string)$n === (string)$v || (float)$v === (float)$n) && $n >= 0 && $n <= 6 ? $n : null;
    }

    /** "HH:MM" ou "HH:MM:SS" → "HH:MM:SS", sinon null. */
    private function normalizeTime(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($v), $m)) return null;
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    private function getDayOfWeek(string $date): int
    {
        // 0=Lundi, 6=Dimanche (PHP: 1=Lundi via N)
        return (int)date('N', strtotime($date)) - 1;
    }

    private function computeStreak(int $userId, \PDO $db): int
    {
        $stmt = $db->prepare(
            'SELECT DISTINCT l.log_date
             FROM m_w_template_logs l
             JOIN m_w_template_blocks b ON b.id = l.template_block_id
             JOIN m_w_week_templates t  ON t.id = b.template_id
             WHERE t.user_id = ? AND l.status = "done"
             ORDER BY l.log_date DESC
             LIMIT 60'
        );
        $stmt->execute([$userId]);
        $dates = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!$dates) return 0;

        $streak   = 0;
        $expected = new \DateTime('today');

        foreach ($dates as $dateStr) {
            $d = new \DateTime($dateStr);
            if ($streak === 0 && $d < (clone $expected)->modify('-1 day')) break;
            if ($d->format('Y-m-d') === $expected->format('Y-m-d')) {
                $streak++;
                $expected->modify('-1 day');
            } else {
                break;
            }
        }

        return $streak;
    }

    private function resource(): string
    {
        $uri      = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $uri      = preg_replace('#^/api#', '', $uri) ?: '/';
        $segments = array_values(array_filter(explode('/', $uri)));
        return $segments[0] ?? '';
    }
}
