<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Email check: did a form email leave the site?
 *
 * "No submission emails" is the first thing people hit with any form plugin, and
 * it was the first thing our own first user reported. WordPress only says
 * true/false; the reason (bad SMTP password, host blocks mail()) goes to the
 * wp_mail_failed action and was thrown away. This keeps it:
 *
 *   - the reason is written onto the entry's activity log,
 *   - recent failures are kept so the dashboard can warn about them,
 *   - a "Send a test email" button lets anyone check before a visitor finds out.
 *
 * Honest limit, and every message here says it the same way: a "true" from
 * wp_mail() only means the mail server (or the SMTP plugin) accepted the message.
 * A bounce after that is invisible to WordPress, so nothing here claims an email
 * was delivered.
 */
class Mail_Check {

    /** Recent failures, newest first: list of { at, form_id, entry_id, to, reason }. */
    public const FAILURES_OPTION = "trinity_forms_mail_failures";

    /** The last test email: { at, to, ok, reason }. Read by the getting-started checklist. */
    public const TEST_OPTION = "trinity_forms_mail_test";

    /** When a form email or a test was last handed to the mail server (unix time). */
    public const LAST_OK_OPTION = "trinity_forms_mail_last_ok";

    /** User meta: the newest failure time this person has dismissed the warning for. */
    public const DISMISS_META = "trinity_forms_mail_notice_dismissed";

    /** How far back the dashboard warning looks. */
    public const WINDOW = 7 * DAY_IN_SECONDS;

    /** How many failures are kept. Enough to show a pattern, small enough to autoload. */
    private const KEEP = 20;

    private static string $last_error = "";

    public static function register(): void {
        add_action( "wp_mail_failed", [ self::class, "capture" ] );
        add_action( "rest_api_init", [ self::class, "register_routes" ] );
        if ( is_admin() ) {
            add_action( "admin_notices", [ self::class, "render_notice" ] );
            add_action( "admin_post_trinity_forms_test_email", [ self::class, "handle_test_email" ] );
            add_action( "admin_post_trinity_forms_dismiss_mail_notice", [ self::class, "handle_dismiss" ] );
        }
    }

    /** wp_mail_failed listener: keep the reason for the send in progress. */
    public static function capture( $error ): void {
        if ( $error instanceof \WP_Error ) {
            self::$last_error = trim( wp_strip_all_tags( (string) $error->get_error_message() ) );
        }
    }

    /** Call right before wp_mail(), so a reason left over from another send is not reused. */
    public static function begin(): void {
        self::$last_error = "";
    }

    /** Why the last send failed, in the mail system's own words when it gave any. */
    public static function reason(): string {
        return "" !== self::$last_error
            ? self::$last_error
            : __( "The mail system turned it down without saying why.", "trinity-forms" );
    }

    /** Remember a failed form email for the dashboard warning. */
    public static function record_failure( int $form_id, int $entry_id, string $to, string $reason ): void {
        $list = self::all_failures();
        array_unshift( $list, [
            "at"       => time(),
            "form_id"  => $form_id,
            "entry_id" => $entry_id,
            "to"       => substr( $to, 0, 200 ),
            "reason"   => substr( $reason, 0, 300 ),
        ] );
        update_option( self::FAILURES_OPTION, array_slice( $list, 0, self::KEEP ), false );
    }

    /** Remember that mail went out, so older failures stop counting as the current state. */
    public static function record_success(): void {
        update_option( self::LAST_OK_OPTION, time(), false );
    }

    /**
     * Failures since mail last went out fine. Browser QA 18 Oct: the checklist
     * said "A form email could not be sent" right after a form email had
     * arrived, because a failure from before the mail setup was fixed was
     * still inside the 7-day window.
     *
     * @return list<array{at:int,form_id:int,entry_id:int,to:string,reason:string}>
     */
    public static function failures_since_last_success(): array {
        return self::recent_failures( (int) get_option( self::LAST_OK_OPTION, 0 ) );
    }

    /** @return list<array{at:int,form_id:int,entry_id:int,to:string,reason:string}> */
    private static function all_failures(): array {
        $list = get_option( self::FAILURES_OPTION, [] );
        return is_array( $list ) ? array_values( array_filter( $list, "is_array" ) ) : [];
    }

    /**
     * Failures newer than $since (and inside the warning window).
     *
     * @return list<array{at:int,form_id:int,entry_id:int,to:string,reason:string}>
     */
    public static function recent_failures( int $since = 0 ): array {
        $floor = max( $since, time() - self::WINDOW );
        return array_values( array_filter( self::all_failures(), static fn( $f ) => (int) ( $f["at"] ?? 0 ) > $floor ) );
    }

    /**
     * Send a test email and say plainly what happened.
     *
     * @return array{ok:bool,message:string}
     */
    public static function send_test( string $to ): array {
        $to = sanitize_email( $to );
        if ( ! is_email( $to ) ) {
            return [ "ok" => false, "message" => __( "That is not an email address.", "trinity-forms" ) ];
        }
        $site = wp_specialchars_decode( (string) get_bloginfo( "name" ), ENT_QUOTES );
        /* translators: %s: site name. */
        $subject = sprintf( __( "Test email from Trinity Forms on %s", "trinity-forms" ), $site );
        $body    = sprintf(
            /* translators: 1: site address, 2: date and time. */
            __( "This is a test sent from Trinity Forms on %1\$s at %2\$s.\n\nIf you are reading it, form emails from this site can reach this inbox. Nothing else to do.", "trinity-forms" ),
            home_url(),
            wp_date( get_option( "date_format" ) . " " . get_option( "time_format" ) )
        );

        self::begin();
        $ok     = wp_mail( $to, $subject, $body );
        $reason = $ok ? "" : self::reason();
        update_option( self::TEST_OPTION, [ "at" => time(), "to" => $to, "ok" => (bool) $ok, "reason" => $reason ], false );
        if ( $ok ) {
            self::record_success();
        }

        if ( $ok ) {
            return [
                "ok"      => true,
                "message" => sprintf(
                    /* translators: %s: email address. */
                    __( "Your site handed the test to its mail server for %s. Check that inbox, and the spam folder, in a few minutes. If it never shows up, the mail server is not passing it on: an SMTP plugin usually fixes that.", "trinity-forms" ),
                    $to
                ),
            ];
        }
        return [
            "ok"      => false,
            "message" => sprintf(
                /* translators: %s: the reason the mail system gave. */
                __( "Your site could not send it. Reason: %s", "trinity-forms" ),
                $reason
            ),
        ];
    }

    public static function register_routes(): void {
        register_rest_route( "trinity-forms/v1", "/mail-test", [
            "methods"             => "POST",
            "callback"            => [ self::class, "rest_test" ],
            "permission_callback" => static fn() => current_user_can( "manage_options" ),
            "args"                => [
                "to" => [ "type" => "string", "required" => true ],
            ],
        ] );
    }

    public static function rest_test( \WP_REST_Request $request ): \WP_REST_Response {
        $to = (string) $request->get_param( "to" );
        // A notification card can hold several addresses; the test goes to the first.
        $to = trim( (string) strtok( $to, "," ) );
        return new \WP_REST_Response( self::send_test( $to ), 200 );
    }

    /** Settings > Email check: the plain-form version of the test button. */
    public static function handle_test_email(): void {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( esc_html__( "You are not allowed to do this.", "trinity-forms" ) );
        }
        check_admin_referer( "trinity_forms_test_email" );
        $to     = isset( $_POST["to"] ) ? sanitize_email( wp_unslash( $_POST["to"] ) ) : "";
        $result = self::send_test( "" !== $to ? $to : (string) wp_get_current_user()->user_email );
        set_transient( "trinity_forms_mail_test_result_" . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( Admin::PARENT . "&page=" . Admin::SETTINGS . "#tf-mail-check" ) );
        exit;
    }

    public static function handle_dismiss(): void {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( esc_html__( "You are not allowed to do this.", "trinity-forms" ) );
        }
        check_admin_referer( "trinity_forms_dismiss_mail_notice" );
        update_user_meta( get_current_user_id(), self::DISMISS_META, time() );
        wp_safe_redirect( wp_get_referer() ?: admin_url( Admin::PARENT ) );
        exit;
    }

    /** The one-shot result of a test sent from Settings, for that screen to show. */
    public static function pop_test_result(): ?array {
        $key    = "trinity_forms_mail_test_result_" . get_current_user_id();
        $result = get_transient( $key );
        if ( ! is_array( $result ) ) {
            return null;
        }
        delete_transient( $key );
        return $result;
    }

    /**
     * Warn on the dashboard and Trinity screens when form emails failed recently.
     * Shown until dismissed; a new failure after the dismissal brings it back.
     */
    public static function render_notice(): void {
        if ( ! current_user_can( "manage_options" ) ) {
            return;
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : "";
        if ( "dashboard" !== $id && false === strpos( $id, "trinity" ) ) {
            return;
        }
        $failures = self::recent_failures( (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) );
        if ( ! $failures ) {
            return;
        }
        $latest  = $failures[0];
        $count   = count( $failures );
        $entry   = (int) ( $latest["entry_id"] ?? 0 );
        $dismiss = wp_nonce_url( admin_url( "admin-post.php?action=trinity_forms_dismiss_mail_notice" ), "trinity_forms_dismiss_mail_notice" );
        ?>
        <div class="notice notice-warning tf-mail-notice">
            <p><strong><?php
                echo esc_html( sprintf(
                    /* translators: %s: number of emails. */
                    _n( "%s form email could not be sent in the last 7 days.", "%s form emails could not be sent in the last 7 days.", $count, "trinity-forms" ),
                    number_format_i18n( $count )
                ) );
            ?></strong>
            <?php
            echo esc_html( sprintf(
                /* translators: 1: email address, 2: the reason the mail system gave. */
                __( "The latest was to %1\$s. Reason: %2\$s", "trinity-forms" ),
                (string) ( $latest["to"] ?? "" ),
                (string) ( $latest["reason"] ?? "" )
            ) );
            ?></p>
            <p>
                <?php if ( $entry ) : ?>
                    <a class="button" href="<?php echo esc_url( Admin::entries_url( [ "entry" => $entry ] ) ); ?>"><?php esc_html_e( "See the entry", "trinity-forms" ); ?></a>
                <?php endif; ?>
                <a class="button" href="<?php echo esc_url( admin_url( Admin::PARENT . "&page=" . Admin::SETTINGS . "#tf-mail-check" ) ); ?>"><?php esc_html_e( "Send a test email", "trinity-forms" ); ?></a>
                <a href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( "Dismiss", "trinity-forms" ); ?></a>
            </p>
        </div>
        <?php
    }
}
