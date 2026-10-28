<?php
/**
 * Trinity Forms — "Feedback & Support" page (free core; ships in Lite and Pro).
 *
 * A simple in-admin form that lets a site admin send the plugin author
 * feedback or a bug report through the site's own wp_mail(). No external
 * HTTP calls — it stays wordpress.org-clean. The handler lives in
 * Admin::handle_send_feedback (admin_post_trinity_forms_send_feedback).
 */

defined( "ABSPATH" ) || exit;

if ( ! current_user_can( "manage_options" ) ) {
    wp_die( esc_html__( "You do not have permission to access this page.", "trinity-forms" ) );
}

$trinity_forms_docs_url = "https://jnkplugins.com/trinity-forms/";

$trinity_forms_feedback = isset( $_GET["ff_feedback"] ) ? sanitize_key( wp_unslash( $_GET["ff_feedback"] ) ) : ""; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
?>
<?php
\TrinityForms\Admin_UI::open(
    __( "Feedback and support", "trinity-forms" ),
    __( "Found a bug, want a feature, or just want to say hi? Your message goes straight to the people who make Trinity Forms.", "trinity-forms" ),
    '<a class="button" href="' . esc_url( $trinity_forms_docs_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( "Read the docs", "trinity-forms" ) . "</a>"
);
?>

    <?php if ( $trinity_forms_feedback === "sent" ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( "Thanks! Your feedback was sent to the Trinity Forms author.", "trinity-forms" ); ?></p></div>
    <?php elseif ( $trinity_forms_feedback === "ratelimited" ) : ?>
        <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( "You’ve sent several messages recently. Please try again in a little while.", "trinity-forms" ); ?></p></div>
    <?php elseif ( $trinity_forms_feedback === "error" ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e( "Sorry, your message could not be sent. Your site’s email may not be configured.", "trinity-forms" ); ?></p></div>
    <?php endif; ?>


    <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="tf-narrow">
        <?php wp_nonce_field( "trinity_forms_feedback" ); ?>
        <input type="hidden" name="action" value="trinity_forms_send_feedback" />

        <?php /* Honeypot: hidden from real users, tempting to bots. */ ?>
        <div style="position:absolute;inset-inline-start:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
            <label for="ff_website"><?php esc_html_e( "Leave this field empty", "trinity-forms" ); ?></label>
            <input type="text" id="ff_website" name="ff_website" value="" tabindex="-1" autocomplete="off" />
        </div>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="ff_type"><?php esc_html_e( "Type", "trinity-forms" ); ?></label></th>
                <td>
                    <select id="ff_type" name="ff_type">
                        <option value="bug"><?php esc_html_e( "Bug report", "trinity-forms" ); ?></option>
                        <option value="feature"><?php esc_html_e( "Feature request", "trinity-forms" ); ?></option>
                        <option value="question"><?php esc_html_e( "Question", "trinity-forms" ); ?></option>
                        <option value="praise"><?php esc_html_e( "Praise", "trinity-forms" ); ?></option>
                        <option value="other"><?php esc_html_e( "Other", "trinity-forms" ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ff_message"><?php esc_html_e( "Message", "trinity-forms" ); ?></label></th>
                <td>
                    <textarea id="ff_message" name="ff_message" rows="7" class="large-text" required></textarea>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ff_reply_email"><?php esc_html_e( "Your email", "trinity-forms" ); ?></label></th>
                <td>
                    <input type="email" id="ff_reply_email" name="ff_reply_email" class="regular-text" value="" autocomplete="off" />
                    <p class="description"><?php esc_html_e( "Optional, so we can reply.", "trinity-forms" ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( "Site info", "trinity-forms" ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ff_site_info" value="1" checked />
                        <?php esc_html_e( "Include basic site info to help debugging", "trinity-forms" ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( "Sends: WordPress version, PHP version, Trinity Forms version, active theme, and site URL. No personal data.", "trinity-forms" ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button( __( "Send message", "trinity-forms" ) ); ?>

        <p class="description">
            <?php esc_html_e( "Sent with your site's own email. Nothing else leaves your site.", "trinity-forms" ); ?>
        </p>
    </form>
<?php \TrinityForms\Admin_UI::close(); ?>
