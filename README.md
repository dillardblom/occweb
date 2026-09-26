# OCCWeb terminal (maintained fork)

### A web terminal for admins to launch Nextcloud's occ commands

![occweb](https://github.com/Adphi/OCCWeb/raw/main/appinfo/screenshot.png)

## About this fork

The original [Adphi/occweb](https://github.com/Adphi/occweb) was marked deprecated by its
author and is unmaintained. This fork tracks [fanategorius/occweb](https://github.com/fanategorius/occweb),
which picked up compatibility maintenance where Adphi left off, and adds SQL query mode,
security hardening (admin checks, CSRF/confirmation flows, transactional batches), and PHP
8.1+ compatibility fixes on top.

### Credits

- [Adphi/occweb](https://github.com/Adphi/occweb) — original author/project.
- [fanategorius/occweb](https://github.com/fanategorius/occweb) — the actively maintained
  fork this repo is based on and tracks.
- [Git-Usr123/occweb](https://github.com/Git-Usr123/occweb) — independent fork; the
  `OC::$server->getConfig()` → `OC::$server->get(\OCP\IConfig::class)` fix in
  `OccController.php` was spotted there and backported here.

**This tool is still not the right place for big/risky operations.** It has no support for
asynchronous or long-running tasks — every command runs synchronously inside a single PHP
web request, so anything that takes a while (`occ files:scan` on a large instance, a Nextcloud
major-version `occ upgrade`, `occ maintenance:mode --on` workflows) can time out mid-operation
with no way to recover cleanly. Use a real shell (SSH + `occ` directly, or the guest-agent/
console) for major upgrades and other long-running or high-blast-radius operations; reserve
this terminal for routine day-to-day occ/SQL admin tasks. [This issue](https://github.com/nextcloud/server/issues/16726)
has background on why Nextcloud has no native async support for occ tasks.


## Install

Running Nextcloud AIO (Docker)? See [INSTALL-AIO.md](INSTALL-AIO.md) instead — the app
path and deploy steps differ from the generic instructions below.

No build step required (plain PHP + vanilla JS). Clone straight into the
target server's `apps/` directory and run the install script:

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/fanategorius/occweb.git
bash occweb/install.sh
```

`install.sh` removes the dev/CI-only files that don't belong in a running
install (`tests/`, `.travis.yml`, `phpunit*.xml`, `composer.json`,
`composer.lock`, `Makefile`), `chown -R`s the app directory to the web
server user, and runs `occ app:enable`. It assumes Nextcloud lives at
`/var/www/nextcloud` and the web server user is `www-data`; pass different
values as arguments if that's not the case:

```bash
bash occweb/install.sh /path/to/nextcloud custom-web-user
```

`occ app:enable` takes care of registering the app's version correctly, so
none of the "Deploying updates" caveats below apply to a fresh install —
they only matter once the app is already enabled and you're updating it in
place.

Before installing, check your Nextcloud version against the range in
`appinfo/info.xml` (`dependencies/nextcloud`, currently `min-version`/
`max-version`) — `occ app:enable` refuses to enable an app outside that
range.

Note: `install.sh` deletes files that are tracked in git. If you plan to
keep this install up to date with `git pull` instead of re-cloning, be
aware that a future upstream change to one of the removed files could make
a plain `git pull` refuse to merge — it will tell you so, and you can
`git checkout -- <file>` to recover it if that happens.

## SQL query mode

Type `sql` in the terminal to switch into SQL query mode and run raw SQL
statements directly against the Nextcloud database (admin only). Separate
statements with `;`. Use **Shift+Enter** to add a new line and **Enter** to
run the whole block in a single request — this matters for scripts like
`SET vars.x = 'value'; SELECT current_setting('vars.x');`, since every
statement in one submission runs on the same database connection/session.
Type `occ` to switch back to the normal occ-command mode.

⚠️ There is no undo for `DELETE`/`UPDATE` statements — double check what
you are about to run, ideally against a non-critical row/user first.

## ⚠️ Warnings ⚠️

- The application is not a real interactive terminal and does not support long running tasks. 
So if your instance is pretty big, commands like `occ files:scan` will time out and fail.
- Do not use `occ maintenance:mode --on`, obvious...
- The `rename-user` SQL template is **best-effort only**: renaming a Nextcloud
  username is not an officially supported operation. It updates the core
  tables it knows about (`oc_users`, `oc_preferences`, `oc_group_user`,
  `oc_group_admin`, `oc_ldap_user_mapping`, `oc_share`, `oc_mounts`,
  `oc_storages`), but does **not** touch app-specific tables (Talk, Calendar,
  Contacts, Mail, two-factor/WebAuthn, etc.). You must also manually rename
  the user's data directory on disk (`data/<old> -> data/<new>`) with the web
  server stopped or in maintenance mode, then run `occ files:scan --all`.
  Back up the database first and test on a non-critical account.

## Deploying updates

After pulling/copying new code into `nextcloud/apps/occweb/`, restart PHP so
the changes actually take effect. `occ app:disable`/`app:enable` is **not**
enough — it does not clear PHP's opcode cache, so a stale, cached version of
the code can keep running (routes silently 404ing is a typical symptom).
Restart the PHP process itself, for example:

```bash
sudo systemctl restart php8.3-fpm   # adjust to your installed PHP version
sudo systemctl restart apache2      # if PHP runs as an Apache module instead
```

### If you bump the `<version>` in `appinfo/info.xml`

Nextcloud tracks each app's installed version in its database (`installed_version`
in `oc_appconfig`) separately from the `<version>` in `info.xml`. If you edit
files in place (copy/`git pull`) instead of going through the app store or
`occ upgrade`, those two get out of sync — Nextcloud then treats the whole
instance as "needs upgrade" and blocks most `occ` commands behind the
CLI-upgrade wizard, even though nothing about Nextcloud core actually
changed. After bumping the version, sync it manually:

```bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set occweb installed_version --value="X.Y.Z"
```

(use the same `X.Y.Z` as the new `<version>`), then restart PHP as above.
This does not apply on a fresh `occ app:enable` install — that command sets
`installed_version` correctly on its own.

## TODOs:
See [open issues](https://github.com/dillardblom/occweb/issues)
