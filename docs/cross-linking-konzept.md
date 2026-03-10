# Konzept: Cross-Linking und Aussteller-Integration

## Ziel

Besucher, die eine Veranstaltungsseite öffnen, sollen dezent auf thematisch verwandte
Veranstaltungen und passende Aussteller hingewiesen werden – ohne Ablenkung vom
eigentlichen Inhalt und ohne zusätzliche externe Dienste.

---

## Datenlage: Haben wir genug?

### Was bereits vorhanden ist

| Datenpunkt | Workshops | Aussteller |
|---|---|---|
| `kategorien` (Array) | ✅ ja | ✅ ja |
| Direkte Aussteller-Verlinkung | ✅ via `aussteller[]` in Notion | – |
| Freitext `beschreibung` | ✅ ja | ✅ ja |
| Referent-IDs | ✅ ja | – |
| Typ / Format | ✅ ja | – |
| Tag / Zeitslot | ✅ ja | – |

### Fazit zur Datenlage

**Für einfaches kategorie-basiertes Cross-Linking reicht das vorhandene JSON vollständig aus.**
Beide Datenquellen (`workshops.json`, `aussteller.json`) nutzen dieselbe Kategorie-Systematik –
das ist die direkte Basis für Verknüpfungen, ohne externe Dienste.

Beispiel-Überschneidungen:

- Workshop-Kategorie `"Heizung & Klima"` ↔ Aussteller mit `"Heizung & Klima"`
- Workshop-Kategorie `"Offroad-Fahrzeuge"` ↔ Aussteller mit `"Offroad-Fahrzeuge"` oder `"Offroad-Zubehör"`
- Workshop-Kategorie `"Touren"` ↔ Aussteller mit `"Touren"` oder `"Expeditionsmobile"`

**Was kategorie-basiertes Matching nicht kann:**

- Semantische Nähe erkennen, die nicht explizit als selbe Kategorie kodiert ist
  (z. B. `"Navigation & GPS"` ↔ `"Expeditionsmobile"`)
- Ähnlichkeit aus dem Freitext der `beschreibung` ableiten
- Automatisches Clustern neuer Kategorievarianten

Für diese Fälle wäre ein n8n-Agent mit LLM sinnvoll – aber erst in einer zweiten Phase
und nur wenn die Trefferqualität des einfachen Ansatzes nicht reicht.

---

## Feature 1: Ähnliche Veranstaltungen

### Idee

Unter dem Hauptinhalt der Detailseite erscheint ein Abschnitt:

> **„Das könnte dich auch interessieren"**

Gezeigt werden 2–3 Kacheln anderer Veranstaltungen mit der höchsten Kategorie-Überschneidung.

### Matching-Logik (clientseitig, kein Server nötig)

```
aktueller Workshop → kategoriemenge A
alle anderen Workshops → jeweils kategoriemenge B

score = |A ∩ B|              // Schnittmenge der Kategorien
tie-break 1: selber Tag bevorzugen
tie-break 2: gleicher Typ (Workshop/Vortrag/...) bevorzugen
```

Ergebnis: die top 2–3 Workshops mit score > 0, sortiert absteigend.

### Darstellung

- Kompakte Kacheln (kein Full-Card), nur Typ-Badge, Titel, Datum+Zeit, Link
- Eine Zeile, horizontal scrollbar auf Mobile
- Visuell klar als Empfehlung markiert, nicht als Teil des Hauptinhalts
- Kein Algorithmus-Hinweis nötig – einfach dezenter Abschnittskopf reicht

### Wo einbauen

**details.html** ist der primäre Ort – die Seite lädt ohnehin schon die vollständige
`workshops.json` per `fetch()`. Die komplette Matching-Logik kann daher rein clientseitig
in `renderDetails()` geschehen, ohne weiteren API-Aufruf.

Einstiegspunkt im bestehenden Code:

```javascript
// direkt nach dem QA-Block in renderDetails(), vor container.innerHTML = html
html += renderAehnlicheVeranstaltungen(ws, allWorkshops);
```

```javascript
function renderAehnlicheVeranstaltungen(ws, allWorkshops) {
    const meineKats = new Set(ws.kategorien || []);
    if (meineKats.size === 0) return '';

    const scored = allWorkshops
        .filter(w => w.id !== ws.id)
        .map(w => {
            const score = (w.kategorien || []).filter(k => meineKats.has(k)).length;
            return { w, score };
        })
        .filter(x => x.score > 0)
        .sort((a, b) => b.score - a.score || (a.w.tag === ws.tag ? -1 : 1));

    if (scored.length === 0) return '';

    const top = scored.slice(0, 3);
    let html = '<section class="cross-link-section">';
    html += '<h3>Das könnte dich auch interessieren</h3>';
    html += '<div class="cross-link-row">';
    top.forEach(({ w }) => {
        html += `<a href="/w/${w.id}/details" class="cross-link-card">
            <span class="typ-badge" data-typ="${escapeHtml(w.typ)}">${escapeHtml(w.typ)}</span>
            <strong>${escapeHtml(w.title)}</strong>
            <span>${escapeHtml(w.tag)} · ${escapeHtml(w.zeit)}</span>
        </a>`;
    });
    html += '</div></section>';
    return html;
}
```

**index.php** (QR-Code-Landingpage): Hier lädt der Server bereits `workshops.json` für die
Anreicherung. Dieselbe Logik kann in PHP repliziert werden und serverseitig gerendert werden –
sinnvoll damit auch der erste Seitenaufruf ohne JS-Laufzeit bereits die Empfehlungen enthält.

---

## Feature 2: Passende Aussteller (nicht auf dem Podium)

### Idee

Neben den direkt mit dem Workshop verknüpften Ausstellern (bereits implementiert über
`ws.aussteller[]`) erscheint ein zweiter, dezenterer Bereich:

> **„Aussteller zum Thema"** _(ohne die bereits direkt verlinkten)_

Gezeigt werden 2–4 Aussteller, deren Kategorien mit den Workshop-Kategorien überlappen,
die aber **nicht** bereits in `ws.aussteller` stehen.

### Matching-Logik

```
Workshop-Kategorien A
Aussteller-Kategorien B

score = |A ∩ B|
Filter: Aussteller-ID darf nicht in ws.aussteller[] vorkommen
```

```javascript
function renderPassendeAussteller(ws, alleAussteller) {
    const meineKats = new Set(ws.kategorien || []);
    if (meineKats.size === 0) return '';

    const bereitsVerlinkt = new Set((ws.aussteller || []).map(a => a.id));

    const scored = alleAussteller
        .filter(a => !bereitsVerlinkt.has(a.id))
        .map(a => {
            const score = (a.kategorien || []).filter(k => meineKats.has(k)).length;
            return { a, score };
        })
        .filter(x => x.score > 0)
        .sort((a, b) => b.score - a.score);

    if (scored.length === 0) return '';

    const top = scored.slice(0, 4);
    let html = '<section class="cross-link-section cross-link-aussteller">';
    html += '<h3>Aussteller zum Thema</h3>';
    html += '<div class="cross-link-row">';
    top.forEach(({ a }) => {
        const standInfo = a.stand ? ` · Stand ${escapeHtml(a.stand)}` : '';
        html += `<a href="/aussteller.html#id=${a.id}" class="cross-link-card">
            ${a.logo_local ? `<img src="${escapeHtml(a.logo_local)}" alt="" class="cross-link-logo">` : ''}
            <strong>${escapeHtml(a.firma)}</strong>
            <span>${escapeHtml(a.kategorien.slice(0, 2).join(', '))}${standInfo}</span>
        </a>`;
    });
    html += '</div></section>';
    return html;
}
```

### Daten-Verfügbarkeit

`aussteller.json` muss für diese Funktion einmalig zusätzlich geladen werden.
Da es eine statische JSON-Datei ist, kann sie parallel zu `workshops.json` gefetcht
und im gleichen Lade-Schritt verarbeitet werden.

```javascript
const [wsData, ausData] = await Promise.all([
    fetch('/api/workshops.json').then(r => r.json()),
    fetch('/api/aussteller.json').then(r => r.json())
]);
```

---

## Dezente UI-Integration

Die Empfehlungsblöcke sollen den Hauptinhalt nicht dominieren. Empfohlene Platzierung:

```
┌──────────────────────────────────────┐
│ Workshop-Card (Titel, Zeit, Ort)     │
├──────────────────────────────────────┤
│ Aktions-Buttons (Favorit, Kalender)  │
├──────────────────────────────────────┤
│ Beschreibung / content_html          │
├──────────────────────────────────────┤
│ Q&A-Bereich (wenn aktiv)             │
├──────────────────────────────────────┤
│ ── ── ── ── ── ── ── ── ── ── ──   │  ← dezente Trennlinie
│ Aussteller zum Thema (klein)         │  ← Feature 2
├──────────────────────────────────────┤
│ Das könnte dich auch interessieren   │  ← Feature 1
└──────────────────────────────────────┘
│ Footer                               │
```

CSS-Prinzip: kleinere Schrift, gedämpfte Farbe, kein visuelles Gewicht –
die Empfehlungen sollen sich anfühlen wie ein "übrigens..." am Ende.

```css
.cross-link-section {
    margin-top: 2rem;
    padding-top: 1.2rem;
    border-top: 1px solid var(--border-light, #e0dbd7);
}
.cross-link-section h3 {
    font-size: .8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-light);
    margin-bottom: .8rem;
}
.cross-link-row {
    display: flex;
    gap: .6rem;
    overflow-x: auto;
    padding-bottom: .4rem;
}
.cross-link-card {
    flex: 0 0 auto;
    min-width: 160px;
    max-width: 220px;
    padding: .7rem .8rem;
    border: 1px solid var(--border-light, #e0dbd7);
    border-radius: 8px;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    gap: .25rem;
    font-size: .82rem;
}
.cross-link-card:hover {
    border-color: var(--as-rot);
}
.cross-link-logo {
    width: 40px;
    height: 24px;
    object-fit: contain;
    margin-bottom: .2rem;
}
```

---

## Brauchen wir n8n mit einem Agenten?

### Kurzantwort: Nein – für den Start nicht.

Das Kategorie-Matching funktioniert rein statisch und reicht für eine solide erste Version.
n8n oder ein LLM-Agent wäre nur dann sinnvoll, wenn:

| Szenario | Bewertung |
|---|---|
| Kategorie-Matching reicht, Kategorien sind konsistent gepflegt | ✅ kein Agent nötig |
| Kategorien fehlen bei manchen Einträgen oder sind inkonsistent | ⚠️ Bereinigung in Notion empfohlen, kein Agent |
| Semantische Ähnlichkeit über Kategoriegrenzen hinweg (z. B. "GPS" ↔ "Expeditionsmobile") | n8n-Agent sinnvoll |
| Matching auf Basis des Beschreibungstextes (inhaltlicher Kontext) | n8n-Agent sinnvoll |
| Automatisches Tagging neuer Workshops beim Anlegen | n8n-Agent sinnvoll |

### Mögliche n8n-Erweiterung (Phase 2)

Wenn Trefferqualität oder Kategorieabdeckung nicht ausreicht:

1. **Embedding-basiertes Matching**: n8n ruft per HTTP-Trigger ein LLM auf,
   das Embeddings für jede `beschreibung` berechnet und in einer kleinen
   Vektordatenbank (z. B. SQLite mit Cosine-Similarity) speichert.
   Beim Abrufen einer Detailseite werden die nächsten Nachbarn abgefragt.

2. **Automatisches Kategorie-Tagging**: n8n-Workflow bei Notion-Webhook → LLM schlägt
   passende Kategorien vor → werden zur Prüfung vorgelegt oder direkt eingetragen.

Beide Erweiterungen können nachgerüstet werden, ohne die bestehende UI zu ändern –
der Output wäre weiterhin das gleiche Kategorie-Array oder ein separates `related_ids`-Feld
in `workshops.json`.

---

## Umsetzung in Phasen

### Phase 1: Reines Kategorie-Matching (kein Aufwand außer JS/PHP)

- [ ] `renderAehnlicheVeranstaltungen()` in `details.html` ergänzen
- [ ] Paralleles Laden von `aussteller.json` in `details.html`
- [ ] `renderPassendeAussteller()` in `details.html` ergänzen
- [ ] CSS-Klassen `.cross-link-section`, `.cross-link-row`, `.cross-link-card` in `style.css`
- [ ] Gleiche Blöcke optional serverseitig in `index.php` vorrendern

### Phase 2: Qualitätsverbesserung durch konsistente Kategorien

- [ ] Prüfen: Haben alle Workshops mindestens eine Kategorie?
- [ ] Prüfen: Überschneiden sich die Kategorien zwischen Workshops und Ausstellern ausreichend?
- [ ] Ggf. fehlende Kategorien in Notion nachtragen

### Phase 3: Semantic Matching via n8n (optional, wenn Phase 1 nicht ausreicht)

- [ ] n8n-Workflow: Beschreibungs-Embedding beim JSON-Generieren berechnen
- [ ] `related_ids`-Felder in `workshops.json` vorberechnen und einbetten
- [ ] Frontend nutzt `related_ids` statt Echtzeit-Berechnung

---

## Zusammenfassung

- **Datenlage**: ausreichend für Phase 1, da beide JSONs `kategorien`-Arrays haben
- **n8n**: für den Start **nicht notwendig** – einfaches Set-Intersection-Matching reicht
- **Umsetzungsort**: primär `details.html` (clientseitig), optional Spiegel in `index.php`
- **UX-Prinzip**: dezent am Ende der Seite, kleine Kacheln, kein visuelles Gewicht
- **Erweiterbarkeit**: Matching-Quelle kann später durch vorberechnete `related_ids`
  aus einem n8n-Agenten ersetzt werden, ohne die UI anzufassen
