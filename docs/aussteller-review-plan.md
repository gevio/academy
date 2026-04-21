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

Aussteller können folgende Felder über das Custom-Frontend prüfen und bearbeiten:

| Feld | Notion-Property (Review-DB) | Editierbar im Frontend |
|---|---|---|
| **Firmenname** | `Firmenname` | Nein (read-only) |
| **Langtext/Beschreibung** | `Beschreibung` | Ja |
| **Messe-Special** | `Messe-Special` | Ja |
| **Link Webseite** | `Webseite` | Ja |
| **Link Shop** | `Webshop` | Ja |
| **Logo** | `Logo` | Nein (Kontakt Team) |

> **Hinweis:** Logo-Änderungen erfolgen nicht über das Formular, sondern per direktem Kontakt zum Team.
> Firmenname wird beim Erstellen aus der Aussteller-DB übernommen und ist danach unveränderlich.

Nach Bearbeitung:
- Bei `REVIEW_AUTO_FREIGABE=true`: Submit setzt Status direkt auf **Freigegeben** + Team-Freigabe=true → n8n WF C überträgt sofort
- Bei `REVIEW_AUTO_FREIGABE=false`: Submit setzt Status auf **Eingereicht** → Team prüft manuell → Freigabe

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
| `Kontakt-Vorname` | text | Vorname des Ansprechpartners, wird beim Review-Erstellen gesetzt ✅ |
| `Team-Freigabe` | checkbox | Nur vom Team setzbar (hidden für Kunde). Workflow C prüft diese Checkbox. |
| `Kommentar` | rich_text | Internes Kommentarfeld fürs Team |

**Status-Flow:**
```
Entwurf → [Eingereicht →] Freigegeben → Übertragen
```
- **Entwurf:** Review-Seite erstellt, E-Mail versendet, Kunde kann bearbeiten
- **Eingereicht:** Nur bei `REVIEW_AUTO_FREIGABE=false` – Kunde reicht ein, Team prüft
- **Freigegeben:** Bei `REVIEW_AUTO_FREIGABE=true` direkt nach Submit (+ Team-Freigabe=true); sonst nach manueller Team-Prüfung
- **Übertragen:** Daten wurden in Aussteller-DB zurückgeschrieben (n8n Workflow C)

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
2. Kontakt-Email + Vorname/Nachname über Relation "Kontakt (Master)" ermitteln
3. Review-Seite in "AS26_Aussteller Reviews"-DB erstellen (inkl. `Kontakt-Vorname`)
4. E-Mail-Draft in E-Mails-DB erstellen; Review-Link verwendet immer `REVIEW_PUBLIC_URL`
5. Response mit Review-URL + Email-Status

> **REVIEW_PUBLIC_URL:** Kunden-E-Mails enthalten immer `https://agenda.adventuresouthside.com/review.html?id=…`
> Die interne Dev-URL (`SITE_URL`) wird Kunden niemals exponiert.

**NotionClient-Methoden:**
- `getAusstellerForReview(string $pageId): array` – Liest Aussteller + Kontakt-Relation (E-Mail, Vorname, Nachname, Du/Sie)
- `createAusstellerReviewPage(array $aussteller, string $deadline, string $kontaktEmail): ?array` – setzt auch `Kontakt-Vorname`
- `createAusstellerEmailDraft(...)` – Liest Notion-Template, ersetzt Platzhalter
- `getAusstellerReview(string $pageId): ?array` – Öffentlicher Read (id, status, firma, kontaktVorname, …)
- `updateAusstellerReview(string $pageId, array $data): bool` – Speichert beschreibung, messeSpecial, webseite, webshop
- `submitAusstellerReview(string $pageId): bool` – Setzt Status je nach `REVIEW_AUTO_FREIGABE`
- `setAusstellerStatus(string $pageId, string $status): bool` – Generisches Status-Update (für CLI)

**Weitere API-Endpunkte:**

| Endpunkt | Methode | Auth | Beschreibung |
|---|---|---|---|
| `get-aussteller-review.php` | GET `?id=` | – (page_id = Token) | Felder der Review lesen |
| `update-aussteller-review.php` | POST | – | beschreibung/messeSpecial/webseite/webshop speichern |
| `submit-aussteller-review.php` | POST | – | Review einreichen (Status-Wechsel) |
| `check-aussteller-review.php` | GET `?aussteller_id=` | X-Admin-Secret | Aktive Review prüfen |

### 3.4 Frontend: Admin-Button auf Aussteller-Profilseite ✅

In `aussteller.js` → `renderProfile()`:
- Button "Review an Aussteller senden" (nur für Admins sichtbar)
- Admin-Modus: `?admin=SECRET` → `sessionStorage` (gleiche Logik wie `details.html`)
- Deadline-Prompt (Standard: +14 Tage) → POST an API → Ergebnis mit E-Mail-Info + Review-Link

### 3.5 Frontend: Kunden-Review-Formular `review.html` ✅

**URL:** `https://agenda.adventuresouthside.com/review.html?id=<32-hex-page-id>`

Eigenes Frontend ohne Bootstrap, nutzt AS26 Design-System (CSS-Variablen: `--as-rot`, `--as-braun-dark`, …).
Die page_id dient gleichzeitig als Auth-Token – kein separates Login.

**States:**
- `loading` → Daten werden aus Notion geladen
- `form` (Status = Entwurf) → Bearbeitbares Formular
- `submitted` (Status = Eingereicht / Freigegeben / Übertragen) → Danke-Seite
- `error` → Fehlermeldung

**Formular-Felder:**
- Firmenname: read-only (`.review-readonly-inline`)
- Webseite, Webshop: text inputs
- Beschreibung, Messe-Special: textareas
- Absenden: Bestätigungs-Dialog → POST an `submit-aussteller-review.php`

**Auto-Save:** Felder werden beim Verlassen automatisch gespeichert (`blur`-Event → `update-aussteller-review.php`).

### 3.6 CLI: Massenversand `cli/send-reviews.php` ✅

```bash
php cli/send-reviews.php                        # alle Aussteller mit Status="Bereit"
php cli/send-reviews.php --dry-run              # Vorschau ohne Änderungen
php cli/send-reviews.php --deadline=2026-06-01  # Deadline überschreiben (Standard: +14 Tage)
php cli/send-reviews.php --limit=5              # max. N Aussteller verarbeiten
```

**Ablauf:**
1. Alle Aussteller mit Status = "Bereit" aus NOTION_AUSSTELLER_DB laden (paginiert)
2. Duplikat-Check: Aussteller mit aktiver Review (Status ≠ Übertragen) überspringen
3. Pro Aussteller: Review-Seite + E-Mail-Draft erstellen (identisch zu `send-aussteller-review.php`)
4. Aussteller-Status auf "Review erfolgt" setzen
5. Rate-Limit: 400ms Pause zwischen Requests

**Voraussetzung:** Aussteller-DB (`NOTION_AUSSTELLER_DB`) braucht Property `Status` (Select) mit Werten `Bereit` und `Review erfolgt`.

### 3.7 n8n Workflows

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
1. [x] Neue Notion-DB "AS26_Aussteller Reviews" angelegt (14 Properties inkl. Kontakt-Vorname)
2. [x] `.env` erweitern: `NOTION_AUSSTELLER_REVIEW_DB`, `NOTION_AUSSTELLER_EMAIL_TEMPLATE`
3. [x] Event-Relation (→ Master-Veranstaltungen) in DB ergänzt
4. [x] E-Mail-Template in Notion angelegt (ID: `21477417cc7a47f0b513fa54298b68cc`)
5. [x] Notion-Integrationen geteilt: Kontakte Master DB, Event DB
6. [x] Property `Kontakt-Vorname` (Text) in AS26_Aussteller Reviews DB angelegt

### Phase 2: PHP Backend ✅
7. [x] `NotionClient.php`: `getAusstellerForReview()` – inkl. Kontakt-Relation (Vorname, Nachname, Du/Sie)
8. [x] `NotionClient.php`: `createAusstellerReviewPage()` – Properties + Body-Blöcke + Kontakt-Vorname
9. [x] `NotionClient.php`: `createAusstellerEmailDraft()` – Template aus Notion, Platzhalter
10. [x] `NotionClient.php`: `getAusstellerReview()`, `updateAusstellerReview()`, `submitAusstellerReview()`, `setAusstellerStatus()`
11. [x] `send-aussteller-review.php` – Endpoint mit Auth, Merge, Review + Email
12. [x] `get-aussteller-review.php`, `update-aussteller-review.php`, `submit-aussteller-review.php`, `check-aussteller-review.php`
13. [x] Logo via SITE_URL + logo_local (statt Notion signed URLs)
14. [x] HTML-Tags aus Template zu Notion-Bold konvertiert
15. [x] Messe-Special + Webshop in `generate-aussteller-json.php` + Frontend
16. [x] `REVIEW_PUBLIC_URL` – Kunden-E-Mails immer mit `agenda.adventuresouthside.com`
17. [x] `REVIEW_AUTO_FREIGABE` – Submit setzt direkt Status "Freigegeben" + Team-Freigabe=true
18. [x] `cli/send-reviews.php` – Massenversand mit `--dry-run`, `--deadline`, `--limit`

### Phase 3: Frontend ✅
19. [x] Admin-Modus in `aussteller.js` (gleiche Logik wie `details.html`)
20. [x] Review-Button im Aussteller-Profil (nur Admin sichtbar)
21. [x] Click-Handler: Deadline-Prompt → API-Call → Ergebnis-Anzeige
22. [x] `review.html` + `review.css` – Kunden-Frontend (formelles Sie, Firmenname read-only, Auto-Save)

### Phase 4: n8n Workflows ✅
23. [ ] **ACTION ITEM:** Team-Entscheidung E-Mail-Versand (Draft vs. Released)
24. [x] Emojis aus Notion E-Mail-Template entfernt
25. [x] Workflow B: Notification ans Team bei Status "Eingereicht" (Telegram + SMTP)
26. [x] Workflow C: Freigabe → Daten in Aussteller-DB übertragen + Status "Übertragen"
27. [x] `regenerate-aussteller.php` Endpoint für sofortige JSON-Aktualisierung
28. [x] 5s Wait vor Regenerate (Timing-Fix)

**n8n Workflow-IDs:**
| Workflow | ID | Trigger |
|---|---|---|
| Workflow B: Eingereicht-Notification | *(bestehend)* | Schedule 15 Min |
| Workflow C: Freigabe → Übertragen | `f0mGzFy9SvXlRMmi` | Schedule 15 Min |

> **Hinweis:** Bei `REVIEW_AUTO_FREIGABE=true` wird Workflow B (Eingereicht-Benachrichtigung)
> nie ausgelöst, da Status direkt zu "Freigegeben" springt. Workflow C greift sofort.

**Workflow C Flow:**
```
Schedule (15 Min) → Query (Status=Freigegeben + Team-Freigabe=true)
  → Daten extrahieren → Aussteller-DB updaten (Notion)
  → Review-Status → "Übertragen"
  → 5s Wait → POST /api/regenerate-aussteller.php (aussteller.json)
  → Telegram Notification
```

### Phase 5: Test & Rollout
29. [x] End-to-End Test auf Dev (4Wheel24, Charme du Süd)
30. [ ] Workflow C auf Prod-URL prüfen + aktiviert lassen
31. [x] CLI `cli/send-reviews.php` bereit für Massenversand
32. [ ] **Massenversand ausführen:** `php cli/send-reviews.php --dry-run` prüfen → dann live

---

## 6. Unterschiede zum Workshop-Review

| Aspekt | Workshop Review | Aussteller Review |
|---|---|---|
| Empfänger | Referenten (Personen) | Aussteller (Firmen) |
| Kunden-Frontend | Notion direkt | Custom `review.html` (eigenes Design) |
| Editierbare Felder | Titel, Beschreibung, Bulletpoints, Kategorien, Format, Dauer, Ort, Datum | Beschreibung, Messe-Special, Webseite, Webshop |
| Datei-Upload | Foto (Referent) | Nicht im Frontend (Logo über Team) |
| Kunde signalisiert "Fertig" | – | Button "Einreichen" in review.html |
| Team-Notification | – | n8n pollt Status "Eingereicht" → Benachrichtigung (bei Auto-Freigabe: entfällt) |
| Auto-Freigabe | Nein | Ja (`REVIEW_AUTO_FREIGABE=true`) → direkt Freigegeben + WF C |
| Auto-Update nach Freigabe | Nein (manuell) | Ja (n8n Workflow C) |
| Massenversand | Einzeln | CLI `cli/send-reviews.php` mit Dry-Run |
| Notion-DB | Workshop Reviews | AS26_Aussteller Reviews |
| E-Mail-DB | E-Mails Ausgehend (bestehend) | E-Mails Ausgehend (bestehend) |
| E-Mail-Template | Notion-Seite | Notion-Seite (eigenes Template, ID: `21477417cc7a47f0b513fa54298b68cc`) |

---

## 7. Offene Punkte

1. **ACTION ITEM (Team):** E-Mail-Versand – Draft vs. Released bei Erstellung (aktuell: Draft)
2. ~~**ACTION ITEM (Notion):** Emojis aus E-Mail-Template entfernen~~ ✅ erledigt
3. ~~**Kontakt-Vorname Property in Review-DB anlegen**~~ ✅ erledigt
4. **Mehrere Ansprechpartner pro Aussteller?** → Aktuell wird nur der erste Kontakt verwendet
5. **Massenversand ausführen:** `php cli/send-reviews.php --dry-run` auf Prod prüfen, dann live
6. **Workflow C auf Prod:** Aktivieren + URL `https://agenda.adventuresouthside.com` bestätigen
7. **Logo-Sync:** Nacht-Cron übernimmt Logos automatisch, Texte sofort via Regenerate-Endpoint
