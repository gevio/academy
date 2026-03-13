# Copilot Instructions for AS26

## Production Update (Same Host)

- Production checkout path: `/var/www/as26.cool-camp.site`
- Standard deployment pull command:
  - `cd /var/www/as26.cool-camp.site && git pull`

## Cron Jobs (JSON and Images)

- Data/assets are regenerated via cron on both prod and dev.
- Hourly fast run (without image refresh):
  - `0 * * * * cd /var/www/as26.cool-camp.site && php cli/generate-json.php --skip-images >> /var/log/as26-json.log 2>&1`
  - `0 * * * * cd /var/www/dev.as26.cool-camp.site && php cli/generate-json.php --skip-images >> /var/log/as26-json.log 2>&1`
- Nightly full refresh (including images):
  - `30 2 * * * cd /var/www/as26.cool-camp.site && php cli/generate-json.php --refresh-images >> /var/log/as26-json.log 2>&1`
  - `30 2 * * * cd /var/www/dev.as26.cool-camp.site && php cli/generate-json.php --refresh-images >> /var/log/as26-json.log 2>&1`
- If `public/img/aussteller/*.webp` appears as local changes on prod, this can be expected from cron regeneration.

## Chat Agent Note

- Assume production is on the same host unless explicitly told otherwise.
- For "deploy to prod" requests, first run:
  - `cd /var/www/as26.cool-camp.site && git pull`
- If pull is blocked by local changes, report exact blockers and ask whether to `stash`, `commit`, or clean files before retrying.

## Release Management

- Release-DB: `storage/releases/releases.sqlite` (SQLite, gitignored – wird pro Umgebung separat geführt)
- CLI-Tool: `php cli/release.php`
  - `init` – DB initialisieren (einmalig pro Umgebung)
  - `current` – Aktuelle Version anzeigen
  - `log [--limit=N]` – Changelog anzeigen
  - `release <X.Y.Z> "<Beschreibung>"` – Neues Release eintragen, aktualisiert automatisch `APP_VERSION` in `config/.env`
- Nach jeder abgeschlossenen Aufgabe: Version hochzählen mit `php cli/release.php release X.Y.Z "Beschreibung"`
- Semantic Versioning: MAJOR.MINOR.PATCH (z.B. 1.1.0 für neue Features, 1.0.1 für Bugfixes)
- Bei Deployment auf Prod:
  1. `cd /var/www/as26.cool-camp.site && git pull`
  2. `php cli/release.php init` (falls DB noch nicht existiert)
  3. `php cli/release.php release <X.Y.Z> "<Beschreibung>"` (gleiche Version wie auf Dev)

