# 发帖头像限制 (`avatarpost`)

未上传头像的用户禁止发帖和回复（主题、回复、快速回复）。可自定义提示文字、开关，以及是否豁免管理组。

| | |
| --- | --- |
| 🆔 Identifier | `avatarpost` |
| 🧰 Requires | Discuz! X3.5 |
| 📁 Files | `source/plugin/avatarpost/` |

## 📥 Install on your own Discuz! forum

1. Copy the plugin folder into your forum, keeping the same path:
   ```
   source/plugin/avatarpost/   →   <your-forum>/source/plugin/avatarpost/
   ```
   (also copy `template/default/plugin/avatarpost/` if this plugin ships templates)
2. **Admin CP (管理中心) → 应用 Apps → 插件 Plugins** → find `avatarpost` → **安装 Install → 启用 Enable**.
   *(Alternative: 导入 Import `discuz_plugin_avatarpost.xml`.)*

## ▶️ How it works / 工作原理

When a member who has **not uploaded a custom avatar** tries to start a new thread
or post a reply, the action is stopped and they see a Simplified-Chinese notice with
a 「立即设置头像」 button linking to **个人中心 → 头像** (`home.php?mod=spacecp&ac=avatar`).

- **What's gated:** new threads (`action=newthread`) and replies (`action=reply`),
  including the full reply form, the floating reply box, and quick-reply *submit*.
- **What's not gated:** editing/deleting your own existing posts, and everything
  outside the post flow.
- **Detection:** the member's `avatarstatus` flag (the same signal Discuz core uses) —
  no extra database query.
- **Where it runs:** a `post_avatarcheck()` hook in `plugin_avatarpost_forum`, fired by
  `runhooks()` **before** `forum_post.php` loads, so a blocked post is never written.

Guests are left to Discuz's own login gate; they are not affected by this plugin.

## ⚙️ Settings / 设置

**Admin CP → 应用 Apps → 插件 Plugins → 发帖头像限制 → 设置 Settings**

| Setting | 说明 | Default |
| --- | --- | --- |
| 总开关 Enable | 启用/关闭整个限制 | 开启 On |
| 管理组豁免 Exempt admins | 管理员/版主等管理组（`adminid>0`）不受限制，避免把自己锁在外面 | 开启 On |
| 提示文字 Message | 用户未设置头像时显示的提示（按钮会自动附加） | `您还没有设置头像，请先上传头像后再发帖或回复。` |

> ℹ️ Note: gating covers the web post flow (`forum.php?mod=post`). Third-party mobile
> apps that post through a separate API path are not covered.

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 Uninstall**, then delete `source/plugin/avatarpost/`.

---
<sub>Developing in this repo? Shortcut install: `make enable-plugin id=avatarpost`.</sub>
