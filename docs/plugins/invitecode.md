# 🎟️ Invitation Code Generator (`invitecode`)

Admin tool to **bulk-generate invitation codes for Discuz's built-in invite registration**
(`pre_common_invite`). It does **not** add its own field — Discuz's own registration validates and
consumes the codes (one-time use, marked on a successful signup).

| | |
| --- | --- |
| 🆔 Identifier | `invitecode` |
| 🧰 Requires | Discuz! X3.5; registration in **invite-code** mode |
| 📁 Files | `source/plugin/invitecode/` (admin page only, no front-end hook) |
| 🗄️ Storage | built-in `pre_common_invite` (core table — created no new tables) |

## 📥 Install on your own Discuz! forum

1. **Copy the files** into your forum, same path:

   ```
   source/plugin/invitecode/   →   <your-forum>/source/plugin/invitecode/
   ```

2. **Install + enable** in **Admin CP (管理中心) → 应用 Apps → 插件 Plugins**:
   find `invitecode` → **安装 / Install** → **启用 / Enable**.
   *(Alternative: 导入 / Import `discuz_plugin_invitecode.xml`.)*

3. **Turn on invite-only registration** (required, otherwise codes aren't asked for):
   **Admin CP → 用户 Members / UCenter → 注册访问控制 (Registration)** → set 允许注册 to
   **邀请码注册 / Invite code**.

## ▶️ Use

1. Admin CP → **应用 Apps → 插件 Plugins → Invitation Code Generator → Codes**.
2. Enter **Count** (1–500) and optional **Expiry days** (`0` = never) → **Generate**.
3. Codes are written to the built-in `common_invite` table and listed (unused / used).
4. Give a code to a prospective member. On the signup page they enter it in the built-in
   **邀请码 / Invitation code** field.
5. On a **successful** registration Discuz marks the code used (`fuid` = new user) — single use.

## 🧾 Manage

- The list shows **unused / used**, who used each code, and when.
- **Delete** removes an *unused* code; used codes stay as a record.
- Codes are generic admin codes (`uid = 0`) → no inviter/friendship side effects.

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 / Uninstall**, then delete `source/plugin/invitecode/`.
Uninstalling does **not** touch `common_invite` (it's a core table). Switch registration back to
*open* via the Registration setting above if you no longer want invite-only.

---
<sub>Developing in this repo? You can shortcut install with `make enable-plugin id=invitecode`.</sub>
