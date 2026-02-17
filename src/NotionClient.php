<?php
// src/NotionClient.php

class NotionClient
{
    private string $token;
    private string $version = '2022-06-28';
    private string $baseUrl = 'https://api.notion.com/v1';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function queryDatabase(string $dbId, array $body): ?array
    {
        return $this->request('POST', "/databases/{$dbId}/query", $body);
    }

    // ── READ ─────────────────────────────────────────────

    public function getWorkshop(string $pageId): ?array
    {
        $data = $this->request('GET', "/pages/{$pageId}");
        if (!$data) return null;

        $props = $data['properties'] ?? [];
        return [
            'id'    => $pageId,
            'title' => $this->extractTitle($props['Titel'] ?? []),
            'typ'   => $props['Typ']['select']['name'] ?? '',
            'tag'   => $props['Tag']['select']['name'] ?? '',
            'zeit'  => $props['Uhrzeit']['select']['name'] ?? '',
            'ort'   => $props['Bühne/Ort']['select']['name'] ?? '',
            'beschreibung' => $this->extractRichText($props['Beschreibung'] ?? []),
            'datum_start' => $props['Datum']['date']['start'] ?? null,
            'kategorien'  => array_map(fn($k) => $k['name'], $props['Kategorien']['multi_select'] ?? []),
            'referent_firma_ids'  => array_column($props['Referenten (Firma)']['relation'] ?? [], 'id'),
            'referent_person_ids' => array_column($props['Referent (Person)']['relation'] ?? [], 'id'),
        ];
    }

    public function getQuestions(string $workshopPageId): array
    {
        $filter = [
            'filter' => [
                'property' => 'Workshop',
                'relation' => ['contains' => $workshopPageId],
            ],
            'sorts' => [
                ['property' => 'Upvotes', 'direction' => 'descending'],
            ],
        ];

        $dbId = NOTION_QA_DB;
        $data = $this->request('POST', "/databases/{$dbId}/query", $filter);
        $results = [];

        foreach (($data['results'] ?? []) as $page) {
            $p = $page['properties'];
            $results[] = [
                'id'      => $page['id'],
                'frage'   => $this->extractTitle($p['Frage'] ?? []),
                'upvotes' => (int)($p['Upvotes']['number'] ?? 0),
                'status'  => $p['Status']['status']['name'] ?? 'Offen',
            ];
        }
        return $results;
    }

    // ── WRITE ────────────────────────────────────────────

public function createFeedback(
    string $workshopPageId,
    int $inhalt,
    int $praesentation,
    int $organisation,
    int $gesamt,
    string $kommentar = '',
    string $deviceId = ''
): ?array {
    $now = (new DateTime())->format('c');
    $properties = [
        'Titel' => [
            'title' => [['text' => ['content' => 'Feedback ' . date('d.m.Y H:i')]]],
        ],
        'Workshop' => [
            'relation' => [['id' => $workshopPageId]],
        ],
        'Inhalt' => ['number' => $inhalt],
        'Präsentation' => ['number' => $praesentation],
        'Organisation' => ['number' => $organisation],
        'Gesamtbewertung' => ['number' => $gesamt],
        'Kommentar' => [
            'rich_text' => [['text' => ['content' => $kommentar]]],
        ],
        'Zeitstempel' => [
            'date' => ['start' => $now],
        ],
    ];

    if ($deviceId) {
        $properties['Device-ID'] = [
            'rich_text' => [['text' => ['content' => $deviceId]]],
        ];
    }

    return $this->request('POST', '/pages', [
        'parent' => ['database_id' => NOTION_FEEDBACK_DB],
        'properties' => $properties,
    ]);
}

public function createQuestion(string $workshopPageId, string $frage, string $deviceId = ''): ?array
{
    $now = (new DateTime())->format('c');
    $properties = [
        'Frage' => [
            'title' => [['text' => ['content' => $frage]]],
        ],
        'Workshop' => [
            'relation' => [['id' => $workshopPageId]],
        ],
        'Upvotes' => ['number' => 0],
        'Zeitstempel' => [
            'date' => ['start' => $now],
        ],
    ];

    if ($deviceId) {
        $properties['Device-ID'] = [
            'rich_text' => [['text' => ['content' => $deviceId]]],
        ];
    }

    return $this->request('POST', '/pages', [
        'parent' => ['database_id' => NOTION_QA_DB],
        'properties' => $properties,
    ]);
}

    public function upvoteQuestion(string $questionPageId): ?array
    {
        // Erst aktuelle Upvotes lesen
        $page = $this->request('GET', "/pages/{$questionPageId}");
        $current = (int)($page['properties']['Upvotes']['number'] ?? 0);

        return $this->request('PATCH', "/pages/{$questionPageId}", [
            'properties' => [
                'Upvotes' => ['number' => $current + 1],
            ],
        ]);
    }

    /**
     * Beliebige Properties einer Page aktualisieren.
     * $properties im Notion-API-Format, z.B.:
     *   ['Stand_X' => ['number' => 45.2], 'Stand_Y' => ['number' => 32.1]]
     */
    public function updatePage(string $pageId, array $properties): ?array
    {
        return $this->request('PATCH', "/pages/{$pageId}", [
            'properties' => $properties,
        ]);
    }

    // ── BULK READ (für JSON-Export) ──────────────────────

    /**
     * Alle Workshops aus der DB laden, optional nach Tag filtern.
     * Paginiert (max 100 pro Request).
     */
    public function getAllWorkshops(string $dbId, array $tags = []): array
    {
        $body = ['page_size' => 100];

        // Filter: nur bestimmte Tage
        if (!empty($tags)) {
            $or = [];
            foreach ($tags as $tag) {
                $or[] = [
                    'property' => 'Tag',
                    'select'   => ['equals' => $tag],
                ];
            }
            $body['filter'] = ['or' => $or];
        }

        $body['sorts'] = [
            ['property' => 'Tag', 'direction' => 'ascending'],
            ['property' => 'Uhrzeit', 'direction' => 'ascending'],
        ];

        $all = [];
        $cursor = null;

        do {
            if ($cursor) $body['start_cursor'] = $cursor;
            $data = $this->queryDatabase($dbId, $body);
            if (!$data) break;

            foreach ($data['results'] ?? [] as $page) {
                $props = $page['properties'] ?? [];
                $all[] = [
                    'id'           => str_replace('-', '', $page['id']),
                    'page_id'      => $page['id'],
                    'title'        => $this->extractTitle($props['Titel'] ?? []),
                    'typ'          => $props['Typ']['select']['name'] ?? '',
                    'tag'          => $props['Tag']['select']['name'] ?? '',
                    'zeit'         => $props['Uhrzeit']['select']['name'] ?? '',
                    'ort'          => $props['Bühne/Ort']['select']['name'] ?? '',
                    'beschreibung' => $this->extractRichText($props['Beschreibung'] ?? []),
                    'datum_start'  => $props['Datum']['date']['start'] ?? null,
                    'kategorien'   => array_map(fn($k) => $k['name'], $props['Kategorien']['multi_select'] ?? []),
                    'referent_firma_ids'  => array_column($props['Referenten (Firma)']['relation'] ?? [], 'id'),
                    'referent_person_ids' => array_column($props['Referent (Person)']['relation'] ?? [], 'id'),
                    'aussteller_ids'      => array_column($props['Aussteller (AS26)']['relation'] ?? [], 'id'),
                ];
            }

            $cursor = $data['next_cursor'] ?? null;
        } while ($data['has_more'] ?? false);

        return $all;
    }

    /**
     * Block-Children einer Seite laden (paginiert).
     * Gibt flaches Array aller Blöcke zurück.
     */
    public function getPageBlocks(string $pageId): array
    {
        $blocks = [];
        $cursor = null;

        do {
            $url = "/blocks/{$pageId}/children?page_size=100";
            if ($cursor) $url .= "&start_cursor={$cursor}";

            $data = $this->request('GET', $url);
            if (!$data) break;

            foreach ($data['results'] ?? [] as $block) {
                $blocks[] = $block;
            }

            $cursor = $data['next_cursor'] ?? null;
        } while ($data['has_more'] ?? false);

        return $blocks;
    }

    // ── HELPERS ──────────────────────────────────────────

    /**
     * Titel einer Notion-Page anhand ihrer ID lesen.
     */
    public function getPageTitle(string $pageId): string
    {
        $data = $this->request('GET', "/pages/{$pageId}");
        if (!$data) return '';
        foreach ($data['properties'] ?? [] as $prop) {
            if (($prop['type'] ?? '') === 'title') {
                return $this->extractTitle($prop);
            }
        }
        return '';
    }

    /**
     * Referent-Details aus einer verknüpften Person-Page lesen.
     * Gibt ['vorname' => ..., 'nachname' => ..., 'firma_ids' => [...]] zurück.
     */
    public function getReferentPerson(string $pageId): array
    {
        $data = $this->request('GET', "/pages/{$pageId}");
        if (!$data) return ['vorname' => '', 'nachname' => '', 'firma_ids' => []];
        $props = $data['properties'] ?? [];
        return [
            'vorname'   => $this->extractRichText($props['Vorname'] ?? []),
            'nachname'  => $this->extractRichText($props['Nachname'] ?? []),
            'firma_ids' => array_column($props['Firma']['relation'] ?? [], 'id'),
        ];
    }

    private function extractTitle(array $prop): string
    {
        $parts = $prop['title'] ?? [];
        return implode('', array_map(fn($t) => $t['plain_text'] ?? '', $parts));
    }

    private function extractRichText(array $prop): string
    {
        $parts = $prop['rich_text'] ?? [];
        return implode('', array_map(fn($t) => $t['plain_text'] ?? '', $parts));
    }

    private function request(string $method, string $endpoint, ?array $body = null): ?array
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Notion-Version: ' . $this->version,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            error_log("Notion API error {$httpCode}: {$response}");
            return null;
        }

        return json_decode($response, true);
    }
}
