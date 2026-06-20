# 🧩 Discuz! X3.5 Plugin Dev Environment

> Boot a fully-installed Discuz! X3.5 forum in Docker — no wizard, ephemeral DB, plugins authored right in the source tree. 🚀

| 🔧 | Value |
| --- | --- |
| 🌐 URL | `http://localhost:34728` (auto-picks next free port) |
| 👤 Admin | `admin` / `admin888` |
| 🐘 Stack | PHP 8.1 + Apache · MariaDB 10.11 |
| 📦 Core | `Discuz_X3.5_SC_UTF8/upload` (20260504) |

## 🏗️ Architecture

```
                 make up
                    │
        ┌───────────┴───────────┐
        ▼                        ▼
┌────────────────┐      ┌──────────────────┐
│  web 🐘         │      │  db 🗄️           │
│  php8.1+apache │◄────►│  mariadb (tmpfs)  │
│  Discuz baked  │      │  RAM-only, wiped  │
│  into image    │      │  & re-seeded/boot │
└───────┬────────┘      └─────────▲────────┘
        │ live mount (ro)         │ auto-load on boot
        ▼                         │
 source/plugin/<id>         seed/db/01-discuz.sql
 template/.../plugin/<id>   seed/config/* + install.lock
        ▲                         ▲
        └──── your repo (committed) ──┘
```

## ⚡ Quick start

| Step | Command | When |
| --- | --- | --- |
| ▶️ Start | `make up` | daily |
| ⏹️ Stop | `make down` | daily |
| 🌱 Build the seed | `make build && make bootstrap && make install && make seed && make restart` | once |

## 🛠️ Commands

| Command | 🧰 Does |
| --- | --- |
| `make up` / `make down` | start (free port) / stop |
| `make reset` | wipe + recreate (re-seed) |
| `make logs` / `make shell` / `make ps` | logs / shell / status |
| `make new-plugin id=<id>` | 🆕 scaffold a plugin |
| `make enable-plugin id=<id>` | ✅ register + enable |
| `make list-plugins` | 🔄 refresh table below |
| `make seed` | 💾 bake current state into seed |

## 🔌 Plugin flow

```
make new-plugin id=foo ─► edit source/plugin/foo/ ─► make enable-plugin id=foo ─► 🌐 live
                          (bind-mounted, no rebuild)                  └─ make seed = permanent
```

> ⚠️ `id` = lowercase letters/digits/`_` only (no hyphens — the hook class is `plugin_<id>`).

## 📋 Plugins

<!-- PLUGINS:START -->
| ID | Name | Docs | Description | Path |
| --- | --- | --- | --- | --- |
| `helloworld` | Hello World | [📖 guide](docs/plugins/helloworld.md) | Example plugin: shows a footer banner on every page. | [`Discuz_X3.5_SC_UTF8/upload/source/plugin/helloworld/`](Discuz_X3.5_SC_UTF8/upload/source/plugin/helloworld/) |
| `invitecode` | Invitation Code Generator | [📖 guide](docs/plugins/invitecode.md) | Admin tool to bulk-generate invitation codes for Discuz's BUILT-IN invite registration (common_invite). Use with registration set to "invite code" mode. | [`Discuz_X3.5_SC_UTF8/upload/source/plugin/invitecode/`](Discuz_X3.5_SC_UTF8/upload/source/plugin/invitecode/) |
<!-- PLUGINS:END -->

## ⚖️ Notes

- 🔁 **Ephemeral:** DB + uploads don't persist; only the source tree & `seed/` are committed.
- 📜 **License:** Discuz! X3.5 is proprietary (free non-commercial; keep the *Powered by Discuz* footer). Public repo → uncomment the distro line in `.gitignore`.
