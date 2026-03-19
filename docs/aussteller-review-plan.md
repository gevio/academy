# Aussteller-Review-Workflow – Konzept & Plan

> Analog zum bestehenden Workshop-Review-System sollen Aussteller ihre Einträge selbst bearbeiten können. Änderungen werden vom Team geprüft und nach Freigabe automatisch in die DB übernommen.

## 1. Übersicht: Ist-Zustand (Workshop Review)

| Komponente | Details |
|---|---|
| Trigger | Admin klickt "Review senden" auf Workshop-Detailseite |
| Backend | `send-review.php` → `NotionClient::createReviewPage()` |
| Notion DBs | Workshop Reviews DB (`NOTION_REVIEW_DB`), E-Mails Ausgehend (`NOTION_EMAIL_DB`) |
| Flow | Review-Seite in Notion erstellen → E-Mail-Draft pro Referent → Referent bearbeitet in Notion → Team prüft → Freigabe |
| Status-Flow | Offen → In Review → Änderungen nötig → Freigegeben |

## 2. Ziel: Aussteller-Review

Aussteller sollen folgende Felder selbst bearbeiten können:

| Feld | Notion-Property (Aussteller-DB) | Typ |
|---|---|---|
| **Logo** | `Logo` | files |
| **Firmenname/Titel** | `Name` / `Firma` | title |
| **Langtext/Beschreibung** | `Beschreibung` | rich_text |
| **Link Webseite/Shop** | `Webseite` | url |

Nach Bearbeitung:
- **Mail ans Team** zum Review
- **Freigabe** → geänderte Daten automatisch in Aussteller-DB eintragen

---

## 3. Benötigte Komponenten

### 3.1 Notion: Neue Datenbank "Aussteller Reviews"

**Datenbank-Properties:**

| Property | Typ | Beschreibung |
|---|---|---|
| `Review-Titel` | title | "Review – {Firmenname}" |
| `Event` | select | "Southside 2026" |
| `Aussteller` | relation | → Aussteller-DB |
| `Review-Status` | status | Offen / In Review / Änderungen nötig / Freigegeben |
| `Deadline` | date | Frist für Rückmeldung |
| `Kontakt-Email` | email | Ansprechpartner beim Aussteller |
| `Notizen` | rich_text | Interne Notizen / weitere Emails |

**Seiteninhalt (Body-Template):**

1. **Anleitung** – "Bitte prüfen Sie die folgenden Daten Ihres Eintrags..."
2. **Status & Deadline**
3. **Aktuelle Aussteller-Daten:**
   - Firmenname
   - Beschreibung (Langtext)
   - Webseite/Shop-Link
   - Logo (Bild-Block oder Platzhalter)
   - Stand-Nr.
   - Kategorien
4. **Änderungs-Checkliste** (Checkboxen):
   - [ ] Logo geprüft / neu hochgeladen
   - [ ] Firmenname korrekt
   - [ ] Beschreibung geprüft / aktualisiert
   - [ ] Webseite-Link geprüft / aktualisiert
   - [ ] Alles korrekt – Freigabe erteilt

### 3.2 Notion: E-Mails Ausgehend DB (bestehend nutzen)

Gleiche DB wie beim Workshop-Review (`NOTION_EMAIL_DB`). Neues Template:

| Property | Wert |
|---|---|
| `Betreff` | "Review: Ihr Eintrag bei Adventure Southside 2026" |
| `Template-Name` | "Review-Link Aussteller (Kommentieren)" |
| `Status` | "Draft" |
| `Versand-Info` | "n8n" |
| `Event-Bezug` | ["Southside 2026"] |

**E-Mail-Text (Vorlage):**
```
Hallo {VORNAME},

vielen Dank für Ihre Teilnahme an der Adventure Southside 2026!

Bitte prüfen Sie Ihren Aussteller-Eintrag und nehmen Sie ggf. Änderungen vor:

👉 {REVIEW_LINK}

Bitte bis {DEADLINE} prüfen und freigeben.

Folgende Daten können Sie bearbeiten:
• Firmenname/Titel
• Beschreibung (Langtext)
• Webseite/Shop-Link
• Logo (neues Bild hochladen)

Bei Fragen antworten Sie einfach auf diese E-Mail.

Viele Grüße,
Das Adventure Southside Team
```

### 3.3 PHP: Neuer Endpoint `send-aussteller-review.php`

**Analog zu `send-review.php`:**

```
POST /api/send-aussteller-review.php
Header: X-Admin-Secret: {secret}
Body:   { "aussteller_id": "...", "deadline": "2026-06-01" }
```

**Ablauf:**
1. Aussteller-Daten aus `aussteller.json` oder live aus Notion laden
2. Kontakt-Email ermitteln (aus Notion Aussteller-DB)
3. Review-Seite in neuer "Aussteller Reviews"-DB erstellen
4. E-Mail-Draft in bestehender E-Mails-DB erstellen
5. Response mit Review-URL + Email-Status

**Neue NotionClient-Methoden:**
- `createAusstellerReviewPage(array $aussteller, string $deadline): ?array`
- `createAusstellerEmailDraft(...)` (oder bestehende `createEmailDraft()` erweitern)

### 3.4 Frontend: Admin-Button auf Aussteller-Detailseite

Auf `aussteller-detail.html` (oder wo Aussteller-Details angezeigt werden):
- Button "📧 Review an Aussteller senden" (nur für Admins sichtbar)
- Gleiche Logik wie bei Workshop-Details (`details.html`)

### 3.5 n8n: Workflow "Aussteller Review → Auto-Update"

**NEUER Workflow – der entscheidende Unterschied zum Workshop-Review:**

Nach Freigabe sollen die Daten **automatisch** in die Aussteller-DB zurückgeschrieben werden.

```
[Schedule Trigger: alle 5-10 Min]
    ↓
[Notion: Query "Aussteller Reviews" WHERE Review-Status = "Freigegeben"]
    ↓
[Filter: Nur neue Freigaben (noch nicht verarbeitet)]
    ↓
[Notion: Review-Seite lesen → geänderte Daten extrahieren]
    ↓
[Notion: Aussteller-DB-Seite updaten (Titel, Beschreibung, Webseite, Logo)]
    ↓
[Review-Status auf "Abgeschlossen" setzen]
    ↓
[Optional: Telegram-Benachrichtigung ans Team]
    ↓
[Optional: JSON-Export neu triggern → aussteller.json aktualisieren]
```

**JSON-Export (Ist-Zustand):**
- Script: `scripts/generate-aussteller-json.php`
- **Cron stündlich:** `php generate-aussteller-json.php --skip-images` (nur Daten, kein Logo-Download)
- **Cron nachts:** `php generate-aussteller-json.php` (mit Logo-Download als WebP, max 200px)
- Output: `public/api/aussteller.json` + `public/api/standplan.json` + `public/img/aussteller/*.webp`
- Felder die exportiert werden: firma, stand, beschreibung, kategorien, website, instagram, logo_url, logo_local, Standplan-Koordinaten

**→ Nach Freigabe reicht es, die Notion-DB zu updaten.** Der stündliche Cron übernimmt die Daten automatisch in die JSON (max 1h Verzögerung). Für Logos (Bild-Upload) greift der Nacht-Job – oder man triggert manuell `--refresh-images`.

### 3.6 n8n: Workflow "Review-Benachrichtigung ans Team"

**Wenn Aussteller die Review-Seite bearbeitet:**

```
[Schedule Trigger: alle 5-10 Min]
    ↓
[Notion: Query "Aussteller Reviews" WHERE last_edited_time > letzte Prüfung]
    ↓
[Filter: Seiten die sich geändert haben]
    ↓
[E-Mail oder Telegram ans Team: "Aussteller X hat Review bearbeitet"]
```

---

## 4. Notion: Kontakt-Email der Aussteller

**Prüfen:** Hat die Aussteller-DB bereits ein Email-Feld?

- Falls ja → direkt nutzen
- Falls nein → Property `Kontakt-Email` (type: email) zur Aussteller-DB hinzufügen
- Alternative: Separate Kontakt-DB mit Relation zur Aussteller-DB

**Ohne Email-Adresse kann kein Review-Link verschickt werden!**

---

## 5. Implementierungsreihenfolge

### Phase 1: Notion Setup
1. [ ] Aussteller-DB prüfen: Email-Feld vorhanden? Ggf. anlegen
2. [ ] Neue Notion-DB "Aussteller Reviews" anlegen (Properties s.o.)
3. [ ] Review-Seitentemplate erstellen und testen
4. [ ] E-Mail-Template für Aussteller-Review erstellen

### Phase 2: PHP Backend
5. [ ] `NotionClient.php` erweitern: `createAusstellerReviewPage()`
6. [ ] `NotionClient.php` erweitern: Email-Draft für Aussteller
7. [ ] Neuer Endpoint `send-aussteller-review.php`
8. [ ] `.env` erweitern: `NOTION_AUSSTELLER_REVIEW_DB=...`
9. [ ] Testen: Review-Seite + Email-Draft werden korrekt erstellt

### Phase 3: Frontend
10. [ ] Admin-Button auf Aussteller-Detailseite
11. [ ] Erfolgs-/Fehlermeldung nach Review-Versand

### Phase 4: n8n Workflows
12. [ ] Workflow: "Aussteller Review Benachrichtigung" (Team informieren bei Änderungen)
13. [ ] Workflow: "Aussteller Review Freigabe → DB Update" (Auto-Übernahme nach Freigabe)
14. [ ] JSON-Export nach DB-Update triggern (`aussteller.json` aktualisieren)

### Phase 5: Test & Rollout
15. [ ] End-to-End Test mit einem Test-Aussteller
16. [ ] Massenversand: Review-Links an alle Aussteller

---

## 6. Unterschiede zum Workshop-Review

| Aspekt | Workshop Review | Aussteller Review (neu) |
|---|---|---|
| Empfänger | Referenten (Personen) | Aussteller (Firmen) |
| Editierbare Felder | Titel, Beschreibung, Bulletpoints, Kategorien, Format, Dauer, Ort, Datum | Logo, Firmenname, Beschreibung, Webseite |
| Datei-Upload | Foto (Referent) | Logo (Firma) |
| Auto-Update nach Freigabe | ❌ Nein (manuell) | ✅ Ja (n8n Workflow) |
| Notion-DB | Workshop Reviews | Aussteller Reviews (neu) |
| E-Mail-DB | E-Mails Ausgehend (bestehend) | E-Mails Ausgehend (bestehend) |

---

## 7. Offene Fragen

1. **Email-Adressen:** Haben alle 162 Aussteller eine Kontakt-Email in Notion?
2. **Logo-Upload:** Soll das Logo direkt in Notion hochgeladen werden (Notion Files) oder per externem Link?
3. **Mehrere Ansprechpartner pro Aussteller?** → Eine oder mehrere Review-Emails?
4. **JSON-Export:** Wie wird `aussteller.json` aktuell generiert? Manuelles Script oder automatisch?
5. **Batch-Versand:** Sollen Reviews einzeln oder gesammelt an alle Aussteller verschickt werden?
6. **Duzen/Siezen:** Einheitlich oder pro Aussteller konfigurierbar?
