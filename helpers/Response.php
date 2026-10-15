<?php
declare(strict_types=1);

namespace Helpers;

class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, array $details = []): never
    {
        $body = ['error' => $message];
        if ($details) $body['details'] = $details;
        self::json($body, $status);
    }
}