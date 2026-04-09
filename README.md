# WP AI Hub (Client)
##### Plugin crafted with AI as test (Idea and fixes by human)

Universal AI WordPress plugin — thin client for [Universal AI Hub](https://github.com/VolkanSah/Universal-AI-Hub) and any compatible (REST) hub.



> Built with the help of Claude (Anthropic) — NO AI FOR WEAPONS! — this started as an interface project for new AI model testing, combining three old handcrafted WordPress plugins, tested with custom hardening via a forked WP-Autoplugin. The AI-generated attempt produced a generated 15-file, ~5800-line trash-monster that didn't even activate. So the test failed, again AI cant code! — not because of the idea, but because neither Gemini nor Claude initially understood what the hub actually is: not an MCP prompt collection server, not a `.claude` config thing — just a geeky self-built Multi-LLM hub with its own features and architecture. Once that was clear, we rebuilt from scratch into a clean ~1000-line single-purpose plugin. Sometimes AI helps you write code. Sometimes it helps you throw away code that other AIs wrote. 😄
>
> Maybe it's useful for your WordPress site too — cool security features, easy tool adding, no bloat.

---

## Best Use Case

Almost everyone has a WordPress site — so why not use it properly? Instead of installing dozens of limited AI plugins, just build your own wrapper or use mine, and connect free AI models to work with WordPress and your community.

Yes, "MCP server" makes my hair stand on end too — but what this plugin was actually built for is a private [Universal AI Hub](https://github.com/VolkanSah/Universal-AI-Hub): a production-grade universal AI wrapper over streamable http + Quart (and many more) with sandboxed tool support, Guardian pattern, and a solid PyFundaments foundation. Pick the description that fits your use case:

- Multi-LLM API Gateway with MCP interface
- Universal MCP Hub (Sandboxed)
- Universal AI Wrapper over Quart
- Or just  an (real) AI HUB

They're all correct. What matters: one hub, all your models, LLM fallback chain built-in — WordPress just talks to it via this thin client. Claude down? Gemini answers. Zero code changes. Zero Docker chaos.

Perfect for working locally on WordPress, TYPO3, or your own code — without running dozens of containers. Access it via shell/CLI or any SSE client like this plugin.

---


> [!IMPORTANT]
> This project is under active development — always use the latest release from [Codey Lab Version ](https://github.com/Codey-LAB/WP-AI-HUB) *( i mean; more stable builds land here first)*.
> This repo ([DEV-STATUS](https://github.com/VolkanSah/WP-AI-HUB)) is where the chaos happens. 🔬 a ⭐ on the repos will be cool 😙


## Architecture

```
wp-aihub.php          → Bootstrap, AiHub_Security, AiHub_Client, AiHub_Base_Tool, WP_AiHub
tools/
  chat.php            → [aihub_chat] shortcode + floating widget
  comment-reply.php   → AI Reply button in WP admin comments
assets/
  js/aihub.js         → Vanilla JS client (no jQuery, no frameworks)
  css/aihub.css       → Scoped styles + CSS custom properties
```

**Drop a `.php` file into `/tools/` → it auto-loads and registers itself. No config needed.**

The plugin is intentionally thin — no provider logic, no API keys for LLMs, no model management. All of that lives in your hub. This plugin just sends requests and renders responses.

## Supported Hubs

Any HUB server exposing:
- `GET /` → health check
- `POST /api` with `{"tool": "...", "params": {...}}` → tool call

Works with: [Universal AI Hub](https://github.com/VolkanSah/Universal-AI-Hub), Ollama, LM Studio, HuggingFace Spaces, any OpenAI-compatible server.

The hub handles: provider abstraction, fallback chain, rate limiting, sandboxed tools, model config.
The plugin handles: WordPress UI, AJAX, nonce security, output sanitization.

## Installation

1. Upload to `/wp-content/plugins/wp-aihub/`
2. Activate in WordPress
3. Set up your hub (see [Universal AI Hub](https://github.com/VolkanSah/Universal-AI-Hub)

## Configuration

**Recommended — wp-config.php:**
```php
define( 'AIHUB_HUB_URL', 'https://your-hub.hf.space' );
define( 'AIHUB_HUB_KEY', 'hf_...' );
define( 'AIHUB_DEFAULT_PROVIDER', 'anthropic' );
define( 'AIHUB_DEFAULT_MODEL', 'claude-haiku-4-5-20251001' );
define( 'AIHUB_MAX_TOKENS', 1024 );
```

Keys never leave your server — they're only used for the hub connection, never exposed to frontend JS.

**Alternative — Settings → AI Hub**
For non-developers only. Keys stored in wp_options. Use wp-config.php whenever possible.

## Usage

**Chat Widget** — appears on every page (bottom right, logged-in users only)

**Embedded Chat:**
```
[aihub_chat]
```

**Comment Reply** — hover over any comment in WP admin → click "AI Reply"

## Adding Tools

Create `/tools/my-tool.php`:
```php
class AiHub_Tool_MyTool extends AiHub_Base_Tool {
    public function get_name():  string { return 'my-tool'; }
    public function get_label(): string { return 'My Tool'; }

    public function register(): void {
        add_shortcode('my_shortcode', [$this, 'render']);
        add_action('wp_ajax_my_action', [$this, 'ajax_handler']);
    }

    public function render(): string {
        $response = $this->client()->complete('Hello!', 'anthropic', '');
        return esc_html($response);
    }

    public function ajax_handler(): void {
        $this->verify_nonce();
        // your logic
        wp_send_json_success(['result' => '...']);
    }
}
```

That's it — no registration needed. The bootstrap scans `/tools/*.php` on every load and auto-registers any class implementing `AiHub_Tool_Interface`.

## Theming

Override CSS custom properties:
```css
:root {
    --aihub-primary:    #your-color;
    --aihub-bg:         #your-bg;
    --aihub-radius:     8px;
    --aihub-chat-width: 400px;
}
```

## Security

Every request goes through `AiHub_Security::check()` — both **input** (before sending to hub) and **output** (before returning to WordPress). This is a WordPress-side pre-filter inspired by [PoisonIvory](https://github.com/VolkanSah/PoisonIvory), adapted from Python PCRE to PHP. Your hub runs its own second layer on top.

Patterns cover:
- SQL injection, XSS, path traversal
- Command & code injection (`eval`, `exec`, `shell_exec` …)
- SSRF & cloud metadata endpoints (AWS, GCP, Azure)
- LLM prompt injection (`ignore previous instructions`, jailbreaks, DAN …)
- API key exfiltration attempts
- Container escape paths (`/proc/self/environ`, `docker.sock`)
- Crypto seed phrases & private keys

Security-first means occasional false positives. That's fine — anyone asking a WordPress chat widget about `private key encryption` can open another tab.

Additional hardening:
- Hub URL and Key never exposed to frontend JS
- All AJAX endpoints protected via nonce + `is_user_logged_in()`
- Comment Reply requires `moderate_comments` capability
- User input sanitized via `sanitize_textarea_field()` before sending to hub
- Hub responses rendered via Markdown parser (`escHtml` first) — no raw HTML injection

## Roadmap

Tools planned for `/tools/`:
- `post-generator.php`  → generate WP draft posts from prompt
- `seo-helper.php`      → meta description + title suggestions
- `admin-test.php`      → connection test in WP admin (optional)
- `translation.php`     → auto-translate post content

Hub Modes (future):
- Direct API fallback if no hub configured
- Per-tool provider/model override via shortcode attrs:
  `[aihub_chat provider="gemini" model="gemini-2.5-flash"]`

## License
This project is dual licensed under: Apache-2.0 [LICENSE] + ESOL [ESOL]-1.1 —
> © Volkan Kücükbudak — for a more secure internet.

##### Note
This plugin isn't perfect yet and should be considered a test for AI programming and human interaction. If you like it, I'd love to hear your feedback. I'm not sure yet if I'll use it for WordPress, but I know what's needed on the web :P Here's an AI code boilerplate for AI orchestrated by a wannabe professor :D

> Special thanks to Anthropic's Claude AI — viva la revolution! 🕊️
