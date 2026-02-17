<?php
/**
 * generate-workshops-json.php – CLI-Script
 *
 * Lädt alle Workshops aus Notion (nur Fr/Sa/So), rendert Page-Content zu HTML
 * und schreibt /public/api/workshops.json. 0 API-Calls pro Besucher danach.
 *
 * Usage:  php scripts/generate-workshops-json.php
 */

$t0 = microtime(true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';
require_once __DIR__ . '/../src/BlockRenderer.php';

$notion   = new NotionClient(NOTION_TOKEN);
$renderer = new BlockRenderer();
$outFile  = __DIR__ . '/../public/api/workshops.json';
$ausstellerFile = __DIR__ . '/../public/api/aussteller.json';

// ── 0) Aussteller-Daten laden (für Workshop↔Aussteller Verlinkung) ──
$ausstellerIndex = []; // page_id (mit Bindestrichen) → Aussteller-Daten
try {
    $ausstellerRaw = json_decode(file_get_contents($ausstellerFile), true);
    foreach (($ausstellerRaw['aussteller'] ?? []) as $a) {
        if (!empty($a['page_id'])) {
            $ausstellerIndex[$a['page_id']] = $a;
            // Auch ohne Bindestriche indizieren
            $ausstellerIndex[str_replace('-', '', $a['page_id'])] = $a;
        }
    }
    echo "📍 " . count($ausstellerRaw['aussteller'] ?? []) . " Aussteller als Lookup geladen.\n\n";
} catch (Throwable $e) {
    echo "⚠ aussteller.json nicht verfügbar: {$e->getMessage()}\n";
    echo "  Workshop↔Aussteller Verlinkung wird übersprungen.\n\n";
}

// ── 1) Alle Workshops laden (nur Messetage) ──────────────
echo "📥 Workshops laden...\n";
$workshops = $notion->getAllWorkshops(
    NOTION_WORKSHOP_DB,
    ['Freitag', 'Samstag', 'Sonntag']
);
echo "   " . count($workshops) . " Workshops gefunden.\n\n";

if (empty($workshops)) {
    // Kein Tag-Filter? Versuche ohne Filter
    echo "⚠ Keine Workshops mit Tag-Filter gefunden. Lade alle...\n";
    $workshops = $notion->getAllWorkshops(NOTION_WORKSHOP_DB);
    echo "   " . count($workshops) . " Workshops gefunden.\n\n";
}

if (empty($workshops)) {
    die("❌ Keine Workshops in der DB. Abbruch.\n");
}

// ── 1b) Referent-Relationen auflösen ─────────────────────
echo "👥 Referent-Relationen auflösen...\n";
$personCache = []; // pageId → ['vorname' => ..., 'nachname' => ..., 'firma_ids' => [...]]
$firmaCache  = []; // pageId → title (Firmenname)

// Alle unique Person-IDs sammeln
$allPersonIds = [];
foreach ($workshops as $ws) {
    foreach (($ws['referent_person_ids'] ?? []) as $id) $allPersonIds[$id] = true;
}

// Personen auflösen (Vorname, Nachname, Firma-Relation)
$personCount = count($allPersonIds);
echo "   {$personCount} Personen aufzulösen...\n";
$firmaIdsToResolve = [];
foreach (array_keys($allPersonIds) as $personId) {
    usleep(350000);
    $personData = $notion->getReferentPerson($personId);
    $personCache[$personId] = $personData;
    foreach ($personData['firma_ids'] as $fId) {
        $firmaIdsToResolve[$fId] = true;
    }
    $name = trim(($personData['vorname'] ?? '') . ' ' . ($personData['nachname'] ?? ''));
    echo "   ✓ {$name}\n";
}

// Firmen aus Workshop-DB + Firmen aus Person-Relationen auflösen
foreach ($workshops as $ws) {
    foreach (($ws['referent_firma_ids'] ?? []) as $id) $firmaIdsToResolve[$id] = true;
}

$firmaCount = count($firmaIdsToResolve);
echo "   {$firmaCount} Firmen aufzulösen...\n";
foreach (array_keys($firmaIdsToResolve) as $firmaId) {
    usleep(350000);
    $firmaCache[$firmaId] = $notion->getPageTitle($firmaId);
    echo "   ✓ {$firmaCache[$firmaId]}\n";
}
echo "   Referenten fertig.\n\n";

// ── 2) Page-Content (Blocks) für jeden Workshop laden ────
echo "📄 Page-Content laden & rendern...\n";
$result = [];
$ok = 0;
$noContent = 0;

foreach ($workshops as $ws) {
    $pageId = $ws['page_id'];
    $cleanId = $ws['id'];

    // Referent-Personen: "Vorname Nachname" oder "N.N."
    $personNames = [];
    foreach (($ws['referent_person_ids'] ?? []) as $pId) {
        $p = $personCache[$pId] ?? [];
        $name = trim(($p['vorname'] ?? '') . ' ' . ($p['nachname'] ?? ''));
        $personNames[] = $name ?: 'N.N.';
    }

    // Firmen: direkt aus Workshop-Relation ODER aus Person→Firma
    $firmaNames = [];
    // Zuerst direkte Firma-Relation am Workshop
    foreach (($ws['referent_firma_ids'] ?? []) as $fId) {
        $firmaNames[] = $firmaCache[$fId] ?? '';
    }
    // Falls keine direkte Firma: aus den Person-Relationen ziehen
    if (empty(array_filter($firmaNames))) {
        foreach (($ws['referent_person_ids'] ?? []) as $pId) {
            foreach (($personCache[$pId]['firma_ids'] ?? []) as $fId) {
                $firmaNames[] = $firmaCache[$fId] ?? '';
            }
        }
    }

    $ws['referent_firma']  = implode(', ', array_unique(array_filter($firmaNames)));
    $ws['referent_person'] = implode(', ', array_filter($personNames));

    echo "   {$cleanId} ";

    // Blocks laden (mit Rate-Limit)
    usleep(350000); // 350ms → ~2.8 req/s (unter Notion-Limit von 3/s)
    $blocks = $notion->getPageBlocks($pageId);

    // ── Redundante Meta-Blöcke filtern ──────────────────
    // Notion-Pages enthalten oft oben: Titel-Wiederholung, "Veranstaltungsdetails",
    // Termin/Ort/Format – das zeigen wir bereits im Workshop-Card.
    $blocks = filterRedundantBlocks($blocks, $ws['title']);

    $contentHtml = '';
    $hasContent = false;

    if (!empty($blocks)) {
        $contentHtml = $renderer->render($blocks);
        $hasContent = !empty(trim(strip_tags($contentHtml)));
    }

    $result[] = [
        'id'           => $cleanId,
        'title'        => $ws['title'],
        'typ'          => $ws['typ'],
        'tag'          => $ws['tag'],
        'zeit'         => $ws['zeit'],
        'ort'          => $ws['ort'],
        'beschreibung' => $ws['beschreibung'],
        'datum_start'  => $ws['datum_start'],
        'kategorien'   => $ws['kategorien'] ?? [],
        'referent_firma'  => $ws['referent_firma'] ?? '',
        'referent_person' => $ws['referent_person'] ?? '',
        'aussteller'   => resolveAussteller($ws['aussteller_ids'] ?? [], $ausstellerIndex),
        'content_html' => $contentHtml,
        'has_content'  => $hasContent,
    ];

    echo($hasContent ? "✓" : "○") . " (" . strlen($contentHtml) . " bytes)\n";
    $hasContent ? $ok++ : $noContent++;
}

// ── 3) JSON schreiben ────────────────────────────────────
$output = [
    'generated' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'count'     => count($result),
    'workshops' => $result,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($outFile, $json);

$size = round(strlen($json) / 1024, 1);
$elapsed = round(microtime(true) - $t0, 1);

echo "\n────────────────────────────────────\n";
echo "✅ {$outFile}\n";
echo "   {$ok} mit Content, {$noContent} ohne Content\n";
echo "   {$size} KB, {$elapsed}s\n";

// ══════════════════════════════════════════════════════════
// Hilfsfunktionen
// ══════════════════════════════════════════════════════════

/**
 * Entfernt redundante Meta-Blöcke am Anfang einer Notion-Page.
 *
 * Typisches Muster in den Workshop-Pages:
 *   🅱️ Workshop: <Titel>           ← Titel-Wiederholung
 *   Veranstaltungsdetails           ← Heading
 *   📅 Termin: 10.–12. Juli 2026   ← Meta
 *   📍 Ort: Selbstausbauer Academy  ← Meta
 *   🎯 Format: Workshop             ← Meta
 *
 * Alles davon wird bereits im Workshop-Card oben angezeigt.
 */
function filterRedundantBlocks(array $blocks, string $workshopTitle): array
{
    // Patterns: Zeilen die wir als redundant erkennen
    $metaPatterns = [
        // Titel-Echo: beliebiges Emoji + Workshop/Vortrag/Expertpanel/Panel/Podium:
        '/^.{0,8}(Workshop|Vortrag|Expertpanel|Panel|Podium)\s*[:：]/ui',
        '/Veranstaltungsdetails/ui',
        '/^📅\s*Termin/ui',
        '/^📍\s*Ort/ui',
        '/^🎯\s*(Format|Typ)/ui',
        '/^🕐\s*(Uhrzeit|Zeit)/ui',
        '/^📌\s*(Bühne|Ort|Location)/ui',
    ];

    $filtered = [];
    $skipZone = true;  // Am Anfang sind wir in der Skip-Zone

    foreach ($blocks as $block) {
        $type = $block['type'] ?? '';

        // Plain-Text des Blocks extrahieren
        $plainText = '';
        $richTextKey = $type; // paragraph → paragraph, heading_1 → heading_1, etc.
        if (isset($block[$richTextKey]['rich_text'])) {
            foreach ($block[$richTextKey]['rich_text'] as $seg) {
                $plainText .= $seg['plain_text'] ?? '';
            }
        }
        $plainText = trim($plainText);

        // Leere Paragraphen in der Skip-Zone → überspringen
        if ($skipZone && $type === 'paragraph' && $plainText === '') {
            continue;
        }

        // Divider in der Skip-Zone → überspringen (oft Trenner nach Meta)
        if ($skipZone && $type === 'divider') {
            continue;
        }

        // Prüfen ob Block zu den redundanten Meta-Patterns passt
        if ($skipZone) {
            $isRedundant = false;
            foreach ($metaPatterns as $pattern) {
                if (preg_match($pattern, $plainText)) {
                    $isRedundant = true;
                    break;
                }
            }
            if ($isRedundant) {
                continue; // Block überspringen
            }

            // Wenn wir hier sind und der Block nicht leer/redundant ist,
            // verlassen wir die Skip-Zone → ab hier alles behalten
            if ($plainText !== '' || !in_array($type, ['paragraph', 'divider'])) {
                $skipZone = false;
            }
        }

        $filtered[] = $block;
    }

    return $filtered;
}

/**
 * Löst Aussteller-Relation-IDs gegen den Aussteller-Index auf.
 * Gibt ein Array von Aussteller-Objekten zurück (nur relevante Felder).
 */
function resolveAussteller(array $ausstellerIds, array $index): array
{
    $result = [];
    foreach ($ausstellerIds as $id) {
        $a = $index[$id] ?? null;
        if (!$a) continue;
        $result[] = [
            'id'      => $a['id'],
            'firma'   => $a['firma'] ?? '',
            'stand'   => $a['stand'] ?? '',
            'stand_x' => $a['stand_x'] ?? null,
            'stand_y' => $a['stand_y'] ?? null,
            'stand_w' => $a['stand_w'] ?? null,
            'stand_h' => $a['stand_h'] ?? null,
        ];
    }
    return $result;
}
