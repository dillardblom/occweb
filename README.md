# Extended OCC Web (occ & SQL terminal)

### A web terminal for Nextcloud admins to run occ commands and SQL queries

Available in the [Nextcloud App Store](https://apps.nextcloud.com/apps/extended_occweb) as `extended_occweb`.

![occweb](https://github.com/dillardblom/occweb/raw/main/appinfo/screenshot.png)

## History

The original [Adphi/occweb](https://github.com/Adphi/occweb) was marked deprecated by its
author and is unmaintained. This project started as a fork of
[fanategorius/occweb](https://github.com/fanategorius/occweb), which had picked up
compatibility maintenance where Adphi left off, and tracked it while adding SQL query mode,
security hardening (admin checks, CSRF/confirmation flows, transactional batches), and PHP
8.1+ compatibility fixes. Since 0.4.3 it is published under its own app id,
`extended_occweb`, and it is now maintained as an independent project.

### Credits

- [Adphi/occweb](https://github.com/Adphi/occweb) — original author/project.
- [fanategorius/occweb](https://github.com/fanategorius/occweb) — the maintained fork this
  project was based on.
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

Nextcloud requires the app's directory name to match its id (`extended_occweb`), so
clone into that name explicitly rather than the repo's own name:

```bash
cd /var/www/nextcloud/apps
git clone https://github.com/dillardblom/occweb.git extended_occweb
bash extended_occweb/install.sh
```

`install.sh` removes the dev/CI-only files that don't belong in a running
install (`tests/`, `.travis.yml`, `phpunit*.xml`, `composer.json`,
`composer.lock`, `Makefile`), `chown -R`s the app directory to the web
server user, and runs `occ app:enable`. It assumes Nextcloud lives at
`/var/www/nextcloud` and the web server user is `www-data`; pass different
values as arguments if that's not the case:

```bash
bash extended_occweb/install.sh /path/to/nextcloud custom-web-user
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

Long values in result tables are cut to fit the terminal. Add `--full` to the
query (it is an SQL comment, so the database ignores it) to see them in full,
e.g. `SELECT configvalue FROM oc_appconfig WHERE configkey = 'x'; --full`.

Every statement that changes data or the schema (`DELETE`, `UPDATE`,
`INSERT`, `DROP`, a data-modifying `WITH`, ...) asks for confirmation before
the batch runs. There is still no undo — double check what you are about to
run, ideally against a non-critical row/user first. Statements that access the
server's filesystem or start programs are blocked.

## Security

OCC Web is a powerful tool: it gives whoever can open it the same control over
Nextcloud as `occ` on the command line, plus direct access to the database.
Treat access to it like shell access to the server.

- **Only admins can use it.** Every endpoint checks that the user is a member
  of the Nextcloud `admin` group, and the endpoints that run commands or SQL
  also require a valid CSRF token; other users get HTTP 403, anonymous
  requests HTTP 401. This is the security boundary of the app.
- **Be careful who you make an admin.** Anyone in the `admin` group can use OCC
  Web, and an admin can do everything this app does in other ways as well.
  Deciding who gets admin rights is up to the instance operator, not this app.
- **Run the database as a scoped user with limited rights.** The SQL mode runs
  with the rights of Nextcloud's own database user. Make sure that user is not
  a database superuser and cannot read or write files on the database server
  or start programs there (on PostgreSQL: no superuser, no
  `pg_execute_server_program`, `pg_read_server_files` or
  `pg_write_server_files`). The checks in the SQL mode are an extra layer, not
  a replacement for this.
- **No blocklist of occ commands, on purpose.** We don't block commands that
  show sensitive settings. It would make the app less useful, and an admin
  can run such commands in a slightly more roundabout way anyway, so it would
  only give a false sense of security.

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

After pulling/copying new code into `nextcloud/apps/extended_occweb/`, restart PHP so
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
sudo -u www-data php /var/www/nextcloud/occ config:app:set extended_occweb installed_version --value="X.Y.Z"
```

(use the same `X.Y.Z` as the new `<version>`), then restart PHP as above.
This does not apply on a fresh `occ app:enable` install — that command sets
`installed_version` correctly on its own.

## Compatibility matrix

`max-version` in `appinfo/info.xml` only ever reflects the highest version we've actually
tested (see the policy comment right above it) - this table is the actual history of what
was verified, so a rollback target is obvious if a newer major causes problems.

| occweb version | Tag | NC version tested | Result |
|---|---|---|---|
| 0.3.4 | `v0.3.4` | 33.0.8 | ✅ Verified (2026-09-25). Last release under the old `occweb` id; does not work on NC34 (missing jQuery, CSRF), fixed from 0.3.5 on. |
| 0.4.3 | `v0.4.3` | 32.0.15, 33.0.9, 34.0.4, 35.0.1 | ✅ Verified (`testbak/`, 2026-09-27). First release under `extended_occweb`: NC35 fatal in `OccOutput` fixed, vendored jQuery only loaded on NC34+. |
| 0.4.4 | (untagged) | 34.0.4 | ✅ Verified (2026-09-30). Brackets in occ output escaped before rendering. |
| 0.4.5 | `v0.4.5` | 32.0.15, 33.0.9, 34.0.4, 35.0.1 | ✅ Verified (`testbak/`, 2026-09-30). SQL mode hardening, occ output sanitized, first release code-signed with the Nextcloud certificate. |
| 0.4.6 | `v0.4.6` | 32.0.15, 33.0.9, 34.0.4, 35.0.1 | ✅ Verified (`testbak/`, 2026-09-30). occ commands logged for the audit trail, console built from the container, more SQL guard gaps closed, long values in result tables cut to fit (`--full` shows them). |

Older entries (0.3.5-0.4.2) are in the git history of this file.

`min-version`/`max-version` in `appinfo/info.xml` are `32`/`35` since 2026-09-27: 30/31 were never tested and are past Nextcloud's supported range, and 35 is verified rather than guessed.

If a future major breaks something, `v0.3.4` is the last version confirmed working on NC33
and older - but a plain `git checkout v0.3.4` is **not** a working rollback by itself. That
tag predates the app id rename (it still declares `<id>occweb</id>`), so checking it out
into an `extended_occweb`-named install just produces a broken id/directory mismatch. A
real rollback means disabling/removing `extended_occweb` and deploying `v0.3.4` fresh under
an `occweb`-named directory instead, same as the original install steps above. This is one
of the items still deferred to when a proper test instance exists (see the code review
notes in git history around 0.4.1) - not yet written up in full.

## TODOs:
See [open issues](https://github.com/dillardblom/occweb/issues)
