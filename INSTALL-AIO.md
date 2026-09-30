# Installing/updating occweb on Nextcloud AIO (Docker)

Nextcloud's official All-in-One (AIO) distribution runs core in its own container
(`nextcloud-aio-nextcloud` by convention), so the generic [install instructions](README.md)
(`git clone` straight into the server) don't apply as-is. This covers the differences.

## Where the app belongs

`/var/www/html/custom_apps/` inside the core container — **not** `/var/www/html/apps/`.
AIO's `config.php` sets `apps_paths` with `apps/` as `writable: false` (managed by
Nextcloud's own core/appstore sync, which can overwrite custom code placed there) and
`custom_apps/` as `writable: true` — the intended location for third-party apps like
this one.

## Deploy/update procedure

The AIO container has no working git access to GitHub, so deploy a tarball instead of
`git clone`/`git pull` inside it:

```bash
# 1. On your machine: clean checkout of the branch/tag you want, dev files stripped
STAGE=$(mktemp -d)
git archive HEAD | tar -x -C "$STAGE"
rm -rf "$STAGE"/{tests,.travis.yml,phpunit.xml,phpunit.integration.xml,composer.json,composer.lock,Makefile}
tar -C "$STAGE" -cf /tmp/occweb-deploy.tar .

# 2. Copy it onto the Docker host, then into the container
scp /tmp/occweb-deploy.tar <docker-host>:/tmp/occweb-deploy.tar
ssh <docker-host> "docker cp /tmp/occweb-deploy.tar nextcloud-aio-nextcloud:/tmp/occweb-deploy.tar && \
  docker exec nextcloud-aio-nextcloud sh -c 'mkdir -p /var/www/html/custom_apps/extended_occweb && \
  tar -xf /tmp/occweb-deploy.tar -C /var/www/html/custom_apps/extended_occweb && rm /tmp/occweb-deploy.tar'"

# 3. Fix ownership - tar preserves the source uid/gid, not www-data
ssh <docker-host> "docker exec nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/extended_occweb"

# 4. Fresh install only (skip when updating an already-enabled app):
ssh <docker-host> "docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable extended_occweb"
```

> **Gotcha**: never copy a file into a container with `ssh host "docker exec container bash -c 'cat > file'"`
> piped from local stdin — that produces an empty file. Always `scp` to the host, then
> `docker cp` into the container, as above.

## ⚠️ Critical after bumping `<version>` in `appinfo/info.xml`

This step is **not optional** — skipping it doesn't just block occ commands, it can
block **logging into the web UI entirely** (Nextcloud shows an upgrade screen to every
user instead of the login page).

Nextcloud tracks each app's installed version in the database (`installed_version` in
`oc_appconfig`), separately from `<version>` in `info.xml`. If you replace files in place
(instead of going through the app store or `occ upgrade`), those two fall out of sync,
and Nextcloud puts the whole instance into a "needs upgrade" state until you sync it
manually:

```bash
ssh <docker-host> "docker exec -u www-data nextcloud-aio-nextcloud php occ config:app:set extended_occweb installed_version --value='X.Y.Z'"
```

(use the same `X.Y.Z` as the new `<version>`). Verify with `occ status` that
`needsDbUpgrade: false` again.

## Restart PHP-FPM after every deploy

`occ app:disable`/`app:enable` does not clear PHP's opcache — an update can silently
fail to take effect without a restart (routes 404ing is the typical symptom). Prefer a
graceful reload over a hard restart (keeps in-flight requests alive):

```bash
ssh <docker-host> "docker exec nextcloud-aio-nextcloud ps aux | grep 'php-fpm: master'"   # find the PID
ssh <docker-host> "docker exec nextcloud-aio-nextcloud kill -USR2 <master-pid>"
```

`supervisorctl` won't work directly inside this container (its socket isn't at the
default path) — hence the manual PID lookup.

## Removing the app

```bash
ssh <docker-host> "docker exec -u www-data nextcloud-aio-nextcloud php occ app:disable extended_occweb && \
  docker exec nextcloud-aio-nextcloud rm -rf /var/www/html/custom_apps/extended_occweb"
```

## `max-version` policy

See the comment next to `<dependencies>` in `appinfo/info.xml`: it must always equal the
highest Nextcloud core version this fork has actually been verified against — never a
value inherited from upstream, and never a guess ahead of what's been tested. Test
against the next major's beta ahead of time and bump it before that major goes final,
rather than reacting after the fact.

## SQL query mode

Type `sql` in the OCCWeb terminal to run raw SQL directly against the Nextcloud
database. Separate statements with `;`, use Shift+Enter for a new line and Enter to run.

Before giving anyone access, read the Security section in the README: only
admins can use the app, and the database user should have limited rights.
