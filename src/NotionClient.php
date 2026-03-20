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
    string $deviceId = '',
    int $appBewertung = 0,
    string $appKommentar = ''
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

    if ($appBewertung > 0) {
        $properties['App-Bewertung'] = ['number' => $appBewertung];
        $properties['App-Kommentar'] = [
            'rich_text' => [['text' => ['content' => $appKommentar]]],
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

        // Checkbox "Du" – true = informelles Du, false/nicht gesetzt = formelles Sie
        $duzen = (bool) ($props['Du']['checkbox'] ?? false);

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
            'duzen'     => $duzen,
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
        // HTML-Entities in Textfeldern dekodieren (z. B. &amp; → &)
        $decode = fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (['title', 'beschreibung', 'typ', 'tag', 'zeit', 'ort'] as $k) {
            if (isset($workshop[$k]) && is_string($workshop[$k])) {
                $workshop[$k] = $decode($workshop[$k]);
            }
        }
        foreach ($referents as &$ref) {
            foreach (['name', 'funktion', 'bio', 'website'] as $k) {
                if (isset($ref[$k]) && is_string($ref[$k])) {
                    $ref[$k] = $decode($ref[$k]);
                }
            }
        }
        unset($ref);
        foreach (['firma', 'beschreibung', 'website'] as $k) {
            if (isset($firma[$k]) && is_string($firma[$k])) {
                $firma[$k] = $decode($firma[$k]);
            }
        }

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

        // Du/Sie: wenn mindestens ein Referent "Sie" bevorzugt, formelle Form nutzen
        $duzen = !empty(array_filter($referents, fn($r) => !empty($r['duzen'])));
        // Falls ALLE Referenten duzen=false → Sie-Form; gemischt → Du (sicherer)
        $alleSiezen = empty(array_filter($referents, fn($r) => !empty($r['duzen'])));

        // 1) Kurz-Anleitung
        $blocks[] = self::h3('1) Kurz-Anleitung für den Referenten');
        if ($alleSiezen) {
            $blocks[] = self::paragraph('Bitte prüfen Sie die Inhalte in den Abschnitten unten.');
            $blocks[] = self::paragraph('**Feedback geben:**');
            $blocks[] = self::bullet('Schreiben Sie Kommentare direkt an die Textstellen.');
            $blocks[] = self::bullet('Wenn Sie Text ändern möchten: bitte **kommentieren** (nicht überschreiben), damit wir es sauber in unsere Datenbank übernehmen.');
            $blocks[] = self::bullet('Einfach an der betreffenden Zeile rechts auf das Icon klicken');
            $blocks[] = self::paragraph('**Bilder hochladen:**');
            $blocks[] = self::bullet('**Ihr Foto:** Ziehen Sie Ihr Bild in den Abschnitt „Referent – Foto".');
            $blocks[] = self::bullet('**Firmenlogo:** Ziehen Sie das Logo in den Abschnitt „Firma – Logo".');
        } else {
            $blocks[] = self::paragraph('Bitte prüfe die Inhalte in den Abschnitten unten.');
            $blocks[] = self::paragraph('**Feedback geben:**');
            $blocks[] = self::bullet('Schreibe Kommentare direkt an die Textstellen.');
            $blocks[] = self::bullet('Wenn du Text ändern möchtest: bitte **kommentieren** (nicht überschreiben), damit wir es sauber in unsere Datenbank übernehmen.');
            $blocks[] = self::bullet('Einfach an der betreffenden Zeile rechts auf das Icon klicken');
            $blocks[] = self::paragraph('**Bilder hochladen:**');
            $blocks[] = self::bullet('**Dein Foto:** Ziehe dein Bild in den Abschnitt „Referent – Foto".');
            $blocks[] = self::bullet('**Firmenlogo:** Ziehe das Logo in den Abschnitt „Firma – Logo".');
        }
        $blocks[] = self::paragraph('**Wichtig**: Ich bestätige, dass ich über alle Rechte an den hochgeladenen Inhalten verfüge. Ich räume der Rough Road Events GmbH sowie von ihr beauftragten Dritten (z. B. Agenturen, Freelancer, Dienstleister) das zeitlich, räumlich und inhaltlich unbeschränkte Recht ein, dieses Material für redaktionelle und werbliche Zwecke (z. B. Social Media, Website, Printmedien) zu nutzen, zu vervielfältigen, zu veröffentlichen sowie zu bearbeiten oder umzugestalten. Die Zustimmung erfolgt unwiderruflich.');

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
        string $nachname,
        string $reviewUrl,
        string $deadline,
        bool $duzen = true
    ): ?array {
        // Template-Text: Du-Form (informell)
        $templateDu = <<<'TPL'
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

        // Template-Text: Sie-Form (formell)
        $templateSie = <<<'TPL'
Liebe/r {VORNAME} {NACHNAME},

vielen Dank für Ihre Unterstützung und die Bereitschaft, einen Workshop zu halten! 🙌

Für Ihren Beitrag zur Selbstausbauer Academy @ Adventure Southside 2026 habe ich ein kompaktes Review-Dokument erstellt. Bitte schauen Sie es sich kurz an und geben Sie mir Feedback direkt als Kommentar im Dokument (bitte nichts überschreiben).

👉 Review-Link: {REVIEW_LINK}

Bitte prüfen / freigeben:
- Titel & Kurzbeschreibung
- Bulletpoints (Was Sie erwartet)
- Referenten-Bio (kurz)
- Termin / Dauer / Ort (falls angegeben)

Bitte hochladen:
- 1 Foto von Ihnen (Portrait, gerne quadratisch oder Hochformat - PNG bevorzugt, transparenter Hintergrund wenn möglich)
- Firmenlogo (PNG bevorzugt, transparenter Hintergrund wenn möglich)

Deadline: {DEADLINE}

Vielen Dank!
Mit freundlichen Grüßen
Pete
TPL;

        $templateText = $duzen ? $templateDu : $templateSie;

        // Deadline als deutsches Datum formatieren
        $deadlineDe = $this->formatDeadlineDe($deadline);

        // Platzhalter ersetzen
        $emailText = str_replace(
            ['{VORNAME}', '{NACHNAME}', '{REVIEW_LINK}', '{DEADLINE}'],
            [$vorname, $nachname, $reviewUrl, $deadlineDe],
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

    // ── AUSSTELLER-REVIEW-SYSTEM ─────────────────────────

    /**
     * Erweiterte Aussteller-Details für Review-Erstellung lesen.
     * Liest alle Felder die für die Review-Seite vorausgefüllt werden.
     */
    public function getAusstellerForReview(string $pageId): array
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

        // Kontakt-Daten: über Relation "Kontakt (Master)" → E-Mail, Vorname, Nachname, Du/Sie
        $kontaktEmail = '';
        $kontaktVorname = '';
        $kontaktNachname = '';
        $kontaktDuzen = false;
        $kontaktIds = array_column($props['Kontakt (Master)']['relation'] ?? [], 'id');
        if (!empty($kontaktIds)) {
            $kontaktData = $this->request('GET', "/pages/{$kontaktIds[0]}");
            if ($kontaktData) {
                $kontaktProps = $kontaktData['properties'] ?? [];
                $kontaktEmail = $kontaktProps['E-Mail']['email'] ?? '';
                $kontaktVorname = $this->extractRichText($kontaktProps['Vorname'] ?? []);
                $kontaktNachname = $this->extractRichText($kontaktProps['Nachname'] ?? []);
                $kontaktDuzen = (bool) ($kontaktProps['Du/Sie']['checkbox'] ?? false);
            }
        }

        // Stand-Nr aus rich_text oder select
        $stand = '';
        if (isset($props['Stand'])) {
            if (($props['Stand']['type'] ?? '') === 'rich_text') {
                $stand = $this->extractRichText($props['Stand']);
            } elseif (($props['Stand']['type'] ?? '') === 'select') {
                $stand = $props['Stand']['select']['name'] ?? '';
            }
        }

        return [
            'id'               => $pageId,
            'firma'            => $this->extractTitle($props['Aussteller'] ?? $props['Firma'] ?? $props['Name'] ?? []),
            'beschreibung'     => $this->extractRichText($props['Beschreibung'] ?? []),
            'messe_special'    => $this->extractRichText($props['Messe-Special'] ?? []),
            'website'          => $props['Website']['url'] ?? $props['Webseite']['url'] ?? '',
            'webshop'          => $props['Webshop']['url'] ?? '',
            'logo'             => $logo,
            'stand'            => $stand,
            'kontakt_email'    => $kontaktEmail,
            'kontakt_vorname'  => $kontaktVorname,
            'kontakt_nachname' => $kontaktNachname,
            'kontakt_duzen'    => $kontaktDuzen,
            'kategorien'       => array_map(fn($k) => $k['name'], $props['Kategorie']['multi_select'] ?? []),
            'event_ids'        => array_column($props['Event']['relation'] ?? [], 'id'),
        ];
    }

    /**
     * Review-Seite in der AS26_Aussteller Reviews DB erstellen.
     * Befüllt Properties mit aktuellen Aussteller-Daten + Body mit Anleitung/Checkliste.
     *
     * @param array  $aussteller  Aussteller-Daten (von getAusstellerForReview oder JSON)
     * @param string $deadline    ISO-Datum (z.B. 2026-06-01)
     * @param string $kontaktEmail  E-Mail des Ansprechpartners (optional)
     * @return array|null  Notion Page (inkl. „id" und „url")
     */
    public function createAusstellerReviewPage(array $aussteller, string $deadline, string $kontaktEmail = ''): ?array
    {
        $decode = fn(string $s): string => html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (['firma', 'beschreibung', 'messe_special', 'website', 'webshop', 'stand'] as $k) {
            if (isset($aussteller[$k]) && is_string($aussteller[$k])) {
                $aussteller[$k] = $decode($aussteller[$k]);
            }
        }

        // ── Page Properties ──
        $properties = [
            'Firmenname' => [
                'title' => [['text' => ['content' => $aussteller['firma'] ?? '']]],
            ],
            'Aussteller (AS26)' => [
                'relation' => [['id' => $aussteller['page_id'] ?? $aussteller['id']]],
            ],
            'Status' => [
                'select' => ['name' => 'Entwurf'],
            ],
            'Deadline' => [
                'date' => ['start' => $deadline],
            ],
        ];

        // Beschreibung vorausfüllen
        if (!empty($aussteller['beschreibung'])) {
            $properties['Beschreibung'] = [
                'rich_text' => [['text' => ['content' => mb_substr($aussteller['beschreibung'], 0, 2000)]]],
            ];
        }

        // Messe-Special vorausfüllen
        if (!empty($aussteller['messe_special'])) {
            $properties['Messe-Special'] = [
                'rich_text' => [['text' => ['content' => mb_substr($aussteller['messe_special'], 0, 2000)]]],
            ];
        }

        // URLs vorausfüllen
        if (!empty($aussteller['website'])) {
            $properties['Webseite'] = ['url' => $aussteller['website']];
        }
        if (!empty($aussteller['webshop'])) {
            $properties['Webshop'] = ['url' => $aussteller['webshop']];
        }

        // Logo aus Aussteller-DB übernehmen
        if (!empty($aussteller['logo'])) {
            $properties['Logo'] = [
                'files' => [[
                    'type' => 'external',
                    'name' => ($aussteller['firma'] ?? 'Logo') . '.png',
                    'external' => ['url' => $aussteller['logo']],
                ]],
            ];
        }

        // Event-Relation aus Aussteller-DB übernehmen
        if (!empty($aussteller['event_ids'])) {
            $eventRelations = array_map(fn($id) => ['id' => $id], $aussteller['event_ids']);
            $properties['Event'] = ['relation' => $eventRelations];
        }

        // Kontakt-Email
        $email = $kontaktEmail ?: ($aussteller['kontakt_email'] ?? '');
        if (!empty($email)) {
            $properties['Kontakt-Email'] = ['email' => $email];
        }

        // ── Page Body (Blöcke) ──
        $blocks = [];
        $deadlineDe = $this->formatDeadlineDe($deadline);

        // 1) Anleitung
        $blocks[] = self::h3('Anleitung');
        $blocks[] = self::calloutBlock(
            'Bitte prüfen Sie die Daten Ihres Aussteller-Eintrags für die Adventure Southside 2026. ' .
            'Änderungen können Sie direkt in den Feldern oben (Properties) vornehmen. ' .
            'Für Ihr Logo laden Sie bitte eine Datei im Logo-Feld hoch.',
            '📋'
        );
        $blocks[] = self::paragraph("**Deadline:** {$deadlineDe}");
        $blocks[] = self::divider();

        // 2) Aktuelle Daten (zur Orientierung)
        $blocks[] = self::h3('Aktuelle Daten (Übersicht)');
        $blocks[] = self::paragraph("**Firmenname:** {$aussteller['firma']}");
        $blocks[] = self::paragraph("**Stand:** " . ($aussteller['stand'] ?: '(wird noch zugewiesen)'));

        $kategorien = implode(', ', $aussteller['kategorien'] ?? []);
        if ($kategorien) {
            $blocks[] = self::paragraph("**Kategorien:** {$kategorien}");
        }

        $blocks[] = self::divider();

        // 3) Editierbare Felder – Hinweise
        $blocks[] = self::h3('Bitte prüfen & bearbeiten');
        $blocks[] = self::paragraph('Die folgenden Felder können Sie **direkt in den Properties** (oben) bearbeiten:');
        $blocks[] = self::bullet('**Firmenname** – Name wie er in der App erscheint');
        $blocks[] = self::bullet('**Beschreibung** – Langtext über Ihr Unternehmen');
        $blocks[] = self::bullet('**Messe-Special** – Besondere Angebote/Aktionen auf der Messe');
        $blocks[] = self::bullet('**Webseite** – Link zu Ihrer Website');
        $blocks[] = self::bullet('**Webshop** – Link zu Ihrem Online-Shop');
        $blocks[] = self::bullet('**Logo** – Firmenlogo hochladen (PNG bevorzugt, transparenter Hintergrund wenn möglich)');
        $blocks[] = self::divider();

        // 4) Checkliste
        $blocks[] = self::h3('Checkliste');
        $blocks[] = self::todoBlock('Logo geprüft / neu hochgeladen');
        $blocks[] = self::todoBlock('Firmenname korrekt');
        $blocks[] = self::todoBlock('Beschreibung geprüft / aktualisiert');
        $blocks[] = self::todoBlock('Messe-Special geprüft / aktualisiert');
        $blocks[] = self::todoBlock('Webseite-Link geprüft / aktualisiert');
        $blocks[] = self::todoBlock('Webshop-Link geprüft / aktualisiert');
        $blocks[] = self::todoBlock('Alles korrekt – Freigabe erteilt');
        $blocks[] = self::divider();

        // 5) Rechtehinweis
        $blocks[] = self::paragraph('**Wichtig:** Ich bestätige, dass ich über alle Rechte an den hochgeladenen Inhalten verfüge. Ich räume der Rough Road Events GmbH sowie von ihr beauftragten Dritten das zeitlich, räumlich und inhaltlich unbeschränkte Recht ein, dieses Material für redaktionelle und werbliche Zwecke zu nutzen.');

        return $this->request('POST', '/pages', [
            'parent' => ['database_id' => NOTION_AUSSTELLER_REVIEW_DB],
            'properties' => $properties,
            'children' => $blocks,
        ]);
    }

    /**
     * E-Mail-Draft für Aussteller-Review erstellen.
     * Nutzt die bestehende E-Mails Ausgehend DB.
     */
    public function createAusstellerEmailDraft(
        string $firmenname,
        string $toAdresse,
        string $vorname,
        string $nachname,
        string $reviewUrl,
        string $deadline,
        bool $duzen = false
    ): ?array {
        $deadlineDe = $this->formatDeadlineDe($deadline);

        // Template aus Notion lesen (NOTION_AUSSTELLER_EMAIL_TEMPLATE)
        $templateText = '';
        $betreffTemplate = '';
        if (!empty(NOTION_AUSSTELLER_EMAIL_TEMPLATE)) {
            $tplPage = $this->request('GET', '/pages/' . NOTION_AUSSTELLER_EMAIL_TEMPLATE);
            if ($tplPage) {
                $tplProps = $tplPage['properties'] ?? [];
                $templateText = $this->extractRichText($tplProps['E-Mail-Text'] ?? []);
                $betreffTemplate = $this->extractTitle($tplProps['Betreff'] ?? []);
            }
        }

        // Fallback: hardcoded Template falls Notion-Template nicht lesbar
        if (empty($templateText)) {
            $templateText = "Hi VORNAME,\n\nvielen Dank für deine Teilnahme an der Adventure Southside 2026!\n\nBitte prüfe deinen Aussteller-Eintrag:\n\n👉 REVIEW_LINK\n\nDeadline: DEADLINE\n\nViele Grüße,\nDas Adventure Southside Team";
        }

        // Platzhalter ersetzen
        $emailText = str_replace(
            ['VORNAME', 'NACHNAME', 'REVIEW_LINK', 'DEADLINE'],
            [$vorname, $nachname, $reviewUrl, $deadlineDe],
            $templateText
        );

        // Betreff: aus Notion-Template oder Fallback
        $betreff = !empty($betreffTemplate)
            ? str_replace('Template: ', '', $betreffTemplate)
            : "Aussteller-Datencheck – Bitte prüfe deine Angaben für die Adventure Southside 2026";

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
                'rich_text' => [['text' => ['content' => 'Review-Link Aussteller (Kommentieren)']]],
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
            return array_values(array_filter(array_map(function($s) {
                return html_entity_decode(trim($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $matches[1])));
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
                    'qa_enabled'          => ($props['Fragen erlauben?']['checkbox'] ?? false) === true,
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

    // ── APP-FEEDBACK ─────────────────────────────────────

    public function createAppFeedback(
        int    $appBewertung,
        int    $navigation,
        int    $ladegeschwindigkeit,
        int    $nuetzlichkeit,
        int    $nps,
        string $verbesserung  = '',
        string $featureWunsch = '',
        string $plattform     = 'Browser',
        string $appVersion    = '',
        string $deviceId      = ''
    ): ?array {
        $now   = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c');
        $title = 'AS26-' . date('Y-m-d') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);

        $properties = [
            'Titel'              => ['title'     => [['text' => ['content' => $title]]]],
            'Event'              => ['select'    => ['name' => 'AS26']],
            'App-Bewertung'      => ['number'    => $appBewertung],
            'Navigation'         => ['number'    => $navigation],
            'Ladegeschwindigkeit'=> ['number'    => $ladegeschwindigkeit],
            'Nützlichkeit'       => ['number'    => $nuetzlichkeit],
            'NPS'                => ['number'    => $nps],
            'Plattform'          => ['select'    => ['name' => $plattform]],
            'Zeitstempel'        => ['date'      => ['start' => $now]],
        ];

        if ($verbesserung !== '') {
            $properties['Verbesserungsvorschlag'] = [
                'rich_text' => [['text' => ['content' => mb_substr($verbesserung, 0, 2000)]]],
            ];
        }
        if ($featureWunsch !== '') {
            $properties['Feature-Wunsch'] = [
                'rich_text' => [['text' => ['content' => mb_substr($featureWunsch, 0, 2000)]]],
            ];
        }
        if ($appVersion !== '') {
            $properties['App-Version'] = [
                'rich_text' => [['text' => ['content' => $appVersion]]],
            ];
        }
        if ($deviceId !== '') {
            $properties['Device-ID'] = [
                'rich_text' => [['text' => ['content' => $deviceId]]],
            ];
        }

        return $this->request('POST', '/pages', [
            'parent'     => ['database_id' => NOTION_APP_FEEDBACK_DB],
            'properties' => $properties,
        ]);
    }

    public function hasAppFeedbackToday(string $deviceId): bool
    {
        if (empty(NOTION_APP_FEEDBACK_DB)) return false;
        $today = (new DateTime('today', new DateTimeZone('Europe/Berlin')))->format('c');

        $result = $this->queryDatabase(NOTION_APP_FEEDBACK_DB, [
            'filter'    => [
                'and' => [
                    ['property' => 'Device-ID',   'rich_text' => ['equals' => $deviceId]],
                    ['property' => 'Zeitstempel',  'date'      => ['on_or_after' => $today]],
                ],
            ],
            'page_size' => 1,
        ]);
        return !empty($result['results']);
    }
}
