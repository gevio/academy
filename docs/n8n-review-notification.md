# n8n Workflow: Telegram-Benachrichtigung bei Review-Aktivität

## Ziel

Wenn ein Referent seine Review-Seite in Notion bearbeitet (Kommentar, Status-Änderung, Checkbox angehakt), soll eine Telegram-Nachricht an das Team gesendet werden, damit wir zeitnah reagieren können.

---

## Kontext / Bestehendes System

### Notion-Datenbank: **Workshop Reviews**

| Eigenschaft | Typ | Beschreibung |
|---|---|---|
| `Review-Titel` | Title | z.B. "Review – Maßgeschneiderte Wassertanks" |
| `Review-Status` | Status | `Offen` → `In Review` → `Änderungen nötig` → `Freigegeben` |
| `Referent E-Mail` | Email | E-Mail des Referenten |
| `Workshop` | Relation | Verknüpfung zur Workshop-DB |
| `Deadline` | Date | Freigabe-Deadline |
| `Notizen` | Rich Text | Interne Notizen |

- **DB-ID:** `b69393bea0474e7cb34aa314c621d618`
- **Notion Internal Integration Token:** Liegt in der `.env` als `NOTION_TOKEN` (wird für die n8n-Credentials benötigt)

### Review-Seiten-Aufbau (Page Body)

Jede Review-Seite enthält am Ende einen Abschnitt **"6) Änderungswünsche"** mit einer Checkliste:
- ☐ Titel passt
- ☐ Beschreibung passt
- ☐ Bulletpoints passen
- ☐ Bio passt
- ☐ Foto → bitte hochladen
- ☐ Logo → Aktuell?
- ☐ Sonstiges

Der Referent wird gebeten, Kommentare direkt an Textstellen zu schreiben und die Checkboxen abzuhaken.

### Bestehende n8n-Instanz

- URL: `https://n8n.gevio.cloud`
- Dort laufen bereits Webhooks für Feedback, Q&A und Upvotes (AS26-Projekt)

---

## Workflow-Design

### Variante A: Polling (einfach, empfohlen zum Start)

```
[Schedule Trigger] → [Notion: Query DB] → [Filter: Geänderte Seiten] → [Telegram: Nachricht senden]
```

**Ablauf:**

1. **Schedule Trigger** – alle 5 Minuten (oder 10 Min.)
2. **Notion Node: Query Database**
   - Database-ID: `b69393bea0474e7cb34aa314c621d618`
   - Filter: `last_edited_time` > letzte Prüfzeit
   - Sort: `last_edited_time` descending
3. **Filter Node** – Nur Seiten durchlassen, deren `last_edited_by` **nicht** der eigene Integration-User ist (sonst Endlos-Loop durch eigene Schreibvorgänge)
4. **Telegram Node** – Nachricht formatieren und senden

#### Wie "letzte Prüfzeit" merken?

- **Option 1 (einfach):** Statischer Filter `last_edited_time >= now - 5min` (= Intervall des Triggers). Kann bei Ausfällen Events verpassen, reicht aber für Notifications.
- **Option 2 (robust):** Timestamp in einer n8n Static Data Variable speichern (`$getWorkflowStaticData('global')`) und nach jedem Lauf aktualisieren.

---

## Schritt-für-Schritt Anleitung (Variante A)

### 1. Notion Credentials in n8n anlegen

- Typ: **Notion API (Internal Integration)**
- Token: Der `NOTION_TOKEN` aus der `.env`-Datei
- Name z.B.: `AS26 Notion`

### 2. Telegram Bot + Credentials

- Falls noch kein Bot existiert: bei `@BotFather` in Telegram einen neuen Bot erstellen
- Bot-Token in n8n als **Telegram Credential** hinterlegen
- Eine Telegram-Gruppe oder einen Chat erstellen, in den der Bot postet
- Die **Chat-ID** notieren (z.B. über `@userinfobot` oder `getUpdates`-API)

### 3. Workflow erstellen

#### Node 1: Schedule Trigger

```json
{
  "rule": {
    "interval": [{ "field": "minutes", "minutesInterval": 5 }]
  }
}
```

#### Node 2: Notion – Database Query

- Operation: **Get Many** (aus Datenbank)
- Database ID: `b69393bea0474e7cb34aa314c621d618`
- Filter (JSON):

```json
{
  "and": [
    {
      "timestamp": "last_edited_time",
      "last_edited_time": {
        "after": "{{ $now.minus({ minutes: 6 }).toISO() }}"
      }
    },
    {
      "property": "Review-Status",
      "status": {
        "does_not_equal": "Offen"
      }
    }
  ]
}
```

> 6 Minuten statt 5 als kleiner Overlap-Puffer, damit nichts verloren geht.
> Filter auf Status ≠ "Offen": Nur Seiten die bereits an den Referenten raus sind.

#### Node 3: Filter – Eigene Änderungen ignorieren

- Bedingung: `{{ $json.last_edited_by.id }}` **ist nicht gleich** der Integration-Bot-User-ID
- Die Bot-User-ID findest du über: `GET https://api.notion.com/v1/users/me` (mit dem Token)

#### Node 4: Telegram – Send Message

- Chat ID: `<deine-chat-id>`
- Parse Mode: **MarkdownV2** oder **HTML**
- Text (Beispiel als HTML):

```
🔔 <b>Review-Aktivität</b>

📝 {{ $json.properties['Review-Titel'].title[0].plain_text }}
📊 Status: {{ $json.properties['Review-Status'].status.name }}
📧 Referent: {{ $json.properties['Referent E-Mail'].email }}
🕐 Bearbeitet: {{ $json.last_edited_time }}

🔗 <a href="{{ $json.url }}">In Notion öffnen</a>
```

### 4. Workflow aktivieren

- Workflow auf **Active** setzen
- Testen: Eine Review-Seite in Notion manuell bearbeiten → innerhalb von 5 Min. sollte die Telegram-Nachricht kommen

---

## Optionale Erweiterungen

### A) Status-Änderungen hervorheben

Einen **zweiten Workflow** oder eine Erweiterung die gezielt auf `Review-Status`-Änderungen reagiert:

- Wenn Status → **"Freigegeben"**: 🎉 Celebration-Nachricht
- Wenn Status → **"Änderungen nötig"**: ⚠️ Action Required

Dafür müsstest du den vorherigen Status speichern (z.B. in einer n8n-internen Datenbank oder einer separaten Notion-DB-Spalte `Letzter-gemeldeter-Status`).

### B) Kommentar-Erkennung

Die Notion API bietet einen Endpoint für Kommentare:

```
GET https://api.notion.com/v1/comments?block_id={page_id}
```

Damit könnte ein separater Workflow neue Kommentare erkennen und deren Inhalt direkt in die Telegram-Nachricht aufnehmen.

### C) Deadline-Erinnerung

Täglicher Check: Wo ist die Deadline in < 3 Tagen und der Status noch nicht "Freigegeben"?

```
[Schedule: täglich 9:00] → [Notion: Query DB, Deadline < +3d, Status ≠ Freigegeben] → [Telegram: Erinnerung]
```

---

## Referenzen

| Ressource | Wert |
|---|---|
| Notion Workshop Reviews DB | `b69393bea0474e7cb34aa314c621d618` |
| Notion E-Mails Ausgehend DB | `7288c0d68377454f9fccb0a8bb218da9` |
| Notion API Docs – Query DB | https://developers.notion.com/reference/post-database-query |
| Notion API Docs – Comments | https://developers.notion.com/reference/retrieve-a-comment |
| n8n Notion Node Docs | https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.notion/ |
| n8n Telegram Node Docs | https://docs.n8n.io/integrations/builtin/app-nodes/n8n-nodes-base.telegram/ |
| n8n Instanz | https://n8n.gevio.cloud |
