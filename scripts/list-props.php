<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$n = new NotionClient(NOTION_TOKEN);
$r = $n->queryDatabase(NOTION_WORKSHOP_DB, ['page_size' => 1]);

if ($r && !empty($r['results'])) {
    $props = $r['results'][0]['properties'];
    echo "Alle Properties:\n";
    foreach ($props as $name => $val) {
        $type = $val['type'] ?? '?';
        // Try to get value preview
        $preview = '';
        if ($type === 'rich_text' && !empty($val['rich_text'])) {
            $preview = $val['rich_text'][0]['plain_text'] ?? '';
        } elseif ($type === 'select' && !empty($val['select'])) {
            $preview = $val['select']['name'] ?? '';
        } elseif ($type === 'multi_select' && !empty($val['multi_select'])) {
            $preview = implode(', ', array_map(fn($s) => $s['name'], $val['multi_select']));
        }
        echo "  {$name} ({$type})" . ($preview ? " = {$preview}" : '') . "\n";
    }
}
