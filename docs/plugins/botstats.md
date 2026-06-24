# 机器人流量统计 (`botstats`)

按小时统计 Cloudflare 验证机器人各分类（含「非机器人 / 真人」）的**访问次数**，后台以走线图 / 饼图展示，可选时间范围与分类。**不记录任何 IP**，只保留「整点小时 × 分类」的计数。

| | |
| --- | --- |
| 🆔 Identifier | `botstats` |
| 🧰 Requires | Discuz! X3.5 + Cloudflare（代理流量）|
| 📁 Files | `source/plugin/botstats/` |
| 🗄️ 数据表 | `pre_botstats_hourly(hourts, category, hits)` |

## 工作原理

1. 前端钩子 `global_footer` 在每个页面渲染时读取请求头 `X-Verified-Bot-Category`（由 Cloudflare 注入），对「当前整点小时 + 该分类」的访问次数 +1。
2. 按**访问次数**（请求计数）而非按 IP 去重——机器人常以同一 IP 代理大量请求，按次数更能反映其活跃度，也无需记录任何 IP。
3. 请求头为空 = 普通访客，记为 `__none__`（图表里显示「非机器人（真人）」）。
4. 分类原样存储（不写死白名单），Cloudflare 之后新增的分类也会被自动收录。
5. 后台「统计面板」把数据按所选时间范围聚合后用 ECharts 画图（ECharts 已随 Discuz 内置，无需联网 CDN）。

## 📥 安装

1. 把插件目录复制到你的论坛，保持同样路径：
   ```
   source/plugin/botstats/   →   <your-forum>/source/plugin/botstats/
   ```
2. **管理中心 → 应用 → 插件** → 找到 `botstats` → **安装 → 启用**。
   *（或：导入 `discuz_plugin_botstats.xml`。）*
3. 安装时若表为空，会自动灌入约 30 天的**示例数据**，便于立刻预览图表；可在面板底部「清空全部数据」清掉。

> 本仓库内开发可直接：`make enable-plugin id=botstats`，再 `make seed` 固化到种子。

## ☁️ 配置 Cloudflare（关键）

要让 PHP 读到分类，必须用「**请求**头改写规则」把 `cf.verified_bot_category` 注入到**转发给源站**的请求里。

> ⚠️ 注意方向：用 **Modify Request Header（修改请求头）**，不要用 **Response Header（响应头）**。
> 响应头只改返回给浏览器的内容，源站收不到，统计会一直为空。

1. Cloudflare 控制台 → 选择域名。
2. **Rules → Transform Rules → Modify Request Header**。
3. **Create rule**，命名如 `Send verified bot category to origin`。
4. **When incoming requests match**：`All incoming requests`。
5. **Then → Set dynamic**：
   - Header name：`X-Verified-Bot-Category`
   - Value（表达式）：`cf.verified_bot_category`
6. **Deploy**。

部署后到面板顶部「**接入自检**」核对：机器人访问会显示对应分类；你自己用浏览器访问通常为空（记为非机器人），属正常。

注意事项：
- 命中 Cloudflare 缓存、未回源的请求不会被 PHP 统计（属正常，统计为尽力采样而非计费级精确）。
- 直连源站（绕过 CF）的访客可伪造该头；生产环境建议在面板「设置」里开启**仅统计经 Cloudflare 的请求**（依据 `CF-Ray` 头），更强可在源站防火墙限制只允许 Cloudflare IP 段。

## ▶️ 使用

进入 **管理中心 → 应用 → 插件 → 机器人流量统计 → 统计面板**：

- **时间范围**：近 24 小时 / 7 天 / 30 天 / 90 天 / 自定义（选起止日期）。跨度 ≤ 3 天按「小时」聚合，更长按「天」聚合。
- **图表类型**：📈 走线图（各分类随时间的访问次数）/ 🥧 饼图（区间内各分类占比）。
- **分类多选**：勾选 / 取消要展示的分类（含未知的新分类）。想在饼图里看清机器人占比时，取消「非机器人（真人）」即可（真人量通常远大于机器人）。
- **设置**：总开关、排除管理员浏览、仅统计经 Cloudflare 的请求。
- **数据管理**：随时「插入示例数据（最近 30 天）」或「清空全部数据」。

Cloudflare 验证机器人分类（共 17 个）的含义见
[Cloudflare 文档](https://developers.cloudflare.com/bots/concepts/bot/verified-bots/#categories)。

## 🗑️ 卸载

管理中心 → 应用 → 插件 → **卸载**（会删除 `pre_botstats_hourly` 表与本插件设置），然后删除 `source/plugin/botstats/`。

---
<sub>开发快捷方式：`make enable-plugin id=botstats`，随后 `make list-plugins` 刷新 README 列表。</sub>
