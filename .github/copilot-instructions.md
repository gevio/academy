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
