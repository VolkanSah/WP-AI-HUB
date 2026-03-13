<?php
/**
 * Plugin Name: WP AI Hub Client
 * Description: Universal AI client for Multi-LLM API Gateway and compatible SSE hubs.
 * Version:     0.1.0
 * Author:      Volkan Kücükbudak
 * License:     Apache-2.0
 * Text Domain: wp-aihub
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'AIHUB_VERSION',  '0.1.0' );
define( 'AIHUB_DIR',      plugin_dir_path( __FILE__ ) );
define( 'AIHUB_URL',      plugin_dir_url( __FILE__ ) );
define( 'AIHUB_SLUG',     'wp-aihub' );

// ─── Secure config from wp-config.php (preferred) ─────────────────────────────
// Add to wp-config.php:
//   define( 'AIHUB_HUB_URL', 'https://your-hub.hf.space' );
//   define( 'AIHUB_HUB_KEY', 'your-hub-token' );

// ─── Interface: every tool must implement this ─────────────────────────────────
interface AiHub_Tool_Interface {
    public function get_name():  string;   // unique slug e.g. 'chat'
    public function get_label(): string;   // human label e.g. 'Chat Widget'
    public function register():  void;     // hooks, shortcodes, etc.
}

// ─── Base Tool ─────────────────────────────────────────────────────────────────
abstract class AiHub_Base_Tool implements AiHub_Tool_Interface {

    /** Access the hub client from any tool */
    protected function client(): AiHub_Client {
        return AiHub_Client::instance();
    }

    /** Shared nonce check for AJAX handlers */
    protected function verify_nonce(): void {
        check_ajax_referer( 'aihub_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Login required.', 'wp-aihub' ) ], 401 );
        }
    }
}

// ─── Hub Client ───────────────────────────────────────────────────────────────
final class AiHub_Client {

    private static ?self $instance = null;
    private string $url;
    private string $key;

    private function __construct() {
        // wp-config.php takes priority — fallback to wp_options
        $this->url = defined( 'AIHUB_HUB_URL' )
            ? rtrim( AIHUB_HUB_URL, '/' )
            : rtrim( get_option( 'aihub_hub_url', '' ), '/' );

        $this->key = defined( 'AIHUB_HUB_KEY' )
            ? AIHUB_HUB_KEY
            : (string) get_option( 'aihub_hub_key', '' );
    }

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** GET / — uptime + status */
    public function health(): array {
        if ( empty( $this->url ) ) {
            return [ 'error' => 'Hub URL not configured.' ];
        }
        $r = wp_remote_get( $this->url . '/', [
            'headers' => $this->headers(),
            'timeout' => 10,
        ] );
        return $this->parse( $r );
    }

    /** POST /api → list_active_tools — providers + models + tools */
    public function fetch_tools(): array {
        return $this->call( 'list_active_tools', [] );
    }

    /** POST /api → any tool call */
    public function call( string $tool, array $params ): array {
        if ( empty( $this->url ) ) {
            return [ 'error' => 'Hub URL not configured.' ];
        }
        $r = wp_remote_post( $this->url . '/api', [
            'headers' => array_merge( $this->headers(), [ 'Content-Type' => 'application/json' ] ),
            'body'    => wp_json_encode( [ 'tool' => $tool, 'params' => $params ] ),
            'timeout' => 60,
        ] );
        return $this->parse( $r );
    }

    /** Shorthand: llm_complete */
    public function complete( string $prompt, string $provider = '', string $model = '', int $max_tokens = 1024 ): string {
        $result = $this->call( 'llm_complete', [
            'prompt'        => $prompt,
            'provider_name' => $provider,
            'model'         => $model,
            'max_tokens'    => $max_tokens,
        ] );
        return $result['result'] ?? $result['error'] ?? __( 'No response.', 'wp-aihub' );
    }

    public function is_configured(): bool {
        return ! empty( $this->url );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function headers(): array {
        $h = [];
        if ( ! empty( $this->key ) ) {
            $h['Authorization'] = 'Bearer ' . $this->key;
        }
        return $h;
    }

    private function parse( $response ): array {
        if ( is_wp_error( $response ) ) {
            // Never log the key — only error message
            return [ 'error' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( $code !== 200 ) {
            return [ 'error' => "HTTP {$code}" ];
        }
        return is_array( $data ) ? $data : [ 'error' => 'Invalid JSON response.' ];
    }
}

// ─── Plugin Bootstrap ─────────────────────────────────────────────────────────
final class WP_AiHub {

    private static ?self $instance = null;
    private array $tools = [];

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'plugins_loaded', [ $this, 'boot' ] );
    }

    public function boot(): void {
        $this->load_tools();
        $this->register_tools();
        add_action( 'admin_menu',              [ $this, 'admin_menu' ] );
        add_action( 'admin_init',              [ $this, 'admin_settings' ] );
        add_action( 'wp_enqueue_scripts',      [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_aihub_health',    [ $this, 'ajax_health' ] );
        add_action( 'wp_ajax_aihub_get_tools', [ $this, 'ajax_get_tools' ] );
    }

    /** Auto-load all tools from /tools/*.php */
    private function load_tools(): void {
        foreach ( glob( AIHUB_DIR . 'tools/*.php' ) as $file ) {
            require_once $file;
        }
    }

    /** Let each tool register its own hooks */
    private function register_tools(): void {
        // Collect all classes implementing AiHub_Tool_Interface
        foreach ( get_declared_classes() as $class ) {
            if (
                $class !== 'AiHub_Base_Tool' &&
                in_array( 'AiHub_Tool_Interface', class_implements( $class ), true )
            ) {
                $tool = new $class();
                $this->tools[ $tool->get_name() ] = $tool;
                $tool->register();
            }
        }
    }

    public function enqueue(): void {
        wp_enqueue_style(
            'aihub-client',
            AIHUB_URL . 'assets/css/aihub.css',
            [],
            AIHUB_VERSION
        );
        wp_enqueue_script(
            'aihub-client',
            AIHUB_URL . 'assets/js/aihub.js',
            [],
            AIHUB_VERSION,
            true
        );
        wp_localize_script( 'aihub-client', 'aihub', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'aihub_nonce' ),
            'i18n'     => [
                'send'        => __( 'Send', 'wp-aihub' ),
                'thinking'    => __( 'Thinking…', 'wp-aihub' ),
                'error'       => __( 'Error. Please try again.', 'wp-aihub' ),
                'login_req'   => __( 'Please log in to use the chat.', 'wp-aihub' ),
            ],
        ] );
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    public function admin_menu(): void {
        add_options_page(
            __( 'WP AI Hub', 'wp-aihub' ),
            __( 'AI Hub', 'wp-aihub' ),
            'manage_options',
            'wp-aihub',
            [ $this, 'settings_page' ]
        );
    }

    public function admin_settings(): void {
        register_setting( 'aihub_options', 'aihub_hub_url',      [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'aihub_options', 'aihub_hub_key',      [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'aihub_options', 'aihub_default_provider', [ 'sanitize_callback' => 'sanitize_key' ] );
        register_setting( 'aihub_options', 'aihub_default_model',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'aihub_options', 'aihub_max_tokens',   [
            'sanitize_callback' => fn( $v ) => max( 256, min( 32000, intval( $v ) ) ),
        ] );
    }

    public function settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $hub_in_config = defined( 'AIHUB_HUB_URL' ) && defined( 'AIHUB_HUB_KEY' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP AI Hub Settings', 'wp-aihub' ); ?></h1>

            <?php if ( $hub_in_config ) : ?>
                <div class="notice notice-success">
                    <p><?php esc_html_e( '✓ Hub URL and Key are set via wp-config.php (recommended).', 'wp-aihub' ); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e( 'For better security, define AIHUB_HUB_URL and AIHUB_HUB_KEY in wp-config.php instead of storing them here.', 'wp-aihub' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'aihub_options' ); ?>
                <table class="form-table">
                    <?php if ( ! $hub_in_config ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Hub URL', 'wp-aihub' ); ?></th>
                        <td>
                            <input type="url" name="aihub_hub_url" class="regular-text"
                                value="<?php echo esc_attr( get_option( 'aihub_hub_url', '' ) ); ?>"
                                placeholder="https://your-hub.hf.space">
                            <p class="description"><?php esc_html_e( 'Your Multi-LLM Hub URL. Ollama, HuggingFace, or any OpenAI-compatible SSE server.', 'wp-aihub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Hub Token', 'wp-aihub' ); ?></th>
                        <td>
                            <input type="password" name="aihub_hub_key" class="regular-text"
                                value="<?php echo esc_attr( get_option( 'aihub_hub_key', '' ) ); ?>"
                                placeholder="hf_...">
                            <p class="description"><?php esc_html_e( 'HuggingFace token or hub API key. Better: set AIHUB_HUB_KEY in wp-config.php', 'wp-aihub' ); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e( 'Default Provider', 'wp-aihub' ); ?></th>
                        <td>
                            <input type="text" name="aihub_default_provider" class="regular-text"
                                value="<?php echo esc_attr( get_option( 'aihub_default_provider', 'anthropic' ) ); ?>"
                                placeholder="anthropic">
                            <p class="description"><?php esc_html_e( 'Loaded dynamically from your hub after connecting.', 'wp-aihub' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Default Model', 'wp-aihub' ); ?></th>
                        <td>
                            <input type="text" name="aihub_default_model" class="regular-text"
                                value="<?php echo esc_attr( get_option( 'aihub_default_model', '' ) ); ?>"
                                placeholder="claude-haiku-4-5-20251001">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Max Tokens', 'wp-aihub' ); ?></th>
                        <td>
                            <input type="number" name="aihub_max_tokens" class="small-text"
                                value="<?php echo esc_attr( get_option( 'aihub_max_tokens', 1024 ) ); ?>"
                                min="256" max="32000">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Connection Test', 'wp-aihub' ); ?></h2>
            <button id="aihub-test-conn" class="button button-secondary">
                <?php esc_html_e( 'Test Connection', 'wp-aihub' ); ?>
            </button>
            <span id="aihub-conn-result" style="margin-left:12px;"></span>
            <script>
            document.getElementById('aihub-test-conn').addEventListener('click', function() {
                var el = document.getElementById('aihub-conn-result');
                el.textContent = '…';
                fetch(ajaxurl + '?action=aihub_health&nonce=<?php echo esc_js( wp_create_nonce( 'aihub_nonce' ) ); ?>')
                    .then(r => r.json())
                    .then(d => {
                        el.textContent = d.success
                            ? '✓ Connected — uptime: ' + (d.data.uptime_seconds ?? '?') + 's'
                            : '✗ ' + (d.data?.error ?? 'Failed');
                        el.style.color = d.success ? 'green' : 'red';
                    })
                    .catch(() => { el.textContent = '✗ Request failed'; el.style.color = 'red'; });
            });
            </script>
        </div>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function ajax_health(): void {
        check_ajax_referer( 'aihub_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'error' => 'Forbidden' ], 403 );
        }
        $result = AiHub_Client::instance()->health();
        isset( $result['error'] )
            ? wp_send_json_error( $result )
            : wp_send_json_success( $result );
    }

    public function ajax_get_tools(): void {
        check_ajax_referer( 'aihub_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'error' => 'Forbidden' ], 403 );
        }
        $result = AiHub_Client::instance()->fetch_tools();
        isset( $result['error'] )
            ? wp_send_json_error( $result )
            : wp_send_json_success( $result );
    }

    // ── Activation ────────────────────────────────────────────────────────────

    public function activate(): void {
        if ( false === get_option( 'aihub_max_tokens' ) ) {
            update_option( 'aihub_max_tokens', 1024 );
        }
        if ( false === get_option( 'aihub_default_provider' ) ) {
            update_option( 'aihub_default_provider', 'anthropic' );
        }
    }
}

// ── Go ────────────────────────────────────────────────────────────────────────
WP_AiHub::instance();
