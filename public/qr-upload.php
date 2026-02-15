<?php
require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../config');
$dotenv->load();

$notionToken = getenv('NOTION_TOKEN');
if (!$notionToken) {
    die("FEHLER: NOTION_TOKEN nicht gefunden in config/.env\n");
}
echo "Token geladen: " . substr($notionToken, 0, 10) . "...\n";

$dbId = '11382138ece1494bafa3cd1bb47dda82';
$qrBaseUrl = 'https://as26.cool-camp.site/qr/';
$liveBaseUrl = 'https://as26.cool-camp.site/w/';

// Alle Seiten holen
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
        $pages[] = $page['id'];
    }
    $cursor = $resp['next_cursor'] ?? null;
} while ($resp['has_more'] ?? false);

echo count($pages) . " Workshops gefunden.\n\n";

if (count($pages) === 0) {
    die("Keine Seiten gefunden. Prüfe DB-ID und Integration-Zugriff.\n");
}

// File-Property updaten
$ok = 0;
$fail = 0;
foreach ($pages as $pageId) {
    $cleanId = str_replace('-', '', $pageId);
    $qrUrl = $qrBaseUrl . $cleanId . '.png';

    $liveUrl = $liveBaseUrl . $cleanId;

    $update = [
        'properties' => [
            'Feedback-QR' => [
                'files' => [[
                    'type' => 'external',
                    'name' => "QR-$cleanId.png",
                    'external' => ['url' => $qrUrl]
                ]]
            ],
            'Live-URL' => [
                'url' => $liveUrl
            ]
        ]
    ];

    $ch = curl_init("https://api.notion.com/v1/pages/$pageId");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $notionToken",
            "Notion-Version: 2022-06-28",
            "Content-Type: application/json",
        ],
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($update),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "✓ $cleanId\n";
        $ok++;
    } else {
        echo "✗ $cleanId (HTTP $httpCode)\n";
        $fail++;
    }
    usleep(350000);
}

echo "\nFertig! $ok OK, $fail Fehler.\n";
