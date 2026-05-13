<?php
/**
 * branding.php – Öffentliche Branding-Konfiguration als JSON.
 *
 * Liefert event-spezifische Werte aus config/.env an das Frontend.
 * Kein Auth nötig – alle Werte sind public.
 */
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

echo json_encode([
    'name'         => APP_NAME,
    'eventName'    => APP_EVENT_NAME,
    'eventShort'   => APP_EVENT_SHORT,
    'eventDate'    => APP_EVENT_DATE,
    'eventWebsite' => APP_EVENT_WEBSITE,
    'ticketUrl'    => APP_EVENT_TICKET_URL,
    'logo'         => APP_LOGO,
    'shareUrl'     => APP_SHARE_URL,
    'shareText'    => APP_SHARE_TEXT,
], JSON_UNESCAPED_UNICODE);
