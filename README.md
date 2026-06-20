# Discuz! X3.5 Plugin Dev Environment

A turnkey Docker environment for developing **Discuz! X3.5** plugins with AI assistance.
The forum boots **already installed** (no setup wizard), the database is **ephemeral**
(re-seeded every boot), and plugins are authored **directly in the source tree** and indexed below.

- **Core:** `Discuz_X3.5_SC_UTF8/upload` (release 20260504), baked into the image.
- **Stack:** PHP 8.1 + Apache (`web`) and MariaDB 10.11 (`db`, in-RAM via tmpfs).
- **Port:** auto-picked starting at **34728** (`+1` until free) — see `.env`.
- **Admin login:** `admin` / `admin888`.

## Quick start

```bash
# First time only — create the seed (one-time install, then snapshot):
make build          # build the web image
make bootstrap      # start in installer mode
make install        # auto-run the Discuz installer (no browser)
make seed           # snapshot DB + config into ./seed  (commit this)
make restart        # boot from the seed -> turnkey

# Everyday:
make up             # start; prints http://localhost:<port>
make down           # stop (DB wiped; seed reloads next time)
```

## Commands

| Command | What it does |
| --- | --- |
| `make up` / `make down` | start (free port) / stop the stack |
| `make reset` | wipe & recreate (re-seed DB) |
| `make build` / `make rebuild` | build / rebuild the web image |
| `make logs` / `make shell` / `make ps` | logs / shell into web / status |
| `make bootstrap` + `make install` + `make seed` | one-time: create the turnkey seed |
| `make new-plugin id=<id>` | scaffold a plugin in the source tree |
| `make enable-plugin id=<id>` | best-effort zero-click register+enable |
| `make list-plugins` | regenerate the Plugins table below |

## Plugin workflow

1. `make new-plugin id=myplugin` → creates
   `Discuz_X3.5_SC_UTF8/upload/source/plugin/myplugin/` (+ template dir). Edit with AI.
2. Files are **bind-mounted live**, so changes show up while the stack runs (PHP is live;
   for `.htm` template changes, clear the template cache in Admin CP).
3. `make enable-plugin id=myplugin` (or Admin CP → Apps → Plugins → import
   `discuz_plugin_myplugin.xml` → Enable).
4. To make it **pre-installed on every boot**, run `make seed` again and commit.

## Notes

- **Ephemeral by design:** the DB and uploaded files do not persist. Only the source tree,
  `./seed`, and your plugins are version-controlled.
- **Licensing:** Discuz! X3.5 is proprietary (free for non-commercial use; keep the
  "Powered by Discuz" footer). Committing the core is fine for a **private** repo. If this repo
  goes **public**, uncomment the distro line in `.gitignore` and `git rm -r --cached Discuz_X3.5_SC_UTF8`.

## Plugins

<!-- PLUGINS:START -->
| ID | Name | Description | Path |
| --- | --- | --- | --- |
| `helloworld` | Hello World | Example plugin: shows a footer banner on every page. | [`Discuz_X3.5_SC_UTF8/upload/source/plugin/helloworld/`](Discuz_X3.5_SC_UTF8/upload/source/plugin/helloworld/) |
| `mobile` | 掌上论坛 |  | [`Discuz_X3.5_SC_UTF8/upload/source/plugin/mobile/`](Discuz_X3.5_SC_UTF8/upload/source/plugin/mobile/) |
| `myrepeats` | 我的马甲 |  | [`Discuz_X3.5_SC_UTF8/upload/source/plugin/myrepeats/`](Discuz_X3.5_SC_UTF8/upload/source/plugin/myrepeats/) |
| `qqconnect` | QQ互联 |  | [`Discuz_X3.5_SC_UTF8/upload/source/plugin/qqconnect/`](Discuz_X3.5_SC_UTF8/upload/source/plugin/qqconnect/) |
| `witframe_api` | WitFrame API |  | [`Discuz_X3.5_SC_UTF8/upload/source/plugin/witframe_api/`](Discuz_X3.5_SC_UTF8/upload/source/plugin/witframe_api/) |
<!-- PLUGINS:END -->
