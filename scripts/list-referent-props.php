<?php
/**
 * Liest die Properties einer verknüpften Referent-Page aus,
 * um die korrekten Feldnamen zu ermitteln.
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$notion = new NotionClient(NOTION_TOKEN);

// Ersten Workshop mit Referent-Relation finden
$body = ['page_size' => 100];
$data = $notion->queryDatabase(NOTION_WORKSHOP_DB, $body);

$firmaId = null;
$personId = null;

foreach ($data['results'] ?? [] as $page) {
    $props = $page['properties'];
    if (!$firmaId && !empty($props['Referenten (Firma)']['relation'])) {
        $firmaId = $props['Referenten (Firma)']['relation'][0]['id'];
    }
    if (!$personId && !empty($props['Referent (Person)']['relation'])) {
        $personId = $props['Referent (Person)']['relation'][0]['id'];
    }
    if ($firmaId && $personId) break;
}

echo "=== Firma Relation Page ===\n";
if ($firmaId) {
    echo "Page-ID: {$firmaId}\n";
    $page = json_decode(file_get_contents('https://api.notion.com/v1/pages/' . $firmaId, false, stream_context_create([
        'http' => [
            'header' => "Authorization: Bearer " . NOTION_TOKEN . "\r\nNotion-Version: 2022-06-28\r\n",
        ]
    ])), true);
    foreach ($page['properties'] ?? [] as $name => $val) {
        echo "  {$name} ({$val['type']})\n";
    }
} else {
    echo "Keine Firma-Relation gefunden\n";
}

echo "\n=== Person Relation Page ===\n";
if ($personId) {
    echo "Page-ID: {$personId}\n";
    $page = json_decode(file_get_contents('https://api.notion.com/v1/pages/' . $personId, false, stream_context_create([
        'http' => [
            'header' => "Authorization: Bearer " . NOTION_TOKEN . "\r\nNotion-Version: 2022-06-28\r\n",
        ]
    ])), true);
    foreach ($page['properties'] ?? [] as $name => $val) {
        echo "  {$name} ({$val['type']})\n";
    }
} else {
    echo "Keine Person-Relation gefunden\n";
}
