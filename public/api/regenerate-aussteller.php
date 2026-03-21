<?php
/**
 * POST /api/regenerate-aussteller.php
 *
 * Triggert die Neugenerierung von aussteller.json (ohne Bilder).
 * Wird von n8n nach Review-Übernahme aufgerufen.
 *
 * Auth: Header X-Admin-Secret muss mit ADMIN_SECRET übereinstimmen.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';

// Auth prüfen
$secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
if (!defined('ADMIN_SECRET') || $secret !== ADMIN_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// generate-aussteller-json.php als CLI-Prozess starten (--skip-images für Speed)
$script = realpath(__DIR__ . '/../../scripts/generate-aussteller-json.php');
if (!$script) {
    http_response_code(500);
    echo json_encode(['error' => 'Script not found']);
    exit;
}

$cmd = 'php ' . escapeshellarg($script) . ' --skip-images 2>&1';
$output = shell_exec($cmd);

echo json_encode([
    'ok'     => true,
    'output' => $output,
]);
