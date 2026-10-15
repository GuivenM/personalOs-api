<?php

declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\Response;
use Middleware\Auth;

/**
 * GET /api/recap?month=YYYY-MM   → résumé mensuel généré par Claude
 */
class RecapController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        $user = Auth::check();

        match (true) {
            $method === 'GET' => $this->recap($user),
            default           => Response::error('Route recap inconnue.', 404),
        };
    }

    private function recap(array $user): void
    {
        $month = $_GET['month'] ?? date('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            Response::error('Format mois invalide (YYYY-MM).', 422);
        }

        // Mois précédent
        $dt       = new \DateTime($month . '-01');
        $dt->modify('-1 month');
        $prevMonth = $dt->format('Y-m');

        $data = $this->collectData($user['id'], $month);
        $prevData = $this->collectData($user['id'], $prevMonth);
        $text = $this->callClaude($data, $prevData, $month);

        Response::json([
            'month'  => $month,
            'recap'  => $text,
            'data'   => $data, // pour debug/affichage des chiffres
        ]);
    }

    // ── Collecte des données du mois ───────────────────────────────────────────

    private function collectData(int $userId, string $month): array
    {
        $db   = Database::get();
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));

        // Blocs
        $stmt = $db->prepare(
            'SELECT
                COUNT(*)                    AS total,
                SUM(l.status = "done")      AS done,
                SUM(l.status = "partial")   AS partial,
                SUM(l.status = "skipped")   AS skipped
            FROM m_w_template_logs l
            JOIN m_w_template_blocks b ON b.id = l.template_block_id
            JOIN m_w_week_templates t  ON t.id = b.template_id
            WHERE t.user_id = ? AND l.log_date BETWEEN ? AND ?'
        );
        $stmt->execute([$userId, $from, $to]);
        $blocks = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Streak max du mois
        $stmt = $db->prepare(
            'SELECT DISTINCT l.log_date
            FROM m_w_template_logs l
            JOIN m_w_template_blocks b ON b.id = l.template_block_id
            JOIN m_w_week_templates t  ON t.id = b.template_id
            WHERE t.user_id = ? AND l.status = "done"
            AND l.log_date BETWEEN ? AND ?
            ORDER BY l.log_date ASC'
        );
        $stmt->execute([$userId, $from, $to]);
        $doneDates  = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $maxStreak  = $this->computeMaxStreak($doneDates);

        // Livres terminés ce mois
        $stmt = $db->prepare(
            'SELECT title, author, phase FROM m_w_books
             WHERE user_id = ? AND status = "done"
               AND updated_at BETWEEN ? AND ?'
        );
        $stmt->execute([$userId, $from . ' 00:00:00', $to . ' 23:59:59']);
        $booksFinished = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Livres en cours
        $stmt = $db->prepare(
            'SELECT title, author FROM m_w_books WHERE user_id = ? AND status = "reading"'
        );
        $stmt->execute([$userId]);
        $booksReading = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Apprentissage
        $stmt = $db->prepare(
            'SELECT domain, SUM(duration_min) AS total_min, COUNT(*) AS sessions
             FROM m_w_learning_sessions
             WHERE user_id = ? AND session_date BETWEEN ? AND ?
             GROUP BY domain ORDER BY total_min DESC'
        );
        $stmt->execute([$userId, $from, $to]);
        $learning = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Musique
        $stmt = $db->prepare(
            'SELECT session_type, SUM(duration_min) AS total_min, COUNT(*) AS sessions
             FROM m_w_music_sessions
             WHERE user_id = ? AND session_date BETWEEN ? AND ?
             GROUP BY session_type ORDER BY total_min DESC'
        );
        $stmt->execute([$userId, $from, $to]);
        $music = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Projets actifs
        $stmt = $db->prepare(
            'SELECT name, domain, status FROM m_w_projects
             WHERE user_id = ? AND status IN ("active", "done")
             ORDER BY status = "done" DESC, updated_at DESC
             LIMIT 6'
        );
        $stmt->execute([$userId]);
        $projects = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Santé
        $stmt = $db->prepare(
            'SELECT
                COUNT(*)              AS days_logged,
                SUM(sleep_ok)         AS sleep_ok,
                SUM(exercise_ok)      AS exercise_ok,
                SUM(food_ok)          AS food_ok
             FROM m_w_health_logs
             WHERE user_id = ? AND log_date BETWEEN ? AND ?'
        );
        $stmt->execute([$userId, $from, $to]);
        $health = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Spiritualité
        $stmt = $db->prepare(
            'SELECT p.name, COUNT(*) AS days_done
             FROM m_w_spiritual_logs l
             JOIN m_w_spiritual_practices p ON p.id = l.practice_id
             WHERE l.user_id = ? AND l.done = 1
               AND l.log_date BETWEEN ? AND ?
             GROUP BY p.id ORDER BY days_done DESC'
        );
        $stmt->execute([$userId, $from, $to]);
        $spiritual = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Vision
        $stmt = $db->prepare('SELECT vision_text FROM m_w_user_settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $vision = $stmt->fetchColumn();

        return compact(
            'month',
            'blocks',
            'maxStreak',
            'booksFinished',
            'booksReading',
            'learning',
            'music',
            'projects',
            'health',
            'spiritual',
            'vision'
        );
    }

    // ── Appel API Anthropic ────────────────────────────────────────────────────

    private function callClaude(array $data, array $prevData, string $month): string
    {
        $key = CFG['gemini']['key'] ?? '';
        if (!$key) Response::error('Clé API Gemini non configurée.', 500);

        $prompt  = $this->buildPrompt($data, $prevData, $month);
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . $key;
        $payload = json_encode([
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 2048,
                'temperature'     => 0.7,
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $httpCode !== 200) {
            Response::error('Erreur API Gemini : HTTP ' . $httpCode . ' — ' . $response, 502);
        }

        $decoded = json_decode($response, true);
        return $decoded['candidates'][0]['content']['parts'][0]['text'] ?? 'Résumé indisponible.';
    }

    // ── Construction du prompt ─────────────────────────────────────────────────

    private function buildPrompt(array $d, array $prev, string $month): string
    {
        $monthLabel = $month;
        try {
            $dt = new \DateTime($month . '-01');
            $fmt = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
            $fmt->setPattern('MMMM yyyy');
            $monthLabel = $fmt->format($dt) ?: $month;
        } catch (\Throwable) {
            $monthLabel = $month;
        }

        $lines = [];

        // Vision
        if ($d['vision']) {
            $lines[] = "**Vision personnelle :** {$d['vision']}";
        }

        // Blocs
        $b = $d['blocks'];
        if ($b['total'] > 0) {
            $pct = round(($b['done'] / $b['total']) * 100);
            $lines[] = "**Blocs quotidiens :** {$b['done']}/{$b['total']} complétés ({$pct}%), streak max {$d['maxStreak']} jours";
        }

        // Livres
        if (!empty($d['booksFinished'])) {
            $titles = array_map(fn($b) => "{$b['title']} ({$b['author']})", $d['booksFinished']);
            $lines[] = "**Livres terminés :** " . implode(', ', $titles);
        }
        if (!empty($d['booksReading'])) {
            $titles = array_map(fn($b) => $b['title'], $d['booksReading']);
            $lines[] = "**En cours de lecture :** " . implode(', ', $titles);
        }

        // Apprentissage
        if (!empty($d['learning'])) {
            $parts = array_map(fn($l) => "{$l['domain']} ({$l['total_min']}min, {$l['sessions']} sessions)", $d['learning']);
            $lines[] = "**Apprentissage :** " . implode(' | ', $parts);
        }

        // Musique
        if (!empty($d['music'])) {
            $totalMin = array_sum(array_column($d['music'], 'total_min'));
            $parts    = array_map(fn($m) => "{$m['session_type']} ({$m['total_min']}min)", $d['music']);
            $lines[]  = "**Musique Zixe :** {$totalMin}min au total — " . implode(' | ', $parts);
        }

        // Projets
        if (!empty($d['projects'])) {
            $parts   = array_map(fn($p) => "{$p['name']} ({$p['status']})", $d['projects']);
            $lines[] = "**Projets :** " . implode(', ', $parts);
        }

        // Santé
        $h = $d['health'];
        if ($h['days_logged'] > 0) {
            $lines[] = "**Santé :** {$h['days_logged']} jours loggés — sommeil {$h['sleep_ok']}j, sport {$h['exercise_ok']}j, alimentation {$h['food_ok']}j";
        }

        // Spiritualité
        if (!empty($d['spiritual'])) {
            $parts   = array_map(fn($s) => "{$s['name']} ({$s['days_done']} jours)", $d['spiritual']);
            $lines[] = "**Pratiques spirituelles :** " . implode(' | ', $parts);
        }

        $prevLines = [];
        $pb = $prev['blocks'];
        if ($pb['total'] > 0) {
            $prevLines[] = "Blocs : {$pb['done']}/{$pb['total']} complétés, streak max {$prev['maxStreak']}j";
        }
        $prevTotalLearn = array_sum(array_column($prev['learning'], 'total_min'));
        if ($prevTotalLearn > 0) {
            $prevLines[] = "Apprentissage : {$prevTotalLearn}min";
        }

        $prevTotalMusic = array_sum(array_column($prev['music'], 'total_min'));
        if ($prevTotalMusic > 0) {
            $prevLines[] = "Musique : {$prevTotalMusic}min";
        }
        if (!empty($prev['booksFinished'])) {
            $prevLines[] = count($prev['booksFinished']) . " livre(s) terminé(s)";
        }

        $prevBlock = !empty($prevLines)
            ? "\n**Mois précédent (" . $prev['month'] . ") pour comparaison :**\n" . implode(' | ', $prevLines)
            : '';

        $dataBlock = implode("\n", $lines);

        return <<<PROMPT
Tu es l'assistant personnel de Zixe, il s'est fixé des objectif pour construire une bonne carrière professionnelle tout en se forgeant vers un avenir politique grâce à une discipline rigoureuse.

Voici ses données pour le mois de {$monthLabel} :

{$dataBlock}
{$prevBlock}

Génère un résumé mensuel personnel, chaleureux et motivant — style bilan de fin de mois entre un mentor et lui.
Ton style : direct, honnête, pas condescendant. Commence par une introduction très brève. Souligne ce qui a bien marché. Identifie ce qui stagne sans juger.
Termine par une phrase d'élan pour le mois suivant.

Si des données du mois précédent sont disponibles, fais une comparaison courte et honnête — ce qui a progressé, ce qui a reculé, sans dramatiser.

Les pourcentages de complétion des blocs quotidiens sont importants, ainsi que le streak max, car c'est la base de sa discipline. Les livres terminés sont aussi un gros point de fierté, surtout s'il y en a plusieurs. L'apprentissage et la musique sont des indicateurs clés de son développement personnel, donc souligne-les aussi.

Format : texte fluide en français, pas de bullet points, 3 paragraphes denses.
PROMPT;
    }

    // ── Calcul streak max ──────────────────────────────────────────────────────

    private function computeMaxStreak(array $dates): int
    {
        if (!$dates) return 0;

        $max     = 1;
        $current = 1;

        for ($i = 1; $i < count($dates); $i++) {
            $prev = new \DateTime($dates[$i - 1]);
            $curr = new \DateTime($dates[$i]);
            $diff = (int)$prev->diff($curr)->days;

            if ($diff === 1) {
                $current++;
                $max = max($max, $current);
            } else {
                $current = 1;
            }
        }

        return $max;
    }
}
