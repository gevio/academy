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
        $datumStart = $props['Datum']['date']['start'] ?? null;
        $datumEnd   = $props['Datum']['date']['end'] ?? null;
        return [
            'id'    => $pageId,
            'title' => $this->extractTitle($props['Titel'] ?? []),
            'typ'   => $props['Typ']['select']['name'] ?? '',
            'tag'   => $props['Tag']['select']['name'] ?? '',
            'zeit'  => $this->formatZeitslot($datumStart, $datumEnd),
            'ort'   => $props['Bühne/Ort']['select']['name'] ?? '',
            'beschreibung' => $this->extractRichText($props['Beschreibung'] ?? []),
            'datum_start' => $datumStart,
            'datum_end'   => $datumEnd,
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

    // ── REVIEW-SYSTEM ────────────────────────────────────

    /**
     * Referent-Details (inkl. E-Mail) aus der Referenten-DB lesen.
     */
    public function getReferentFull(string $pageId): array
    {
        $data = $this->request('GET', "/pages/{$pageId}");
        if (!$data) return [];
        $props = $data['properties'] ?? [];

        $foto = '';
        $fotoFiles = $props['Foto']['files'] ?? [];
        if (!empty($fotoFiles)) {
            $f = $fotoFiles[0];
            $foto = $f['file']['url'] ?? $f['external']['url'] ?? '';
        }

        return [
            'id'        => $pageId,
            'vorname'   => $this->extractRichText($props['Vorname'] ?? []),
            'nachname'  => $this->extractRichText($props['Nachname'] ?? []),
            'name'      => trim($this->extractRichText($props['Vorname'] ?? []) . ' ' . $this->extractRichText($props['Nachname'] ?? [])),
            'email'     => $props['E-Mail-Adresse']['email'] ?? '',
            'bio'       => $this->extractRichText($props['Kurz-Bio'] ?? []),
            'funktion'  => $this->extractRichText($props['Funktion'] ?? []),
            'website'   => $props['Website']['url'] ?? '',
            'foto'      => $foto,
        ];
    }

    /**
     * Aussteller-Details aus der Aussteller-DB lesen.
     */
    public function getAusstellerInfo(string $pageId): array
    {
        $data = $this->request('GET', "/pages/{$pageId}");
        if (!$data) return [];
        $props = $data['properties'] ?? [];

        $logo = '';
        $logoFiles = $props['Logo']['files'] ?? [];
        if (!empty($logoFiles)) {
            $f = $logoFiles[0];
            $logo = $f['file']['url'] ?? $f['external']['url'] ?? '';
        }

        return [
            'id'           => $pageId,
            'firma'        => $this->extractTitle($props['Firma'] ?? []),
            'beschreibung' => $this->extractRichText($props['Beschreibung'] ?? []),
            'website'      => $props['Website']['url'] ?? '',
            'logo'         => $logo,
        ];
    }

    /**
     * Review-Seite in der Workshop-Reviews-DB erstellen.
     * Befüllt Page-Properties + Page-Body (Blöcke) nach Template-Struktur.
     *
     * @return array|null  Notion Page (inkl. „id" und „url")
     */
    /**
     * @param array $referents Array von Referenten (jeweils mit name, vorname, email, bio, funktion, website, foto)
     */
    public function createReviewPage(array $workshop, array $referents, array $firma, string $deadline): ?array
    {
        $reviewTitel = 'Review – ' . $workshop['title'];

        // Alle E-Mails sammeln (für Property – Notion email-Feld nimmt nur 1 Adresse,
        // daher erste nehmen; alle Adressen stehen zusätzlich in Notizen)
        $allEmails = array_filter(array_column($referents, 'email'));
        $firstEmail = $allEmails[0] ?? '';

        // ── Page Properties ──
        $properties = [
            'Review-Titel' => [
                'title' => [['text' => ['content' => $reviewTitel]]],
            ],
            'Event' => [
                'select' => ['name' => 'Southside 2026'],
            ],
            'Workshop' => [
                'relation' => [['id' => $workshop['page_id'] ?? $workshop['id']]],
            ],
            'Review-Status' => [
                'status' => ['name' => 'Offen'],
            ],
            'Deadline' => [
                'date' => ['start' => $deadline],
            ],
        ];

        if ($firstEmail) {
            $properties['Referent E-Mail'] = ['email' => $firstEmail];
        }
        // Bei mehreren Referenten: alle Adressen in Notizen
        if (count($allEmails) > 1) {
            $properties['Notizen'] = [
                'rich_text' => [['text' => ['content' => 'E-Mails: ' . implode(', ', $allEmails)]]],
            ];
        }

        // ── Page Body (Blöcke nach Template) ──
        $blocks = [];

        // 1) Kurz-Anleitung
        $blocks[] = self::h3('1) Kurz-Anleitung für den Referenten');
        $blocks[] = self::paragraph('Bitte prüfe die Inhalte in den Abschnitten unten.');
        $blocks[] = self::paragraph('**Feedback geben:**');
        $blocks[] = self::bullet('Schreibe Kommentare direkt an die Textstellen.');
        $blocks[] = self::bullet('Wenn du Text ändern möchtest: bitte **kommentieren** (nicht überschreiben), damit wir es sauber in unsere Datenbank übernehmen.');
        $blocks[] = self::bullet('Einfach an der betreffenden Zeile rechts auf das Icon klicken');
        $blocks[] = self::paragraph('**Bilder hochladen:**');
        $blocks[] = self::bullet('**Dein Foto:** Ziehe dein Bild in den Abschnitt „Referent – Foto".');
        $blocks[] = self::bullet('**Firmenlogo:** Ziehe das Logo in den Abschnitt „Firma – Logo".');

        // 2) Status & Deadlines
        $blocks[] = self::h3('2) Status & Deadlines');
        $deadlineDe = $this->formatDeadlineDe($deadline);
        $blocks[] = self::bullet('**Review-Status:** Offen');
        $blocks[] = self::bullet("**Bitte Rückmeldung bis:** {$deadlineDe}");
        $blocks[] = self::divider();

        // 3) Workshop
        $blocks[] = self::h3('3) Workshop (für Website/App)');
        $blocks[] = self::paragraph("**Titel (final):** {$workshop['title']}");
        $blocks[] = self::paragraph('**Untertitel/Teaser (optional):**');
        $blocks[] = self::paragraph("**Kurzbeschreibung (1–3 Absätze):** {$workshop['beschreibung']}");

        // Bulletpoints aus content_html extrahieren (Was dich erwartet)
        $bullets = $this->extractBulletpoints($workshop['content_html'] ?? '');
        $blocks[] = self::paragraph('**Was Teilnehmende lernen (Bulletpoints):**');
        if (!empty($bullets)) {
            foreach ($bullets as $bp) {
                $blocks[] = self::bullet($bp);
            }
        } else {
            // Leere Platzhalter
            for ($i = 0; $i < 5; $i++) $blocks[] = self::bullet('');
        }

        $kategorien = implode(', ', $workshop['kategorien'] ?? []);
        $blocks[] = self::paragraph("**Kategorien (Workshop):** {$kategorien}");
        $blocks[] = self::paragraph('**Zielgruppe:**');
        $blocks[] = self::paragraph("**Format:** {$workshop['typ']}");
        $blocks[] = self::paragraph("**Dauer:** {$workshop['zeit']}");
        $blocks[] = self::paragraph("**Bühne/Ort (falls fix):** {$workshop['ort']}");
        $blocks[] = self::paragraph("**Termin/Uhrzeit (falls fix):** {$workshop['tag']} {$workshop['zeit']}");
        $blocks[] = self::paragraph('**SM-Hashtags (optional):**');
        $blocks[] = self::divider();

        // 4) Referent(en)
        $refCount = count($referents);
        $blocks[] = self::h3($refCount > 1 ? '4) Referenten' : '4) Referent');
        foreach ($referents as $idx => $referent) {
            if ($refCount > 1) {
                $blocks[] = self::h4('Referent ' . ($idx + 1) . ': ' . ($referent['name'] ?? ''));
            }
            $blocks[] = self::paragraph("**Name:** {$referent['name']}");
            $blocks[] = self::paragraph("**Titel/Funktion (1 Zeile):** {$referent['funktion']}");
            $blocks[] = self::paragraph("**Kurz-Bio (max. 6–8 Zeilen):** {$referent['bio']}");
            $blocks[] = self::paragraph("**Website / Social (optional):** {$referent['website']}");
            $blocks[] = self::h4(($refCount > 1 ? "Referent " . ($idx + 1) . " – " : "Referent – ") . 'Foto');
            $blocks[] = self::calloutBlock('Bitte hier ein Portraitfoto einfügen (quadratisch oder Hochformat, PNG bevorzugt, transparenter Hintergrund wenn möglich).', '📸');
            if ($idx < $refCount - 1) {
                $blocks[] = self::paragraph(''); // Abstand zwischen Referenten
            }
        }
        $blocks[] = self::divider();

        // 5) Firma
        $blocks[] = self::h3('5) Firma');
        $blocks[] = self::paragraph("**Firmenname:** {$firma['firma']}");
        $blocks[] = self::paragraph("**Kurzprofil (2–4 Zeilen):** {$firma['beschreibung']}");
        $blocks[] = self::paragraph("**Website:** {$firma['website']}");
        $blocks[] = self::h4('Firma – Logo');
        $blocks[] = self::calloutBlock('Wenn noch kein Firmenlogo vorliegt: bitte hier hochladen (PNG bevorzugt, transparenter Hintergrund wenn möglich).', '⚠️');
        $blocks[] = self::divider();

        // 6) Änderungswünsche
        $blocks[] = self::h3('6) Änderungswünsche (Zusammenfassung)');
        foreach (['Titel ok', 'Beschreibung ok', 'Bulletpoints ok', 'Bio ok', 'Foto geliefert', 'Logo geliefert', 'Sonstiges:'] as $item) {
            $blocks[] = self::todoBlock($item);
        }

        // Notion API: max 100 Blöcke pro Request – wir sind deutlich drunter
        return $this->request('POST', '/pages', [
            'parent' => ['database_id' => NOTION_REVIEW_DB],
            'properties' => $properties,
            'children' => $blocks,
        ]);
    }

    /**
     * E-Mail-Draft in der Email-ausgehend-DB erstellen.
     * Ersetzt Platzhalter VORNAME, REVIEW_LINK, DEADLINE im Template-Text.
     *
     * @return array|null  Notion Page
     */
    public function createEmailDraft(
        string $betreff,
        string $toAdresse,
        string $vorname,
        string $reviewUrl,
        string $deadline
    ): ?array {
        // Template-Text mit Platzhaltern
        $templateText = <<<'TPL'
Hi {VORNAME},

Danke als erstes für deine Unterstützung und die Bereitschaft einen Workshop zu halten! 🙌

Für deinen Beitrag zur Selbstausbauer Academy @ Adventure Southside 2026 habe ich ein kompaktes Review-Dokument erstellt. Bitte schau es dir kurz an und gib mir Feedback direkt als Kommentar im Dokument (bitte nichts überschreiben).

👉 Review-Link: {REVIEW_LINK}

Bitte prüfen / freigeben:
- Titel & Kurzbeschreibung
- Bulletpoints (Was dich erwartet)
- Referenten-Bio (kurz)
- Termin / Dauer / Ort (falls angegeben)

Bitte hochladen:
- 1 Foto von dir (Portrait, gerne quadratisch oder Hochformat - PNG bevorzugt, transparenter Hintergrund wenn möglich)
- Firmenlogo (PNG bevorzugt, transparenter Hintergrund wenn möglich)

Deadline: {DEADLINE}

Vielen Dank!
Viele Grüße
Pete
TPL;

        // Deadline als deutsches Datum formatieren
        $deadlineDe = $this->formatDeadlineDe($deadline);

        // Platzhalter ersetzen
        $emailText = str_replace(
            ['{VORNAME}', '{REVIEW_LINK}', '{DEADLINE}'],
            [$vorname, $reviewUrl, $deadlineDe],
            $templateText
        );

        $properties = [
            'Betreff' => [
                'title' => [['text' => ['content' => $betreff]]],
            ],
            'Status' => [
                'select' => ['name' => 'Draft'],
            ],
            'TO-Adresse' => [
                'rich_text' => [['text' => ['content' => $toAdresse]]],
            ],
            'E-Mail-Text' => [
                'rich_text' => [['text' => ['content' => $emailText]]],
            ],
            'Versand-Info' => [
                'select' => ['name' => 'n8n'],
            ],
            'Template-Name' => [
                'rich_text' => [['text' => ['content' => 'Review-Link Workshop (Kommentieren)']]],
            ],
            'Event-Bezug' => [
                'multi_select' => [['name' => 'Southside 2026']],
            ],
        ];

        return $this->request('POST', '/pages', [
            'parent' => ['database_id' => NOTION_EMAIL_DB],
            'properties' => $properties,
        ]);
    }

    // ── Block-Builder Helpers (static) ───────────────────

    private static function paragraph(string $text): array
    {
        return [
            'object' => 'block',
            'type' => 'paragraph',
            'paragraph' => ['rich_text' => self::richTextFromMarkdown($text)],
        ];
    }

    private static function h3(string $text): array
    {
        return [
            'object' => 'block',
            'type' => 'heading_3',
            'heading_3' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $text]]]],
        ];
    }

    private static function h4(string $text): array
    {
        return [
            'object' => 'block',
            'type' => 'heading_4',
            'heading_4' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $text]]]],
        ];
    }

    private static function bullet(string $text): array
    {
        return [
            'object' => 'block',
            'type' => 'bulleted_list_item',
            'bulleted_list_item' => ['rich_text' => self::richTextFromMarkdown($text)],
        ];
    }

    private static function todoBlock(string $text, bool $checked = false): array
    {
        return [
            'object' => 'block',
            'type' => 'to_do',
            'to_do' => [
                'rich_text' => [['type' => 'text', 'text' => ['content' => $text]]],
                'checked' => $checked,
            ],
        ];
    }

    private static function divider(): array
    {
        return ['object' => 'block', 'type' => 'divider', 'divider' => new \stdClass()];
    }

    private static function calloutBlock(string $text, string $emoji = '📋'): array
    {
        return [
            'object' => 'block',
            'type' => 'callout',
            'callout' => [
                'rich_text' => self::richTextFromMarkdown($text),
                'icon' => ['type' => 'emoji', 'emoji' => $emoji],
            ],
        ];
    }

    private static function quoteBlock(string $text): array
    {
        return [
            'object' => 'block',
            'type' => 'quote',
            'quote' => ['rich_text' => self::richTextFromMarkdown($text)],
        ];
    }

    private static function imageBlock(string $url): array
    {
        return [
            'object' => 'block',
            'type' => 'image',
            'image' => [
                'type' => 'external',
                'external' => ['url' => $url],
            ],
        ];
    }

    /**
     * Einfaches Markdown-Parsing für Bold (**text**) in Notion rich_text Array.
     */
    private static function richTextFromMarkdown(string $text): array
    {
        if (empty($text)) return [['type' => 'text', 'text' => ['content' => '']]];

        $parts = preg_split('/(\*\*[^*]+\*\*)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = [];
        foreach ($parts as $part) {
            if (empty($part)) continue;
            if (preg_match('/^\*\*(.+)\*\*$/', $part, $m)) {
                $result[] = [
                    'type' => 'text',
                    'text' => ['content' => $m[1]],
                    'annotations' => ['bold' => true],
                ];
            } else {
                $result[] = ['type' => 'text', 'text' => ['content' => $part]];
            }
        }
        return $result ?: [['type' => 'text', 'text' => ['content' => '']]];
    }

    /**
     * Bulletpoints aus content_html extrahieren.
     * Sucht nach "✓ ..." Zeilen die typisch am Anfang eines <p>-Tags stehen.
     * Nur die Zeilen unter "Was dich erwartet:" werden genommen.
     */
    private function extractBulletpoints(string $html): array
    {
        if (empty($html)) return [];

        // Abschnitt "Was dich erwartet" finden, falls vorhanden
        $section = $html;
        $pos = stripos($html, 'Was dich erwartet');
        if ($pos !== false) {
            $section = substr($html, $pos);
            // Bis zum nächsten <hr> oder <h abschneiden
            if (preg_match('/<(hr|h[1-6])/i', $section, $m, PREG_OFFSET_CAPTURE, 20)) {
                $section = substr($section, 0, $m[0][1]);
            }
        }

        // ✓-Zeilen extrahieren: nach > oder Zeilenanfang
        preg_match_all('/(?:^|>)\s*[✓✔]\s*([^<]+)/u', $section, $matches);
        if (!empty($matches[1])) {
            return array_values(array_filter(array_map('trim', $matches[1])));
        }
        return [];
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
            ['property' => 'Datum', 'direction' => 'ascending'],
        ];

        $all = [];
        $cursor = null;

        do {
            if ($cursor) $body['start_cursor'] = $cursor;
            $data = $this->queryDatabase($dbId, $body);
            if (!$data) break;

            foreach ($data['results'] ?? [] as $page) {
                $props = $page['properties'] ?? [];
                $datumStart = $props['Datum']['date']['start'] ?? null;
                $datumEnd   = $props['Datum']['date']['end'] ?? null;
                $all[] = [
                    'id'           => str_replace('-', '', $page['id']),
                    'page_id'      => $page['id'],
                    'title'        => $this->extractTitle($props['Titel'] ?? []),
                    'typ'          => $props['Typ']['select']['name'] ?? '',
                    'tag'          => $props['Tag']['select']['name'] ?? '',
                    'zeit'         => $this->formatZeitslot($datumStart, $datumEnd),
                    'ort'          => $props['Bühne/Ort']['select']['name'] ?? '',
                    'beschreibung' => $this->extractRichText($props['Beschreibung'] ?? []),
                    'datum_start'  => $datumStart,
                    'datum_end'    => $datumEnd,
                    'kategorien'   => array_map(fn($k) => $k['name'], $props['Kategorien']['multi_select'] ?? []),
                    'status'       => $props['Status']['status']['name'] ?? '',
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
     * Alle Referenten aus der DB laden (paginiert).
     * Gibt Array mit Vorname, Nachname, Foto, Bio, Funktion, Kategorie, Website zurück.
     */
    public function getAllReferenten(string $dbId): array
    {
        $body = ['page_size' => 100];
        $all = [];
        $cursor = null;

        do {
            if ($cursor) $body['start_cursor'] = $cursor;
            $data = $this->queryDatabase($dbId, $body);
            if (!$data) break;

            foreach ($data['results'] ?? [] as $page) {
                $props = $page['properties'] ?? [];

                // Foto: erstes File aus dem Files-Property
                $foto = '';
                $fotoFiles = $props['Foto']['files'] ?? [];
                if (!empty($fotoFiles)) {
                    $f = $fotoFiles[0];
                    $foto = $f['file']['url'] ?? $f['external']['url'] ?? '';
                }

                $all[] = [
                    'id'        => str_replace('-', '', $page['id']),
                    'page_id'   => $page['id'],
                    'vorname'   => $this->extractRichText($props['Vorname'] ?? []),
                    'nachname'  => $this->extractRichText($props['Nachname'] ?? []),
                    'foto'      => $foto,
                    'bio'       => $this->extractRichText($props['Kurz-Bio'] ?? []),
                    'funktion'  => $this->extractRichText($props['Funktion'] ?? []),
                    'kategorie' => $props['Kategorie']['select']['name'] ?? '',
                    'website'   => $props['Website']['url'] ?? '',
                    'workshop_ids' => array_column($props['Workshops & Vorträge']['relation'] ?? [], 'id'),
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

    /**
     * ISO-Datum (2026-03-21) in deutsches Format (21.03.2026).
     */
    private function formatDeadlineDe(string $date): string
    {
        try {
            $d = new \DateTime($date);
            return $d->format('d.m.Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    /**
     * Zeitslot aus Datum-Start/End ableiten (Timezone-korrekt).
     * z.B. "11:00 \u2013 11:45 Uhr" oder "11:00 Uhr" (ohne End).
     */
    private function formatZeitslot(?string $start, ?string $end): string
    {
        if (!$start) return '';
        try {
            $tz = new \DateTimeZone('Europe/Berlin');
            $s = new \DateTime($start);
            $s->setTimezone($tz);
            $result = $s->format('H:i');
            if ($end) {
                $e = new \DateTime($end);
                $e->setTimezone($tz);
                $result .= " \u{2013} " . $e->format('H:i');
            }
            return $result . ' Uhr';
        } catch (\Throwable $ex) {
            return '';
        }
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
            $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($jsonBody === false) {
                error_log("Notion API: json_encode failed – " . json_last_error_msg());
                curl_close($ch);
                return null;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($jsonBody === false) {
                error_log("Notion API: json_encode failed – " . json_last_error_msg());
                curl_close($ch);
                return null;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
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
