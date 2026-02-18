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

$notion  = new NotionClient(NOTION_TOKEN);
$outFile = __DIR__ . '/../public/api/experten.json';
$wsFile  = __DIR__ . '/../public/api/workshops.json';

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

    // Firma aus den Aussteller-Verknüpfungen der Workshops ableiten
    $firmen = [];
    foreach ($workshops as $ws) {
        foreach ($ws['aussteller'] ?? [] as $a) {
            $f = $a['firma'] ?? '';
            if ($f && !in_array($f, $firmen)) $firmen[] = $f;
        }
    }
    $firma = implode(', ', $firmen);

    $result[] = [
        'id'        => $ref['id'],
        'name'      => $name,
        'vorname'   => $ref['vorname'],
        'nachname'  => $ref['nachname'],
        'foto'      => $ref['foto'],
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
