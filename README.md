# WP AI Hub Client

Universal AI WordPress plugin — thin client for [Multi-LLM API Gateway](https://github.com/VolkanSah/Multi-LLM-API-Gateway) and any compatible SSE hub.

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

Apache-2.0 — © Volkan Kücükbudak
