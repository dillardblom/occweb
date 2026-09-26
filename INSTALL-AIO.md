# occweb installeren/updaten in Nextcloud AIO

Fork van de originele OCCWeb-app (upstream `fanategorius/occweb` toont weinig activiteit;
laatste bekende versie daar is nog altijd 0.3.3, geen tags/releases). Deze fork
(`dillardblom/occweb`, gespiegeld op `git.ensembia.net/Ensembia/occweb`) is de actief
onderhouden versie en draait op nc01.

**Versie**: zie `<version>` in `appinfo/info.xml`.

## Waar de app hoort

`/var/www/html/custom_apps/occweb` — **niet** `/var/www/html/apps/occweb`. AIO's
`config.php` heeft `apps_paths` met `apps/` op `writable:false` (beheerd door
Nextcloud's core-appstore-sync, kan custom code overschrijven) en `custom_apps/` op
`writable:true` (de bedoelde plek voor third-party apps als deze).

## Deploy/update-procedure

De container heeft geen (werkende) git-toegang naar GitHub; deploy lokaal een tarball
i.p.v. `git clone`/`git pull` in de container.

```bash
# 1. Lokaal: clean checkout van main (dev-bestanden eruit) als tar
cd ~/Next/Documents/Development/Nextcloud/occweb
STAGE=$(mktemp -d)
git archive main | tar -x -C "$STAGE"
rm -rf "$STAGE"/{tests,.travis.yml,phpunit.xml,phpunit.integration.xml,composer.json,composer.lock,Makefile}
tar -C "$STAGE" -cf /tmp/occweb-deploy.tar .

# 2. Naar nc01, in de container uitpakken over de bestaande app
scp /tmp/occweb-deploy.tar nc01:/tmp/occweb-deploy.tar
ssh nc01 "docker cp /tmp/occweb-deploy.tar nextcloud-aio-nextcloud:/tmp/occweb-deploy.tar && \
  docker exec nextcloud-aio-nextcloud sh -c 'mkdir -p /var/www/html/custom_apps/occweb && \
  tar -xf /tmp/occweb-deploy.tar -C /var/www/html/custom_apps/occweb && rm /tmp/occweb-deploy.tar'"

# 3. Eigendom herstellen — tar bewaart de lokale uid (bv. 1000), niet www-data
ssh nc01 "docker exec nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/occweb"

# 4. Alleen bij een fresh install nodig (overslaan bij een update van een al-enabled app):
ssh nc01 "docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable occweb"
```

> **Gotcha — nooit `ssh host "docker exec container bash -c 'cat > file'"` met lokale stdin
> gebruiken**: dat maakt een leeg bestand. Altijd `scp` → `docker cp`, zoals hierboven.

## ⚠️ Cruciaal bij een `<version>`-bump in `appinfo/info.xml`

Dit is **niet optioneel** en blokkeert bij overslaan niet alleen occ-commando's maar ook
**gewoon inloggen op de webinterface** (zelf ondervonden op 2026-09-25: na een deploy met
een nieuwe `<version>` maar zonder onderstaande stap toonde Nextcloud voor iedereen een
upgrade-scherm i.p.v. de loginpagina).

Nextcloud houdt `installed_version` per app bij in de database (`oc_appconfig`), los van
`<version>` in `info.xml`. Na het handmatig vervangen van bestanden (i.p.v. via de appstore
of `occ upgrade`) lopen die twee uit elkaar → Nextcloud zet de hele instantie op
"needs upgrade", wat ook de login blokkeert totdat je dit synct:

```bash
ssh nc01 "docker exec -u www-data nextcloud-aio-nextcloud php occ config:app:set occweb installed_version --value='X.Y.Z'"
```

(zelfde `X.Y.Z` als de nieuwe `<version>`). Verifieer met `occ status` dat
`needsDbUpgrade: false` weer klopt.

## PHP-FPM herstarten na elke deploy

`occ app:disable`/`app:enable` leegt PHP's opcache niet — een update kan zonder restart
onopgemerkt niet actief worden (routes die stil 404'en is het typische symptoom). Graceful
reload i.p.v. hard restart (behoudt actieve requests):

```bash
ssh nc01 "docker exec nextcloud-aio-nextcloud ps aux | grep 'php-fpm: master'"   # zoek de PID
ssh nc01 "docker exec nextcloud-aio-nextcloud kill -USR2 <master-pid>"
```

`supervisorctl` werkt hier niet direct (de supervisord-socket zit niet op het standaardpad
binnen deze AIO-container) — vandaar de handmatige PID-lookup.

## Verwijderen

```bash
ssh nc01 "docker exec -u www-data nextcloud-aio-nextcloud php occ app:disable occweb && \
  docker exec nextcloud-aio-nextcloud rm -rf /var/www/html/custom_apps/occweb"
```

## SQL query mode

Type `sql` in de OCCWeb-terminal voor directe SQL-queries tegen de NC-database.
Statements scheiden met `;`, Shift+Enter voor nieuwe regel, Enter om te sturen.
