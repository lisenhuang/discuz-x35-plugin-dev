# CLAUDE.md — working in this repo

This repo is a Docker-based **Discuz! X3.5 plugin development environment**. Read this before
authoring plugins or changing infrastructure.

## What runs where
- **Core (do not edit casually):** `Discuz_X3.5_SC_UTF8/upload/` is the Discuz web root, baked
  into the `web` image at build (`Dockerfile`: `COPY .../upload → /var/www/html`).
- **Services:** `docker-compose.yml` — `web` (php:8.1-apache) + `db` (mariadb:10.11, **tmpfs** →
  DB is ephemeral and re-seeded from `./seed/db` on every boot).
- **Turnkey seed:** `./seed/db/01-discuz.sql` + `./seed/config/*` are injected by
  `scripts/entrypoint.sh` so the install wizard never runs. Regenerate with `make seed`.
- **Port:** chosen by `scripts/pick-port.sh` → `.env` (`DZ_PORT`, default 34728+).

## Authoring a plugin (the only supported flow)
1. Scaffold: `make new-plugin id=<id>` → creates, in the source tree:
   - `Discuz_X3.5_SC_UTF8/upload/source/plugin/<id>/<id>.class.php` — front-end hook class
     `plugin_<id>`; public methods named after Discuz hooks (e.g. `global_footer()`) run there.
   - `.../source/plugin/<id>/admincp.inc.php` — admin settings module (type 3).
   - `.../source/plugin/<id>/install.php` / `uninstall.php` — set `$finish = TRUE;`.
   - `.../source/plugin/<id>/discuz_plugin_<id>.xml` — manifest (modules: type 11 hook + type 3 admincp).
   - `.../template/default/plugin/<id>/` — `.htm` templates (only if needed).
2. Edit the PHP/templates with the user. Plugin dirs are **bind-mounted read-only** into the
   running container, so PHP edits are live (opcache validates timestamps). `.htm` template edits
   need a cache clear (Admin CP → Tools → Update cache).
3. Enable: `make enable-plugin id=<id>` (best-effort, via `scripts/import-plugin.php`). If it
   doesn't take, enable in Admin CP → Apps → Plugins → import `discuz_plugin_<id>.xml` → Enable.
4. Persist: `make seed` bakes the now-installed plugin into the seed so it's pre-installed on boot.
5. Index: `make list-plugins` refreshes the Plugins table in `README.md`.

## Conventions & gotchas
- Plugin `<id>`: lowercase letters/digits/underscore. The class must be `plugin_<id>` in
  `<id>.class.php`. The manifest `identifier` and `directory` must match `<id>`.
- Guard every PHP file with `if(!defined('IN_DISCUZ')) { exit('Access Denied'); }`.
- Discuz uses **mysqli** (not PDO). DB table prefix is `pre_`. In bootstrapped scripts use
  `DB::table('...')`, `DB::query/insert/update`, and `updatecache(...)`.
- Don't bind-mount all of `source/plugin` from an empty host dir — here the host dir is the full
  tree (bundled + custom), so the existing read-only mounts are safe.
- After changing the core or the seed schema, re-run `make seed`.
- Never commit `config_global.php`, `config_ucenter.php`, or runtime data — `.gitignore` covers them.

## Useful commands
`make up | down | reset | build | rebuild | logs | shell | ps | bootstrap | install | seed |
new-plugin id=<id> | enable-plugin id=<id> | list-plugins`
