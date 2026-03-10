# Copilot Instructions for AS26

## Production Update (Same Host)

- Production checkout path: `/var/www/as26.cool-camp.site`
- Standard deployment pull command:
  - `cd /var/www/as26.cool-camp.site && git pull`

## Chat Agent Note

- Assume production is on the same host unless explicitly told otherwise.
- For "deploy to prod" requests, first run:
  - `cd /var/www/as26.cool-camp.site && git pull`
- If pull is blocked by local changes, report exact blockers and ask whether to `stash`, `commit`, or clean files before retrying.
