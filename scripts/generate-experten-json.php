<?php
/**
 * generate-experten-json.php – CLI-Script
 *
 * Lädt alle Referenten/Experten aus Notion, verknüpft sie mit den Workshops
 * aus workshops.json und schreibt /public/api/experten.json.
 *
 * Usage:  php scripts/generate-experten-json.php
 */

$t0 = microtime(true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$notion   = new NotionClient(NOTION_TOKEN);
$outFile  = __DIR__ . '/../public/api/experten.json';
$wsFile   = __DIR__ . '/../public/api/workshops.json';
$imgDir   = __DIR__ . '/../public/img/experten';
$imgUrl   = '/img/experten';               // relativer Web-Pfad

if (!is_dir($imgDir)) {
    mkdir($imgDir, 0755, true);
}

/**
 * Notion-Foto herunterladen, als WebP speichern und lokalen Pfad zurückgeben.
 * Gibt '' zurück, wenn kein Foto oder Download fehlschlägt.
 */
function downloadFoto(string $notionUrl, string $id, string $imgDir, string $imgUrl): string
{
    if (!$notionUrl) return '';

    $localFile = $imgDir . '/' . $id . '.webp';
    $localPath = $imgUrl . '/' . $id . '.webp';

    // Herunterladen
    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'ignore_errors' => true,
    ]]);
    $imgData = @file_get_contents($notionUrl, false, $ctx);
    if (!$imgData || strlen($imgData) < 100) {
        echo "   ⚠ Foto-Download fehlgeschlagen für {$id}\n";
        // Fallback: existierende Datei behalten
        return file_exists($localFile) ? $localPath : '';
    }

    // Mit GD laden und als WebP speichern (max 400px breit)
    $src = @imagecreatefromstring($imgData);
    if (!$src) {
        echo "   ⚠ Foto nicht lesbar für {$id}\n";
        return file_exists($localFile) ? $localPath : '';
    }

    $origW = imagesx($src);
    $origH = imagesy($src);
    $maxW  = 400;

    if ($origW > $maxW) {
        $newW = $maxW;
        $newH = (int) round($origH * ($maxW / $origW));
        $dst  = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);
        $src = $dst;
    }

    imagewebp($src, $localFile, 82);
    imagedestroy($src);

    $size = round(filesize($localFile) / 1024, 1);
    echo "   📸 Foto gespeichert: {$id}.webp ({$size} KB)\n";
    return $localPath;
}

if (!NOTION_REFERENTEN_DB) {
    die("❌ NOTION_REFERENTEN_DB nicht gesetzt. Bitte in .env eintragen.\n");
}

// ── 0) Workshops-Index laden (ID → Kurzinfo) ──
$workshopIndex = [];
if (file_exists($wsFile)) {
    $wsData = json_decode(file_get_contents($wsFile), true);
    foreach (($wsData['workshops'] ?? []) as $ws) {
        $workshopIndex[$ws['id']] = [
            'id'         => $ws['id'],
            'title'      => $ws['title'],
            'typ'        => $ws['typ'],
            'tag'        => $ws['tag'],
            'zeit'       => $ws['zeit'],
            'ort'        => $ws['ort'],
            'kategorien' => $ws['kategorien'] ?? [],
            'status'     => $ws['status'] ?? '',
            'aussteller' => $ws['aussteller'] ?? [],
        ];
    }
    echo "📋 " . count($workshopIndex) . " Workshops als Lookup geladen.\n\n";
} else {
    echo "⚠ workshops.json nicht gefunden. Workshop-Verknüpfung wird übersprungen.\n\n";
}

// ── 1) Alle Referenten laden ──
echo "📥 Referenten laden...\n";
$referenten = $notion->getAllReferenten(NOTION_REFERENTEN_DB);
echo "   " . count($referenten) . " Referenten gefunden.\n\n";

if (empty($referenten)) {
    die("❌ Keine Referenten in der DB. Abbruch.\n");
}

// ── 2) Filtern: nur Personen mit mindestens 1 Workshop behalten ──
//    und Workshops auflösen
echo "🔗 Workshops verknüpfen...\n";
$result = [];
$skipped = 0;

foreach ($referenten as $ref) {
    // Workshop-IDs aus Relation → gegen workshopIndex matchen
    // Nur Workshops mit Status "Referent bestätigt" einbeziehen
    $workshops = [];
    foreach ($ref['workshop_ids'] as $wsPageId) {
        $cleanId = str_replace('-', '', $wsPageId);
        if (isset($workshopIndex[$cleanId])) {
            $wsEntry = $workshopIndex[$cleanId];
            if (($wsEntry['status'] ?? '') === 'Referent bestätigt') {
                $workshops[] = $wsEntry;
            }
        }
    }

    // Nur Referenten mit mindestens 1 bestätigtem Workshop
    if (empty($workshops)) {
        $skipped++;
        continue;
    }

    $name = trim(($ref['vorname'] ?? '') . ' ' . ($ref['nachname'] ?? ''));
    if (!$name) $name = 'N.N.';

    // Alle Workshop-Kategorien sammeln (dedupliziert)
    $allKats = [];
    foreach ($workshops as $ws) {
        foreach ($ws['kategorien'] ?? [] as $k) {
            if (!in_array($k, $allKats)) $allKats[] = $k;
        }
    }
    sort($allKats);

    // Firma: nur aus Referenten-Funktion oder Bio ableiten (nicht aus Workshop-Ausstellern,
    // da diese dem Workshop gehören, nicht unbedingt dem einzelnen Referenten)
    $firma = '';

    // Foto lokal herunterladen (Notion-S3-URLs sind temporär ~1h)
    $fotoLocal = downloadFoto($ref['foto'], $ref['id'], $imgDir, $imgUrl);

    $result[] = [
        'id'        => $ref['id'],
        'name'      => $name,
        'vorname'   => $ref['vorname'],
        'nachname'  => $ref['nachname'],
        'foto'      => $fotoLocal,
        'bio'       => $ref['bio'],
        'funktion'  => $ref['funktion'],
        'kategorie' => $ref['kategorie'],
        'website'   => $ref['website'],
        'firma'     => $firma,
        'kategorien' => $allKats,
        'workshops' => $workshops,
    ];

    echo "   ✓ {$name} ({$ref['kategorie']}) – " . count($workshops) . " Workshop(s)\n";
}

// Alphabetisch nach Nachname sortieren
usort($result, function ($a, $b) {
    return strcasecmp($a['nachname'], $b['nachname']);
});

echo "\n   {$skipped} Referenten ohne Workshop übersprungen.\n";

// ── 3) JSON schreiben ──
$output = [
    'generated' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'count'     => count($result),
    'experten'  => $result,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($outFile, $json);

$size = round(strlen($json) / 1024, 1);
$elapsed = round(microtime(true) - $t0, 1);

echo "\n────────────────────────────────────\n";
echo "✅ {$outFile}\n";
echo "   {$size} KB, " . count($result) . " Experten, {$elapsed}s\n";
