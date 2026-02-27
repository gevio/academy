# Spickzettel – AS26 Agenda-App

Schnellreferenz für häufige Aufgaben.

---

## 1. Admin-Zugang

Den Admin-Modus aktivierst du, indem du `?admin=<SECRET>` an die URL hängst.  
Das Secret ist in `config/.env` unter `ADMIN_SECRET` hinterlegt.

| Umgebung | URL |
|---|---|
| **Dev** | `https://dev.as26.cool-camp.site/?admin=as26-review-2026` |
| **Live** | `https://agenda.adventuresouthside.com/?admin=as26-review-2026` |

Funktioniert auf jeder Seite, z. B.:
- `https://dev.as26.cool-camp.site/programm?admin=as26-review-2026`

Das Secret wird per JavaScript in `sessionStorage` gespeichert und sofort aus der URL entfernt.  
Ein neues Tab erfordert erneute Eingabe.

---

## 2. QR-Codes erzeugen

Beide Skripte liegen in `public/` und werden auf der **Konsole** ausgeführt:

```bash
# Ins Projektverzeichnis wechseln
cd /var/www/dev.as26.cool-camp.site

# 1) QR-Code-PNGs generieren (pro Workshop eine Datei in public/qr/)
php public/qr-generate.php

# 2) QR-URLs + Live-URLs in die Notion-DB zurückschreiben
php public/qr-upload.php
```

### Was passiert?

| Skript | Beschreibung |
|---|---|
| `qr-generate.php` | Liest alle Workshop-IDs aus Notion, erzeugt pro Workshop eine PNG-Datei unter `public/qr/<id>.png`. Basis-URL: `https://agenda.adventuresouthside.com/w/<id>` |
| `qr-upload.php` | Schreibt die Property **Feedback-QR** (externer File-Link) und **Live-URL** in jede Notion-Seite zurück. |

---

## 3. JSON-Daten regenerieren (Notion → API)

Die statischen JSON-Dateien unter `public/api/` werden aus Notion generiert.

### Konsole (manuell)

```bash
cd /var/www/dev.as26.cool-camp.site

# Alle drei auf einmal (mit Lock-Schutz):
php cli/generate-json.php

# Nur einzeln:
php scripts/generate-workshops-json.php
php scripts/generate-aussteller-json.php
php scripts/generate-experten-json.php
```

### Cron (automatisch, stündlich)

```cron
0 * * * * cd /var/www/as26.cool-camp.site && php cli/generate-json.php >> /var/log/as26-json.log 2>&1
```

### Erzeugte Dateien

| Generator | Zieldatei |
|---|---|
| `generate-workshops-json.php` | `public/api/workshops.json` |
| `generate-aussteller-json.php` | `public/api/aussteller.json` |
| `generate-experten-json.php` | `public/api/experten.json` |

> **Hinweis:** `cli/generate-json.php` nutzt eine Lock-Datei (`storage/generate-json.lock`), um parallele Läufe zu verhindern. Falls ein Lauf hängt, die Lock-Datei manuell löschen:
> ```bash
> rm storage/generate-json.lock
> ```

---

## 4. Wichtige URLs (Dev)

| Seite | URL |
|---|---|
| Startseite | `https://dev.as26.cool-camp.site/` |
| Programm | `https://dev.as26.cool-camp.site/programm` |
| Aussteller | `https://dev.as26.cool-camp.site/aussteller` |
| Experten | `https://dev.as26.cool-camp.site/experten` |
| Workshop (Beispiel) | `https://dev.as26.cool-camp.site/w/<32-hex-id>` |
| Workshop-Feedback | `https://dev.as26.cool-camp.site/w/<id>/feedback` |
| Workshop-Q&A | `https://dev.as26.cool-camp.site/w/<id>/qa` |
| Workshop-Wall | `https://dev.as26.cool-camp.site/w/<id>/wall` |
| FAQ | `https://dev.as26.cool-camp.site/faq` |
| Impressum | `https://dev.as26.cool-camp.site/impressum` |

---

## 5. Notion-Datenbanken

Übersicht der genutzten DBs (IDs aus `config/.env`):

| Zweck | ENV-Variable |
|---|---|
| Workshops | `NOTION_WORKSHOP_DB` |
| Aussteller | `NOTION_AUSSTELLER_DB` |
| Referenten/Experten | `NOTION_REFERENTEN_DB` |
| Feedback | `NOTION_FEEDBACK_DB` |
| Q&A-Fragen | `NOTION_QA_DB` |
| Reviews | `NOTION_REVIEW_DB` |
| E-Mail-Adressen | `NOTION_EMAIL_DB` |

---

## 6. Webhooks (n8n)

| Webhook | ENV-Variable |
|---|---|
| Feedback | `N8N_FEEDBACK_WEBHOOK` |
| Q&A | `N8N_QA_WEBHOOK` |
| Upvote | `N8N_UPVOTE_WEBHOOK` |
