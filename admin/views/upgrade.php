<?php
/**
 * Trinity Forms — "Upgrade to Pro" page (free build only).
 *
 * A single, restrained in-admin upsell: a feature summary, the in-place upgrade
 * reassurance, and a link out to pricing. Registered only when the Pro module is
 * absent (see Admin::register_menus); the Pro build shows its License page
 * instead. No premium code, no phone-home — just static marketing + a link.
 */

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( "edit_posts" ) ) {
    wp_die( esc_html__( "You do not have permission to access this page.", "trinity-forms" ) );
}

// utm_content records HOW the visitor reached this page, so the admin-menu item, the
// Plugins-row link and any future in-context pointer can be told apart on the far
// side. Whitelisted rather than passed through, since the value lands in a URL.
$trinity_forms_src     = isset( $_GET["src"] ) ? sanitize_key( wp_unslash( $_GET["src"] ) ) : ""; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only attribution, changes no state.
$trinity_forms_allowed = [ "plugins-row", "form-editor", "entries-empty", "settings", "tab" ];
$trinity_forms_content = in_array( $trinity_forms_src, $trinity_forms_allowed, true ) ? $trinity_forms_src : "admin-menu";

$trinity_forms_pricing_url = apply_filters( "trinity_forms_upgrade_url", "https://jnkplugins.com/trinity-forms/pricing.php?utm_source=trinity-forms&utm_medium=plugin&utm_campaign=pro-upgrade&utm_content=" . rawurlencode( $trinity_forms_content ) );

$trinity_forms_pro_features = [
    [ __( "Payments", "trinity-forms" ), __( "Collect payments with Stripe, PayPal and Mollie, including recurring billing and coupons.", "trinity-forms" ) ],
    [ __( "CRM & marketing integrations", "trinity-forms" ), __( "Send submissions to your email-marketing and CRM tools automatically.", "trinity-forms" ) ],
    [ __( "Guided forms & landing pages", "trinity-forms" ), __( "One question at a time with a progress bar, or a form on its own full-page URL. (Multi-step page breaks are already free.)", "trinity-forms" ) ],
    [ __( "Advanced fields", "trinity-forms" ), __( "Signature, repeater, calculation, rich text and chained selects.", "trinity-forms" ) ],
    [ __( "Save & resume", "trinity-forms" ), __( "Let visitors save a partial submission and finish later.", "trinity-forms" ) ],
    [ __( "Public submissions table", "trinity-forms" ), __( "Publish a read-only entries table on the front end with [trinity_forms_submissions].", "trinity-forms" ) ],
];
?>
<?php
\TrinityForms\Admin_UI::open(
    __( "Upgrade to Trinity Forms Pro", "trinity-forms" ),
    __( "You are on free Trinity Forms: a complete builder that stores your entries with no caps. Pro adds the parts you need once you take money or grow.", "trinity-forms" ),
    '<a href="' . esc_url( $trinity_forms_pricing_url ) . '" target="_blank" rel="noopener noreferrer" class="button button-primary">' . esc_html__( "See Pro pricing", "trinity-forms" ) . "</a>"
);
?>
    <div class="tf-feature-grid">
        <?php foreach ( $trinity_forms_pro_features as $f ) : ?>
            <div class="jnkui-card"><div class="jnkui-card-body">
                <h2><?php echo esc_html( $f[0] ); ?></h2>
                <p><?php echo esc_html( $f[1] ); ?></p>
            </div></div>
        <?php endforeach; ?>
    </div>

    <div class="jnkui-callout">
        <p><strong><?php esc_html_e( "Upgrading keeps everything.", "trinity-forms" ); ?></strong>
        <?php esc_html_e( "Install the Pro version over this one. Same plugin, same database. Your forms and entries carry over untouched: no reinstall, no data loss.", "trinity-forms" ); ?></p>
    </div>

    <div class="tf-hero-cta">
        <a href="<?php echo esc_url( $trinity_forms_pricing_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary button-hero">
            <?php esc_html_e( "Get Trinity Forms Pro", "trinity-forms" ); ?>
        </a>
    </div>

    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Already bought Pro?", "trinity-forms" ); ?></h2></div></div>
    <div class="jnkui-card-body"><p style="margin:0"><strong><?php esc_html_e( "Fastest: paste your Pro license key in Trinity Forms Settings, under License, and click Install Pro now. Pro is installed over this version for you. Or do it by hand:", "trinity-forms" ); ?></strong> <a class="button" href="<?php echo esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=trinity-forms-settings#lp-trinity-forms-license" ) ); ?>"><?php esc_html_e( "Open the License box", "trinity-forms" ); ?></a></p></div>
        <div class="jnkui-card-body">
            <ol class="jnkui-steps">
                <li><strong><?php esc_html_e( "Download the Pro zip", "trinity-forms" ); ?></strong><?php esc_html_e( "From your account on jnkplugins.com, under your license.", "trinity-forms" ); ?>
                    <br><a class="button button-primary" style="margin-top:6px" href="<?php echo esc_url( "https://license.jnkplugins.com/account/licenses?utm_source=plugin&utm_medium=upgrade-page&utm_campaign=trinity-forms" ); ?>" target="_blank" rel="noopener"><?php esc_html_e( "Download the Pro zip", "trinity-forms" ); ?></a></li>
                <li><strong><?php esc_html_e( "Upload it over this one", "trinity-forms" ); ?></strong><?php esc_html_e( "Plugins, Add New, Upload Plugin. WordPress shows this copy as Trinity Forms and the upload as Trinity Forms Pro. Choose “Replace current with uploaded”.", "trinity-forms" ); ?></li>
                <li><strong><?php esc_html_e( "Enter your license key", "trinity-forms" ); ?></strong><?php esc_html_e( "A notice at the top of the screen takes you to the box, in Trinity Forms Settings under License. The key is in your purchase email.", "trinity-forms" ); ?></li>
            </ol>
    <?php
    // The Pro zip is several MB; many hosts cap uploads at 2 MB, and WordPress's
    // own error ("exceeds the upload_max_filesize directive") says nothing useful.
    $trinity_forms_limit = (int) wp_max_upload_size();
    ?>
            <p class="description">
        <?php
        printf(
            /* translators: %s: this site's maximum upload size, e.g. "2 MB". */
            esc_html__( "The Pro zip is about 6.5 MB and this site accepts uploads up to %s. If WordPress says the file “exceeds the upload_max_filesize directive”, ask your host to raise upload_max_filesize and post_max_size to 16 MB, or unzip it on your computer and upload the trinity-forms folder to wp-content/plugins/ over FTP/SFTP, replacing the existing one. Your forms and entries are kept either way.", "trinity-forms" ),
            esc_html( size_format( $trinity_forms_limit ) )
        );
        ?>
    </p>
        </div>
    </div>
<?php \TrinityForms\Admin_UI::close(); ?>
