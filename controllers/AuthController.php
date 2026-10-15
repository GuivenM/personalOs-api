<?php
declare(strict_types=1);

namespace Controllers;

use Config\Database;
use Helpers\JWT;
use Helpers\Response;
use Middleware\Auth;

class AuthController
{
    public function dispatch(string $method, ?int $id, ?string $action, array $body): void
    {
        match (true) {
            $method === 'POST' && $action === 'login'    => $this->login($body),
            $method === 'POST' && $action === 'register' => $this->register($body),
            $method === 'POST' && $action === 'logout'   => $this->logout(),
            $method === 'GET'  && $action === 'me'       => $this->me(),
            default => Response::error('Action auth inconnue.', 404),
        };
    }

    // ------------------------------------------------------------------ //
    // POST /api/auth/login
    // ------------------------------------------------------------------ //
    private function login(array $b): void
    {
        $username = trim($b['username'] ?? '');
        $password = $b['password'] ?? '';

        if (!$username || !$password) {
            Response::error('Username et mot de passe requis.', 422);
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, username, password_hash FROM m_w_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('Identifiants incorrects.', 401);
        }

        $token = JWT::encode(['sub' => $user['id']]);

        Response::json([
            'token' => $token,
            'user'  => [
                'id'       => $user['id'],
                'username' => $user['username'],
            ],
        ]);
    }

    // ------------------------------------------------------------------ //
    // POST /api/auth/register
    // App privée : le premier compte est créé librement,
    // les suivants nécessitent un JWT valide (l'owner invitant).
    // ------------------------------------------------------------------ //
    private function register(array $b): void
    {
        $errors = [];
        $username = trim($b['username'] ?? '');
        $password = $b['password'] ?? '';

        if (!$username || strlen($username) < 3)       $errors[] = 'Username : 3 caractères minimum.';
        if (!$password || strlen($password) < 8)       $errors[] = 'Mot de passe : 8 caractères minimum.';
        if ($errors) Response::error('Données invalides.', 422, $errors);

        $db = Database::get();

        // Vérifier si un compte existe déjà (app mono-user ou multi)
        $count = (int)$db->query('SELECT COUNT(*) FROM m_w_users')->fetchColumn();

        // Si des comptes existent déjà, exiger un JWT valide
        if ($count > 0) {
            Auth::check(); // coupe la requête si non authentifié
        }

        // Username unique
        $stmt = $db->prepare('SELECT id FROM m_w_users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            Response::error('Ce username est déjà utilisé.', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('INSERT INTO m_w_users (username, password_hash) VALUES (?, ?)')
           ->execute([$username, $hash]);

        $userId = (int)$db->lastInsertId();

        // Initialiser les settings vides
        $db->prepare('INSERT INTO m_w_user_settings (user_id) VALUES (?)')
           ->execute([$userId]);

        // Seeder les 7 blocs par défaut
        $this->seedBlocks($db, $userId);

        $token = JWT::encode(['sub' => $userId]);

        Response::json([
            'message' => 'Compte créé.',
            'token'   => $token,
            'user'    => ['id' => $userId, 'username' => $username],
        ], 201);
    }

    // ------------------------------------------------------------------ //
    // POST /api/auth/logout  — JWT stateless : on informe juste le client
    // ------------------------------------------------------------------ //
    private function logout(): void
    {
        // Avec JWT stateless, la révocation côté serveur n'existe pas
        // sans table de blacklist. Pour cette app privée, on se contente
        // de confirmer : le client supprime son token localement.
        Response::json(['message' => 'Déconnecté. Supprimez votre token côté client.']);
    }

    // ------------------------------------------------------------------ //
    // GET /api/auth/me
    // ------------------------------------------------------------------ //
    private function me(): void
    {
        $user = Auth::check();

        // Charger les settings liés
        $db   = Database::get();
        $stmt = $db->prepare('SELECT vision_text FROM m_w_user_settings WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);

        Response::json([
            'id'          => $user['id'],
            'username'    => $user['username'],
            'created_at'  => $user['created_at'],
            'vision_text' => $settings['vision_text'] ?? null,
        ]);
    }

    // ------------------------------------------------------------------ //
    // Seed : 7 blocs quotidiens par défaut
    // ------------------------------------------------------------------ //
    private function seedBlocks(\PDO $db, int $userId): void
    {
        $blocks = [
            ['Réveil & Spiritualité',  'Liturgie, méditation, ancrage',      '06:00:00', '07:00:00', 1],
            ['Apprentissage',          'Code, IA, réseau',                    '07:00:00', '09:00:00', 2],
            ['Projet principal',       'Travail profond',                     '09:00:00', '12:00:00', 3],
            ['Pause & Lecture',        'Déjeuner + lecture 30 min',           '12:00:00', '13:30:00', 4],
            ['Projet secondaire',      'Web novel, musique, autre',           '13:30:00', '16:30:00', 5],
            ['Réseau & Admin',         'Contacts, finances, revue',           '16:30:00', '18:00:00', 6],
            ['Soirée & Recharge',      'Sport, famille, lecture libre',       '18:00:00', '22:00:00', 7],
        ];

        $stmt = $db->prepare(
            'INSERT INTO m_w_daily_blocks (user_id, name, description, time_start, time_end, order_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($blocks as $b) {
            $stmt->execute([$userId, ...$b]);
        }
    }
}