<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( "manage_options" ) ) {
    return;
}

$settings = get_option( "trinity_forms_settings", [] );
$turnstile = $settings["turnstile"] ?? [];
$retention = (int) ( $settings["default_retention_days"] ?? 0 );
// Default 30 rather than 0: trash you never empty is just a slower disk leak, and
// 30 days matches what WordPress does with posts.
$trash_purge = isset( $settings["trash_purge_days"] ) ? (int) $settings["trash_purge_days"] : 30;
$delete_data = ! empty( $settings["delete_data_on_uninstall"] );

// Render saved secrets as blank inputs with a placeholder so we don't echo encrypted blobs.
$secret_attr = static function ( string $stored ): string {
    if ( $stored === "" ) {
        return 'value=""';
    }
    return 'value="" placeholder="' . esc_attr__( "(saved, leave blank to keep)", "trinity-forms" ) . '"';
};
?>
<?php
\TrinityForms\Admin_UI::open(
    __( "Settings", "trinity-forms" ),
    __( "Site-wide defaults for every form. Each form can still change most of these in its own settings.", "trinity-forms" )
);
?>
    <nav class="tf-jump" aria-label="<?php esc_attr_e( "Settings sections", "trinity-forms" ); ?>" hidden></nav>
    <?php
    // options.php redirects back with settings-updated=true, but only screens under
    // Settings get WordPress's automatic notice; this one lives under the Forms menu.
    // Validation messages (e.g. a refused payment key) are parked by options.php in the
    // settings_errors transient; screens outside Settings must print them themselves.
    settings_errors( "trinity_forms_settings" );
    if ( ! empty( $_GET["settings-updated"] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by options.php after its own nonce check.
        ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( "Settings saved.", "trinity-forms" ); ?></p></div>
    <?php endif; ?>

    <?php
    // Email check. Its own small form (a form cannot sit inside the options form
    // below), so it sends right away without saving anything else.
    $tf_mail_result   = \TrinityForms\Mail_Check::pop_test_result();
    $tf_mail_failures = \TrinityForms\Mail_Check::recent_failures();
    $tf_mail_last     = get_option( \TrinityForms\Mail_Check::TEST_OPTION, [] );
    $tf_mail_last     = is_array( $tf_mail_last ) ? $tf_mail_last : [];
    ?>
    <div class="jnkui-card" id="tf-mail-check">
        <div class="jnkui-card-head"><div>
            <h2><?php esc_html_e( "Email check", "trinity-forms" ); ?></h2>
            <p><?php esc_html_e( "Send yourself a test to see whether form emails can leave this site.", "trinity-forms" ); ?></p>
        </div></div>
        <div class="jnkui-card-body">
            <?php if ( $tf_mail_result ) : ?>
                <div class="jnkui-callout<?php echo $tf_mail_result["ok"] ? "" : " is-warn"; ?>"><p><?php echo esc_html( (string) $tf_mail_result["message"] ); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="tf-mail-check-form">
                <input type="hidden" name="action" value="trinity_forms_test_email" />
                <?php wp_nonce_field( "trinity_forms_test_email" ); ?>
                <label for="tf-mail-check-to" class="screen-reader-text"><?php esc_html_e( "Send the test to", "trinity-forms" ); ?></label>
                <input type="email" class="regular-text" id="tf-mail-check-to" name="to" value="<?php echo esc_attr( (string) wp_get_current_user()->user_email ); ?>" />
                <button type="submit" class="button button-primary"><?php esc_html_e( "Send a test email", "trinity-forms" ); ?></button>
            </form>
            <p class="description"><?php esc_html_e( "WordPress can only tell us the email was handed to your mail server. Whether it reaches the inbox is up to that server, so check the inbox (and the spam folder) yourself.", "trinity-forms" ); ?></p>
            <?php if ( ! empty( $tf_mail_last["at"] ) && ! $tf_mail_result ) : ?>
                <p class="description"><?php
                    echo esc_html( sprintf(
                        /* translators: 1: date, 2: email address, 3: outcome. */
                        __( "Last test: %1\$s to %2\$s. %3\$s", "trinity-forms" ),
                        wp_date( get_option( "date_format" ) . " " . get_option( "time_format" ), (int) $tf_mail_last["at"] ),
                        (string) ( $tf_mail_last["to"] ?? "" ),
                        ! empty( $tf_mail_last["ok"] ) ? __( "Handed to the mail server.", "trinity-forms" ) : __( "It could not be sent.", "trinity-forms" )
                    ) );
                ?></p>
            <?php endif; ?>
            <?php if ( $tf_mail_failures ) : ?>
                <h3><?php esc_html_e( "Failed in the last 7 days", "trinity-forms" ); ?></h3>
                <ul class="jnkui-list tf-mail-failures">
                    <?php foreach ( array_slice( $tf_mail_failures, 0, 5 ) as $tf_fail ) : ?>
                        <li><span>
                            <?php echo esc_html( (string) $tf_fail["to"] ); ?>
                            <span class="jnkui-sub"><?php echo esc_html( (string) $tf_fail["reason"] ); ?></span>
                        </span>
                        <span class="jnkui-sub"><?php
                            echo esc_html( wp_date( get_option( "date_format" ) . " " . get_option( "time_format" ), (int) $tf_fail["at"] ) );
                            if ( ! empty( $tf_fail["entry_id"] ) ) {
                                echo ' &middot; <a href="' . esc_url( \TrinityForms\Admin::entries_url( [ "entry" => (int) $tf_fail["entry_id"] ] ) ) . '">' . esc_html__( "See the entry", "trinity-forms" ) . "</a>";
                            }
                        ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <p class="description"><?php esc_html_e( "Most of the time this means the site has no working mail setup. An SMTP plugin that sends through your email provider usually fixes it.", "trinity-forms" ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="options.php" class="tf-settings">
        <?php settings_fields( "trinity_forms_settings_group" ); ?>

        <?php
        // Premium payment-gateway settings (Stripe, PayPal) are rendered by pro/.
        // $secret_attr is the shared "leave blank to keep" helper for secret fields.
        do_action( "trinity_forms_render_settings_sections", $settings, $secret_attr );
        ?>

        <?php
        // Deliberately NOT wrapped in Form_Builder::enabled(): this control is how a
        // site switches editors, so hiding it when the canvas is off would strand
        // anyone who turned it off with no way back.
        ?>
        <h2><?php esc_html_e( "Form editor", "trinity-forms" ); ?></h2>
        <?php $default_editor = $settings["default_editor"] ?? "canvas"; ?>
        <table class="form-table">
            <tr><th><label for="trinity_forms_default_editor"><?php esc_html_e( "Open forms in", "trinity-forms" ); ?></label></th>
                <td>
                    <select id="trinity_forms_default_editor" name="trinity_forms_settings[default_editor]">
                        <option value="canvas" <?php selected( $default_editor, "canvas" ); ?>><?php esc_html_e( "Visual builder (drag and drop)", "trinity-forms" ); ?></option>
                        <option value="block" <?php selected( $default_editor, "block" ); ?>><?php esc_html_e( "Block editor", "trinity-forms" ); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e( "The default for new forms. Any form can override this in its own settings. Both editors read and write the same form, so you can switch at any time.", "trinity-forms" ); ?></p>
                    <p class="description"><?php esc_html_e( "Forms you built before upgrading keep opening in the block editor until you change them individually.", "trinity-forms" ); ?></p>
                </td></tr>
        </table>

        <h2><?php esc_html_e( "Spam protection (CAPTCHA)", "trinity-forms" ); ?></h2>
        <?php $captcha_provider = $settings["captcha_provider"] ?? "turnstile"; ?>
        <table class="form-table">
            <tr><th><label for="trinity_forms_captcha_provider"><?php esc_html_e( "Provider", "trinity-forms" ); ?></label></th>
                <td>
                    <select id="trinity_forms_captcha_provider" name="trinity_forms_settings[captcha_provider]">
                        <?php foreach ( \TrinityForms\Anti_Spam::CAPTCHA_PROVIDERS as $pkey => $pmeta ) : ?>
                            <option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $captcha_provider, $pkey ); ?>><?php echo esc_html( $pmeta["label"] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( "Enter the site + secret keys for your chosen provider below, then enable the challenge in each form's settings.", "trinity-forms" ); ?></p>
                </td></tr>
        </table>
        <?php
        // Driven off Anti_Spam::CAPTCHA_PROVIDERS rather than a list repeated here —
        // a hard-coded copy is how reCAPTCHA v3 shipped with no way to select it and
        // no key fields, despite the engine supporting it.
        foreach ( \TrinityForms\Anti_Spam::CAPTCHA_PROVIDERS as $pkey => $pmeta ) :
            $plabel = $pmeta["label"];
            $pconf = is_array( $settings[ $pkey ] ?? null ) ? $settings[ $pkey ] : [];
            ?>
            <?php
            // Folded away unless it is the chosen provider or already has keys, so
            // four sets of key boxes do not bury the rest of the page. The fields are
            // in the form either way and save as before.
            $popen = $pkey === $captcha_provider || ! empty( $pconf["site_key"] );
            ?>
            <details class="tf-provider"<?php echo $popen ? " open" : ""; ?>>
            <summary><?php echo esc_html( $plabel ); ?><?php if ( $pkey === $captcha_provider ) : ?> <span class="jnkui-pill is-brand"><?php esc_html_e( "Selected", "trinity-forms" ); ?></span><?php endif; ?></summary>
            <table class="form-table">
                <tr><th><label for="trinity_forms_<?php echo esc_attr( $pkey ); ?>_site_key"><?php esc_html_e( "Site key", "trinity-forms" ); ?></label></th>
                    <td><input type="text" class="regular-text" id="trinity_forms_<?php echo esc_attr( $pkey ); ?>_site_key" name="trinity_forms_settings[<?php echo esc_attr( $pkey ); ?>][site_key]" value="<?php echo esc_attr( \TrinityForms\Secrets::decrypt( (string) ( $pconf["site_key"] ?? "" ) ) ); ?>" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" /></td></tr>
                <tr><th><label for="trinity_forms_<?php echo esc_attr( $pkey ); ?>_secret_key"><?php esc_html_e( "Secret key", "trinity-forms" ); ?></label></th>
                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $secret_attr() returns a pre-escaped attribute fragment (value=""/placeholder via esc_attr__). ?>
                    <td><input type="password" class="regular-text" id="trinity_forms_<?php echo esc_attr( $pkey ); ?>_secret_key" name="trinity_forms_settings[<?php echo esc_attr( $pkey ); ?>][secret_key]" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" <?php echo $secret_attr( (string) ( $pconf["secret_key"] ?? "" ) ); ?> /></td></tr>
            </table>
            </details>
        <?php endforeach; ?>

        <h2><?php esc_html_e( "Keeping entries", "trinity-forms" ); ?></h2>
        <table class="form-table">
            <tr><th><label><?php esc_html_e( "Keep entries for (days)", "trinity-forms" ); ?></label></th>
                <td>
                    <input type="number" min="0" name="trinity_forms_settings[default_retention_days]" value="<?php echo esc_attr( (string) $retention ); ?>" />
                    <p class="description"><?php esc_html_e( "0 keeps entries forever. A form's own setting overrides this.", "trinity-forms" ); ?></p>
                </td></tr>
            <tr><th><label><?php esc_html_e( "Empty trash after (days)", "trinity-forms" ); ?></label></th>
                <td>
                    <input type="number" min="0" name="trinity_forms_settings[trash_purge_days]" value="<?php echo esc_attr( (string) $trash_purge ); ?>" />
                    <p class="description"><?php esc_html_e( "Entries in the trash are deleted for good after this many days. 0 never empties the trash.", "trinity-forms" ); ?></p>
                </td></tr>
        </table>

        <h2><?php esc_html_e( "Data", "trinity-forms" ); ?></h2>
        <table class="form-table">
            <tr><th scope="row"><?php esc_html_e( "Delete data on uninstall", "trinity-forms" ); ?></th>
                <td>
                    <label>
                        <input type="hidden" name="trinity_forms_settings[delete_data_on_uninstall]" value="0" />
                        <input type="checkbox" name="trinity_forms_settings[delete_data_on_uninstall]" value="1" <?php checked( $delete_data ); ?> />
                        <?php esc_html_e( "Delete all Trinity Forms data when the plugin is deleted", "trinity-forms" ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( "Off by default. Your forms and entries are kept if you deactivate, delete, or let a Pro license lapse. Tick this only to also remove data on delete.", "trinity-forms" ); ?></p>
                </td></tr>
        </table>

        <div class="tf-sticky-save"><?php submit_button( __( "Save settings", "trinity-forms" ), "primary", "submit", false ); ?></div>
    </form>
    <?php
    /**
     * Premium license/activation UI renders here (from pro/), keeping settings
     * and licensing on one screen instead of a separate WP → Settings page.
     */
    ob_start();
    do_action( "trinity_forms_settings_after", $settings );
    $tf_after = (string) ob_get_clean();
    if ( "" !== trim( $tf_after ) ) :
        ?>
        <h2 id="tf-licence" class="tf-section-title"><?php esc_html_e( "License", "trinity-forms" ); ?></h2>
        <div class="jnkui-card tf-licence-card"><div class="jnkui-card-body">
            <?php echo $tf_after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup from the licensing panel, escaped where it is built. ?>
        </div></div>
    <?php endif; ?>
<?php \TrinityForms\Admin_UI::close(); ?>
