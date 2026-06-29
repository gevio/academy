<?php
/**
 * fix-duplicate-stands.php
 *
 * Einmalig-Skript: Findet Aussteller mit gleicher Standnummer,
 * bei denen ein Eintrag Koordinaten hat und andere nicht,
 * und kopiert die Koordinaten per Notion API auf die fehlenden Einträge.
 *
 * Usage:
 *   php cli/fix-duplicate-stands.php          # Dry-run (nur Ausgabe)
 *   php cli/fix-duplicate-stands.php --write  # Wirklich in Notion schreiben
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$dryRun = !in_array('--write', $argv ?? [], true);

if ($dryRun) {
    echo "=== DRY RUN – keine Änderungen in Notion ===\n";
    echo "Mit --write tatsächlich schreiben.\n\n";
} else {
    echo "=== WRITE MODE – schreibe in Notion ===\n\n";
}

$data = json_decode(file_get_contents(__DIR__ . '/../public/api/aussteller.json'), true);
if (!$data || empty($data['aussteller'])) {
    die("❌ aussteller.json nicht lesbar oder leer\n");
}

// Nach Standnummer gruppieren
$byStand = [];
foreach ($data['aussteller'] as $a) {
    if (!($a['stand'] ?? '')) continue;
    $byStand[$a['stand']][] = $a;
}

$notion = $dryRun ? null : new NotionClient(NOTION_TOKEN);

$updatedCount = 0;
$skippedCount = 0;
$noSourceCount = 0;

foreach ($byStand as $stand => $group) {
    if (count($group) <= 1) continue;

    // Quelle: erster Eintrag mit Koordinaten
    $source = null;
    foreach ($group as $a) {
        if ($a['stand_x'] !== null && $a['stand_y'] !== null) {
            $source = $a;
            break;
        }
    }

    if (!$source) {
        echo "⏭  $stand – alle ohne Koordinaten, übersprungen\n";
        $noSourceCount++;
        continue;
    }

    // Fehlende Einträge updaten
    $missing = array_filter($group, fn($a) => $a['stand_x'] === null || $a['stand_y'] === null);
    if (empty($missing)) {
        // Alle haben schon Koordinaten → nichts zu tun
        continue;
    }

    foreach ($missing as $a) {
        $firma = $a['firma'] ?? $a['page_id'];
        echo sprintf(
            "✏  %-20s  %-45s  ← von %-45s  (x=%.1f, y=%.1f)\n",
            $stand,
            substr($firma, 0, 45),
            substr($source['firma'] ?? '', 0, 45),
            $source['stand_x'],
            $source['stand_y']
        );

        if (!$dryRun) {
            $props = [
                'Stand_X' => ['number' => round((float)$source['stand_x'], 1)],
                'Stand_Y' => ['number' => round((float)$source['stand_y'], 1)],
                'Stand_W' => ['number' => $source['stand_w'] !== null ? round((float)$source['stand_w'], 1) : null],
                'Stand_H' => ['number' => $source['stand_h'] !== null ? round((float)$source['stand_h'], 1) : null],
            ];
            $ok = $notion->updatePage($a['page_id'], $props);
            if (!$ok) {
                echo "   ❌ Notion-Update fehlgeschlagen für {$a['page_id']}\n";
            }
            usleep(350000); // ~3 req/sec
        }
        $updatedCount++;
    }
}

echo "\n";
if ($dryRun) {
    echo "Dry-run: $updatedCount Einträge würden aktualisiert, $noSourceCount Stands ohne jede Koordinate.\n";
    echo "Zum Ausführen: php cli/fix-duplicate-stands.php --write\n";
} else {
    echo "✅ $updatedCount Einträge in Notion aktualisiert, $noSourceCount Stands ohne Koordinaten übersprungen.\n";
    echo "Bitte JSON neu generieren: php cli/generate-json.php --skip-images\n";
}
