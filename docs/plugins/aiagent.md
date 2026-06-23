# AI Database Assistant (`aiagent`)

Chat with an AI **inside the admin control panel** about your forum's database. Configure an
[OpenRouter](https://openrouter.ai) API key + a model (free models supported), then ask questions in
plain language — the assistant runs **read-only** queries to answer. Optionally let it **propose**
data changes that only run after you click **Approve**.

| | |
| --- | --- |
| 🆔 Identifier | `aiagent` |
| 🧰 Requires | Discuz! X3.5, PHP cURL, an OpenRouter API key |
| 📁 Files | `source/plugin/aiagent/` |
| 🔐 Access | **Founder only** (uid + groupid 1 + adminid 1) |
| 🗄️ Tables | `pre_aiagent_log` (audit trail) |

## ✨ What it does

- **Ask about your data** — "How many members registered this week?", "Which forum has the most
  posts?", "Show the 5 newest threads". The AI calls read-only tools (`list_tables`,
  `describe_table`, `run_select`) and answers from real rows.
- **Propose changes (opt-in)** — in *Allow writes* mode the AI can propose an `INSERT/UPDATE/DELETE`.
  It is **never executed** until you click **Approve & run**. You see the exact SQL and a "~N rows
  affected" preview first.
- **Audit log** — every read, write, and blocked attempt is recorded in `pre_aiagent_log` and shown
  on the **Activity log** tab.

## 📥 Install on your own Discuz! forum

1. Copy the plugin folder into your forum, keeping the same path:
   ```
   source/plugin/aiagent/   →   <your-forum>/source/plugin/aiagent/
   ```
2. **Admin CP (管理中心) → 应用 Apps → 插件 Plugins** → find **AI Database Assistant** → **安装 Install → 启用 Enable**.
   *(Alternative: 导入 Import `discuz_plugin_aiagent.xml`.)*
3. Open the plugin's **Settings** tab and paste your **OpenRouter API key**. Pick a model that
   supports **tool / function calling** (the defaults do).

## ▶️ Use

Open **Admin CP → Apps → Plugins → AI Database Assistant** — three tabs:

- **💬 Chat** — type a question and press Enter. Suggested prompts are shown on first load. Results
  render as tables; the AI's reasoning is concise Markdown.
- **⚙ Settings**
  - **Status** — enable/disable the assistant.
  - **OpenRouter API key** — stored in the database (plaintext, like other plugin secrets), founder
    only, never echoed back to the browser.
  - **Model** — a live **dropdown of your account's free models**, fetched from OpenRouter's `/models`
    endpoint (🔧 marks the ones that support **tool calling**, recommended — needed for DB Q&A; models
    without it still work through a one-click *Run SQL* fallback). Pick one or type any model id in the
    box. Hit **↻ Refresh** to reload the list. Default: `meta-llama/llama-3.3-70b-instruct:free`.
  - **Write mode** — `🔒 Read-only` (default) or `✏️ Allow writes — with manual Approve`.
  - **Max rows per query** — caps how many rows a SELECT returns to the AI (token control).
- **🧾 Activity log** — newest-first list of every database action, with the SQL and row counts.

### How writes are gated

```
AI → propose_write(sql, rationale)   ── never runs ──►  shown as an Approve card
You click "Approve & run"            ── re-validated server-side ──►  executes, logs, reports rows
```

DDL (`DROP/ALTER/TRUNCATE/CREATE/RENAME/GRANT`) is always rejected, `UPDATE`/`DELETE` must include a
`WHERE` clause, and only one statement per call is allowed.

## 🏗️ How it works

- The chat UI lives in `admincp.inc.php` (admin module, type 3). It POSTs to the JSON endpoint
  `plugin.php?id=aiagent:chat` (`chat.inc.php`) — admin.php can't return clean JSON because it wraps
  output in admin chrome, so the agent loop runs over plugin.php instead.
- `chat.inc.php` gates every request: **founder-only** + a **plugin-context formhash** (note:
  `formhash()` is salted differently inside the admin CP, so the page emits the un-salted token via
  `aiagent_plugin_formhash()`) + same-host referer + POST.
- `function_aiagent.php` holds the OpenRouter cURL call, the tool specs/executors, the SQL guardrails
  (`aiagent_validate_select` / `aiagent_validate_write`), and the audit logger.
- The agentic loop (max 6 round trips/turn) lets the model call read tools, which execute immediately;
  `propose_write` only records a proposal for your approval.

## ⚠️ Security notes

- **Founder-only.** Anyone else (including other admins) gets a 403 from the endpoint.
- The API key is stored in plaintext in `pre_common_setting` (same trust model as other plugin
  secrets — admin access already implies full DB access).
- Read results are **row- and byte-capped** to control token usage; large result sets are truncated.
- Treat the AI as a powerful tool: review proposed writes before approving. Everything is logged.

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 Uninstall** (drops `pre_aiagent_log`), then delete
`source/plugin/aiagent/`. The stored settings row is left in place so reinstalling restores your
configuration.

---
<sub>Developing in this repo? Shortcut install: `make enable-plugin id=aiagent`.</sub>
