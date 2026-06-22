# Telegram Push 推送 (`tgnotify`)

Push **every new thread and reply** to a Telegram channel — immediately. Choose which forums (板块)
are forwarded from a checkbox list, set a read-permission ceiling, and shape the message text with
toggleable cleanup rules (quotes/links/hidden content/attachments → emoji, strip BBCode, truncate)
plus your own custom regex rules. **Pure plugin — it does not modify Discuz core.**

| | |
| --- | --- |
| 🆔 Identifier | `tgnotify` |
| 🧰 Requires | Discuz! X3.5, PHP with cURL, outbound HTTPS to `api.telegram.org` |
| 📁 Files | `source/plugin/tgnotify/` |
| 🗄 Tables | `pre_tgnotify_state` (one row: send cursor + stats) |

## 📥 Install on your own Discuz! forum

1. Copy the plugin folder into your forum, keeping the same path:
   ```
   source/plugin/tgnotify/   →   <your-forum>/source/plugin/tgnotify/
   ```
2. **Admin CP (管理中心) → 应用 Apps → 插件 Plugins** → find `tgnotify` → **安装 Install → 启用 Enable**.
   *(Alternative: 导入 Import `discuz_plugin_tgnotify.xml`.)*

## ⚙️ Set up (Admin CP → Apps → Plugins → Telegram Push → Settings)

The settings page has four tabs:

### 🔌 连接 Connection
- **Bot Token** — create a bot with [@BotFather](https://t.me/BotFather) and paste its token (`123456:ABC…`).
- **Channel ID** — `-100xxxxxxxxxx` (numeric) or `@channelusername` (public channels). **Add the bot to the
  channel as an administrator** with permission to post. To find a numeric id, forward a channel message
  to [@userinfobot](https://t.me/userinfobot).
- **Base domain** — used to build the "查看新贴 / 查看回复" buttons. Leave **blank** to auto-use the site's
  own URL. ⚠️ Telegram **rejects inline-button URLs that point at `localhost` / an internal IP** — so if
  your forum is reached at `localhost`/`127.0.0.1`/a LAN IP, set a real **public** domain here (e.g.
  `https://bbs.example.com`). When the domain isn't public the message is still sent, just **without** the button.
- **API base** (optional) — override `https://api.telegram.org` with a reverse-proxy/mirror reachable from
  your network. Use this if the server **cannot reach `api.telegram.org` directly** (e.g. Telegram is
  blocked/throttled on the server's network, as in mainland China).
- **Send retries** — attempts per message; rides through intermittent TLS/network drops.
- **Master switch**, push thread/reply toggles, link-preview toggle, scan interval, batch size.
- **Debug mode** — when on, payloads are written to `data/log/tgnotify.log` instead of being sent (handy
  for testing without spamming the channel).
- **Send test** button — sends a sample message to your channel to confirm the token/channel are correct.

### 🗂 板块 Forums
A checkbox tree of **all** your forums, grouped by category (**default: none selected → nothing is sent**).
Tick the forums whose new threads/replies should be forwarded.
Also here: **阅读权限上限 / Read-permission ceiling** — a thread is skipped when its `readperm` ≥ this value
(e.g. `10` → readperm ≥ 10 is never pushed). **`0` = no limit (push everything).** Default `1` = only fully
public content (readperm 0).

### ✂️ 消息规则 Message rules
Toggle each built-in cleanup step and see a **live preview**:

| Step | Effect |
| --- | --- |
| Quote | `[quote]…[/quote]` → 「…」 |
| URLs | external links → 🔗 (internal thread links keep their label text) |
| @ mentions | `@` → `@ ` (prevents accidental Telegram mentions) |
| Hidden | `[hide]…[/hide]` → 【隐藏内容】 (original text never leaks) |
| Attachments | `[attach]N[/attach]`, `[img]…[/img]` → 🖼️ |
| Strip BBCode | removes any remaining `[..]` tags |
| Collapse | multiple spaces/newlines → a single space |
| Truncate | cut to N characters (default 128) + `...` |

**Custom rules** (textarea): add your own, one `pattern => replacement` per line. The pattern is a full PCRE
with delimiters; lines starting with `#` are comments; invalid lines are skipped. Example:
```
# turn smilie codes into an emoji
/\{:[^}]+:\}/ => 😊
# collapse [code] blocks
/\[code\].*?\[\/code\]/is => 【代码】
```
You can also set the anonymous display name and the two button labels here.

### 📊 状态 Status
Shows the send cursor (last processed `pid`), how many posts are pending, last scan / last send time,
success & failure counts, and the last error — useful for confirming delivery and diagnosing problems.

## ▶️ How it works (and its limits)

Discuz X3.5 has no plugin hook in the post-submit path, so — without editing core — the plugin detects
new content out-of-band: on each full page render it runs a **throttled, lock-protected** scan that walks
a `forum_post.pid` cursor (new threads = first posts, replies = the rest), applies your filters and rules,
and POSTs to the Telegram Bot API. Because the poster is redirected to a normal page right after posting,
delivery is effectively immediate, and the cursor guarantees nothing is sent twice.

Limits to be aware of:
- If **no page is ever rendered** after a post (e.g. pure-API posting), it waits for the next page view.
- Posts **held for moderation and approved later** are not back-filled (the cursor has already passed them).
- Edits and deletions are not forwarded; images appear as 🖼️ (not re-uploaded).

## 🩺 Troubleshooting (Status tab shows the last error)

| Symptom (last error) | Cause & fix |
| --- | --- |
| `[400] … button URL '…localhost…' is invalid` | The base domain is empty/localhost. Set a **public** domain in Connection (or accept buttonless messages). |
| `[400] chat not found` / `[403] … not enough rights` | Wrong **Channel ID**, or the bot isn't a channel **admin** with post permission. |
| `[401] Unauthorized` | Wrong/expired **Bot Token**. |
| `curl#35 … unexpected eof` / `curl#28 timeout` / `HTTP 0` | The server **can't reach Telegram** (blocked/throttled network). Set an **API base** override to a reachable mirror. Messages auto-retry. |

Use **Connection → Send test** to validate token + channel quickly; the result shows the exact API response.

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 Uninstall** (drops `pre_tgnotify_state` and the settings), then delete
`source/plugin/tgnotify/`.

---
<sub>Developing in this repo? Shortcut install: `make enable-plugin id=tgnotify`.</sub>
