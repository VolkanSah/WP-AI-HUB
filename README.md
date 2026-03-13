# WP AI Hub Client
##### Plugin crafted with AI as test (Idea and fixes by human)

Universal AI WordPress plugin — thin client for [Multi-LLM API Gateway](https://github.com/VolkanSah/Multi-LLM-API-Gateway) and any compatible SSE hub.

> Built with the help of Claude (Anthropic) — NO AI FOR WEAPONS! — this started as an interface project for new AI model testing, combining three old handcrafted WordPress plugins, tested with custom hardening via a forked WP-Autoplugin. The AI-generated attempt produced a generated 15-file, ~5800-line monster that didn't even activate. So the test failed — not because of the idea, but because neither Gemini nor Claude initially understood what the hub actually is: not an MCP prompt collection server, not a `.claude` config thing — just a geeky self-built Multi-LLM hub with its own features and architecture. Once that was clear, we rebuilt from scratch into a clean ~1000-line single-purpose plugin. Sometimes AI helps you write code. Sometimes it helps you throw away code that other AIs wrote. 😄
>
> Maybe it's useful for your WordPress site too — cool security features, easy tool adding, no bloat.

---

## Architecture

```
wp-aihub.php          → Bootstrap, AiHub_Client, AiHub_Base_Tool, WP_AiHub
tools/
  chat.php            → [aihub_chat] shortcode + floating widget
  comment-reply.php   → AI Reply button in WP admin comments
assets/
  js/aihub.js         → Vanilla JS client (no jQuery, no frameworks)
  css/aihub.css       → Scoped styles + CSS custom properties
```

**Drop a `.php` file into `/tools/` → it auto-loads and registers itself. No config needed.**

## Supported Hubs

Any SSE server exposing:
- `GET /` → health check
- `POST /api` with `{"tool": "...", "params": {...}}` → tool call

Works with: [Multi-LLM API Gateway](https://github.com/VolkanSah/Multi-LLM-API-Gateway), Ollama, LM Studio, HuggingFace Spaces, any OpenAI-compatible server.

## Installation

1. Upload to `/wp-content/plugins/wp-aihub/`
2. Activate in WordPress

## Configuration

**Recommended — wp-config.php:**
```php
define( 'AIHUB_HUB_URL', 'https://your-hub.hf.space' );
define( 'AIHUB_HUB_KEY', 'hf_...' );
define( 'AIHUB_DEFAULT_PROVIDER', 'anthropic' );
define( 'AIHUB_DEFAULT_MODEL', 'claude-haiku-4-5-20251001' );
define( 'AIHUB_MAX_TOKENS', 1024 );
```

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

That's it — no registration needed.

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

Every request goes through `AiHub_Security::check()` — both **input** (before sending to hub) and **output** (before returning to WordPress). Patterns cover:

- SQL injection, XSS, path traversal
- Command & code injection (`eval`, `exec`, `shell_exec` …)
- SSRF & cloud metadata endpoints
- LLM prompt injection (`ignore previous instructions`, jailbreaks …)
- API key exfiltration attempts
- Container escape paths (`/proc/self/environ`, `docker.sock`)
- Crypto seed phrases & private keys

Pattern design inspired by [PoisonIvory](https://github.com/VolkanSah/PoisonIvory) — adapted from Python PCRE to PHP. The hub runs its own second layer on top.

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
This project is dual licensed under : Apache-2.0 [LICENSE] + ESOL[ESOL]-1.1 —
> by © Volkan Kücükbudak for a more secure internet.
