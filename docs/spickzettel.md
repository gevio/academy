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

Admin Dashboard: https://dev.as26.cool-camp.site/admin/analytics.html (Login mit Admin-Secret)

Secret manuell eingeben im Formular
?admin=as26-review-2026 als URL-Parameter (wie überall sonst)
Automatisch wenn schon auf einer anderen Seite mit ?admin=... angemeldet (sessionStorage)




---

## 2. QR-Codes erzeugen

**Vereinfachte Formel-Lösung:** QR-PNGs werden mit deterministischem Dateinamen generiert und automatisch über Notion-Formeln verlinkt.

### Workshops: QR-Feedback-Codes

```bash
cd /var/www/dev.as26.cool-camp.site
php public/qr-generate.php
```

Speichert pro Workshop: `public/qr/<id-ohne-hyphens>.png` (verlinkt auf `https://agenda.adventuresouthside.com/w/<id>`)

**Notion-Formel in NOTION_WORKSHOP_DB:**

| Property | Typ | Formel |
|---|---|---|
| **Feedback-QR** | `formula` | `"https://agenda.adventuresouthside.com/qr/" + replaceAll(id(), "-", "") + ".png"` |

### Aussteller: App-QR-Codes

```bash
cd /var/www/dev.as26.cool-camp.site
php public/qr-generate-aussteller.php
```

Speichert pro Aussteller: `public/qr-aussteller/<id-ohne-hyphens>.png` (verlinkt auf `https://agenda.adventuresouthside.com/aussteller.html#id=<id>`)

**Notion-Formel in NOTION_AUSSTELLER_DB:**

| Property | Typ | Formel |
|---|---|---|
| **App-QR** | `formula` | `"https://agenda.adventuresouthside.com/qr-aussteller/" + replaceAll(id(), "-", "") + ".png"` |

**Einmalig manuell in Notion einrichten, dann vollautomatisch!**

### Hintergrund

| Skript | Beschreibung |
|---|---|
| `qr-generate.php` | Workshops: Liest NOTION_WORKSHOP_DB, erzeugt PNGs unter `public/qr/` für Feedback-Link |
| `qr-generate-aussteller.php` | Aussteller: Liest NOTION_AUSSTELLER_DB, erzeugt PNGs unter `public/qr-aussteller/` für App-Link |
| `qr-upload.php` | ⚠️ **Veraltet** – Mit Formel-Properties nicht mehr nötig. |

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

| Zweck | ENV-Variable | AS26-DB-ID | AN26-DB-ID |
|---|---|---|---|
| Workshops | `NOTION_WORKSHOP_DB` | `11382138ece1494bafa3cd1bb47dda82` | `fa2fab25709b4e65bb4e281351322aaa` |
| Aussteller | `NOTION_AUSSTELLER_DB` | `30a9c964c82f80dab1b6c0f9cb6bef9a` | `afb1ec07595b4a47b10a299d210e8eea` |
| Referenten/Experten (Slots) | `NOTION_REFERENTEN_DB` | `2a69c964c82f806386fdf6d372ed5c4b` | `8e346ae2083240d5ae9937f665c3eef8` |
| Feedback | `NOTION_FEEDBACK_DB` | `833c76f1256d444e8e83a0cf8b333992` | `2dfe321a25c24f6aaa5c003e6fa1f4a5` |
| App-Feedback | `NOTION_APP_FEEDBACK_DB` | `c2fab1ab25a64b89a61de6aa1cb519dd` | ⚠️ TODO |
| Q&A-Fragen | `NOTION_QA_DB` | `7f2c6f2db4994ecda4a0a591629b1d9c` | `416fff4cb763491f99039bdaad8215f3` |
| Workshop-Reviews | `NOTION_REVIEW_DB` | `b69393bea0474e7cb34aa314c621d618` | ⚠️ TODO |
| Aussteller-Reviews | `NOTION_AUSSTELLER_REVIEW_DB` | `d9797aa541bb47dab3b4f6766a646c61` | `1bda03c0b1494ba398c46edc88bd4d33` |
| E-Mail-Adressen | `NOTION_EMAIL_DB` | `7288c0d68377454f9fccb0a8bb218da9` | ⚠️ TODO |

---

## 6. Webhooks (n8n)

| Webhook | ENV-Variable |
|---|---|
| Feedback | `N8N_FEEDBACK_WEBHOOK` |
| Q&A | `N8N_QA_WEBHOOK` |
| Upvote | `N8N_UPVOTE_WEBHOOK` |

---

## 7. Release-Management

Jede abgeschlossene Aufgabe (Feature, Bugfix, Refactoring) **muss** mit einem Release dokumentiert werden. Ohne diesen Schritt ist die Aufgabe nicht abgeschlossen.

### Datenbank & CLI

| Was | Pfad |
|---|---|
| Release-DB | `storage/releases/releases.sqlite` (SQLite, gitignored, pro Umgebung separat) |
| CLI-Tool | `php cli/release.php` |

### Befehle

```bash
cd /var/www/dev.as26.cool-camp.site

# DB initialisieren (einmalig pro Umgebung)
php cli/release.php init

# Aktuelle Version anzeigen
php cli/release.php current

# Changelog anzeigen (optional mit Limit)
php cli/release.php log
php cli/release.php log --limit=10

# Neues Release eintragen
php cli/release.php release 1.2.3 "Kurze Beschreibung der Änderung"
```

### Semantic Versioning

| Stufe | Wann | Beispiel |
|---|---|---|
| **PATCH** | Bugfixes, kleine Korrekturen | `1.0.0` → `1.0.1` |
| **MINOR** | Neue Features, Erweiterungen | `1.0.1` → `1.1.0` |
| **MAJOR** | Breaking Changes | `1.1.0` → `2.0.0` |

### Ablauf: Neues Release erzeugen

1. Code-Änderungen fertigstellen und testen
2. Git-Commit erstellen
3. Release eintragen:
   ```bash
   php cli/release.php release <X.Y.Z> "Beschreibung"
   ```
   → Aktualisiert automatisch `APP_VERSION` in `config/.env`
4. Erneut committen (Version-Bump) und pushen

### Deployment auf Produktion

```bash
cd /var/www/as26.cool-camp.site && git pull
php cli/release.php init          # falls DB noch nicht existiert
php cli/release.php release <X.Y.Z> "Beschreibung"   # gleiche Version wie auf Dev
```

---

## 8. Standplan-Editor (Map-Editor)

Visuelles Tool zum Verorten von Ausstellerständen auf dem Hallenplan.

| Umgebung | URL |
|---|---|
| **Dev** | `https://dev.as26.cool-camp.site/tools/map-editor.html` |
| **Live** | `https://agenda.adventuresouthside.com/tools/map-editor.html` |

> Kein Admin-Login nötig – Tool ist direkt erreichbar.

### Verfügbare Hallenpläne

| Kürzel | Bereich | Bilddatei |
|---|---|---|
| `FW` / `AT` | Foyer West / Atrium | `public/img/plan/FW.jpg` |
| `FG` / `FGO` | Freigelände West | `public/img/plan/FG.jpg` |
| `A3` | Halle A3 | `public/img/plan/A3.jpg` |
| `A4` | Halle A4 | `public/img/plan/A4.jpg` |
| `A5` | Halle A5 | `public/img/plan/A5.jpg` |
| `A6` | Halle A6 | `public/img/plan/A6.jpg` |
| *(Übersicht)* | Gesamtübersicht | `public/img/plan/overview.jpg` |

### Workflow

1. Tool öffnen → Halle auswählen
2. Aussteller per Klick auf die Karte platzieren (lädt Koordinaten aus `aussteller.json`)
3. **Speichern** → POST an `/api/standplan-save.php` (schreibt Koordinaten nach Notion)
4. Ergebnis landet in `public/api/standplan.json`

### Hintergrund

| Was | Wo |
|---|---|
| Tool | `tools/map-editor.html` |
| Hallenplan-Grafiken | `public/img/plan/*.jpg` |
| Speichern-Endpunkt | `public/api/standplan-save.php` |
| Ausgabe-JSON | `public/api/standplan.json` |

> **Hinweis:** Neue oder geänderte Hallenplan-Grafiken müssen committed und auf Prod deployed werden – sie werden **nicht** per Cron regeneriert.
