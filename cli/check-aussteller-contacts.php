<?php
/**
 * cli/check-aussteller-contacts.php – Read-only Bericht
 *
 * Prüft die Kontakt-(Master)-Relation aller AS26_Aussteller-Einträge
 * und meldet Anomalien.
 *
 * Kategorien:
 *   ⛔ 0 Kontakt (Master)   → Waise – Review-Versand nicht möglich
 *   ⛔ >1 Kontakt (Master)  → Bug   – welcher Kontakt wäre der Empfänger?
 *   ⚠  1 Kontakt, keine E-Mail → Review-Mail kann nicht versendet werden
 *   ✅  1 Kontakt mit E-Mail  → alles OK
 *   ℹ️  Kontakt mit mehreren Aussteller-Einträgen → legitimes 1:N (Info)
 *
 * Usage:
 *   php cli/check-aussteller-contacts.php          # nur Struktur (schnell)
 *   php cli/check-aussteller-contacts.php --emails # auch E-Mails prüfen (~langsamer)
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$args       = $argv ?? [];
$checkEmails = in_array('--emails', $args, true);

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  AS26_Aussteller ↔ Kontakt (Master) – Bericht\n";
echo "  Modus: " . ($checkEmails ? "Struktur + E-Mail-Prüfung" : "Struktur (--emails für E-Mail-Check)") . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$notion = new NotionClient(NOTION_TOKEN);

// ── 1) Alle Aussteller paginiert laden ──────────────────────────────────────
echo "📥 Lade Aussteller aus Notion...\n";

$allAussteller = [];
$cursor = null;
do {
    $body = ['page_size' => 100, 'sorts' => [['property' => 'Aussteller', 'direction' => 'ascending']]];
    if ($cursor) $body['start_cursor'] = $cursor;

    $data = $notion->queryDatabase(NOTION_AUSSTELLER_DB, $body);
    if (!$data) {
        echo "❌ Notion-Abfrage fehlgeschlagen.\n";
        exit(1);
    }

    foreach ($data['results'] ?? [] as $page) {
        $props  = $page['properties'] ?? [];
        $pageId = $page['id'];

        $firma = '';
        foreach ($props['Aussteller']['title'] ?? [] as $t) $firma .= $t['plain_text'] ?? '';
        $firma = trim($firma) ?: '(kein Name)';

        $relation      = $props['Kontakt (Master)'] ?? [];
        $kontaktIds    = array_column($relation['relation'] ?? [], 'id');
        $kontaktHasMore = $relation['has_more'] ?? false;

        $allAussteller[] = [
            'page_id'           => $pageId,
            'firma'             => $firma,
            'kontakt_ids'       => $kontaktIds,
            'kontakt_has_more'  => $kontaktHasMore,
        ];
    }

    $cursor = $data['next_cursor'] ?? null;
} while ($data['has_more'] ?? false);

$total = count($allAussteller);
echo "   {$total} Einträge geladen.\n\n";

// ── 2) Kategorisieren ────────────────────────────────────────────────────────
$orphans         = []; // 0 Kontakt
$bugs            = []; // >1 Kontakt oder has_more
$singleKontakt   = []; // genau 1 Kontakt → E-Mail noch ungeprüft
$kontaktToEntries = []; // kontakt_id → [aussteller-Einträge]

foreach ($allAussteller as $aus) {
    $count = count($aus['kontakt_ids']);

    if ($aus['kontakt_has_more'] || $count > 1) {
        $bugs[] = $aus;
        continue;
    }
    if ($count === 0) {
        $orphans[] = $aus;
        continue;
    }

    // Genau 1 Kontakt
    $kid = $aus['kontakt_ids'][0];
    $singleKontakt[] = ['aus' => $aus, 'kontakt_id' => $kid];
    $kontaktToEntries[$kid][] = $aus;
}

// ── 3) Optional: E-Mails prüfen ─────────────────────────────────────────────
$kontaktCache = []; // kid → ['name' => ..., 'email' => ...]
$noEmail      = [];
$okList       = [];

if ($checkEmails) {
    $uniqueIds = array_keys($kontaktToEntries);
    echo "🔍 Prüfe " . count($uniqueIds) . " Kontakt-Seiten (E-Mail)...\n";

    foreach ($uniqueIds as $kid) {
        $ch = curl_init("https://api.notion.com/v1/pages/{$kid}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . NOTION_TOKEN,
                'Notion-Version: 2022-06-28',
            ],
        ]);
        $d = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $kp    = $d['properties'] ?? [];
        $email = $kp['E-Mail']['email'] ?? '';
        $name  = '';
        foreach ($kp['Vorname']['rich_text'] ?? []  as $t) $name .= $t['plain_text'] ?? '';
        foreach ($kp['Nachname']['rich_text'] ?? [] as $t) $name .= ' ' . ($t['plain_text'] ?? '');
        $kontaktCache[$kid] = ['email' => trim($email), 'name' => trim($name)];

        usleep(300000); // ~3 req/s
    }

    foreach ($singleKontakt as $entry) {
        $kid   = $entry['kontakt_id'];
        $email = $kontaktCache[$kid]['email'] ?? '';
        if ($email) {
            $okList[]  = $entry + ['email' => $email, 'kontakt_name' => $kontaktCache[$kid]['name'] ?? ''];
        } else {
            $noEmail[] = $entry + ['kontakt_name' => $kontaktCache[$kid]['name'] ?? ''];
        }
    }
} else {
    // Ohne E-Mail-Check: alle mit genau 1 Kontakt als "unklar" markieren
    $okList = $singleKontakt;
}

// Kontakte mit mehreren Einträgen (legitimes 1:N)
$multiEntries = array_filter($kontaktToEntries, fn($e) => count($e) > 1);

// ── 4) Report ausgeben ────────────────────────────────────────────────────────
$sepLine = str_repeat('─', 52);

// --- WAISEN (0 Kontakt) ---
echo "\n⛔ WAISEN – kein Kontakt (Master): " . count($orphans) . "\n";
if ($orphans) {
    echo $sepLine . "\n";
    foreach ($orphans as $a) {
        echo sprintf("  %-48s  %s\n", substr($a['firma'], 0, 48), $a['page_id']);
    }
}

// --- BUG (>1 Kontakt) ---
echo "\n⛔ BUG – mehrere Kontakte: " . count($bugs) . "\n";
if ($bugs) {
    echo $sepLine . "\n";
    foreach ($bugs as $a) {
        $count = count($a['kontakt_ids']) . ($a['kontakt_has_more'] ? '+' : '');
        echo sprintf("  %-44s  [%sx Kontakt]  %s\n",
            substr($a['firma'], 0, 44), $count, $a['page_id']);
        foreach ($a['kontakt_ids'] as $kid) {
            echo "    → Kontakt: {$kid}\n";
        }
    }
}

// --- KEIN EMAIL (nur bei --emails) ---
if ($checkEmails) {
    echo "\n⚠  KEIN E-MAIL beim Kontakt: " . count($noEmail) . "\n";
    if ($noEmail) {
        echo $sepLine . "\n";
        foreach ($noEmail as $e) {
            echo sprintf("  %-40s  ← Kontakt: %s (%s)\n",
                substr($e['aus']['firma'], 0, 40),
                $e['kontakt_id'],
                $e['kontakt_name'] ?: 'kein Name');
        }
    }
} else {
    echo "\n  (E-Mail-Prüfung nicht aktiv – mit --emails ausführen)\n";
}

// --- LEGITIMES 1:N (Info) ---
echo "\nℹ️  KONTAKT MIT MEHREREN EINTRÄGEN (legitim): " . count($multiEntries) . "\n";
if ($multiEntries) {
    echo $sepLine . "\n";
    foreach ($multiEntries as $kid => $entries) {
        $kontaktName = $kontaktCache[$kid]['name'] ?? $kid;
        echo "  Kontakt: {$kontaktName} ({$kid})\n";
        foreach ($entries as $e) {
            echo "    → {$e['firma']} [{$e['page_id']}]\n";
        }
    }
}

// --- OK-Einträge ---
if ($checkEmails) {
    echo "\n✅ OK (1 Kontakt + E-Mail): " . count($okList) . "\n";
} else {
    echo "\n✅ OK (1 Kontakt, E-Mail ungeprüft): " . count($okList) . "\n";
}

// ── 5) Zusammenfassung ────────────────────────────────────────────────────────
$kritisch = count($orphans) + count($bugs) + ($checkEmails ? count($noEmail) : 0);
echo "\n" . str_repeat('━', 52) . "\n";
echo "Gesamt:      {$total} Einträge\n";
echo "Kritisch:    {$kritisch}";
if (!$checkEmails && count($noEmail) === 0 && $kritisch === 0) {
    echo " (ohne E-Mail-Prüfung)";
}
echo "\nWarnung:     " . count($multiEntries) . " Kontakte mit mehreren Einträgen (Info)\n";
echo str_repeat('━', 52) . "\n";

if ($kritisch > 0) {
    echo "\n→ Bitte Anomalien in Notion bereinigen, bevor der Massenversand läuft.\n";
    echo "→ Tipp: Relation 'Kontakt (Master)' in Notion auf 'Limit: 1 Seite' setzen.\n";
} else {
    echo "\n→ Alle Einträge haben genau 1 Kontakt.\n";
}
