<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one read-only count on the custom entries table for the overview tiles.

/**
 * Page chrome for every Trinity Forms admin screen: the branded top bar with the
 * plugin's own tabs, and a page header (title, one plain line, actions).
 *
 * Markup only. Menus, capabilities, nonces and REST routes are untouched, so a
 * screen behaves exactly as before; the WordPress submenu stays too, the tabs sit
 * alongside it. Styles: admin/assets/css/jnk-ui.css (the shared JnK kit, scoped
 * under .jnkui) plus Trinity's own admin.css (brand colour and screen details).
 *
 * The class name is Trinity's own on purpose: three JnK plugins can be active at
 * once, and two of them declaring the same class would be a fatal error.
 */
class Admin_UI {

    /** Screens drawn with the kit, by ?page= slug. */
    private const PAGES = [
        "trinity-forms-builder"      => "forms",
        "trinity-forms-templates"    => "templates",
        "trinity-forms-entries"      => "entries",
        "trinity-forms-results"      => "results",
        "trinity-forms-integrations" => "integrations",
        "trinity-forms-transfer"     => "transfer",
        "trinity-forms-import"       => "import",
        "trinity-forms-settings"     => "settings",
        "trinity-forms-feedback"     => "feedback",
        "trinity-forms-upgrade"      => "upgrade",
    ];

    /** Post-type list screens (edit.php) that get the same top bar and header. */
    private const LIST_SCREENS = [
        "edit-trinity_forms_form" => "forms",
        "edit-trinity_forms_view" => "views",
    ];

    public static function bootstrap(): void {
        add_filter( "admin_body_class", [ self::class, "body_class" ] );
        add_action( "in_admin_header", [ self::class, "list_screen_header" ] );
    }

    private static function page_url( string $page ): string {
        return admin_url( Admin::PARENT . "&page=" . $page );
    }

    /**
     * Main tabs: key => [ label, url, pro-only ].
     *
     * @return array<string, array{0:string,1:string,2:bool}>
     */
    private static function primary_tabs(): array {
        return [
            "forms"        => [ __( "Forms", "trinity-forms" ), admin_url( Admin::PARENT ), false ],
            "templates"    => [ __( "Templates", "trinity-forms" ), self::page_url( Templates::PAGE ), false ],
            "entries"      => [ __( "Entries", "trinity-forms" ), self::page_url( Admin::ENTRIES ), false ],
            "results"      => [ __( "Results", "trinity-forms" ), self::page_url( Results::PAGE ), false ],
            "views"        => [ __( "Views", "trinity-forms" ), admin_url( "edit.php?post_type=trinity_forms_view" ), true ],
            "integrations" => [ __( "Integrations", "trinity-forms" ), self::page_url( "trinity-forms-integrations" ), true ],
        ];
    }

    /** Less-used tools, under "More". */
    private static function more_tabs(): array {
        return [
            "transfer" => [ __( "Import / Export", "trinity-forms" ), self::page_url( Forms_List::TRANSFER_PAGE ), false ],
            "import"   => [ __( "Import from Gravity Forms", "trinity-forms" ), self::page_url( Admin::IMPORT ), false ],
            "feedback" => [ __( "Send feedback", "trinity-forms" ), self::page_url( Admin::FEEDBACK ), false ],
        ];
    }

    /** Which tab the current request belongs to, or "" when it is not one of ours. */
    public static function current(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks the active tab.
        $page = isset( $_GET["page"] ) ? sanitize_key( wp_unslash( $_GET["page"] ) ) : "";
        if ( "" !== $page ) {
            return self::PAGES[ $page ] ?? "";
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        return $screen ? ( self::LIST_SCREENS[ (string) $screen->id ] ?? "" ) : "";
    }

    private static function is_list_screen(): bool {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        return $screen && isset( self::LIST_SCREENS[ (string) $screen->id ] ) && empty( $_GET["page"] );
    }

    public static function body_class( string $classes ): string {
        if ( "" === self::current() ) {
            return $classes;
        }
        return $classes . " tf-ui" . ( self::is_list_screen() ? " tf-ui-list" : "" );
    }

    /**
     * Where a Pro tab points. A site that was never licensed gets the upgrade page
     * (free build) or Settings, where the licence key goes (Pro build).
     *
     * @return array{0:string,1:bool} URL and whether the tab is locked.
     */
    private static function href( string $url, bool $pro ): array {
        if ( ! $pro || License::is_pro_runtime() ) {
            return [ $url, false ];
        }
        return License::pro_installed()
            ? [ self::page_url( Admin::SETTINGS ), true ]
            : [ self::page_url( "trinity-forms-upgrade" ) . "&src=tab", true ];
    }

    private static function tab( string $key, array $tab, string $current, string $class = "jnkui-tab" ): string {
        [ $href, $locked ] = self::href( $tab[1], $tab[2] );
        $active = $key === $current;
        $pill   = $locked ? ' <span class="jnkui-pill is-pro">' . esc_html__( "Pro", "trinity-forms" ) . "</span>" : "";
        return '<a class="' . esc_attr( trim( $class . ( $active ? " is-active" : "" ) ) ) . '" href="' . esc_url( $href ) . '"'
            . ( $active ? ' aria-current="page"' : "" ) . ">" . esc_html( $tab[0] ) . $pill . "</a>";
    }

    /** The branded top bar with the in-page tabs. */
    public static function topbar(): string {
        $current = self::current();
        $more    = self::more_tabs();
        $in_more = isset( $more[ $current ] );

        $out  = '<div class="jnkui-topbar">';
        $out .= '<a class="jnkui-brand" href="' . esc_url( admin_url( Admin::PARENT ) ) . '">'
            . '<span class="jnkui-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h4"/></svg></span>'
            . "<b>" . esc_html__( "Trinity Forms", "trinity-forms" ) . "</b>"
            . '<span class="jnkui-ver">' . esc_html( "v" . TRINITY_FORMS_VERSION ) . "</span></a>";

        $out .= '<nav class="jnkui-tabs" aria-label="' . esc_attr__( "Trinity Forms sections", "trinity-forms" ) . '">';
        foreach ( self::primary_tabs() as $key => $tab ) {
            $out .= self::tab( $key, $tab, $current );
        }
        $out .= '<details class="jnkui-more"><summary class="jnkui-tab' . ( $in_more ? " is-active" : "" ) . '">'
            . esc_html__( "More", "trinity-forms" ) . ' <span aria-hidden="true">&#9662;</span></summary><div class="jnkui-menu">';
        foreach ( $more as $key => $tab ) {
            $out .= self::tab( $key, $tab, $current, "" );
        }
        $out .= "</div></details></nav>";

        $out .= '<div class="jnkui-topbar-end">'
            . '<a href="https://jnkplugins.com/trinity-forms/features.php" target="_blank" rel="noopener">' . esc_html__( "Help", "trinity-forms" ) . "</a>"
            . self::tab( "settings", [ __( "Settings", "trinity-forms" ), self::page_url( Admin::SETTINGS ), false ], $current )
            . "</div></div>";
        return $out;
    }

    /**
     * Open a screen. $actions is trusted HTML built by the caller (buttons).
     *
     * @param array{head?:bool,class?:string} $args head=false skips the page header (the Visual Builder has its own).
     */
    public static function open( string $title, string $description = "", string $actions = "", array $args = [] ): void {
        $class = trim( "wrap jnkui tf-ui-wrap " . (string) ( $args["class"] ?? "" ) );
        echo '<div class="' . esc_attr( $class ) . '">';
        echo self::topbar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* in topbar().

        // Admin notices are moved by WordPress to just after this marker.
        echo '<hr class="wp-header-end" style="display:none">';

        echo '<div class="jnkui-page">';
        if ( false !== ( $args["head"] ?? true ) ) {
            echo self::head( $title, $description, $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in head().
        }
    }

    public static function close(): void {
        echo "</div></div>";
    }

    /** The page header: title, one plain line, actions (trusted HTML). */
    public static function head( string $title, string $description = "", string $actions = "" ): string {
        $out = '<div class="jnkui-head"><div><h1>' . esc_html( $title ) . "</h1>";
        if ( "" !== $description ) {
            $out .= "<p>" . esc_html( $description ) . "</p>";
        }
        $out .= "</div>";
        if ( "" !== $actions ) {
            $out .= '<div class="jnkui-head-actions">' . $actions . "</div>";
        }
        return $out . "</div>";
    }

    /** A friendly empty state. $action is trusted HTML (a button) or "". */
    public static function empty_state( string $title, string $text, string $action = "", string $icon = "inbox" ): string {
        $icons = [
            "check" => '<path d="M20 6 9 17l-5-5"/>',
            "inbox" => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            "chart" => '<path d="M3 3v18h18"/><path d="M7 15v2"/><path d="M12 10v7"/><path d="M17 6v11"/>',
            "form"  => '<rect x="4" y="3" width="16" height="18" rx="2.5"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h4"/>',
            "search" => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        ];
        return '<div class="jnkui-empty"><div class="jnkui-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . ( $icons[ $icon ] ?? $icons["inbox"] ) . "</svg></div><h3>" . esc_html( $title ) . "</h3><p>" . esc_html( $text ) . "</p>" . $action . "</div>";
    }

    /** A status pill. $tone is one of Admin::pill_tone()'s tones. */
    public static function pill( string $label, string $tone = "neutral" ): string {
        $map = [
            "success" => "is-ok",
            "warning" => "is-warn",
            "danger"  => "is-bad",
            "info"    => "is-info",
            "brand"   => "is-brand",
        ];
        return '<span class="jnkui-pill ' . esc_attr( $map[ $tone ] ?? "" ) . '">' . esc_html( $label ) . "</span>";
    }

    /**
     * Top bar and header for the post-type list screens (Forms, Views).
     *
     * WordPress draws those screens itself, so there is no template to wrap. This
     * prints before WordPress' own .wrap; admin.css hides the duplicate core title
     * and "Add" link (the same link is in this header) and styles the list table.
     */
    public static function list_screen_header(): void {
        if ( ! self::is_list_screen() ) {
            return;
        }
        $key = self::current();
        echo '<div class="jnkui tf-ui-wrap tf-ui-listhead">';
        echo self::topbar(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* in topbar().
        echo '<div class="jnkui-page">';

        if ( "views" === $key ) {
            $type    = get_post_type_object( "trinity_forms_view" );
            $actions = $type && current_user_can( (string) $type->cap->create_posts )
                ? '<a class="button button-primary" href="' . esc_url( admin_url( "post-new.php?post_type=trinity_forms_view" ) ) . '">' . esc_html__( "New View", "trinity-forms" ) . "</a>"
                : "";
            echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in head().
                __( "Views", "trinity-forms" ),
                __( "Show entries on your site as a table, a list or cards. Put a View on any page with its shortcode.", "trinity-forms" ),
                $actions
            );
            echo "</div></div>";
            return;
        }

        $actions = '<a class="button" href="' . esc_url( self::page_url( Templates::PAGE ) ) . '">' . esc_html__( "Start from a template", "trinity-forms" ) . "</a>"
            . '<a class="button button-primary" href="' . esc_url( admin_url( "post-new.php?post_type=trinity_forms_form" ) ) . '">' . esc_html__( "New form", "trinity-forms" ) . "</a>";
        echo self::head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in head().
            __( "Forms", "trinity-forms" ),
            __( "Every form on your site. Open one to edit it, or copy its shortcode onto a page.", "trinity-forms" ),
            $actions
        );
        echo self::form_stats(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in form_stats().
        echo Onboarding::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render().
        echo "</div></div>";
    }

    /** Four overview tiles above the forms list. */
    private static function form_stats(): string {
        global $wpdb;
        $counts = wp_count_posts( "trinity_forms_form" );
        $live   = (int) ( $counts->publish ?? 0 );
        $drafts = (int) ( $counts->draft ?? 0 );
        $all    = Entries::counts( 0 );

        $counted = Entries::counted_statuses();
        $holds   = implode( ",", array_fill( 0, count( $counted ), "%s" ) );
        // submitted_at is stored in site time (current_time( "mysql" )), so compare in site time.
        $since   = wp_date( "Y-m-d H:i:s", time() - WEEK_IN_SECONDS );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders; table name from $wpdb->prefix.
        $week = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . Entries::table() . " WHERE submitted_at >= %s AND status IN ({$holds})", $since, ...$counted ) );

        $entries = self::page_url( Admin::ENTRIES );
        $tile    = static function ( string $label, int $value, string $foot, string $dot, string $href = "" ): string {
            $tag = "" !== $href ? "a" : "div";
            return "<{$tag} class=\"jnkui-stat\"" . ( "" !== $href ? ' href="' . esc_url( $href ) . '"' : "" ) . ">"
                . '<div class="jnkui-stat-label"><span class="jnkui-dot ' . esc_attr( $dot ) . '"></span>' . esc_html( $label ) . "</div>"
                . '<div class="jnkui-stat-value">' . esc_html( number_format_i18n( $value ) ) . "</div>"
                . '<div class="jnkui-stat-foot">' . esc_html( $foot ) . "</div></{$tag}>";
        };

        return '<div class="jnkui-grid tf-stats">'
            . $tile(
                __( "Live forms", "trinity-forms" ),
                $live,
                /* translators: %s: number of draft forms. */
                $drafts ? sprintf( _n( "Plus %s draft", "Plus %s drafts", $drafts, "trinity-forms" ), number_format_i18n( $drafts ) ) : __( "No drafts waiting", "trinity-forms" ),
                $live ? "is-ok" : "is-warn"
            )
            . $tile( __( "Entries", "trinity-forms" ), (int) $all["all"], __( "Kept in your own database", "trinity-forms" ), "is-brand", $entries )
            . $tile(
                __( "Unread", "trinity-forms" ),
                (int) $all["unread"],
                $all["unread"] ? __( "Waiting for you to open them", "trinity-forms" ) : __( "You are all caught up", "trinity-forms" ),
                $all["unread"] ? "is-warn" : "is-ok",
                add_query_arg( "view", "unread", $entries )
            )
            . $tile( __( "Last 7 days", "trinity-forms" ), $week, __( "New entries this week", "trinity-forms" ), "is-brand" )
            . "</div>";
    }
}
