<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- two read-only existence checks.

/**
 * The first ten minutes: a short checklist on the forms list, and a "Create a page
 * with this form" button.
 *
 * Every tick comes from real state (a form exists, a published page holds one, an
 * entry came in), except "the email arrived": only the person reading the inbox
 * knows that, so that step is ticked by them. No wizard, nothing installed, nothing
 * pre-ticked.
 */
class Onboarding {

    public const OPTION        = "trinity_forms_onboarding";
    public const CREATE_ACTION = "trinity_forms_create_page";
    public const STEP_ACTION   = "trinity_forms_onboarding";

    public static function register(): void {
        add_action( "admin_post_" . self::CREATE_ACTION, [ self::class, "handle_create_page" ] );
        add_action( "admin_post_" . self::STEP_ACTION, [ self::class, "handle_step" ] );
        add_filter( "post_row_actions", [ self::class, "row_action" ], 20, 2 );
        add_filter( "trinity_forms_builder_config", [ self::class, "builder_config" ], 10, 2 );
    }

    /** @return array{email_ok?:bool,dismissed?:bool} */
    private static function state(): array {
        $s = get_option( self::OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    /** The newest form, which the checklist's buttons act on. */
    public static function latest_form_id(): int {
        $ids = get_posts( [
            "post_type"      => "trinity_forms_form",
            "post_status"    => [ "publish", "draft" ],
            "posts_per_page" => 1,
            "orderby"        => "date",
            "order"          => "DESC",
            "fields"         => "ids",
        ] );
        return $ids ? (int) $ids[0] : 0;
    }

    /** Published content (any post type) that holds a Trinity form: block or shortcode. */
    public static function form_on_a_page( string $status = "publish" ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_status = %s AND post_type NOT IN ('trinity_forms_form','revision','trinity_forms_view')
                AND ( post_content LIKE %s OR post_content LIKE %s )
              ORDER BY ID DESC LIMIT 1",
            $status,
            "%" . $wpdb->esc_like( "<!-- wp:trinity-forms/form" ) . "%",
            "%" . $wpdb->esc_like( "[trinity-forms " ) . "%"
        ) );
    }

    private static function has_entry(): bool {
        return (int) ( Entries::counts( 0 )["all"] ?? 0 ) > 0;
    }

    /**
     * The four steps and whether each is done.
     *
     * @return array<string,bool> form, page, entry, email
     */
    public static function steps(): array {
        $counts = wp_count_posts( "trinity_forms_form" );
        return [
            "form"  => ( (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 ) ) > 0,
            "page"  => self::form_on_a_page() > 0,
            "entry" => self::has_entry(),
            "email" => ! empty( self::state()["email_ok"] ),
        ];
    }

    /** Shown once a form exists, until every step is done or it is dismissed. */
    public static function visible(): bool {
        if ( ! current_user_can( "edit_posts" ) || ! empty( self::state()["dismissed"] ) ) {
            return false;
        }
        $steps = self::steps();
        return $steps["form"] && in_array( false, $steps, true );
    }

    private static function step_url( string $step ): string {
        return add_query_arg( [ "action" => self::STEP_ACTION, "step" => $step, "_wpnonce" => wp_create_nonce( self::STEP_ACTION ) ], admin_url( "admin-post.php" ) );
    }

    public static function create_page_url( int $form_id ): string {
        // Raw (not wp_nonce_url's &amp;): the Visual Builder uses it as a JS href.
        return add_query_arg( [ "action" => self::CREATE_ACTION, "form_id" => $form_id, "_wpnonce" => wp_create_nonce( self::CREATE_ACTION . "_" . $form_id ) ], admin_url( "admin-post.php" ) );
    }

    /** The checklist card (trusted HTML), or "" when it should not show. */
    public static function render(): string {
        if ( ! self::visible() ) {
            return "";
        }
        $steps   = self::steps();
        $form_id = self::latest_form_id();
        $draft   = $steps["page"] ? 0 : self::form_on_a_page( "draft" );
        $done    = count( array_filter( $steps ) );

        $items = [];

        $items[] = [ $steps["form"], __( "Create a form", "trinity-forms" ), __( "Done. You can add more from a template at any time.", "trinity-forms" ), "" ];

        if ( $steps["page"] ) {
            $page_action = "";
        } elseif ( $draft ) {
            $page_action = '<a class="button button-small" href="' . esc_url( (string) get_edit_post_link( $draft ) ) . '">' . esc_html__( "Open the draft page and publish it", "trinity-forms" ) . "</a>";
        } else {
            $page_action = $form_id ? '<a class="button button-small" href="' . esc_url( self::create_page_url( $form_id ) ) . '">' . esc_html__( "Create a page with this form", "trinity-forms" ) . "</a>" : "";
        }
        $items[] = [
            $steps["page"],
            __( "Put it on a page", "trinity-forms" ),
            $draft && ! $steps["page"]
                ? __( "A draft page with your form is waiting. It goes live when you publish it.", "trinity-forms" )
                : __( "Make a page that holds the form, or paste its shortcode into any page.", "trinity-forms" ),
            $page_action,
        ];

        $live = $steps["page"] ? get_permalink( self::form_on_a_page() ) : "";
        $items[] = [
            $steps["entry"],
            __( "Send a test entry", "trinity-forms" ),
            __( "Fill the form in yourself, ideally in a private window, the way a visitor would.", "trinity-forms" ),
            $live && ! $steps["entry"] ? '<a class="button button-small" href="' . esc_url( $live ) . '" target="_blank" rel="noopener">' . esc_html__( "Open the page", "trinity-forms" ) . "</a>" : "",
        ];

        $failed  = Mail_Check::failures_since_last_success();
        $items[] = [
            $steps["email"],
            __( "Check the email arrived", "trinity-forms" ),
            $failed
                ? __( "A form email could not be sent. Send a test email to see why.", "trinity-forms" )
                : __( "Look in the inbox the form sends to, and the spam folder. Only you can see whether it got there.", "trinity-forms" ),
            $steps["email"] ? "" : '<a class="button button-small" href="' . esc_url( self::step_url( "email_ok" ) ) . '">' . esc_html__( "It arrived", "trinity-forms" ) . "</a> "
                . '<a class="button button-small" href="' . esc_url( admin_url( Admin::PARENT . "&page=" . Admin::SETTINGS . "#tf-mail-check" ) ) . '">' . esc_html__( "Send a test email", "trinity-forms" ) . "</a>",
        ];

        $out  = '<div class="jnkui-card tf-onboarding">';
        $out .= '<div class="jnkui-card-head"><div><h2>' . esc_html__( "Your first ten minutes", "trinity-forms" ) . "</h2><p>"
            /* translators: 1: steps done, 2: total steps. */
            . esc_html( sprintf( __( "%1\$d of %2\$d done. Each step ticks itself once it has really happened.", "trinity-forms" ), $done, count( $steps ) ) )
            . '</p></div><a class="tf-onboarding-dismiss" href="' . esc_url( self::step_url( "dismiss" ) ) . '">' . esc_html__( "Hide this", "trinity-forms" ) . "</a></div>";
        $out .= '<ol class="jnkui-steps">';
        foreach ( $items as [ $is_done, $title, $text, $action ] ) {
            $out .= "<li" . ( $is_done ? ' class="is-done"' : "" ) . "><strong>" . esc_html( $title ) . "</strong>"
                . '<span class="jnkui-muted">' . esc_html( $text ) . "</span>"
                . ( $action ? '<div class="tf-onboarding-action">' . $action . "</div>" : "" ) . "</li>";
        }
        $out .= "</ol></div>";
        return $out;
    }

    public static function handle_step(): void {
        if ( ! current_user_can( "edit_posts" ) ) {
            wp_die( esc_html__( "You are not allowed to do this.", "trinity-forms" ) );
        }
        check_admin_referer( self::STEP_ACTION );
        $step  = isset( $_GET["step"] ) ? sanitize_key( wp_unslash( $_GET["step"] ) ) : "";
        $state = self::state();
        if ( "email_ok" === $step ) {
            $state["email_ok"] = true;
        } elseif ( "dismiss" === $step ) {
            $state["dismissed"] = true;
        }
        update_option( self::OPTION, $state, false );
        wp_safe_redirect( admin_url( Admin::PARENT ) );
        exit;
    }

    /**
     * Make a draft page holding the form block and open it in the editor. A
     * draft, so nothing goes live until the person presses Publish.
     */
    public static function create_page( int $form_id ): int {
        $form = get_post( $form_id );
        if ( ! $form || "trinity_forms_form" !== $form->post_type ) {
            return 0;
        }
        $page_id = wp_insert_post( [
            "post_type"    => "page",
            "post_status"  => "draft",
            "post_title"   => $form->post_title ?: __( "Contact", "trinity-forms" ),
            "post_content" => '<!-- wp:trinity-forms/form {"formId":' . (int) $form_id . '} /-->',
        ], true );
        return is_wp_error( $page_id ) ? 0 : (int) $page_id;
    }

    public static function handle_create_page(): void {
        $form_id = isset( $_GET["form_id"] ) ? absint( $_GET["form_id"] ) : 0;
        check_admin_referer( self::CREATE_ACTION . "_" . $form_id );
        if ( ! current_user_can( "edit_pages" ) || ! current_user_can( "edit_post", $form_id ) ) {
            wp_die( esc_html__( "You are not allowed to create pages.", "trinity-forms" ) );
        }
        $page_id = self::create_page( $form_id );
        if ( ! $page_id ) {
            wp_die( esc_html__( "The page could not be created.", "trinity-forms" ) );
        }
        wp_safe_redirect( (string) get_edit_post_link( $page_id, "raw" ) );
        exit;
    }

    public static function row_action( array $actions, $post ): array {
        if ( $post instanceof \WP_Post && "trinity_forms_form" === $post->post_type && current_user_can( "edit_pages" ) ) {
            $actions["trinity_forms_create_page"] = '<a href="' . esc_url( self::create_page_url( (int) $post->ID ) ) . '">' . esc_html__( "Create a page", "trinity-forms" ) . "</a>";
        }
        return $actions;
    }

    public static function builder_config( array $config, int $form_id ): array {
        if ( $form_id > 0 && current_user_can( "edit_pages" ) ) {
            $config["createPageUrl"] = self::create_page_url( $form_id );
        }
        return $config;
    }
}
