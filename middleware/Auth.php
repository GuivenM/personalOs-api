<?php
declare(strict_types=1);

namespace Middleware;

use Helpers\JWT;
use Helpers\Response;
use Config\Database;

class Auth
{
    /**
     * Vérifie le JWT et retourne l'utilisateur depuis m_w_users.
     * Appel bloquant : coupe la requête avec 401 si invalide.
     *
     * @return array{id: int, username: string, created_at: string}
     */
    public static function check(): array
    {
        $token = self::extractToken();
        if (!$token) {
            Response::error('Non authentifié — token manquant.', 401);
        }

        try {
            $payload = JWT::decode($token);
        } catch (\RuntimeException $e) {
            Response::error('Token invalide : ' . $e->getMessage(), 401);
        }

        if (empty($payload['sub'])) {
            Response::error('Payload JWT invalide.', 401);
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT id, username, created_at FROM m_w_users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$payload['sub']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            Response::error('Utilisateur introuvable.', 401);
        }

        return $user;
    }

    // ------------------------------------------------------------------ //

    private static function extractToken(): ?string
    {
        // 1. Header standard (Nginx / PHP-FPM)
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        // 2. Apache via RewriteRule [E=HTTP_AUTHORIZATION:%1]
        $redirect = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $redirect, $m)) {
            return trim($m[1]);
        }

        // 3. Apache mod_fcgid
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    if (preg_match('/^Bearer\s+(.+)$/i', $value, $m)) {
                        return trim($m[1]);
                    }
                }
            }
        }

        // 4. Fallback cookie (optionnel)
        return $_COOKIE['mw_token'] ?? null;
    }
}