<?php
/**
 * generate-aussteller-json.php – CLI-Script
 *
 * Lädt alle Aussteller aus der Notion DB "AS26_Aussteller"
 * und schreibt /public/api/aussteller.json.
 *
 * Usage:
 *   php scripts/generate-aussteller-json.php
 *   php scripts/generate-aussteller-json.php --skip-images
 *   php scripts/generate-aussteller-json.php --refresh-images
 */

$t0 = microtime(true);
$args = $argv ?? [];
$skipImages = in_array('--skip-images', $args, true);
$refreshImages = in_array('--refresh-images', $args, true);

if ($skipImages && $refreshImages) {
    // Expliziter Refresh hat Vorrang.
    $skipImages = false;
}

$downloadImages = !$skipImages;

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$notion  = new NotionClient(NOTION_TOKEN);
$outFile     = __DIR__ . '/../public/api/aussteller.json';
$standFile   = __DIR__ . '/../public/api/standplan.json';
$imgDir      = __DIR__ . '/../public/img/aussteller';
$imgUrl      = '/img/aussteller';               // relativer Web-Pfad

if (!is_dir($imgDir)) {
    mkdir($imgDir, 0755, true);
}

/**
 * Notion-Logo herunterladen, als WebP speichern und lokalen Pfad zurückgeben.
 * Gibt '' zurück, wenn kein Logo oder Download fehlschlägt.
 */
function downloadLogo(string $notionUrl, string $id, string $imgDir, string $imgUrl): string
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
        echo "   ⚠ Logo-Download fehlgeschlagen für {$id}\n";
        return file_exists($localFile) ? $localPath : '';
    }

    // Mit GD laden und als WebP speichern (max 200px breit)
    $src = @imagecreatefromstring($imgData);
    if (!$src) {
        echo "   ⚠ Logo nicht lesbar für {$id}\n";
        return file_exists($localFile) ? $localPath : '';
    }

    $origW = imagesx($src);
    $origH = imagesy($src);
    $maxW  = 200;

    if ($origW > $maxW) {
        $newW = $maxW;
        $newH = (int) round($origH * ($maxW / $origW));
        $dst  = imagecreatetruecolor($newW, $newH);
        // Transparenz beibehalten (PNG-Logos)
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);
        $src = $dst;
    } else {
        // Auch bei kleinen Bildern Alpha beibehalten
        imagealphablending($src, false);
        imagesavealpha($src, true);
    }

    imagewebp($src, $localFile, 82);
    imagedestroy($src);

    $size = round(filesize($localFile) / 1024, 1);
    echo "   🖼 Logo gespeichert: {$id}.webp ({$size} KB)\n";
    return $localPath;
}

if (!defined('NOTION_AUSSTELLER_DB') || empty(NOTION_AUSSTELLER_DB)) {
    die("❌ NOTION_AUSSTELLER_DB nicht gesetzt. Bitte in .env konfigurieren.\n");
}

if ($downloadImages) {
    echo "🖼 Bilder: Refresh aktiv\n";
} else {
    echo "🖼 Bilder: Skip (nutze vorhandene lokale Dateien)\n";
}

// ── 1) Alle Aussteller laden ──
echo "📥 Aussteller laden...\n";

$body = [
    'page_size' => 100,
    'sorts' => [
        ['property' => 'Aussteller', 'direction' => 'ascending'],
    ],
];

$all = [];
$cursor = null;

do {
    if ($cursor) $body['start_cursor'] = $cursor;
    $data = $notion->queryDatabase(NOTION_AUSSTELLER_DB, $body);
    if (!$data) break;

    foreach ($data['results'] ?? [] as $page) {
        $props = $page['properties'] ?? [];

        // Aussteller (title)
        $firma = '';
        foreach ($props['Aussteller']['title'] ?? [] as $t) {
            $firma .= $t['plain_text'] ?? '';
        }
        $firma = trim($firma);
        if (empty($firma)) continue;

        // Stand (rich_text)
        $stand = '';
        foreach ($props['Stand']['rich_text'] ?? [] as $t) {
            $stand .= $t['plain_text'] ?? '';
        }
        $stand = trim($stand);

        // Beschreibung (rich_text)
        $beschreibung = '';
        foreach ($props['Beschreibung']['rich_text'] ?? [] as $t) {
            $beschreibung .= $t['plain_text'] ?? '';
        }

        // Kategorie (multi_select)
        $kategorien = [];
        foreach ($props['Kategorie']['multi_select'] ?? [] as $ms) {
            if (!empty($ms['name'])) $kategorien[] = $ms['name'];
        }

        // Messe-Special (rich_text)
        $messeSpecial = '';
        foreach ($props['Messe-Special']['rich_text'] ?? [] as $t) {
            $messeSpecial .= $t['plain_text'] ?? '';
        }

        // Webshop (url)
        $webshop = $props['Webshop']['url'] ?? '';

        // Website (url)
        $website = $props['Website']['url'] ?? '';

        // Instagram (url)
        $instagram = $props['Instagram']['url'] ?? '';

        // Standplan-Koordinaten (Number)
        $standX = $props['Stand_X']['number'] ?? null;
        $standY = $props['Stand_Y']['number'] ?? null;
        $standW = $props['Stand_W']['number'] ?? null;
        $standH = $props['Stand_H']['number'] ?? null;

        // Logo (files-Feld, manuell hochgeladen)
        $logoNotionUrl = '';
        $logoFiles = $props['Logo']['files'] ?? [];
        if (!empty($logoFiles)) {
            $first = $logoFiles[0];
            if (($first['type'] ?? '') === 'file') {
                $logoNotionUrl = $first['file']['url'] ?? '';
            } elseif (($first['type'] ?? '') === 'external') {
                $logoNotionUrl = $first['external']['url'] ?? '';
            }
        }

        // LogoUrl (formula → Brandfetch CDN)
        $logoUrl = $props['LogoUrl']['formula']['string'] ?? '';

        // Domain extrahieren für Google Favicon Fallback
        $domain = '';
        if ($website) {
            $parsed = parse_url($website);
            $domain = $parsed['host'] ?? '';
            $domain = preg_replace('/^www\./', '', $domain);
        }

        $id = str_replace('-', '', $page['id']);

        // Logo lokal herunterladen (nur wenn Logo-Feld in Notion gefüllt)
        $logoLocal = '';
        if ($logoNotionUrl) {
            if ($downloadImages) {
                $logoLocal = downloadLogo($logoNotionUrl, $id, $imgDir, $imgUrl);
            } else {
                $localFile = $imgDir . '/' . $id . '.webp';
                $logoLocal = file_exists($localFile) ? ($imgUrl . '/' . $id . '.webp') : '';
            }
        }

        $entry = [
            'id'             => $id,
            'page_id'        => $page['id'],
            'firma'          => $firma,
            'stand'          => $stand,
            'beschreibung'   => trim($beschreibung),
            'messe_special'  => trim($messeSpecial),
            'kategorien'     => $kategorien,
            'website'        => $website ?: '',
            'webshop'        => $webshop ?: '',
            'domain'         => $domain,
            'instagram'      => $instagram ?: '',
            'logo_url'       => $logoUrl ?: '',
            'logo_local'     => $logoLocal,
            'stand_x'        => $standX,
            'stand_y'        => $standY,
            'stand_w'        => $standW,
            'stand_h'        => $standH,
        ];

        $all[] = $entry;

        $standInfo = $stand ? "({$stand})" : "(kein Stand)";
        echo "   ✓ {$firma} {$standInfo}\n";
    }

    $cursor = $data['next_cursor'] ?? null;
    usleep(350000); // Rate-Limit
} while ($data['has_more'] ?? false);

echo "\n   " . count($all) . " Aussteller geladen.\n\n";

// ── 2) JSON schreiben ──
$output = [
    'generated' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'count'     => count($all),
    'aussteller' => $all,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($outFile, $json);

$size = round(strlen($json) / 1024, 1);
$elapsed = round(microtime(true) - $t0, 1);

echo "────────────────────────────────────\n";
echo "✅ {$outFile}\n";
echo "   {$size} KB, {$elapsed}s\n";

// ── 3) standplan.json ableiten (Koordinaten aus Notion) ──
$hallenConfig = [
    'FW'  => ['bild' => '/img/plan/FW.jpg', 'label' => 'Foyer West'],
    'AT'  => ['bild' => '/img/plan/FW.jpg', 'label' => 'Foyer West (Atrium)'],
    'FG'  => ['bild' => '/img/plan/FG.jpg', 'label' => 'Freigelände West'],
    'FGO' => ['bild' => '/img/plan/FG.jpg', 'label' => 'Freigelände Ost'],
    'A3'  => ['bild' => '/img/plan/A3.jpg', 'label' => 'Halle A3'],
    'A4'  => ['bild' => '/img/plan/A4.jpg', 'label' => 'Halle A4'],
    'A5'  => ['bild' => '/img/plan/A5.jpg', 'label' => 'Halle A5'],
    'A6'  => ['bild' => '/img/plan/A6.jpg', 'label' => 'Halle A6'],
];

$staende = [];
$coordCount = 0;
foreach ($all as $a) {
    if ($a['stand'] && $a['stand_x'] !== null && $a['stand_y'] !== null) {
        $entry = ['x' => $a['stand_x'], 'y' => $a['stand_y']];
        if ($a['stand_w'] !== null) $entry['w'] = $a['stand_w'];
        if ($a['stand_h'] !== null) $entry['h'] = $a['stand_h'];
        $staende[$a['stand']] = $entry;
        $coordCount++;
    }
}

$standplanOutput = [
    'generated' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'hallen'    => $hallenConfig,
    'staende'   => (object)$staende,
];

$standJson = json_encode($standplanOutput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($standFile, $standJson);

echo "✅ {$standFile}\n";
echo "   {$coordCount} St\u00e4nde mit Koordinaten\n";
