<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Admin {

    public const CAP      = "manage_options";
    public const PARENT   = "edit.php?post_type=trinity_forms_form";
    public const ENTRIES  = "trinity-forms-entries";
    public const SETTINGS = "trinity-forms-settings";
    public const FEEDBACK = "trinity-forms-feedback";
    public const IMPORT   = "trinity-forms-import";

    /**
     * Semantic tone for a status pill on the entries list.
     *
     * Both the entry status and the payment status come from small fixed sets, and
     * both read as a wall of identical lowercase words without colour. Map each value
     * to a tone rather than to a literal colour, so the two columns stay consistent
     * and a new value only needs a line here.
     *
     * @param string $context "status" or "payment".
     * @param string $value   The raw column value.
     * @return string One of: success, warning, info, danger, muted, neutral.
     */
    public static function pill_tone( string $context, string $value ): string {
        $map = [
            "status"  => [
                "completed"      => "success",
                "approved"       => "success",
                "pending"        => "warning",
                "pending_review" => "warning",
                "failed"         => "danger",
                "rejected"       => "danger",
                "spam"           => "muted",
                "partial"        => "muted",
            ],
            "payment" => [
                "paid"     => "success",
                "pending"  => "warning",
                "refunded" => "info",
                "failed"   => "danger",
                "none"     => "muted",
            ],
        ];

        return $map[ $context ][ strtolower( $value ) ] ?? "neutral";
    }

    public static function bootstrap(): void {
        add_action( "admin_menu",            [ self::class, "register_menus" ] );
        add_action( "admin_enqueue_scripts", [ self::class, "enqueue_assets" ] );
        add_action( "admin_init",            [ self::class, "register_settings" ] );
        add_action( "admin_init",            [ self::class, "maybe_render_print_view" ] );
        add_action( "admin_post_trinity_forms_delete_entry",  [ self::class, "handle_delete_entry" ] );
        add_action( "admin_post_trinity_forms_entry_action",  [ self::class, "handle_entry_action" ] );
        add_action( "admin_post_trinity_forms_entry_bulk",    [ self::class, "handle_entry_bulk" ] );
        add_action( "admin_post_trinity_forms_update_entry",  [ self::class, "handle_update_entry" ] );
        add_action( "admin_post_trinity_forms_entry_columns", [ self::class, "handle_entry_columns" ] );
        add_action( "admin_post_trinity_forms_add_note",      [ self::class, "handle_add_note" ] );
        add_action( "admin_post_trinity_forms_delete_note",   [ self::class, "handle_delete_note" ] );
        add_action( "admin_post_trinity_forms_send_feedback", [ self::class, "handle_send_feedback" ] );
        add_action( "admin_post_trinity_forms_gf_import",      [ self::class, "handle_gf_import" ] );
        add_action( "admin_post_trinity_forms_import",         [ self::class, "handle_import" ] );
        add_filter( "allowed_block_types_all", [ self::class, "restrict_form_palette" ], 10, 2 );
        add_filter( "plugin_action_links_" . plugin_basename( TRINITY_FORMS_FILE ), [ self::class, "plugin_action_links" ] );
        add_filter( "plugin_row_meta", [ self::class, "plugin_row_meta" ], 10, 2 );
        add_action( "admin_menu", [ self::class, "order_submenu" ], 999 );
        Form_Editor::bootstrap();
        Forms_List::bootstrap();
        Admin_UI::bootstrap();
    }

    /**
     * Action links on the Plugins screen (next to Deactivate): Settings, and an
     * Upgrade link whenever Pro is not active (mirrors the admin-menu gating).
     */
    public static function plugin_action_links( array $links ): array {
        $settings = '<a href="' . esc_url( admin_url( self::PARENT . "&page=" . self::SETTINGS ) ) . '">'
            . esc_html__( "Settings", "trinity-forms" ) . "</a>";
        array_unshift( $links, $settings );

        if ( ! License::is_pro() ) {
            // `src` distinguishes this entry point from the admin-menu item in the
            // upgrade page's outbound utm_content, which was otherwise constant.
            $links["trinity_forms_upgrade"] = '<a href="' . esc_url( admin_url( self::PARENT . "&page=trinity-forms-upgrade&src=plugins-row" ) ) . '" '
                . 'style="color:#b45309;font-weight:600;">' . esc_html__( "Upgrade to Pro", "trinity-forms" ) . "</a>";
        }
        return $links;
    }

    /** Row meta on the Plugins screen (after "View details"): Docs + Support. */
    public static function plugin_row_meta( array $meta, string $file ): array {
        if ( plugin_basename( TRINITY_FORMS_FILE ) === $file ) {
            // Docs = the feature guide (the plugin-site link WordPress already adds is
            // the product page), Support = our own contact page: the wordpress.org
            // listing and its forum are closed.
            $meta[] = '<a href="https://jnkplugins.com/trinity-forms/features.php" target="_blank" rel="noopener">'
                . esc_html__( "Docs", "trinity-forms" ) . "</a>";
            $meta[] = '<a href="https://jnkplugins.com/contact.php" target="_blank" rel="noopener">'
                . esc_html__( "Support", "trinity-forms" ) . "</a>";
        }
        return $meta;
    }

    /** Limit the form CPT block palette to trinity-forms/field-* (plus a couple of structural blocks). */
    public static function restrict_form_palette( $allowed, $context ) {
        $post = isset( $context->post ) && $context->post instanceof \WP_Post ? $context->post : null;
        if ( ! $post || $post->post_type !== "trinity_forms_form" ) {
            return $allowed;
        }
        return self::form_palette();
    }

    /**
     * The blocks a form may contain — one list, shared by the block editor and the
     * visual builder, so the two editors can never offer a different set.
     *
     * $context "insert" is what a person may ADD to a form; "edit" is what the
     * editors may understand and change in place. They differ only on a Pro site
     * whose licence lapsed: Pro fields already on a form stay editable there, but
     * new ones can't be added.
     *
     * @return array<int,string>
     */
    public static function form_palette( string $context = "insert" ): array {
        // Free-tier blocks only. Premium field blocks (signature / payment /
        // repeater) are added to the palette by pro/ via the trinity_forms_form_palette
        // filter; the free build never offers them in the editor.
        $palette = [
            "trinity-forms/field-text",
            "trinity-forms/field-email",
            "trinity-forms/field-textarea",
            "trinity-forms/field-select",
            "trinity-forms/field-number",
            "trinity-forms/field-range",
            "trinity-forms/field-name",
            "trinity-forms/field-phone",
            "trinity-forms/field-address",
            "trinity-forms/field-country",
            "trinity-forms/field-radio",
            "trinity-forms/field-checkbox",
            "trinity-forms/field-date",
            "trinity-forms/field-time",
            "trinity-forms/field-hidden",
            "trinity-forms/field-html",
            "trinity-forms/field-page-break",
            "trinity-forms/field-section",
            "trinity-forms/field-rating",
            "trinity-forms/field-consent",
            "trinity-forms/field-website",
            "trinity-forms/field-password",
            "trinity-forms/field-multiselect",
            "trinity-forms/field-image-choice",
            "trinity-forms/field-multiple-choice",
            "trinity-forms/field-list",
            "trinity-forms/field-product",
            "trinity-forms/field-quantity",
            "trinity-forms/field-option",
            "trinity-forms/field-shipping",
            "trinity-forms/field-total",
            "trinity-forms/field-file",
            "core/heading",
            "core/paragraph",
        ];
        return array_values( array_unique( (array) apply_filters( "trinity_forms_form_palette", $palette, "edit" === $context ? "edit" : "insert" ) ) );
    }

    public static function register_menus(): void {
        add_submenu_page(
            self::PARENT,
            __( "Entries", "trinity-forms" ),
            __( "Entries", "trinity-forms" ),
            self::CAP,
            self::ENTRIES,
            [ self::class, "render_entries" ]
        );

        // Premium submenus (e.g. Integrations) register here from pro/; the free
        // build adds nothing further.
        do_action( "trinity_forms_register_admin_menu", self::PARENT, self::CAP );

        add_submenu_page(
            self::PARENT,
            __( "Settings", "trinity-forms" ),
            __( "Settings", "trinity-forms" ),
            "manage_options",
            self::SETTINGS,
            [ self::class, "render_settings" ]
        );

        add_submenu_page(
            self::PARENT,
            __( "Feedback & Support", "trinity-forms" ),
            __( "Feedback", "trinity-forms" ),
            self::CAP,
            self::FEEDBACK,
            [ self::class, "render_feedback" ]
        );

        // Always registered: pricing and the readme sell this importer, and a screen
        // that only appeared once Gravity Forms tables existed looked like a WP-CLI-only
        // feature. With no Gravity Forms data the screen says so and explains why.
        add_submenu_page(
            self::PARENT,
            __( "Import forms", "trinity-forms" ),
            __( "Import forms", "trinity-forms" ),
            self::CAP,
            self::IMPORT,
            [ self::class, "render_import" ]
        );

        // Show the "Upgrade to Pro" entry point whenever Pro is not active — the
        // free build, OR the Pro build with no/lapsed/deactivated license. When
        // licensed, the inline License panel on Settings handles management instead.
        if ( ! \TrinityForms\License::is_pro() && ! \TrinityForms\License::pro_installed() ) {
            add_submenu_page(
                self::PARENT,
                __( "Upgrade to Pro", "trinity-forms" ),
                '<span class="trinity-forms-upgrade-menu" style="color:#f0b849;">' . esc_html__( "Upgrade to Pro", "trinity-forms" ) . '</span>',
                self::CAP,
                "trinity-forms-upgrade",
                [ self::class, "render_upgrade" ]
            );
        }
    }

    /**
     * Put the submenu in working order.
     *
     * Items register from several classes at different admin_menu priorities, which
     * left "Visual Builder" and "Results" dangling after "Feedback" and "Upgrade to
     * Pro". Anything not named here (a Pro screen, a future add-on) keeps its
     * relative place between the known items and Settings.
     */
    public static function order_submenu(): void {
        global $submenu;
        if ( empty( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) {
            return;
        }
        $head = [ self::PARENT, "post-new.php?post_type=trinity_forms_form", "trinity-forms-builder", "trinity-forms-templates", "edit.php?post_type=trinity_forms_view", self::ENTRIES, "trinity-forms-results" ];
        $tail = [ "trinity-forms-transfer", self::SETTINGS, self::IMPORT, self::FEEDBACK, "trinity-forms-upgrade" ];
        $rank = static function ( array $item ) use ( $head, $tail ): int {
            $slug = (string) ( $item[2] ?? "" );
            $h    = array_search( $slug, $head, true );
            if ( false !== $h ) {
                return (int) $h;
            }
            $t = array_search( $slug, $tail, true );
            return false !== $t ? 1000 + (int) $t : 500;
        };
        $items = array_values( $submenu[ self::PARENT ] );
        $order = array_keys( $items );
        usort( $order, static function ( $a, $b ) use ( $items, $rank ) {
            return [ $rank( $items[ $a ] ), $a ] <=> [ $rank( $items[ $b ] ), $b ];
        } );
        $sorted = [];
        foreach ( $order as $i => $idx ) {
            $sorted[ ( $i + 1 ) * 5 ] = $items[ $idx ];
        }
        $submenu[ self::PARENT ] = $sorted; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own submenu, the documented way.
    }

    public static function render_upgrade(): void {
        include TRINITY_FORMS_PATH . "admin/views/upgrade.php";
    }

    public static function render_import(): void {
        include TRINITY_FORMS_PATH . "admin/views/import.php";
    }

    public static function enqueue_assets( string $hook ): void {
        $kit = "" !== Admin_UI::current();
        if ( strpos( $hook, "trinity-forms" ) === false && ! self::is_form_post_screen() && ! $kit ) {
            return;
        }
        // The shared JnK design kit (scoped under .jnkui) for the screens drawn with
        // Admin_UI. The handle is Trinity's own, so another JnK plugin's copy never
        // replaces this one.
        $deps = [];
        if ( $kit ) {
            wp_enqueue_style( "trinity-forms-jnk-ui", TRINITY_FORMS_URL . "admin/assets/css/jnk-ui.css", [], TRINITY_FORMS_VERSION );
            $deps = [ "trinity-forms-jnk-ui" ];
        }
        wp_enqueue_style(  "trinity-forms-admin", TRINITY_FORMS_URL . "admin/assets/css/admin.css", $deps, TRINITY_FORMS_VERSION );
        wp_enqueue_script( "trinity-forms-admin", TRINITY_FORMS_URL . "admin/assets/js/admin.js",   [ "jquery" ], TRINITY_FORMS_VERSION, true );
        wp_localize_script( "trinity-forms-admin", "TrinityFormsAdmin", [
            "rest_url" => esc_url_raw( rest_url( "trinity-forms/v1/" ) ),
            "nonce"    => wp_create_nonce( "wp_rest" ),
        ] );
    }

    private static function is_form_post_screen(): bool {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        return $screen && $screen->post_type === "trinity_forms_form";
    }

    public static function register_settings(): void {
        register_setting( "trinity_forms_settings_group", "trinity_forms_settings", [
            "type"              => "array",
            "sanitize_callback" => [ self::class, "sanitize_settings" ],
            "default"           => [],
        ] );
    }

    public static function sanitize_settings( $value ): array {
        $value = is_array( $value ) ? $value : [];
        $existing = get_option( "trinity_forms_settings", [] );
        if ( ! is_array( $existing ) ) { $existing = []; }

        // Empty submitted secrets mean "leave as-is" (the UI shows a placeholder
        // for already-saved values rather than echoing the encrypted blob).
        $value = self::keep_existing_secrets( $value, $existing );

        $merged = array_replace_recursive( $existing, $value );

        // Sanitize the non-secret free-tier fields explicitly.
        if ( isset( $merged["default_retention_days"] ) ) {
            $merged["default_retention_days"] = absint( $merged["default_retention_days"] );
        }
        if ( isset( $merged["partial_retention_days"] ) ) {
            $merged["partial_retention_days"] = max( 1, absint( $merged["partial_retention_days"] ) );
        }
        if ( isset( $merged["trash_purge_days"] ) ) {
            $merged["trash_purge_days"] = absint( $merged["trash_purge_days"] );
        }
        foreach ( [ "turnstile", "recaptcha", "hcaptcha" ] as $captcha_p ) {
            if ( isset( $merged[ $captcha_p ] ) && is_array( $merged[ $captcha_p ] ) ) {
                foreach ( $merged[ $captcha_p ] as $tk => $tv ) {
                    if ( is_scalar( $tv ) ) {
                        $merged[ $captcha_p ][ $tk ] = sanitize_text_field( (string) $tv );
                    }
                }
            }
        }
        if ( isset( $merged["captcha_provider"] ) ) {
            $merged["captcha_provider"] = in_array( $merged["captcha_provider"], [ "turnstile", "recaptcha", "recaptcha_v3", "hcaptcha" ], true )
                ? $merged["captcha_provider"] : "turnstile";
        }
        if ( isset( $merged["default_editor"] ) ) {
            $merged["default_editor"] = in_array( $merged["default_editor"], [ "canvas", "block" ], true )
                ? $merged["default_editor"] : "block";
        }
        foreach ( [ "recaptcha_v3" ] as $captcha_p ) {
            if ( isset( $merged[ $captcha_p ] ) && is_array( $merged[ $captcha_p ] ) ) {
                foreach ( $merged[ $captcha_p ] as $tk => $tv ) {
                    if ( is_scalar( $tv ) ) {
                        $merged[ $captcha_p ][ $tk ] = sanitize_text_field( (string) $tv );
                    }
                }
            }
        }

        // Normalise the uninstall data-deletion flag to a real bool. The settings
        // form posts a hidden "0" plus the checkbox "1", so the key is always
        // present and unchecking reliably turns it off.
        if ( array_key_exists( "delete_data_on_uninstall", $merged ) ) {
            $merged["delete_data_on_uninstall"] = ! empty( $merged["delete_data_on_uninstall"] )
                && $merged["delete_data_on_uninstall"] !== "0";
        }

        return Secrets::encrypt_recursive( Secrets::decrypt_public( $merged ) );
    }

    /** Recursively replace empty submitted secret-key values with the existing stored value. */
    private static function keep_existing_secrets( array $submitted, array $existing ): array {
        foreach ( $submitted as $k => $v ) {
            if ( is_array( $v ) ) {
                $submitted[ $k ] = self::keep_existing_secrets( $v, is_array( $existing[ $k ] ?? null ) ? $existing[ $k ] : [] );
            } elseif ( Secrets::looks_secret( (string) $k ) && $v === "" && ! empty( $existing[ $k ] ) ) {
                $submitted[ $k ] = $existing[ $k ];
            }
        }
        return $submitted;
    }

    public static function render_entries(): void {
        require TRINITY_FORMS_PATH . "admin/views/entries-list.php";
    }

    public static function render_settings(): void {
        require TRINITY_FORMS_PATH . "admin/views/settings.php";
    }

    public static function render_feedback(): void {
        require TRINITY_FORMS_PATH . "admin/views/feedback.php";
    }

    /**
     * Send an admin's in-dashboard feedback/bug report to the plugin author via the
     * site's own wp_mail() — no external HTTP, so it stays wordpress.org-clean.
     * Paired with admin/views/feedback.php. Honeypot + a light per-user rate limit
     * keep it from being abused as an open relay.
     */
    public static function handle_send_feedback(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }
        check_admin_referer( "trinity_forms_feedback" );

        $redirect = static function ( string $state ): void {
            wp_safe_redirect( admin_url( self::PARENT . "&page=" . self::FEEDBACK . "&ff_feedback=" . $state ) );
            exit;
        };

        // Honeypot: bots fill hidden fields. Pretend success without sending.
        if ( trim( (string) wp_unslash( $_POST["ff_website"] ?? "" ) ) !== "" ) {
            $redirect( "sent" );
        }

        // Light rate limit: 5 messages / hour / user.
        $rl_key = "trinity_forms_feedback_rl_" . get_current_user_id();
        $count  = (int) get_transient( $rl_key );
        if ( $count >= 5 ) {
            $redirect( "ratelimited" );
        }

        $type    = sanitize_key( wp_unslash( $_POST["ff_type"] ?? "other" ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST["ff_message"] ?? "" ) );
        $reply   = sanitize_email( wp_unslash( $_POST["ff_reply_email"] ?? "" ) );
        if ( $message === "" ) {
            $redirect( "error" );
        }

        $lines = [
            "Type: " . $type,
            "From site: " . home_url(),
            "Reply-to: " . ( is_email( $reply ) ? $reply : "(not provided)" ),
            "",
            $message,
        ];

        if ( ! empty( $_POST["ff_site_info"] ) ) {
            $theme   = wp_get_theme();
            $lines[] = "";
            $lines[] = "--- Site info ---";
            $lines[] = "WordPress: " . get_bloginfo( "version" );
            $lines[] = "PHP: " . PHP_VERSION;
            $lines[] = "Trinity Forms: " . TRINITY_FORMS_VERSION;
            $lines[] = "Theme: " . $theme->get( "Name" ) . " " . $theme->get( "Version" );
            $lines[] = "Site URL: " . home_url();
        }

        $recipient = (string) apply_filters( "trinity_forms_feedback_recipient", "support@jnkplugins.com" );
        /* translators: %s: feedback type (bug report, feature request, …). */
        $subject   = sprintf( __( "Trinity Forms feedback: %s", "trinity-forms" ), $type );
        $headers   = [];
        if ( is_email( $reply ) ) {
            $headers[] = "Reply-To: " . $reply;
        }

        $sent = wp_mail( $recipient, $subject, implode( "\n", $lines ), $headers );

        set_transient( $rl_key, $count + 1, HOUR_IN_SECONDS );

        $redirect( $sent ? "sent" : "error" );
    }

    public static function handle_delete_entry(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }
        $id = absint( wp_unslash( $_REQUEST["entry_id"] ?? 0 ) );
        check_admin_referer( "trinity_forms_delete_entry_" . $id );
        // Routed through the service so child rows (meta, notes, files) and the
        // before-delete hook are handled in one place rather than two.
        Entries::delete( [ $id ] );
        wp_safe_redirect( self::entries_url( [ "tf_done" => "deleted", "tf_n" => 1 ] ) );
        exit;
    }

    /**
     * Serve the standalone print view for one entry, before any admin chrome.
     *
     * Runs on admin_init rather than inside the page callback because by the time
     * the callback fires the admin header, menu and toolbar have already been
     * emitted — and the whole point of this view is that none of that is on the page.
     */
    public static function maybe_render_print_view(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render; gated on the capability check below, and printing an entry changes nothing.
        if ( empty( $_GET["tf_print"] ) || ( $_GET["page"] ?? "" ) !== self::ENTRIES ) {
            return;
        }
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( "You are not allowed to view this entry.", "trinity-forms" ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render, capability-checked above.
        $tf_print_entry_id = absint( wp_unslash( $_GET["entry"] ?? 0 ) );
        if ( $tf_print_entry_id < 1 ) {
            return;
        }

        require TRINITY_FORMS_PATH . "admin/views/entry-print.php";
        exit;
    }

    /**
     * Rebuild an Entries-screen URL, carrying the current filters through so an
     * action doesn't dump you back at an unfiltered list.
     *
     * @param array<string,mixed> $extra
     */
    public static function entries_url( array $extra = [] ): string {
        $carry = [];
        foreach ( [ "form_id", "view", "s", "from", "to", "payment_status", "paged", "entry" ] as $key ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter values echoed back into a redirect; each is sanitized below.
            $raw = $_REQUEST[ $key ] ?? null;
            if ( null === $raw || "" === $raw ) {
                continue;
            }
            $carry[ $key ] = in_array( $key, [ "form_id", "paged", "entry" ], true )
                ? absint( wp_unslash( $raw ) )
                : sanitize_text_field( wp_unslash( $raw ) );
        }

        return add_query_arg(
            array_merge( $carry, $extra ),
            admin_url( "edit.php?post_type=trinity_forms_form&page=" . self::ENTRIES )
        );
    }

    /**
     * Single-row actions from the entries list and the entry screen.
     *
     * One handler with a whitelisted verb rather than eight admin_post hooks —
     * every branch needs the same capability check, nonce check and redirect.
     */
    public static function handle_entry_action(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }

        $id     = absint( wp_unslash( $_REQUEST["entry_id"] ?? 0 ) );
        $verb   = sanitize_key( wp_unslash( $_REQUEST["verb"] ?? "" ) );
        check_admin_referer( "trinity_forms_entry_action_" . $id );

        if ( $id < 1 ) {
            wp_safe_redirect( self::entries_url() );
            exit;
        }

        $done = $verb;
        switch ( $verb ) {
            case "star":     Entries::set_starred( [ $id ], true );  break;
            case "unstar":   Entries::set_starred( [ $id ], false ); break;
            case "read":     Entries::set_read( [ $id ], true );     break;
            case "unread":   Entries::set_read( [ $id ], false );    break;
            case "spam":     Entries::set_status( [ $id ], "spam" );      break;
            case "trash":    Entries::set_status( [ $id ], "trash" );     break;
            case "restore":  Entries::set_status( [ $id ], "completed" ); break;
            case "delete":   Entries::delete( [ $id ] ); $done = "deleted"; break;
            case "resend":
                Notification_Engine::dispatch( $id );
                Entry_Notes::log( $id, sprintf(
                    /* translators: %s: display name of the user who resent the notification. */
                    __( "Notifications resent by %s.", "trinity-forms" ),
                    wp_get_current_user()->display_name
                ) );
                break;
            default:
                wp_safe_redirect( self::entries_url() );
                exit;
        }
        Activity::record( [ $id ], $verb );

        // Deleting or moving an entry out of the current view makes its detail page
        // a dead end, so those verbs go back to the list; the rest stay put.
        $back_to_list = in_array( $verb, [ "delete", "trash", "spam" ], true );
        $args         = [ "tf_done" => $done, "tf_n" => 1 ];
        if ( $back_to_list ) {
            $args["entry"] = null;
        }

        wp_safe_redirect( self::entries_url( $args ) );
        exit;
    }

    /** Bulk actions from the entries list. */
    public static function handle_entry_bulk(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }
        check_admin_referer( "trinity_forms_entry_bulk" );

        $verb = sanitize_key( wp_unslash( $_REQUEST["bulk_action"] ?? "" ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above by check_admin_referer.
        $ids  = array_map( "absint", (array) ( $_REQUEST["entry_ids"] ?? [] ) );
        $ids  = array_values( array_filter( $ids ) );

        if ( ! $ids || "" === $verb ) {
            wp_safe_redirect( self::entries_url( [ "tf_done" => "noop" ] ) );
            exit;
        }

        // These two answer with a document rather than a redirect, so they short-
        // circuit before the "what changed" bookkeeping below.
        if ( "export" === $verb ) {
            Entries::send_csv( Entries::to_csv( $ids ), "trinity-forms-selected.csv" );
        }
        if ( "print" === $verb ) {
            $tf_print_entry_ids = $ids;
            require TRINITY_FORMS_PATH . "admin/views/entry-print.php";
            exit;
        }

        $n = 0;
        switch ( $verb ) {
            case "read":    $n = Entries::set_read( $ids, true );      break;
            case "unread":  $n = Entries::set_read( $ids, false );     break;
            case "star":    $n = Entries::set_starred( $ids, true );   break;
            case "unstar":  $n = Entries::set_starred( $ids, false );  break;
            case "spam":    $n = Entries::set_status( $ids, "spam" );      break;
            case "trash":   $n = Entries::set_status( $ids, "trash" );     break;
            case "restore": $n = Entries::set_status( $ids, "completed" ); break;
            case "delete":  $n = Entries::delete( $ids );                  break;
            case "approve":
            case "reject":
                // Moderation lives in Pro; it answers with the number it changed.
                $n = (int) apply_filters( "trinity_forms_entry_bulk_moderate", 0, $ids, "approve" === $verb ? "approved" : "rejected" );
                break;
            case "resend":
                // Sending mail inline blocks the request, so anything beyond a
                // handful is queued. Action Scheduler ships with the plugin and is
                // already how integrations run.
                if ( count( $ids ) > 10 && function_exists( "as_enqueue_async_action" ) ) {
                    foreach ( $ids as $id ) {
                        as_enqueue_async_action( "trinity_forms_resend_notification", [ $id ] );
                    }
                } else {
                    foreach ( $ids as $id ) {
                        Notification_Engine::dispatch( $id );
                    }
                }
                foreach ( $ids as $id ) {
                    Entry_Notes::log( $id, sprintf(
                        /* translators: %s: display name of the user who resent the notification. */
                        __( "Notifications resent by %s.", "trinity-forms" ),
                        wp_get_current_user()->display_name
                    ) );
                }
                $n = count( $ids );
                break;
            default:
                wp_safe_redirect( self::entries_url( [ "tf_done" => "noop" ] ) );
                exit;
        }

        Activity::record( $ids, $verb );
        wp_safe_redirect( self::entries_url( [ "tf_done" => $verb, "tf_n" => $n ] ) );
        exit;
    }

    /**
     * Save an admin edit of an entry.
     *
     * Runs the submitted values through the very same Field::sanitize()/validate()
     * the front end uses, so an edit cannot put a value into an entry that the form
     * itself would have rejected. Conditional logic is deliberately NOT re-run:
     * hiding a field on the form is about what the visitor should answer, and an
     * admin correcting a record is a different job.
     */
    public static function handle_update_entry(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }

        $id = absint( wp_unslash( $_REQUEST["entry_id"] ?? 0 ) );
        check_admin_referer( "trinity_forms_update_entry_" . $id );

        $entry = Entries::get( $id );
        if ( ! $entry ) {
            wp_safe_redirect( self::entries_url() );
            exit;
        }

        $form = Form_Manager::get( (int) $entry["form_id"] );
        if ( ! $form ) {
            wp_safe_redirect( self::entries_url( [ "entry" => $id, "tf_done" => "noop" ] ) );
            exit;
        }

        $before     = Entries::values( $id );
        $uneditable = Entries::uneditable_types();
        $sanitized  = [];
        $errors     = [];

        // Field_Base::name() nests every input under trinity_forms_fields[<id>], and
        // this form is drawn by that same renderer — so the posted payload arrives in
        // the same envelope the front end and REST use, not as top-level keys.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; each value is handed to its own field sanitizer below, which is the only thing that knows its shape.
        $payload = (array) wp_unslash( $_POST["trinity_forms_fields"] ?? [] );

        // Two buckets on purpose: $sanitized is what may be written, $submitted is
        // every value as typed — including the rejected ones. Redrawing a failed edit
        // from $sanitized alone would silently restore the stored value under the
        // error message, so the field you were told to fix would look already correct.
        $submitted = [];

        foreach ( $form->fields() as $field ) {
            if ( in_array( $field->type, $uneditable, true ) ) {
                continue;
            }
            $raw   = $payload[ $field->id ] ?? null;
            $value = $field->sanitize( $raw );

            $submitted[ $field->id ] = $value;

            $field_errors = $field->validate( $value );
            if ( $field_errors ) {
                $errors[ $field->id ] = array_map(
                    static fn( $m ) => sprintf( "%s: %s", $field->label ?: $field->id, $m ),
                    $field_errors
                );
                continue;
            }
            $sanitized[ $field->id ] = $value;
        }

        if ( $errors ) {
            // Park the errors AND what they typed, so the redirect back to the editor
            // can redraw the form as they left it instead of as it is stored.
            set_transient(
                "trinity_forms_edit_errors_" . get_current_user_id() . "_" . $id,
                [ "fields" => $errors, "values" => $submitted ],
                5 * MINUTE_IN_SECONDS
            );
            wp_safe_redirect( self::entries_url( [ "entry" => $id, "tf_edit" => 1 ] ) );
            exit;
        }

        Entries::update_values( $id, $form, $sanitized );

        // Name the fields that actually moved. "Entry edited" tells you nothing six
        // months later; "Email, Priority" is the whole reason to keep an audit note.
        $changed = [];
        foreach ( $sanitized as $fid => $new ) {
            $old = $before[ $fid ] ?? null;
            if ( ! Entries::same_value( $old, $new ) ) {
                $label = $fid;
                foreach ( $form->fields() as $f ) {
                    if ( $f->id === $fid ) {
                        $label = $f->label ?: $f->id;
                        break;
                    }
                }
                $changed[] = $label;
            }
        }

        if ( $changed ) {
            Entry_Notes::log( $id, sprintf(
                /* translators: 1: user display name, 2: comma-separated list of field labels. */
                __( "Edited by %1\$s — changed: %2\$s.", "trinity-forms" ),
                wp_get_current_user()->display_name,
                implode( ", ", $changed )
            ) );

            // Only notifications explicitly attached to the "entry edited" event.
            // Firing the ordinary ones would email the visitor again every time
            // somebody fixed a typo on their submission.
            Notification_Engine::dispatch( $id, "entry_edited" );
        }

        wp_safe_redirect( self::entries_url( [
            "entry"   => $id,
            "tf_done" => $changed ? "edited" : "unchanged",
            "tf_n"    => count( $changed ),
        ] ) );
        exit;
    }

    /**
     * Run a Gravity Forms import.
     *
     * The report goes into a short transient rather than the URL: it carries a list
     * of skipped field names, which has no business in a query string.
     */
    public static function handle_gf_import(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }
        check_admin_referer( "trinity_forms_gf_import" );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $gf_form_id   = absint( wp_unslash( $_POST["gf_form_id"] ?? 0 ) );
        $with_entries = ! empty( $_POST["with_entries"] );
        $dry_run      = ! empty( $_POST["dry_run"] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $report = GF_Importer::import_form( $gf_form_id, $with_entries, $dry_run );
        $report["dry_run"] = $dry_run;
        set_transient( "trinity_forms_gf_import_report", $report, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( admin_url( self::PARENT . "&page=" . self::IMPORT . "&tf_imported=1" ) );
        exit;
    }

    /**
     * Run an import from any form builder (Importers\Registry). The form posts
     * source=<importer slug>:<that builder's form id>.
     */
    public static function handle_import(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }
        check_admin_referer( "trinity_forms_import" );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $source       = sanitize_text_field( wp_unslash( (string) ( $_POST["source"] ?? "" ) ) );
        $with_entries = ! empty( $_POST["with_entries"] );
        $dry_run      = ! empty( $_POST["dry_run"] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        [ $slug, $id ] = array_pad( explode( ":", $source, 2 ), 2, "" );
        $importer      = Importers\Registry::get( $slug );
        $report        = $importer && "" !== $id
            ? $importer->import( ctype_digit( $id ) ? (int) $id : $id, [ "with_entries" => $with_entries, "dry_run" => $dry_run ] )
            : [ "form_id" => 0, "title" => "", "fields" => 0, "entries" => 0, "skipped" => [], "notifications" => 0 ];
        $report["dry_run"] = $dry_run;
        $report["source"]  = $slug;
        set_transient( "trinity_forms_import_report_" . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( admin_url( self::PARENT . "&page=" . self::IMPORT . "&tf_imported=1" ) );
        exit;
    }

    /** Save which answer columns this user wants on the entries list for one form. */
    public static function handle_entry_columns(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }

        $form_id = absint( wp_unslash( $_REQUEST["form_id"] ?? 0 ) );
        check_admin_referer( "trinity_forms_entry_columns_" . $form_id );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above by check_admin_referer.
        $columns = array_map( "sanitize_text_field", (array) wp_unslash( $_POST["columns"] ?? [] ) );
        Entries::save_user_columns( $form_id, $columns );

        wp_safe_redirect( self::entries_url( [ "tf_done" => "columns" ] ) );
        exit;
    }

    /** Add a note to an entry. */
    public static function handle_add_note(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }
        $id = absint( wp_unslash( $_REQUEST["entry_id"] ?? 0 ) );
        check_admin_referer( "trinity_forms_add_note_" . $id );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above by check_admin_referer.
        $note = sanitize_textarea_field( wp_unslash( $_POST["note"] ?? "" ) );
        if ( "" !== trim( $note ) ) {
            Entry_Notes::add( $id, $note );
        }

        wp_safe_redirect( self::entries_url( [ "entry" => $id, "tf_done" => "note" ] ) . "#tf-notes" );
        exit;
    }

    /** Delete a user note (system notes are the audit trail and stay). */
    public static function handle_delete_note(): void {
        if ( ! current_user_can( self::CAP ) ) { wp_die( "forbidden" ); }
        $note_id  = absint( wp_unslash( $_REQUEST["note_id"] ?? 0 ) );
        $entry_id = absint( wp_unslash( $_REQUEST["entry_id"] ?? 0 ) );
        check_admin_referer( "trinity_forms_delete_note_" . $note_id );

        Entry_Notes::delete( $note_id );

        wp_safe_redirect( self::entries_url( [ "entry" => $entry_id, "tf_done" => "note_deleted" ] ) . "#tf-notes" );
        exit;
    }
}
