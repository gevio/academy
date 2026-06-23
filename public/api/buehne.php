<?php
/**
 * GET /api/buehne.php?secret=ADMIN_SECRET
 *
 * Liefert alle Bühnentechnik-Geräte aus Notion als vis-network-kompatibles JSON.
 * Bühnen-Namen werden direkt aus NOTION_MASTER_BUEHNEN_DB aufgelöst.
 *
 * Response:
 *   {
 *     "ok": true,
 *     "nodes": [ { id, label, group, title, color, shape, device } ],
 *     "edges": [ { from, to, label, arrows } ],
 *     "buehnen": { "<pageId>": "<name>", ... }
 *   }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

// ── Auth ─────────────────────────────────────────────────────────────
$secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $_GET['secret'] ?? '';
if (empty(ADMIN_SECRET) || !hash_equals(ADMIN_SECRET, $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (empty(NOTION_STAGE_DB)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'NOTION_STAGE_DB nicht konfiguriert']);
    exit;
}

// ── Daten laden ───────────────────────────────────────────────────────
$client = new NotionClient(NOTION_TOKEN);

// Bühnen-Namen auflösen (optional – leere Map wenn DB nicht konfiguriert)
$buehnenMap = [];
if (!empty(NOTION_MASTER_BUEHNEN_DB)) {
    $buehnenMap = $client->getBuehnen(NOTION_MASTER_BUEHNEN_DB);
}

// Geräte laden
$devices = $client->getBuehnetechnik(NOTION_STAGE_DB);

// ── Farb- und Form-Mapping ───────────────────────────────────────────
function statusColor(string $status): array
{
    return match (true) {
        str_contains($status, 'Aktiv')      => ['background' => '#2ecc71', 'border' => '#27ae60', 'highlight' => ['background' => '#58d68d', 'border' => '#27ae60']],
        str_contains($status, 'Wartung')    => ['background' => '#f39c12', 'border' => '#d68910', 'highlight' => ['background' => '#f8c471', 'border' => '#d68910']],
        str_contains($status, 'Defekt')     => ['background' => '#e74c3c', 'border' => '#c0392b', 'highlight' => ['background' => '#f1948a', 'border' => '#c0392b']],
        str_contains($status, 'Reserve')    => ['background' => '#95a5a6', 'border' => '#7f8c8d', 'highlight' => ['background' => '#bdc3c7', 'border' => '#7f8c8d']],
        str_contains($status, 'Verliehen')  => ['background' => '#3498db', 'border' => '#2980b9', 'highlight' => ['background' => '#7fb3d3', 'border' => '#2980b9']],
        default                             => ['background' => '#bdc3c7', 'border' => '#95a5a6', 'highlight' => ['background' => '#d5d8dc', 'border' => '#95a5a6']],
    };
}

function kategorieShape(string $kategorie): string
{
    return match (true) {
        in_array($kategorie, ['Kamera', 'Recorder'])          => 'box',
        in_array($kategorie, ['Mischpult', 'Switcher'])       => 'diamond',
        in_array($kategorie, ['Mikrofon', 'Lautsprecher'])    => 'ellipse',
        in_array($kategorie, ['Licht'])                        => 'star',
        in_array($kategorie, ['Projektor'])                    => 'triangle',
        in_array($kategorie, ['Netzwerk'])                     => 'hexagon',
        default                                                => 'dot',
    };
}

// ── vis-network Nodes bauen ──────────────────────────────────────────
$nodes = [];
foreach ($devices as $device) {
    // Bühnen-Namen für Tooltip
    $buehneNames = array_map(
        fn($id) => $buehnenMap[$id] ?? $id,
        $device['buehne_ids']
    );
    $buehneLabel = implode(', ', array_filter($buehneNames));

    // Tooltip (HTML für vis-network title)
    $tooltip = "<strong>{$device['name']}</strong><br>"
        . ($device['kategorie']  ? "Kategorie: {$device['kategorie']}<br>" : '')
        . ($device['status']     ? "Status: {$device['status']}<br>" : '')
        . ($buehneLabel          ? "Bühne: {$buehneLabel}<br>" : '')
        . ($device['rolle']      ? "Rolle: {$device['rolle']}<br>" : '')
        . ($device['input_protokoll']  ? "Input: {$device['input_protokoll']}<br>" : '')
        . ($device['output_protokoll'] ? "Output: {$device['output_protokoll']}<br>" : '')
        . ($device['seriennummer']     ? "SN: {$device['seriennummer']}<br>" : '')
        . ($device['notizen']          ? "<em>{$device['notizen']}</em>" : '');

    $nodes[] = [
        'id'    => $device['id'],
        'label' => $device['name'] ?: '(unbenannt)',
        'group' => $device['kategorie'] ?: 'Sonstige',
        'title' => $tooltip,
        'color' => statusColor($device['status']),
        'shape' => kategorieShape($device['kategorie']),
        'font'  => ['color' => '#372F2C', 'size' => 13, 'face' => 'PT Sans, sans-serif'],
        'device' => $device,  // volle Daten für Detail-Panel
    ];
}

// ── vis-network Edges bauen ──────────────────────────────────────────
$edges = [];
// Index für schnellen Lookup: pageId → device
$deviceIndex = array_column($devices, null, 'id');

foreach ($devices as $device) {
    foreach ($device['signal_geht_zu'] as $targetId) {
        // Kante-Label = Output-Protokoll der Quelle (wenn vorhanden)
        $edgeLabel = $device['output_protokoll'] ?: '';
        // Zusatz: Output-Kabel in Klammern
        if ($device['output_kabel'] && $device['output_kabel'] !== $device['output_protokoll']) {
            $edgeLabel .= $edgeLabel ? "\n({$device['output_kabel']})" : $device['output_kabel'];
        }

        $edges[] = [
            'from'    => $device['id'],
            'to'      => $targetId,
            'label'   => $edgeLabel,
            'arrows'  => 'to',
            'font'    => ['size' => 10, 'align' => 'middle', 'color' => '#6E6159'],
            'color'   => ['color' => '#BFB7AF', 'highlight' => '#CF3628'],
            'smooth'  => ['type' => 'curvedCW', 'roundness' => 0.2],
        ];
    }
}

echo json_encode([
    'ok'      => true,
    'nodes'   => $nodes,
    'edges'   => $edges,
    'buehnen' => $buehnenMap,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
