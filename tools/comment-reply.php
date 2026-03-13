<?php
/**
 * Tool: Comment Reply
 * Adds "Suggest AI Reply" button in WP admin comment list.
 * Drop this file in /tools/ to activate — remove to deactivate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AiHub_Tool_Comment_Reply extends AiHub_Base_Tool {

    public function get_name():  string { return 'comment-reply'; }
    public function get_label(): string { return __( 'AI Comment Reply', 'wp-aihub' ); }

    public function register(): void {
        add_filter( 'comment_row_actions',                [ $this, 'add_action_link' ], 10, 2 );
        add_action( 'admin_footer-edit-comments.php',     [ $this, 'admin_script' ] );
        add_action( 'wp_ajax_aihub_suggest_reply',        [ $this, 'ajax_suggest' ] );
    }

    // ── Comment row action ────────────────────────────────────────────────────

    public function add_action_link( array $actions, WP_Comment $comment ): array {
        if ( ! current_user_can( 'moderate_comments' ) ) return $actions;
        if ( ! $this->client()->is_configured() ) return $actions;

        $actions['aihub_suggest'] = sprintf(
            '<a href="#" class="aihub-suggest-reply" data-comment-id="%d" data-nonce="%s">%s</a>',
            absint( $comment->comment_ID ),
            esc_attr( wp_create_nonce( 'aihub_nonce' ) ),
            esc_html__( 'AI Reply', 'wp-aihub' )
        );
        return $actions;
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public function ajax_suggest(): void {
        check_ajax_referer( 'aihub_nonce', 'nonce' );

        if ( ! current_user_can( 'moderate_comments' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'wp-aihub' ) ], 403 );
        }

        $comment_id = absint( $_POST['comment_id'] ?? 0 );
        if ( ! $comment_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid comment ID.', 'wp-aihub' ) ] );
        }

        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            wp_send_json_error( [ 'message' => __( 'Comment not found.', 'wp-aihub' ) ] );
        }

        $prompt = sprintf(
            "Write a helpful, friendly reply to the following blog comment. Reply only with the reply text, no preamble.\n\nComment: %s",
            wp_strip_all_tags( $comment->comment_content )
        );

        $provider = sanitize_key( $_POST['provider'] ?? get_option( 'aihub_default_provider', '' ) );
        $model    = sanitize_text_field( wp_unslash( $_POST['model'] ?? get_option( 'aihub_default_model', '' ) ) );
        $reply    = $this->client()->complete( $prompt, $provider, $model, 512 );

        wp_send_json_success( [ 'reply' => wp_kses_post( $reply ) ] );
    }

    // ── Admin JS ──────────────────────────────────────────────────────────────

    public function admin_script(): void {
        ?>
        <script>
        (function() {
            document.addEventListener('click', function(e) {
                var link = e.target.closest('.aihub-suggest-reply');
                if (!link) return;
                e.preventDefault();

                var commentId = link.dataset.commentId;
                var nonce     = link.dataset.nonce;
                var original  = link.textContent;

                link.textContent = '…';
                link.style.pointerEvents = 'none';

                var fd = new FormData();
                fd.append('action',     'aihub_suggest_reply');
                fd.append('nonce',      nonce);
                fd.append('comment_id', commentId);

                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) {
                            // Find reply textarea for this comment and populate it
                            var row      = document.getElementById('comment-' + commentId);
                            var replyBtn = row ? row.querySelector('.reply a') : null;
                            if (replyBtn) replyBtn.click();

                            setTimeout(function() {
                                var ta = document.getElementById('replycontent');
                                if (ta) ta.value = d.data.reply;
                            }, 300);
                        } else {
                            alert('AI Hub: ' + (d.data?.message || 'Error'));
                        }
                    })
                    .catch(function() { alert('AI Hub: Request failed'); })
                    .finally(function() {
                        link.textContent        = original;
                        link.style.pointerEvents = '';
                    });
            });
        })();
        </script>
        <?php
    }
}
