<?php
/**
 * legacy-redirect.php – Redirect alte URLs auf neue /w/{id}[/action] URLs
 *
 * Wird eingebunden, falls jemand direkt /index.php?id=X, /feedback.php?id=X etc. aufruft.
 * In Nginx: try_files $uri /legacy-redirect.php; (nur für die alten .php Pfade)
 *
 * Alternativ kann man das auch komplett über Nginx lösen:
 *   location = /index.php    { if ($arg_id ~* "^[a-f0-9]{32}$") { return 301 /w/$arg_id; } }
 *   location = /feedback.php { if ($arg_id ~* "^[a-f0-9]{32}$") { return 301 /w/$arg_id/feedback; } }
 *   location = /qa.php       { if ($arg_id ~* "^[a-f0-9]{32}$") { return 301 /w/$arg_id/qa; } }
 *   location = /wall.php     { if ($arg_id ~* "^[a-f0-9]{32}$") { return 301 /w/$arg_id/wall; } }
 */

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    http_response_code(404);
    exit('Seite nicht gefunden.');
}

$script = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');

$actionMap = [
    'index.php'    => '',
    'feedback.php' => '/feedback',
    'qa.php'       => '/qa',
    'wall.php'     => '/wall',
];

$action = $actionMap[$script] ?? '';
$newUrl = '/w/' . $id . $action;

// Query-Params beibehalten (außer id)
$params = $_GET;
unset($params['id']);
if ($params) {
    $newUrl .= '?' . http_build_query($params);
}

header('Location: ' . $newUrl, true, 301);
exit;
