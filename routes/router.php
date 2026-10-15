<?php

declare(strict_types=1);

use Helpers\Response;

// ---------------------------------------------------------------
//  routes/router.php
//  Pattern : METHOD /api/resource[/:id][/:action]
// ---------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$uri    = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$uri    = parse_url($uri, PHP_URL_PATH);

$uri = '/' . trim($uri ?? '/', '/');

// Retirer le préfixe /api si présent
$uri = preg_replace('#^/api#', '', $uri) ?: '/';

// Décomposer en segments
$segments = array_values(array_filter(explode('/', $uri)));
$resource = $segments[0] ?? '';
$id       = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;
$action   = $id ? ($segments[2] ?? null) : ($segments[1] ?? null);

// Corps JSON de la requête
// Corps JSON de la requête
$body = [];
$raw  = file_get_contents('php://input');
if ($raw) {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) $body = $decoded;
}

// ---------------------------------------------------------------
//  Table de routage
// ---------------------------------------------------------------
$routes = [
    // Auth — GET pour /me, POST pour login/register/logout
    'auth'     => fn() => (new Controllers\AuthController)->dispatch($method, $id, $action, $body),

    // Schedule — config des blocs quotidiens
    'schedule' => fn() => (new Controllers\TemplateController)->dispatch($method, $id, $action, $body),

    // Blocks — logs quotidiens (statuts done/skipped/partial/pending)
    'blocks'   => fn() => (new Controllers\TemplateController)->dispatch($method, $id, $action, $body),

    // Dashboard — snapshot agrégé
    'dashboard'  => fn() => (new Controllers\DashboardController)->dispatch($method, $id, $action, $body),

    // Projets + tâches + jalons
    'projects'   => fn() => (new Controllers\ProjectController)->dispatch($method, $id, $action, $body),
    'tasks'      => fn() => (new Controllers\ProjectController)->dispatch($method, $id, $action, $body),
    'milestones' => fn() => (new Controllers\ProjectController)->dispatch($method, $id, $action, $body),

    // Livres + citations
    'books'  => fn() => (new Controllers\BookController)->dispatch($method, $id, $action, $body),
    'quotes' => fn() => (new Controllers\BookController)->dispatch($method, $id, $action, $body),

    // Santé
    'health' => fn() => (new Controllers\HealthController)->dispatch($method, $id, $action, $body),

    // Notes / Journal
    'notes'     => fn() => (new Controllers\JournalController)->dispatch($method, $id, $action, $body),
    'journal'   => fn() => (new Controllers\JournalController)->dispatch($method, $id, $action, $body),

    // Apprentissage / Learning
    'learning'  => fn() => (new Controllers\LearningController)->dispatch($method, $id, $action, $body),

    // Musique
    'music'     => fn() => (new Controllers\MusicController)->dispatch($method, $id, $action, $body),

    // Finaces
    'finances'  => fn() => (new Controllers\FinanceController)->dispatch($method, $id, $action, $body),

    // Spiritualité
    'spiritual' => fn() => (new Controllers\SpiritualController)->dispatch($method, $id, $action, $body),

    // Vision / objectifs
    'vision'    => fn() => (new Controllers\VisionController)->dispatch($method, $id, $action, $body),

    // Réseau / contacts
    'network'   => fn() => (new Controllers\NetworkController)->dispatch($method, $id, $action, $body),

    'recap' => fn() => (new Controllers\RecapController)->dispatch($method, $id, $action, $body),

    // Consistency — streaks blocs/lecture/spiritualité, séparés + fusionnés
    'consistency' => fn() => (new Controllers\ConsistencyController)->dispatch($method, $id, $action, $body),
];

if (!$resource || !array_key_exists($resource, $routes)) {
    Response::error("Route /$resource inconnue.", 404);
}

($routes[$resource])();
