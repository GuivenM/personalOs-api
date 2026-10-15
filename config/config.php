<?php
// ---------------------------------------------------------------
//  config/config.php  —  Variables d'environnement
//  Renommer .env.example en .env et ne jamais committer .env
// ---------------------------------------------------------------
return [
    'db' => [
        'host'    => getenv('DB_HOST')    ?: 'localhost',
        'port'    => getenv('DB_PORT')    ?: '3306',
        'name'    => getenv('DB_NAME')    ?: 'hubspot',
        'user'    => getenv('DB_USER')    ?: 'root',
        'pass'    => getenv('DB_PASS')    ?: '',
        'charset' => 'utf8mb4',
    ],
    'jwt' => [
        'secret'  => getenv('JWT_SECRET') ?: 'change-this-secret-in-production',
        'ttl'     => (int)(getenv('JWT_TTL') ?: 86400), // secondes (24h)
        'issuer'  => getenv('APP_URL')    ?: 'https://hubspot.local',
    ],
    'app' => [
        'url'       => getenv('APP_URL')       ?: 'http://localhost',
        'env'       => getenv('APP_ENV')       ?: 'production',
        'debug'     => getenv('APP_DEBUG')     === 'true',
        'cors_origin' => getenv('CORS_ORIGIN') ?: 'http://localhost:5173',
    ],
    'upload' => [
        'path'     => getenv('UPLOAD_PATH') ?: ROOT . '/uploads',
        'max_size' => 5 * 1024 * 1024, // 5 Mo
        'allowed'  => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ],
    'gemini' => [
        'key' => getenv('GEMINI_API_KEY') ?: '',
    ],
];
