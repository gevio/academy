<?php
// config/bootstrap.php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->load();

define('NOTION_TOKEN',       getenv('NOTION_TOKEN'));
define('NOTION_WORKSHOP_DB', getenv('NOTION_WORKSHOP_DB'));
define('NOTION_FEEDBACK_DB', getenv('NOTION_FEEDBACK_DB'));
define('NOTION_QA_DB',       getenv('NOTION_QA_DB'));
define('NOTION_AUSSTELLER_DB', getenv('NOTION_AUSSTELLER_DB') ?: '');
define('NOTION_REFERENTEN_DB', getenv('NOTION_REFERENTEN_DB') ?: '');
define('NOTION_REVIEW_DB',    getenv('NOTION_REVIEW_DB') ?: '');
define('NOTION_EMAIL_DB',     getenv('NOTION_EMAIL_DB') ?: '');
define('NOTION_REVIEW_TEMPLATE', getenv('NOTION_REVIEW_TEMPLATE') ?: '');
define('NOTION_EMAIL_TEMPLATE',  getenv('NOTION_EMAIL_TEMPLATE') ?: '');
define('ADMIN_SECRET',        getenv('ADMIN_SECRET') ?: '');
define('N8N_FEEDBACK_WEBHOOK', getenv('N8N_FEEDBACK_WEBHOOK') ?: '');
define('N8N_QA_WEBHOOK',       getenv('N8N_QA_WEBHOOK') ?: '');
define('N8N_UPVOTE_WEBHOOK',   getenv('N8N_UPVOTE_WEBHOOK') ?: '');
define('SITE_URL',           getenv('SITE_URL'));

/**
 * Webhook-First Helper: POST JSON an n8n Webhook.
 * Gibt true zurück bei HTTP 2xx, false bei Fehler/Timeout/leerem URL.
 * Timeout bewusst kurz (5s) – Besucher soll nicht warten.
 */
function postToWebhook(string $url, array $data): bool {
    if (empty($url)) return false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $result = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log("Webhook failed ({$code}): {$url} – " . substr($result, 0, 200));
        return false;
    }
    return true;
}

/**
 * Device-ID: Erzeugt oder liest eine persistente Geräte-ID (Cookie).
 * Wird für Spam-Schutz verwendet (1 Feedback/Workshop, 1 Upvote/Frage).
 * Cookie-Laufzeit: 1 Jahr. HttpOnly + Secure + SameSite=Lax.
 */
function getOrCreateDeviceId(): string {
    if (!empty($_COOKIE['as26_device_id'])) {
        return $_COOKIE['as26_device_id'];
    }
    $id = bin2hex(random_bytes(16));
    setcookie('as26_device_id', $id, [
        'expires'  => time() + 86400 * 365,
        'path'     => '/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['as26_device_id'] = $id;
    return $id;
}
