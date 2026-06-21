# 💳 Buy Invite Code via Stripe (`buycode`)

Sell Discuz's **built-in** invitation codes online. A visitor opens a buy page, picks a quantity,
enters an email, pays with **Stripe**, and instantly gets a real `common_invite` code **on screen and by
email** — plus a one-click **register link that auto-fills the code**. Codes are identical to the
[`invitecode`](invitecode.md) plugin's, so Discuz's native invite registration accepts and consumes them.

| | |
| --- | --- |
| 🆔 Identifier | `buycode` |
| 🧰 Requires | Discuz! X3.5; PHP cURL; registration in **invite-code** mode (`regstatus=2`); a Stripe account |
| 📁 Files | `source/plugin/buycode/` (admin settings + 3 front-end endpoints, no templates) |
| 🗄️ Storage | core `pre_common_invite` (codes) + `pre_buycode_order` (Stripe orders) |
| 🌐 Pages | buy `plugin.php?id=buycode` · success `:return` · webhook `:notify` |

## 📥 Install on your own Discuz! forum

1. **Copy the files**, same path:
   ```
   source/plugin/buycode/   →   <your-forum>/source/plugin/buycode/
   ```
2. **Install + enable**: **Admin CP (管理中心) → 应用 Apps → 插件 Plugins** → `buycode` → **安装 / Install → 启用 / Enable**.
   *(Alternative: 导入 / Import `discuz_plugin_buycode.xml`.)*
3. **Turn on invite-code registration** (so codes are actually required):
   **Admin CP → 用户 / UCenter → 注册访问控制** → 允许注册 = **邀请码注册 / Invite code** (`regstatus=2`).

## ⚙️ Configure (Admin CP → Apps → Plugins → Buy Invite Code (Stripe) → Settings)

| Field | What to enter |
| --- | --- |
| **Enabled** | `Yes` to open the buy page |
| **Mode** | `Test` while developing, `Live` for production |
| **Test / Live secret key** | from Stripe → Developers → API keys (`sk_test_…` / `sk_live_…`) |
| **Test / Live webhook secret** | the signing secret of the webhook you create below (`whsec_…`) |
| **Unit amount** | price in the **smallest currency unit** (e.g. `500` = $5.00); **Currency** e.g. `usd` |
| **Product label** | shown on the buy/checkout page (e.g. `论坛邀请码`) |
| **Max qty per order** | upper bound on the quantity selector |
| **Code length** | default `6` (alphabet excludes look-alikes `I O 0 1`) |
| **Code expiry days** | `0` = never |
| **Post-payment redirect URL** | default `member.php?mod=register`; the code is auto-appended as `&invitecode=…` |

> 🔑 **Webhook is required for reliable delivery.** In **Stripe Dashboard → Developers → Webhooks → Add
> endpoint**, paste the **Webhook URL** shown on the settings page (it's auto-derived from the domain/port
> you're browsing — standard 80/443 ports are omitted), select event **`checkout.session.completed`**, then
> copy the endpoint's **Signing secret** (`whsec_…`) back into the matching mode field. The success page also
> self-fulfills as a fallback, but the webhook is the source of truth.

## ▶️ Use

1. Visitor opens **`plugin.php?id=buycode`** → picks **数量 (quantity)**, enters **邮箱 (email)** → **立即购买并支付**.
2. Redirected to **Stripe Checkout** → pays.
3. Back on the **success page**: the code(s) are shown, emailed, and a **立即注册 →** button opens the
   register page with the code **already filled in** (single-use; consumed on a successful signup).

## 🧪 Test locally with Cloudflare Tunnel (least effort)

Stripe must reach your webhook over HTTPS. The fastest way to expose `http://localhost:<port>` with **no
login and one command**:

```bash
brew install cloudflared                       # macOS (or see cloudflare docs for your OS)
cloudflared tunnel --url http://localhost:34728   # use your DZ_PORT (see .env)
```

It prints a public URL like `https://random-words.trycloudflare.com` (ephemeral, zero-config). Then:

1. In the plugin settings set **Mode = Test** and paste your **test** secret key.
2. In **Stripe Dashboard (test mode) → Webhooks → Add endpoint**, use
   `https://random-words.trycloudflare.com/plugin.php?id=buycode:notify`, event `checkout.session.completed`,
   and copy the **signing secret** into **Test webhook secret**.
3. Open the buy page **through the tunnel URL** (`https://random-words.trycloudflare.com/plugin.php?id=buycode`)
   so the success/cancel URLs are public, and pay with Stripe's test card **`4242 4242 4242 4242`**, any
   future expiry, any CVC.
4. Watch the order flip to **paid** in the settings' *Recent orders* list and the code arrive by email.

> 💡 The quick tunnel hostname changes each run. For a **stable** URL, set up a named tunnel once with
> `cloudflared tunnel login` (browser OAuth) → `cloudflared tunnel create <name>` →
> `cloudflared tunnel route dns <name> <subdomain>` → `cloudflared tunnel run <name>`. Re-paste the new
> URL + webhook secret whenever it changes.

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 / Uninstall**, then delete `source/plugin/buycode/`. This drops
`pre_buycode_order` and the settings but **never** touches `common_invite` (already-issued codes stay valid).

---
<sub>Developing in this repo? Shortcut install: `make enable-plugin id=buycode`. Pairs with [invitecode](invitecode.md) (free admin-generated codes).</sub>
