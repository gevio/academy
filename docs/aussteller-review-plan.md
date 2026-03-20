# Aussteller-Review-Workflow – Konzept & Plan

> Analog zum bestehenden Workshop-Review-System sollen Aussteller ihre Einträge selbst bearbeiten können. Änderungen werden vom Team geprüft und nach Freigabe automatisch in die DB übernommen.

## 1. Übersicht: Ist-Zustand (Workshop Review)

| Komponente | Details |
|---|---|
| Trigger | Admin klickt "Review senden" auf Workshop-Detailseite |
| Backend | `send-review.php` → `NotionClient::createReviewPage()` |
| Notion DBs | Workshop Reviews DB (`NOTION_REVIEW_DB`), E-Mails Ausgehend (`NOTION_EMAIL_DB`) |
| Flow | Review-Seite in Notion erstellen → E-Mail-Draft pro Referent → Referent bearbeitet in Notion → Team prüft → Freigabe |
| Status-Flow | Offen → In Review → Änderungen nötig → Freigegeben → Abgeschlossen |

## 2. Ziel: Aussteller-Review

Aussteller sollen folgende Felder selbst bearbeiten können:

| Feld | Notion-Property (Aussteller-DB) | Typ |
|---|---|---|
| **Logo** | `Logo` | files |
| **Firmenname/Titel** | `Name` / `Firma` | title |
| **Langtext/Beschreibung** | `Beschreibung` | rich_text |
| **Messe-Special** | `Messe-Special` | rich_text |
| **Link Webseite** | `Webseite` | url |
| **Link Shop** | `Webshop` | url |

Nach Bearbeitung:
- **Notification ans Team** wenn Kunde Status auf "Eingereicht" setzt
- **Team-Freigabe** → n8n schreibt Daten automatisch in Aussteller-DB zurück

---

## 3. Benötigte Komponenten

### 3.1 Notion: Datenbank "AS26_Aussteller Reviews" ✅

> DB-ID: `d9797aa541bb47dab3b4f6766a646c61`
> `.env`: `NOTION_AUSSTELLER_REVIEW_DB`

**Datenbank-Properties (ist-Stand):**

| Property | Typ | Beschreibung |
|---|---|---|
| `Firmenname` | title | Name des Ausstellers |
| `Logo` | files | Logo-Upload durch Aussteller |
| `Beschreibung` | rich_text | Langtext-Beschreibung |
| `Messe-Special` | rich_text | Messe-Special / Angebote auf der Messe |
| `Webseite` | url | Link zur Firmen-Website |
| `Webshop` | url | Link zum Online-Shop |
| `Aussteller (AS26)` | relation | → AS26_Aussteller (single_property) |
| `Event` | relation | → Master-Veranstaltungen (zeigt Title, z.B. "Adventure Southside 2026") |
| `Status` | select | Entwurf / Eingereicht / Freigegeben / Übertragen |
| `Deadline` | date | Frist für Rückmeldung des Ausstellers |
| `Änderungsdatum` | date | Letzte Änderung durch den Aussteller |
| `Kontakt-Email` | email | Ansprechpartner, wird per n8n aus Kontakte Master befüllt |
| `Team-Freigabe` | checkbox | Nur vom Team setzbar (hidden für Kunde). Workflow C prüft diese Checkbox. |
| `Kommentar` | rich_text | Internes Kommentarfeld fürs Team |

**Status-Flow:**
```
Entwurf → Eingereicht → Freigegeben → Übertragen
```
- **Entwurf:** Review-Seite erstellt, E-Mail versendet, Kunde kann bearbeiten
- **Eingereicht:** Kunde hat Daten geprüft und Status selbst auf "Eingereicht" gesetzt
- **Freigegeben:** Team hat Review geprüft und freigegeben
- **Übertragen:** Daten wurden in Aussteller-DB zurückgeschrieben (n8n)

**Seiteninhalt (Body-Template):**

1. **Anleitung** – "Bitte prüfen Sie die folgenden Daten Ihres Eintrags..."
2. **Status & Deadline**
3. **Aktuelle Aussteller-Daten:**
   - Firmenname
   - Beschreibung (Langtext)
   - Messe-Special
   - Webseite-Link
   - Webshop-Link
   - Logo (Bild-Block oder Platzhalter)
   - Stand-Nr.
   - Kategorien
4. **Änderungs-Checkliste** (Checkboxen):
   - [ ] Logo geprüft / neu hochgeladen
   - [ ] Firmenname korrekt
   - [ ] Beschreibung geprüft / aktualisiert
   - [ ] Messe-Special geprüft / aktualisiert
   - [ ] Webseite-Link geprüft / aktualisiert
   - [ ] Webshop-Link geprüft / aktualisiert
   - [ ] Alles korrekt – Freigabe erteilt
5. **Hinweis:** "Wenn alles passt, setze bitte den Status (oben) auf **Eingereicht**."

### 3.2 Notion: E-Mails Ausgehend DB (bestehend nutzen)

Gleiche DB wie beim Workshop-Review (`NOTION_EMAIL_DB`). Neues Template:

| Property | Wert |
|---|---|
| `Betreff` | "Aussteller-Datencheck – Bitte prüfe deine Angaben für die Adventure Southside 2026" |
| `Template-Name` | "Review-Link Aussteller (Kommentieren)" |
| `Status` | "Draft" |
| `Versand-Info` | "n8n" |
| `Event-Bezug` | ["Southside 2026"] |

**E-Mail-Template:** Wird aus Notion gelesen (NOTION_AUSSTELLER_EMAIL_TEMPLATE).
- Template-ID: `21477417cc7a47f0b513fa54298b68cc`
- Platzhalter: VORNAME, NACHNAME, REVIEW_LINK, DEADLINE
- **Keine Emojis im E-Mail-Text** (werden in Outlook nicht korrekt dargestellt)
- `<b>`-Tags im Template werden automatisch zu Notion-Bold konvertiert

### 3.3 PHP: Endpoint `send-aussteller-review.php` ✅

```
POST /api/send-aussteller-review.php
Header: X-Admin-Secret: {secret}
Body:   { "aussteller_id": "...", "deadline": "2026-06-01" }
```

**Ablauf:**
1. Aussteller-Daten aus `aussteller.json` + live aus Notion laden (Merge)
2. Kontakt-Email über Relation "Kontakt (Master)" → E-Mail ermitteln
3. Review-Seite in "AS26_Aussteller Reviews"-DB erstellen (inkl. Logo via SITE_URL)
4. E-Mail-Draft in E-Mails-DB erstellen (Template aus Notion mit Platzhalter-Ersetzung)
5. Response mit Review-URL + Email-Status

**NotionClient-Methoden:**
- `getAusstellerForReview(string $pageId): array` – Liest Aussteller + Kontakt-Relation
- `createAusstellerReviewPage(array $aussteller, string $deadline, string $kontaktEmail): ?array`
- `createAusstellerEmailDraft(...)` – Liest Notion-Template, ersetzt Platzhalter

### 3.4 Frontend: Admin-Button auf Aussteller-Profilseite ✅

In `aussteller.js` → `renderProfile()`:
- Button "Review an Aussteller senden" (nur für Admins sichtbar)
- Admin-Modus: `?admin=SECRET` → `sessionStorage` (gleiche Logik wie `details.html`)
- Deadline-Prompt (Standard: +14 Tage) → POST an API → Ergebnis mit E-Mail-Info + Review-Link

### 3.5 n8n Workflows

#### Workflow A: E-Mail-Versand

> **ACTION ITEM – Team-Entscheidung:**
> Soll der E-Mail-Draft nach erfolgreichem Test direkt mit Status "Released" erstellt werden
> (statt "Draft"), damit der bestehende n8n-Versand-Workflow ihn sofort verschickt?
> → Dann entfällt ein separater Polling-Workflow.

**Aktueller Stand:** E-Mail-Draft wird mit Status "Draft" erstellt.
**Vorschlag:** Nach Team-Freigabe Status auf "Released" setzen → bestehender n8n-Workflow verschickt.

#### Workflow B: Notification ans Team (Status = Eingereicht)

**Trigger:** Schedule (alle 15–30 Min)

```
[Schedule Trigger]
    ↓
[Notion: Query "AS26_Aussteller Reviews" WHERE Status = "Eingereicht"]
    ↓
[Filter: Nur neue Einreichungen (noch nicht benachrichtigt)]
    ↓
[Team-Benachrichtigung: E-Mail/Telegram an Team]
    "Aussteller {Firmenname} hat Review eingereicht – bitte prüfen"
    + Link zur Review-Seite
    ↓
[Optional: Status-Marker setzen oder Kommentar, damit nicht doppelt benachrichtigt wird]
```

**Wie erkennt n8n "neu eingereicht"?**
- Option 1: Prüfe `last_edited_time` > letzte Ausführung
- Option 2: Setze nach Notification einen internen Marker (z.B. Kommentar "Team benachrichtigt")
- Option 3: Nutze n8n Static Data (`$getWorkflowStaticData`) um letzte IDs zu merken

#### Workflow C: Freigabe → Daten in Aussteller-DB übertragen

**Trigger:** Schedule (alle 15–30 Min)

**Sicherheit:** Nur wenn `Status = Freigegeben` **UND** `Team-Freigabe = true` (Checkbox).
Der Kunde kann zwar den Status auf "Freigegeben" setzen, aber die Checkbox ist für ihn
unsichtbar (hidden). Nur das Team kann die Checkbox setzen → doppelte Absicherung.

```
[Schedule Trigger]
    ↓
[Notion: Query "AS26_Aussteller Reviews" WHERE Status = "Freigegeben" AND Team-Freigabe = true]
    ↓
[Notion: Review-Seite lesen → Firmenname, Beschreibung, Messe-Special, Webseite, Webshop, Logo]
    ↓
[Notion: Aussteller-DB-Seite updaten (über Aussteller-Relation)]
    ↓
[Status auf "Übertragen" setzen]
    ↓
[Optional: Team-Benachrichtigung "Daten für {Firma} übertragen"]
```

**Logo-Handling:**
- Wenn Aussteller neues Logo in Review-Seite hochlädt → Notion Files URL
- n8n muss Logo herunterladen und auf VPS speichern (`/img/aussteller/{id}.webp`)
- Alternative: Nacht-Cron (`generate-aussteller-json.php`) holt Logo automatisch

**JSON-Export:**
- Stündlicher Cron übernimmt Daten automatisch in `aussteller.json` (max 1h Verzögerung)
- Für Logos greift der Nacht-Job oder manuelles `--refresh-images`

---

## 4. Notion: Kontakt-Email der Aussteller ✅

Die Kontakt-Email wird **beim Erstellen der Review-Seite** per PHP aus der Notion-Relation geholt:
- Aussteller-Seite → Relation "Kontakt (Master)" → Kontakte Master DB → Feld "E-Mail"
- Zusätzlich: Vorname, Nachname, Du/Sie (für Anrede in E-Mail)
- **Voraussetzung:** Kontakte Master DB muss mit Notion-Integration (Clawdy-n8n) geteilt sein ✅

---

## 5. Implementierungsreihenfolge

### Phase 1: Notion Setup ✅
1. [x] Neue Notion-DB "AS26_Aussteller Reviews" angelegt (13 Properties)
2. [x] `.env` erweitern: `NOTION_AUSSTELLER_REVIEW_DB`, `NOTION_AUSSTELLER_EMAIL_TEMPLATE`
3. [x] Event-Relation (→ Master-Veranstaltungen) in DB ergänzt
4. [x] E-Mail-Template in Notion angelegt (ID: `21477417cc7a47f0b513fa54298b68cc`)
5. [x] Notion-Integrationen geteilt: Kontakte Master DB, Event DB

### Phase 2: PHP Backend ✅
6. [x] `NotionClient.php`: `getAusstellerForReview()` – inkl. Kontakt-Relation
7. [x] `NotionClient.php`: `createAusstellerReviewPage()` – Properties + Body-Blöcke
8. [x] `NotionClient.php`: `createAusstellerEmailDraft()` – Template aus Notion, Platzhalter
9. [x] `send-aussteller-review.php` – Endpoint mit Auth, Merge, Review + Email
10. [x] Logo via SITE_URL + logo_local (statt Notion signed URLs)
11. [x] HTML-Tags aus Template zu Notion-Bold konvertiert
12. [x] Messe-Special + Webshop in `generate-aussteller-json.php` + Frontend

### Phase 3: Frontend ✅
13. [x] Admin-Modus in `aussteller.js` (gleiche Logik wie `details.html`)
14. [x] Review-Button im Aussteller-Profil (nur Admin sichtbar)
15. [x] Click-Handler: Deadline-Prompt → API-Call → Ergebnis-Anzeige

### Phase 4: n8n Workflows
16. [ ] **ACTION ITEM:** Team-Entscheidung E-Mail-Versand (Draft vs. Released)
17. [ ] **ACTION ITEM:** Emojis aus Notion E-Mail-Template entfernen
18. [ ] Workflow B: Notification ans Team bei Status "Eingereicht"
19. [ ] Workflow C: Freigabe → Daten in Aussteller-DB übertragen + Status "Übertragen"

### Phase 5: Test & Rollout
20. [ ] End-to-End Test mit einem Test-Aussteller
21. [ ] Massenversand: Review-Links an alle Aussteller (ggf. in Batches)

---

## 6. Unterschiede zum Workshop-Review

| Aspekt | Workshop Review | Aussteller Review (neu) |
|---|---|---|
| Empfänger | Referenten (Personen) | Aussteller (Firmen) |
| Editierbare Felder | Titel, Beschreibung, Bulletpoints, Kategorien, Format, Dauer, Ort, Datum | Logo, Firmenname, Beschreibung, Messe-Special, Webseite, Webshop |
| Datei-Upload | Foto (Referent) | Logo (Firma) |
| Kunde signalisiert "Fertig" | – | Status → "Eingereicht" (Option B) |
| Team-Notification | – | n8n pollt Status "Eingereicht" → Benachrichtigung |
| Auto-Update nach Freigabe | Nein (manuell) | Ja (n8n Workflow C) |
| Notion-DB | Workshop Reviews | AS26_Aussteller Reviews (neu) |
| E-Mail-DB | E-Mails Ausgehend (bestehend) | E-Mails Ausgehend (bestehend) |
| E-Mail-Template | Notion-Seite | Notion-Seite (eigenes Template) |

---

## 7. Offene Punkte

1. **ACTION ITEM (Team):** E-Mail-Versand – Draft vs. Released bei Erstellung
2. **ACTION ITEM (Notion):** Emojis aus E-Mail-Template entfernen
3. **Mehrere Ansprechpartner pro Aussteller?** → Aktuell wird nur der erste Kontakt verwendet
4. **Batch-Versand:** Reviews einzeln oder gesammelt an alle 162 Aussteller? (ggf. in Batches à 20-30)
5. **Logo-Sync:** Nacht-Cron reicht oder sofortige Übernahme gewünscht?
