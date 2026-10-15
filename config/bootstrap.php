<?php
declare(strict_types=1);

// ---------------------------------------------------------------
//  config/bootstrap.php
// ---------------------------------------------------------------

// Charger .env si présent
$envFile = ROOT . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
        $_ENV[trim($k)] = trim($v);
    }
}

$config = require ROOT . '/config/config.php';
define('CFG', $config);

// Autoload PSR-4 simple
spl_autoload_register(function (string $class): void {
    $map = [
        'Config\\'      => ROOT . '/config/',
        'Controllers\\' => ROOT . '/controllers/',
        'Models\\'      => ROOT . '/models/',
        'Middleware\\'  => ROOT . '/middleware/',
        'Helpers\\'     => ROOT . '/helpers/',
    ];
    foreach ($map as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) require $file;
            return;
        }
    }
});

// Gestion des erreurs
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

set_exception_handler(function (\Throwable $e): void {
    $debug = CFG['app']['debug'];
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $debug ? $e->getMessage() : 'Erreur serveur interne.',
        'trace'   => $debug ? $e->getTraceAsString() : null,
    ]);
    exit;
});

// Timezone
date_default_timezone_set('Africa/Porto-Novo');

// Headers CORS universels (avant tout routage)
$origin = CFG['app']['cors_origin'];
header("Access-Control-Allow-Origin: {$origin}");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
