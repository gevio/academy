# Konzept: Analytics fuer AS26 Live

## Ziel

Die App soll drei Dinge belastbar auswertbar machen:

1. Installation der PWA
2. Endgeraete und Nutzungskontext
3. Nutzungsdauer und Aktivitaet innerhalb der App

Wichtig ist dabei eine Loesung, die zur bestehenden App-Struktur passt, datensparsam bleibt und mit einem internen Admin-Bereich ausgewertet werden kann.

---

## Ausgangslage im Bestand

- Die PWA ist bereits technisch vorhanden.
- Auf der Startseite existieren bereits Installations-Anknuepfungspunkte ueber `beforeinstallprompt` und `appinstalled`.
- Es gibt bereits einen Admin-Mechanismus mit Secret und Session-Freischaltung, der als Basis fuer ein internes Analytics-Dashboard genutzt werden kann.
- Das Projekt ist weitgehend serverseitig/PHP-basiert und nutzt statische JSON-Dateien fuer Inhalte.

Das ist ein guter Ausgangspunkt: Wir muessen keinen externen Komplettanbieter erzwingen, sondern koennen ein kleines, kontrollierbares First-Party-Tracking ergaenzen.

---

## Was genau gemessen werden koennte

### 1. Installation der App

Ziel: Nicht nur finale Installationen zaehlen, sondern den Installations-Funnel verstehen.

Empfohlene Events:

- `install_prompt_eligible`
  Wenn ein Geraet technisch installierbar ist.
- `install_prompt_shown`
  Wenn der eigene Installationshinweis sichtbar wird.
- `install_prompt_clicked`
  Wenn der Nutzer aktiv auf "Installieren" klickt.
- `install_prompt_accepted`
  Wenn der Browser-Prompt bestaetigt wird.
- `install_prompt_dismissed`
  Wenn der Browser-Prompt abgelehnt wird.
- `app_installed`
  Wenn das Event `appinstalled` feuert.
- `app_opened_standalone`
  Wenn die App im Standalone-Modus gestartet wird.

Nutzen:

- Installationsquote je Tag
- Vergleich Browser-Modus vs. PWA-Modus
- Erkennen, ob viele Nutzer abbrechen, obwohl der Prompt gezeigt wird

### 2. Endgeraete

Ziel: Verstehen, auf welchen Geraeten und in welchem Nutzungskontext die App verwendet wird.

Empfohlene Merkmale pro Session:

- Geraeteklasse: `mobile`, `tablet`, `desktop`
- Betriebssystem: z. B. `iOS`, `Android`, `Windows`, `macOS`
- Browser: z. B. `Safari`, `Chrome`, `Samsung Internet`
- Modus: `browser` oder `standalone`
- Sprache / Locale
- Bildschirmgroesse als grobe Klasse, nicht als exakte Pixelhistorie

Nicht speichern:

- Keine vollstaendige IP-Adresse
- Kein exakter User-Agent im Rohformat, wenn er nicht wirklich gebraucht wird
- Keine personenbezogenen IDs

Nutzen:

- Welche Plattformen bringen die meisten Installationen?
- Wo ist die durchschnittliche Nutzung am laengsten?
- Auf welchen Geraeten gibt es moegliche UX-Probleme?

### 3. Nutzungsdauer

Ziel: Nicht nur Pageviews, sondern echte Nutzung abschaetzen.

Empfohlene Events:

- `session_start`
- `page_view`
- `feature_use`
  z. B. Programm geoeffnet, Favorit gesetzt, Q&A geoeffnet, Feedback geoeffnet, Ausstellerliste genutzt
- `heartbeat`
  alle 15 bis 30 Sekunden, aber nur solange der Tab sichtbar ist
- `session_end`
  soweit technisch moeglich beim Verlassen oder nach Timeout serverseitig berechnet

Abgeleitete Kennzahlen:

- aktive Sessions pro Tag
- durchschnittliche Sitzungsdauer
- mediane Sitzungsdauer
- Seiten / Features pro Session
- Wiederkehrer innerhalb eines Event-Tages

Wichtig: Die Sitzungsdauer sollte nicht aus rohen "Tab offen"-Zeiten entstehen, sondern nur aus sichtbarer, aktiver Nutzung.

---

## Empfohlene technische Loesung

### Empfehlung

Empfohlen ist eine kleine First-Party-Analytics-Loesung im eigenen Projekt statt eines komplett externen Tools.

Warum:

- passt zur bestehenden PHP-Struktur
- Daten bleiben im eigenen System
- Admin-Auswertung kann direkt in die App integriert werden
- Datenschutz und Datenminimierung lassen sich gezielt steuern

### Bausteine

#### A. Clientseitiger Tracker

Eine kleine JavaScript-Datei, z. B. `public/js/analytics.js`, die:

- beim App-Start eine anonyme Session-ID erzeugt
- Installations-Events mitschreibt
- Seitenaufrufe und Feature-Nutzung sendet
- Heartbeats nur bei sichtbarer Seite sendet
- Events gesammelt per `sendBeacon()` oder `fetch(..., { keepalive: true })` an den Server uebertraegt

#### B. Serverseitiger Event-Collector

Neuer Endpoint, z. B. `public/api/analytics.php`:

- nimmt JSON-Events entgegen
- validiert Event-Typ und erlaubte Felder
- entfernt oder kuerzt potenziell heikle Rohdaten
- schreibt Events in eine lokale Speicherung

Moegliche Speicheroptionen:

1. NDJSON-Dateien pro Tag in `storage/analytics/`
2. SQLite-Datei in `storage/analytics.sqlite`

Empfehlung: SQLite, weil Auswertungen fuer das Admin-Dashboard deutlich einfacher werden.

#### C. Aggregation

Ein kleines CLI-Skript oder Cronjob berechnet taeglich oder stuendlich Kennzahlen:

- Installationen pro Tag
- aktive Sessions
- durchschnittliche Nutzungsdauer
- Verteilung nach Geraetetyp, OS, Browser
- Top-Seiten / Top-Features

Die Ergebnisse koennen als JSON fuer das Dashboard voraggregiert werden, damit das Admin-Interface schnell bleibt.

---

## Datenmodell Vorschlag

### Tabelle `analytics_events`

- `id`
- `created_at`
- `event_name`
- `session_id`
- `visitor_day_id`
- `page`
- `feature`
- `device_type`
- `os_family`
- `browser_family`
- `display_mode`
- `locale`
- `screen_bucket`
- `duration_hint_seconds`
- `payload_json`

### Tabelle `analytics_daily`

- `date`
- `sessions`
- `unique_visitors_estimate`
- `avg_session_duration_seconds`
- `median_session_duration_seconds`
- `installs`
- `standalone_opens`
- `install_prompt_shown`
- `install_prompt_accepted`

### Pseudonymisierung

- `session_id`: clientseitig erzeugt, nur fuer eine Sitzung oder maximal fuer einen Tag gueltig
- `visitor_day_id`: optional, z. B. gehashter Tages-Identifier fuer grobe Wiederkehr-Erkennung innerhalb eines Tages

Damit bleiben die Daten auswertbar, ohne ein dauerhaftes Personenprofil aufzubauen.

---

## Admin-Interface zur Auswertung

### Zielbild

Eigenes internes Dashboard hinter dem bestehenden Admin-Mechanismus.

Moegliche URL:

- `/admin/analytics.html`
oder
- eigener Admin-Bereich innerhalb einer bestehenden Seite

### Inhalte des Dashboards

#### 1. KPI-Kacheln

- aktive Sessions heute
- Installationen heute / gesamt im Zeitraum
- durchschnittliche Nutzungsdauer
- Anteil Standalone-Nutzung
- Top-Geraeteklasse

#### 2. Installations-Funnel

- installierbar
- Install-Hinweis gesehen
- Install-Button geklickt
- Installation bestaetigt
- App installiert

Damit sieht man sofort, wo Nutzer abspringen.

#### 3. Endgeraete-Auswertung

- Mobile / Tablet / Desktop
- iOS / Android / andere
- Browser-Verteilung
- Browser-Modus vs. Standalone

#### 4. Nutzungsanalyse

- Sessions pro Tag
- durchschnittliche und mediane Nutzungsdauer
- meistgenutzte Seiten
- meistgenutzte Features
- Nutzung nach Uhrzeit waehrend des Events

#### 5. Filter

- Zeitraum
- nur Standalone
- nur mobile Geraete
- nur bestimmte Seiten oder Features

#### 6. Export

- CSV-Export fuer Tageswerte
- optional JSON-Export fuer tiefergehende Analysen

### UI-Empfehlung

Fuer den Start reicht ein bewusst einfaches Dashboard:

- KPI-Kacheln oben
- 2 bis 4 Diagramme darunter
- Tabellen fuer Detailwerte
- kein komplexes BI-System im ersten Schritt

---

## Datenschutz und Recht

Das Thema ist hier zentral, weil die App oeffentlich genutzt wird.

### Was in die Datenschutzhinweise aufgenommen werden sollte

- welche Analytics-Daten erhoben werden
- zu welchem Zweck die Erhebung erfolgt
- ob die Daten anonym, pseudonym oder personenbezogen sind
- Speicherdauer
- Rechtsgrundlage
- ob Daten an Dritte uebermittelt werden
- wie Nutzer widersprechen oder Einwilligungen widerrufen koennen

### Praktische Datenschutz-Leitplanken

- keine Speicherung voller IP-Adressen
- keine Nutzerkonten mit Analytics verknuepfen
- keine exakten Bewegungsprofile erstellen
- kurze Aufbewahrung, z. B. 30 bis 90 Tage fuer Rohdaten
- Aggregationen laenger behalten, Rohdaten frueh loeschen

### Einwilligung / Consent

Hier gibt es zwei sinnvolle Wege:

#### Variante A: Streng datensparsam und moeglichst cookieless

- nur funktional notwendige, stark minimierte Messung
- keine dauerhaften Identifier
- nur aggregationsnahe Daten

Vorteil:

- einfachere Datenschutzlage

Nachteil:

- deutlich weniger Analyse-Tiefe

#### Variante B: Einwilligungsbasiertes Analytics

- Consent-Banner oder Consent-Schalter
- Tracking erst nach Zustimmung
- dafuer sauberere Rechtsgrundlage fuer detailliertere Auswertung

Vorteil:

- robustere juristische Position bei detaillierter Nutzungsmessung

Nachteil:

- weniger Datenbasis, weil nicht alle zustimmen

### Empfehlung

Wenn wirklich Nutzungsdauer, Endgeraete und Installations-Funnel ausgewertet werden sollen, ist eine datensparsame Einwilligungsloesung der sauberste Weg. Die finale Bewertung sollte trotzdem mit der verantwortlichen Stelle fuer Datenschutz abgestimmt werden.

---

## Umsetzungsphasen

### Phase 1: Minimal sinnvoll

- Installations-Tracking
- Session-Start
- Pageviews
- Geraeteklasse und Display-Modus
- einfache Admin-KPIs

Ergebnis:

- schnelle Sicht auf Adoption der App

### Phase 2: Nutzungsdauer und Feature-Nutzung

- Heartbeat bei aktiver Sichtbarkeit
- Feature-Events fuer Programm, Favoriten, Q&A, Feedback, Aussteller
- Sessions und Dauer sauber berechnen

Ergebnis:

- echte Nutzung statt nur Aufrufe

### Phase 3: Ausbau des Dashboards

- Zeitfilter
- Diagramme
- CSV-Export
- Vergleich Browser vs. Standalone

Ergebnis:

- brauchbares internes Steuerungsinstrument fuer Eventtage

---

## Alternativen

### Matomo

Vorteile:

- sofort viele Reports vorhanden
- selbst hostbar
- gute Standardauswertungen

Nachteile:

- zusaetzliche Komplexitaet und eigenes System
- eigenes Admin-Interface waere dann eher Matomo statt AS26-intern

### Plausible

Vorteile:

- sehr schlank
- datenschutzfreundlicher als viele klassische Tools

Nachteile:

- weniger Tiefe bei PWA-spezifischen Events und Session-Logik
- eigenes internes Dashboard nur eingeschraenkt

### Empfehlung im Vergleich

Wenn der Fokus auf einem internen, projektnahen Admin-Bereich liegt, ist eine kleine Eigenloesung am passendsten. Wenn schnelle Standard-Webanalyse wichtiger ist als enge Integration, waere Matomo die pragmatische Alternative.

---

## Konkrete Fragen

1. Soll Analytics nur intern fuer das Team sichtbar sein oder spaeter auch fuer Aussteller / Referenten?
2. Wollt ihr nur aggregierte Zahlen oder auch einen echten Installations-Funnel mit Abbruchpunkten?
3. Ist ein Consent-Banner akzeptiert, falls dadurch die Datenschutzlage sauberer wird?
4. Sollen Rohdaten nur waehrend des Event-Zeitraums gehalten werden oder auch fuer Vergleiche mit kuenftigen Jahren?
5. Reicht eine Auswertung auf Tagesbasis oder braucht ihr Live-Zahlen waehrend der Veranstaltung?
6. Soll das Dashboard nur lesen oder auch Schalter enthalten, z. B. Analytics aktivieren/deaktivieren oder Tracking-Status pruefen?

---

## Empfehlung in einem Satz

Eine kleine First-Party-Analytics-Loesung mit datensparsamen Events, Consent-Option und internem Admin-Dashboard ist fuer dieses Projekt der sinnvollste Mittelweg zwischen Erkenntnisgewinn, technischer Kontrolle und Datenschutz.