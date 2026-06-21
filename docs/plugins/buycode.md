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
| 🌐 Pages | **Live:** buy `plugin.php?id=buycode` · webhook `:notify` — **Test:** add `&env=test` to each |

> 🔀 **Test & live run independently and at the same time.** Each environment has its own enable
> toggle, keys, webhook, and base URL, selected per request by `?env=`. The clean URLs are **live**;
> append **`&env=test`** for the **test** environment. So you can keep a published live store running
> while you test on a dev tunnel — they never interfere.

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
| **🧪 Test enabled / 🚀 Live enabled** | independent `Yes/No` switches — turn either or both on |
| **Test / Live secret key** | from Stripe → Developers → API keys (`sk_test_…` / `sk_live_…`) |
| **Test / Live webhook secret** | the signing secret (`whsec_…`) — **auto-filled** by Auto-register below |
| **Unit amount** | price in the **smallest currency unit** (e.g. `500` = $5.00); **Currency** e.g. `usd` |
| **Product label** | shown on the buy/checkout page (e.g. `论坛邀请码`) |
| **Max qty per order** | upper bound on the quantity selector |
| **Code length** | default `6` (alphabet excludes look-alikes `I O 0 1`) |
| **Code expiry days** | `0` = never |
| **Post-payment redirect URL** | default `member.php?mod=register`; the code is auto-appended as `&invitecode=…` |
| **Domain** *(per env, in each Auto-register box)* | the public base for that environment — **live** = your real site domain (or blank to auto-detect); **test** = your Cloudflare Tunnel domain `https://xxx.trycloudflare.com`. Saved as that env's base URL so its webhook, checkout redirect, and register link all use a host **Stripe can reach**. Bare domain assumed `https://`. |

> 🔑 **Webhook — two ways to set it up** (it's the reliable delivery path; the success page also
> self-fulfills as a fallback):
>
> - **Easiest — one click, no Dashboard:** there's a separate **Auto-register** box per environment
>   (🧪 TEST and 🚀 LIVE). Save that env's secret key, type its public **Domain**, and click
>   **「自动注册 / Auto-register webhook」**. The plugin calls Stripe's API to create the endpoint at the
>   env-specific URL (test → `…:notify&env=test`, live → `…:notify`), **saves the signing secret
>   automatically**, and **saves the domain as that env's base URL** — so its checkout redirect and
>   register link use it too while you keep browsing on `localhost`. Stripe requires HTTPS + a publicly
>   reachable URL. Re-click anytime the domain changes — it updates the same endpoint.
> - **Manual:** in **Stripe Dashboard → Developers → Webhooks → Add endpoint** (in the matching test/live
>   dashboard mode), paste that env's **Webhook URL** from the *Integration URLs* panel, select event
>   **`checkout.session.completed`**, and copy the **Signing secret** (`whsec_…`) into that env's field.

## ▶️ Use

1. Visitor opens **`plugin.php?id=buycode`** (live) → picks **数量 (quantity)**, enters **邮箱 (email)** → **立即购买并支付**.
2. Redirected to **Stripe Checkout** → pays.
3. Back on the **success page**: the code(s) are shown, emailed, and a **立即注册 →** button opens the
   register page with the code **already filled in** (single-use; consumed on a successful signup).

*Testing the dev environment instead?* Use **`plugin.php?id=buycode&env=test`** — it charges with the
test keys and shows a **测试模式 TEST** badge, completely separate from the live store.

## 🧪 Test locally with Cloudflare Tunnel (least effort)

Stripe must reach your webhook over HTTPS. The fastest way to expose `http://localhost:<port>` with **no
login and one command**:

```bash
brew install cloudflared                       # macOS (or see cloudflare docs for your OS)
cloudflared tunnel --url http://localhost:34728   # use your DZ_PORT (see .env)
```

It prints a public URL like `https://random-words.trycloudflare.com` (ephemeral, zero-config). Then:

1. In the buycode settings, turn **🧪 Test enabled = Yes**, paste your **test** secret key, and **Save**.
2. In the **🧪 TEST Auto-register** box, type the tunnel URL into **Domain**
   (`https://random-words.trycloudflare.com`) and click **「自动注册 / Auto-register webhook」**. It creates
   the test endpoint at `…/plugin.php?id=buycode:notify&env=test`, saves the signing secret, and saves the
   domain as the test base URL — so the test flow uses the tunnel even while you browse via `localhost`.
   *(Or add it manually in the Dashboard's **test mode**, event `checkout.session.completed`.)*
3. Open the **test** buy page (`/plugin.php?id=buycode&env=test`), pay with Stripe's test card
   **`4242 4242 4242 4242`**, any future expiry, any CVC.
4. Watch the order flip to **paid** in the settings' *Recent orders* list (Env = `test`) and the code arrive
   by email. Your **live** store (if enabled) keeps running untouched the whole time.

> 💡 The quick tunnel hostname changes each run. For a **stable** URL, set up a named tunnel once with
> `cloudflared tunnel login` (browser OAuth) → `cloudflared tunnel create <name>` →
> `cloudflared tunnel route dns <name> <subdomain>` → `cloudflared tunnel run <name>`. Re-paste the new
> URL + webhook secret whenever it changes.

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 / Uninstall**, then delete `source/plugin/buycode/`. This drops
`pre_buycode_order` and the settings but **never** touches `common_invite` (already-issued codes stay valid).

---
<sub>Developing in this repo? Shortcut install: `make enable-plugin id=buycode`. Pairs with [invitecode](invitecode.md) (free admin-generated codes).</sub>
