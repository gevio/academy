# Aussteller & Standplan – Feature-Dokumentation

## Zweck

Besucher der AS26-App klicken auf einen Aussteller und sehen dessen
Standposition auf dem Hallenplan. Die Daten kommen aus zwei JSON-Dateien:
`aussteller.json` (Notion-Export) und `standplan.json` (Koordinaten-Mapping).

---

## Architektur

```
Notion DB (AS26_Aussteller)
        |
        v
scripts/generate-aussteller-json.php  -->  public/api/aussteller.json
                                                     |
tools/map-editor.html  -->  public/api/standplan.json |
                                                     |
                            public/aussteller.html  <--+-- liest beide JSONs
                            public/js/aussteller.js
                            public/css/aussteller.css
```

---

## Dateien

| Datei | Zweck |
|-------|-------|
| `public/aussteller.html` | Aussteller-Listenseite (Suche, Filter, Karte) |
| `public/js/aussteller.js` | Frontend-Logik: Laden, Filtern, Map-Overlay |
| `public/css/aussteller.css` | Styling: Cards, Tags, Map-Overlay, Marker |
| `public/api/aussteller.json` | Aussteller-Daten (generiert via CLI-Script) |
| `public/api/standplan.json` | Stand-Koordinaten (generiert via Mapping-Tool) |
| `public/img/plan/*.jpg` | 7 Hallenpläne (Gesamt + 6 Einzelpläne) |
| `scripts/generate-aussteller-json.php` | Notion DB -> JSON Export |
| `tools/map-editor.html` | Koordinaten-Mapping-Tool (nur lokal, nicht deployed) |
| `config/.env` | `NOTION_AUSSTELLER_DB` Variable |

---

## Datenquellen

### aussteller.json

Generiert aus der Notion-DB `AS26_Aussteller` (ID `30a9c964-c82f-80da-b1b6-c0f9cb6bef9a`).

```bash
php scripts/generate-aussteller-json.php
```

Felder: `firma`, `stand`, `beschreibung`, `kategorie`, `website`, `instagram`, `logo`

### standplan.json

Generiert mit dem Mapping-Tool (`tools/map-editor.html`).
Enthält pro Stand die prozentualen x/y-Koordinaten auf dem Hallenplan-Bild.

---

## Hallenpläne & Prefix-Mapping

| Prefix | Bild | Bereich |
|--------|------|---------|
| `FW-` | FW.jpg | Foyer West |
| `AT-` | FW.jpg | Foyer West (Atrium) |
| `FG-` | FG.jpg | Freigelände West |
| `FGO-` | FG.jpg | Freigelände Ost |
| `A3-` | A3.jpg | Halle A3 |
| `A4-` | A4.jpg | Halle A4 |
| `A5-` | A5.jpg | Halle A5 |
| `A6-` | A6.jpg | Halle A6 |

Die Halle wird aus dem Stand-Prefix abgeleitet (alles vor dem `-`).
`AT-` und `FW-` teilen sich dasselbe Bild, ebenso `FG-` und `FGO-`.

---

## Mapping-Tool Workflow

Das Tool liegt unter `tools/map-editor.html` und wird lokal im Browser geöffnet.
Es wird NICHT deployed.

1. `tools/map-editor.html` im Browser öffnen
2. Halle im Dropdown wählen (FW, FG, A3-A6)
3. Das Tool lädt `public/api/aussteller.json` und zeigt alle Standnummern
   der gewählten Halle als klickbare Liste (Sidebar)
4. Stand in der Liste anklicken (wird gelb markiert)
5. Position auf dem Hallenplan klicken -> Stand wird platziert
6. Tool springt automatisch zum nächsten unplatzierten Stand
7. Nach Fertigstellung: "Export JSON" -> `standplan.json` herunterladen
8. Datei nach `public/api/standplan.json` kopieren

**Tastenkürzel:**
- `M` – Punkt/Rechteck-Modus wechseln
- `Del` – Ausgewählten Stand löschen
- `Esc` – Auswahl aufheben

---

## Frontend-Funktionsweise

`aussteller.js` lädt beim Seitenaufruf beide JSONs parallel:

1. **Aussteller-Liste** – Cards mit Firma, Stand-Badge, Beschreibung, Kategorie
2. **Suchfeld** – filtert nach Firmenname
3. **Kategorie-Filter** – Tag-Buttons aus den Kategorien der Aussteller
4. **Karten-Overlay** – Klick auf einen Aussteller öffnet Fullscreen-Overlay:
   - Hallenplan-Bild wird anhand des Stand-Prefix geladen
   - Puls-Marker wird per `position: absolute; left: x%; top: y%` platziert
   - Bild scrollt automatisch zum Marker

---

## Service Worker

Cache-Version: `as26-live-v4`

Precached Assets:
- `/aussteller.html`, `/css/aussteller.css`, `/js/aussteller.js`
- `/api/aussteller.json`, `/api/standplan.json`
- Alle JSON-Dateien unter `/api/` nutzen Stale-While-Revalidate

---

## Offene Punkte

- [ ] `aussteller.json` auf Server generieren (Notion-Credentials in `.env`)
- [ ] `standplan.json` via Mapping-Tool befüllen (alle Stände durchklicken)
- [ ] Hallenplan-Bilder für Web optimieren (Komprimierung prüfen)
- [ ] Workshop-DB Relation `Aussteller (AS26)` für Verlinkung nutzen
