<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required.

/**
 * Visitor IP addresses on entries (TF-34, free).
 *
 * By default an entry keeps the visitor's IP address, as it always has. A
 * site-wide switch (Settings > Data) or a per-form switch (General tab) stops
 * that: the entry, and a Pro partial entry, are written with no address.
 *
 * Only storage changes. Everything that looks at the live request keeps working:
 * spam lists and Akismet, the submit rate limit, CAPTCHA. Things that need the
 * stored address do without it: the "per IP address" entry limit (nothing to
 * count), the {ip} merge tag (empty) and Pro geolocation (nothing to look up).
 *
 * A Settings card clears the addresses already stored, for every form or one.
 */
class Ip_Privacy {

    /** Optional export column (not in the default selection). */
    public const COLUMN = "ip_address";

    public static function register(): void {
        add_action( "trinity_forms_form_editor_general_rows", [ self::class, "render_form_row" ], 6, 2 );
        add_action( "trinity_forms_settings_cards", [ self::class, "render_clear_card" ], 20 );
        add_action( "admin_post_trinity_forms_clear_ips", [ self::class, "handle_clear" ] );
    }

    /** The site-wide switch: true when no form stores IP addresses. */
    public static function site_off(): bool {
        $settings = get_option( "trinity_forms_settings", [] );
        return is_array( $settings ) && ! empty( $settings["no_store_ip"] );
    }

    /** Does this form keep the visitor's IP address on its entries? */
    public static function stores( ?Form $form ): bool {
        $store = ! self::site_off() && ! ( $form && $form->get_setting( "no_store_ip", false ) );
        /**
         * Filter whether the visitor IP address is written to a form's entries.
         *
         * @param bool      $store
         * @param Form|null $form
         */
        return (bool) apply_filters( "trinity_forms_store_ip", $store, $form );
    }

    /** The packed address to write for an entry of this form, or null. */
    public static function for_entry( ?Form $form ): ?string {
        if ( ! self::stores( $form ) ) {
            return null;
        }
        $ip = Submission_Pipeline::client_ip();
        $packed = $ip ? @inet_pton( $ip ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validated by client_ip(); false on anything odd.
        return false === $packed ? null : $packed;
    }

    /** Readable address from a stored value ("" when none). */
    public static function readable( $stored ): string {
        if ( null === $stored || "" === $stored ) {
            return "";
        }
        $ip = @inet_ntop( (string) $stored ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- old rows may hold junk.
        return false === $ip ? "" : $ip;
    }

    /** How many entries hold an address (one form, or all with 0). */
    public static function count_stored( int $form_id = 0 ): int {
        global $wpdb;
        $table = $wpdb->prefix . "trinity_forms_entries";
        if ( $form_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE ip_address IS NOT NULL AND form_id = %d", $form_id ) );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ip_address IS NOT NULL" );
    }

    /** Remove stored addresses (one form, or all with 0). Returns how many entries changed. */
    public static function clear( int $form_id = 0 ): int {
        global $wpdb;
        $table = $wpdb->prefix . "trinity_forms_entries";
        if ( $form_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $n = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET ip_address = NULL WHERE ip_address IS NOT NULL AND form_id = %d", $form_id ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $n = $wpdb->query( "UPDATE {$table} SET ip_address = NULL WHERE ip_address IS NOT NULL" );
        }
        $n = (int) $n;
        if ( $n > 0 ) {
            Logger::info( "privacy", /* translators: %d: number of entries. */ sprintf( _n( "Removed the stored IP address from %d entry", "Removed the stored IP addresses from %d entries", $n, "trinity-forms" ), $n ), null, [ "form_id" => $form_id ] );
        }
        return $n;
    }

    /** General tab: the per-form switch. */
    public static function render_form_row( $form, $settings ): void {
        $on = ! empty( ( (array) $settings )["no_store_ip"] );
        ?>
        <tr><th><?php esc_html_e( "Visitor IP address", "trinity-forms" ); ?></th>
            <td><label>
                <input type="hidden" name="trinity-forms[settings][no_store_ip]" value="0" />
                <input type="checkbox" name="trinity-forms[settings][no_store_ip]" value="1" <?php checked( $on || self::site_off() ); ?> <?php disabled( self::site_off() ); ?> />
                <?php esc_html_e( "Don't store the visitor's IP address with entries of this form", "trinity-forms" ); ?>
            </label>
            <?php if ( self::site_off() ) : ?>
                <input type="hidden" name="trinity-forms[settings][no_store_ip]" value="<?php echo $on ? "1" : "0"; ?>" />
                <p class="description"><?php esc_html_e( "Turned off for every form in Settings > Data.", "trinity-forms" ); ?></p>
            <?php endif; ?>
            <p class="description"><?php esc_html_e( "Spam lists and rate limits still check the address while the form is being sent. The per IP address entry limit needs stored addresses, so it does nothing while this is on.", "trinity-forms" ); ?></p></td></tr>
        <?php
    }

    /** Settings: clear the addresses already stored. */
    public static function render_clear_card(): void {
        if ( ! current_user_can( "manage_options" ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after our own nonce-checked redirect.
        $done  = isset( $_GET["tf_ips_cleared"] ) ? absint( $_GET["tf_ips_cleared"] ) : null;
        $total = self::count_stored();
        $forms = get_posts( [ "post_type" => "trinity_forms_form", "post_status" => [ "publish", "draft", "private" ], "numberposts" => 200, "orderby" => "title", "order" => "ASC" ] );
        ?>
        <div class="jnkui-card" id="tf-clear-ips">
            <div class="jnkui-card-head"><div>
                <h2><?php esc_html_e( "Stored IP addresses", "trinity-forms" ); ?></h2>
                <p><?php
                    /* translators: %s: number of entries */
                    echo esc_html( sprintf( _n( "%s entry holds a visitor IP address.", "%s entries hold a visitor IP address.", $total, "trinity-forms" ), number_format_i18n( $total ) ) );
                ?></p>
            </div></div>
            <div class="jnkui-card-body">
                <?php if ( null !== $done ) : ?>
                    <div class="jnkui-callout"><p><?php
                        /* translators: %s: number of entries */
                        echo esc_html( sprintf( _n( "Removed the IP address from %s entry.", "Removed the IP address from %s entries.", $done, "trinity-forms" ), number_format_i18n( $done ) ) );
                    ?></p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" onsubmit="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?php esc_attr_e( "Remove the stored IP addresses? This cannot be undone. The entries themselves are kept.", "trinity-forms" ); ?>">
                    <input type="hidden" name="action" value="trinity_forms_clear_ips" />
                    <?php wp_nonce_field( "trinity_forms_clear_ips" ); ?>
                    <label for="tf-clear-ips-form"><?php esc_html_e( "Entries of", "trinity-forms" ); ?></label>
                    <select id="tf-clear-ips-form" name="form_id">
                        <option value="0"><?php esc_html_e( "All forms", "trinity-forms" ); ?></option>
                        <?php foreach ( $forms as $f ) : ?>
                            <option value="<?php echo esc_attr( (string) $f->ID ); ?>"><?php echo esc_html( get_the_title( $f ) ?: "#" . $f->ID ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button" <?php disabled( 0 === $total ); ?>><?php esc_html_e( "Remove stored IP addresses", "trinity-forms" ); ?></button>
                    <p class="description"><?php esc_html_e( "Entries keep everything else. To stop new entries storing addresses, use the switch under Data below or on a form's General tab.", "trinity-forms" ); ?></p>
                </form>
            </div>
        </div>
        <?php
    }

    public static function handle_clear(): void {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( esc_html__( "You are not allowed to do that.", "trinity-forms" ), 403 );
        }
        check_admin_referer( "trinity_forms_clear_ips" );
        $form_id = isset( $_POST["form_id"] ) ? absint( $_POST["form_id"] ) : 0;
        $n       = self::clear( $form_id );
        wp_safe_redirect( add_query_arg( "tf_ips_cleared", $n, admin_url( Admin::PARENT . "&page=" . Admin::SETTINGS ) ) . "#tf-clear-ips" );
        exit;
    }
}
