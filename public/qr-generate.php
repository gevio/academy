<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../config');
$dotenv->load();

use chillerlan\QRCode\{QRCode, QROptions};

$notionToken = getenv('NOTION_TOKEN');
if (!$notionToken) {
    die("FEHLER: NOTION_TOKEN nicht gefunden in config/.env\n");
}
echo "Token geladen: " . substr($notionToken, 0, 10) . "...\n";

$options = new QROptions([
    'outputType'    => QRCode::OUTPUT_IMAGE_PNG,
    'eccLevel'      => QRCode::ECC_L,
    'scale'         => 10,
    'imageBase64'   => false,
]);

$dbId = '11382138ece1494bafa3cd1bb47dda82';
$baseUrl = 'https://agenda.adventuresouthside.com/w/';
$outDir = __DIR__ . '/qr';

if (!is_dir($outDir)) mkdir($outDir, 0755, true);

// Notion DB abfragen
$pages = [];
$cursor = null;
do {
    $body = ['page_size' => 100];
    if ($cursor) $body['start_cursor'] = $cursor;

    $ch = curl_init("https://api.notion.com/v1/databases/$dbId/query");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $notionToken",
            "Notion-Version: 2022-06-28",
            "Content-Type: application/json",
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        die("DB-Query fehlgeschlagen (HTTP $code): $raw\n");
    }

    $resp = json_decode($raw, true);
    foreach ($resp['results'] ?? [] as $page) {
        $id = str_replace('-', '', $page['id']);
        $pages[] = $id;
    }
    $cursor = $resp['next_cursor'] ?? null;
} while ($resp['has_more'] ?? false);

echo count($pages) . " Workshops gefunden.\n\n";

if (count($pages) === 0) {
    die("Keine Seiten gefunden.\n");
}

// QR-Codes generieren
$qr = new QRCode($options);
$ok = 0;
$skip = 0;
foreach ($pages as $id) {
    // Nur gültige 32-Hex-IDs verarbeiten
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
        echo "⚠ SKIP ungültige ID: $id (" . strlen($id) . " Zeichen)\n";
        $skip++;
        continue;
    }

    $url = $baseUrl . $id;

    // Sicherheitscheck: URL darf max ~200 Zeichen haben
    if (strlen($url) > 200) {
        echo "⚠ SKIP zu lange URL ($url): " . strlen($url) . " Zeichen\n";
        $skip++;
        continue;
    }

    try {
        $qr = new QRCode($options); $png = $qr->render($url);
        file_put_contents("$outDir/$id.png", $png);
        echo "✓ $id.png\n";
        $ok++;
    } catch (\Exception $e) {
        echo "✗ $id: " . $e->getMessage() . "\n";
        echo "  URL war: $url (" . strlen($url) . " Zeichen)\n";
        $skip++;
    }
}

echo "\nFertig! $ok OK, $skip übersprungen.\n";
