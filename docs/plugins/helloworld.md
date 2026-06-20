# 👋 Hello World (`helloworld`)

Minimal example plugin — appends a small banner to the **footer of every page**. A reference for the
hook-based plugin shape.

| | |
| --- | --- |
| 🆔 Identifier | `helloworld` |
| 🧰 Requires | Discuz! X3.5 (PHP 7.x/8.x) |
| 📁 Files | `source/plugin/helloworld/` (no templates) |

## 📥 Install on your own Discuz! forum

1. **Copy the files.** Put the plugin folder into your forum, keeping the same path:

   ```
   source/plugin/helloworld/   →   <your-forum>/source/plugin/helloworld/
   ```
   (Upload via FTP/SFTP, or `scp`/`rsync`. Keep the folder name = `helloworld`.)

2. **Install in the dashboard.** Log in to **Admin CP (管理中心) → 应用 Apps → 插件 Plugins**.
   `helloworld` appears under *uninstalled plugins* → click **安装 / Install** (runs its `install.php`).

   *Alternative:* on the Plugins page use **导入 / Import** and upload
   `source/plugin/helloworld/discuz_plugin_helloworld.xml`.

3. **Enable it.** Toggle the plugin **启用 / Enable**.

## ▶️ Use

Open any forum page → you'll see **`[Hello World] plugin active`** centered in the footer.

## 🛠️ Customize

Edit the hook method, then Admin CP → **Tools → Update cache** if you change templates:

```php
// source/plugin/helloworld/helloworld.class.php
public function global_footer() {
    return '<div style="text-align:center">your text here</div>';
}
```

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 / Uninstall**, then delete `source/plugin/helloworld/`.

---
<sub>Developing in this repo? You can shortcut install with `make enable-plugin id=helloworld`.</sub>
