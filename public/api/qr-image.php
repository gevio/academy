<?php
/**
 * GET /api/qr-image.php?data=<urlencoded>
 *
 * Rendert einen QR-Code als PNG fuer den uebergebenen Inhalt.
 * Gedacht fuer HTML-E-Mails (img src) und oeffentliche Links.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use chillerlan\QRCode\{QRCode, QROptions};

$data = trim((string)($_GET['data'] ?? ''));
if ($data === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing query parameter: data';
    exit;
}

// Schutz gegen sehr grosse Payloads.
if (strlen($data) > 800) {
    http_response_code(413);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Payload too large';
    exit;
}

$options = new QROptions([
    'outputType'  => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'    => QRCode::ECC_M,
    'scale'       => 8,
    'imageBase64' => false,
]);

try {
    $png = (new QRCode($options))->render($data);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR rendering failed';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo $png;
