<?php
declare(strict_types=1);

namespace Helpers;

class JWT
{
    private static function key(): string
    {
        $k = CFG['jwt']['secret'] ?? '';
        if (!$k) throw new \RuntimeException('JWT secret non configuré.');
        return $k;
    }

    public static function encode(array $payload): string
    {
        $ttl = (int)(CFG['jwt']['ttl'] ?? 86400);

        $payload['iat'] = time();
        $payload['exp'] = time() + $ttl;

        $header  = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body    = self::b64(json_encode($payload));
        $sig     = self::b64(hash_hmac('sha256', "$header.$body", self::key(), true));

        return "$header.$body.$sig";
    }

    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new \RuntimeException('Format JWT invalide.');

        [$header, $body, $sig] = $parts;

        $expected = self::b64(hash_hmac('sha256', "$header.$body", self::key(), true));
        if (!hash_equals($expected, $sig)) {
            throw new \RuntimeException('Signature invalide.');
        }

        $payload = json_decode(self::b64d($body), true);
        if (!$payload) throw new \RuntimeException('Payload illisible.');

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new \RuntimeException('Token expiré.');
        }

        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64d(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}