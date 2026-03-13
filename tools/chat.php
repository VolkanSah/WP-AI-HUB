<?php
/**
 * Tool: Chat
 * Registers [aihub_chat] shortcode + floating widget.
 * Drop this file in /tools/ to activate — remove to deactivate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AiHub_Tool_Chat extends AiHub_Base_Tool {

    public function get_name():  string { return 'chat'; }
    public function get_label(): string { return __( 'Chat Widget', 'wp-aihub' ); }

    public function register(): void {
        add_shortcode( 'aihub_chat',             [ $this, 'shortcode' ] );
        add_action(    'wp_footer',              [ $this, 'floating_widget' ] );
        add_action(    'wp_ajax_aihub_chat',     [ $this, 'ajax_chat' ] );
    }

    // ── Shortcode ─────────────────────────────────────────────────────────────

    public function shortcode( array $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<p class="aihub-login-notice">' . esc_html__( 'Please log in to use the AI chat.', 'wp-aihub' ) . '</p>';
        }
        return $this->render_chat( 'shortcode' );
    }

    // ── Floating Widget ───────────────────────────────────────────────────────

    public function floating_widget(): void {
        if ( ! is_user_logged_in() ) return;
        echo $this->render_chat( 'widget' ); // phpcs:ignore
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function ajax_chat(): void {
        $this->verify_nonce();

        $message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $provider = sanitize_key( $_POST['provider'] ?? '' );
        $model    = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );

        if ( empty( $message ) ) {
            wp_send_json_error( [ 'message' => __( 'Message cannot be empty.', 'wp-aihub' ) ] );
        }

        if ( ! $this->client()->is_configured() ) {
            wp_send_json_error( [ 'message' => __( 'AI Hub is not configured.', 'wp-aihub' ) ] );
        }

        $max_tokens = intval( get_option( 'aihub_max_tokens', 1024 ) );
        $response   = $this->client()->complete( $message, $provider, $model, $max_tokens );

        wp_send_json_success( [ 'response' => $response ] );
    }

    // ── HTML ──────────────────────────────────────────────────────────────────

    private function render_chat( string $mode ): string {
        $is_widget  = $mode === 'widget';
        $wrapper_id = $is_widget ? 'aihub-widget' : 'aihub-shortcode';
        $provider   = esc_attr( get_option( 'aihub_default_provider', 'anthropic' ) );
        $model      = esc_attr( get_option( 'aihub_default_model', '' ) );

        ob_start();
        ?>
        <?php if ( $is_widget ) : ?>
        <div id="aihub-widget-toggle" class="aihub-widget-toggle" aria-label="<?php esc_attr_e( 'Open AI Chat', 'wp-aihub' ); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                <path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.38 5.06L2 22l4.94-1.38A9.953 9.953 0 0 0 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2z"/>
            </svg>
        </div>
        <?php endif; ?>

        <div id="<?php echo esc_attr( $wrapper_id ); ?>" class="aihub-chat <?php echo $is_widget ? 'aihub-chat--widget aihub-hidden' : 'aihub-chat--embedded'; ?>"
             data-provider="<?php echo $provider; ?>"
             data-model="<?php echo $model; ?>">

            <div class="aihub-chat__header">
                <span class="aihub-chat__title"><?php esc_html_e( 'AI Assistant', 'wp-aihub' ); ?></span>
                <?php if ( $is_widget ) : ?>
                <button class="aihub-chat__close" aria-label="<?php esc_attr_e( 'Close', 'wp-aihub' ); ?>">&times;</button>
                <?php endif; ?>
            </div>

            <div class="aihub-chat__messages" id="<?php echo esc_attr( $wrapper_id ); ?>-messages"></div>

            <div class="aihub-chat__input-area">
                <textarea
                    class="aihub-chat__input"
                    id="<?php echo esc_attr( $wrapper_id ); ?>-input"
                    placeholder="<?php esc_attr_e( 'Type a message…', 'wp-aihub' ); ?>"
                    rows="2"
                    aria-label="<?php esc_attr_e( 'Message input', 'wp-aihub' ); ?>"
                ></textarea>
                <button class="aihub-chat__send" id="<?php echo esc_attr( $wrapper_id ); ?>-send"
                        aria-label="<?php esc_attr_e( 'Send message', 'wp-aihub' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
