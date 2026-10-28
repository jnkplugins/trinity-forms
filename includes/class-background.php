<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Work a submission starts that the visitor should not wait for: integrations
 * (webhooks, CRMs, mailing lists), geolocation, delivery alerts.
 *
 * Trinity bundles no Action Scheduler; it uses one when another plugin has
 * loaded it. Action Scheduler runs its queue from WP-Cron, or from the owner's
 * wp-admin page views. On a host with DISABLE_WP_CRON and no server cron, a
 * queued webhook therefore waited until the owner next opened wp-admin (3.5
 * minutes in a test, days on a real site), and only on sites that happened to
 * have such a plugin. There the job now runs on the submit request itself, as
 * it always did without Action Scheduler: after the response where the server
 * can finish it early (PHP-FPM, LiteSpeed), otherwise at once.
 *
 * A job is either queued or run here, never both, so it is sent once.
 */
class Background {

    /**
     * Action Scheduler's WP-Cron event is due every minute. With WP-Cron off,
     * an event this many seconds overdue means no server cron is running it.
     */
    public const STALE_AFTER = 300;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> Jobs waiting for shutdown. */
    private static array $deferred = [];

    /**
     * Run do_action( $hook, ...$args ) off the visitor's request: queued when a
     * queue runner is known to work, otherwise here (after the response when possible).
     *
     * @param array<int, mixed> $args
     */
    public static function run( string $hook, array $args = [] ): void {
        if ( self::use_queue( $hook ) ) {
            as_enqueue_async_action( $hook, $args );
            return;
        }
        // Already in shutdown (a deferred notification failed and raised an
        // alert): the response is out, run now.
        if ( doing_action( "shutdown" ) || ! self::can_finish_early() ) {
            do_action( $hook, ...$args );
            return;
        }
        if ( ! self::$deferred ) {
            // After the notifications (priority 2), so mail goes first, as when queued.
            add_action( "shutdown", [ self::class, "run_deferred" ], 3 );
        }
        self::$deferred[] = [ $hook, $args ];
    }

    /** Whether $hook goes to Action Scheduler. */
    public static function use_queue( string $hook ): bool {
        if ( ! function_exists( "as_enqueue_async_action" ) ) {
            return false;
        }
        $runs = self::queue_runs( defined( "DISABLE_WP_CRON" ) && DISABLE_WP_CRON, self::queue_next_run(), time() );
        /**
         * Filter whether a background job is queued on Action Scheduler or run
         * on the request that started it. Only asked when Action Scheduler is loaded.
         *
         * @param bool   $queue Default: WP-Cron is on, or a server cron keeps the queue running.
         * @param string $hook  The job's action hook.
         */
        return (bool) apply_filters( "trinity_forms_queue_background_job", $runs, $hook );
    }

    /**
     * Is something running Action Scheduler's queue? WP-Cron on: yes, visitors
     * drive it. WP-Cron off: only if its every-minute event is not long overdue,
     * which means a server cron (or wp-cron.php hit from outside) runs it.
     *
     * @param int|false $next_run wp_next_scheduled( "action_scheduler_run_queue" ).
     */
    public static function queue_runs( bool $wp_cron_off, $next_run, int $now ): bool {
        if ( ! $wp_cron_off ) {
            return true;
        }
        return is_int( $next_run ) && $next_run > $now - self::STALE_AFTER;
    }

    /**
     * When WP-Cron next runs Action Scheduler's queue. Action Scheduler 3.0+
     * schedules the event with the argument "WP Cron", older copies without one.
     *
     * @return int|false
     */
    public static function queue_next_run() {
        $next = wp_next_scheduled( "action_scheduler_run_queue", [ "WP Cron" ] );
        return false !== $next ? $next : wp_next_scheduled( "action_scheduler_run_queue" );
    }

    /** A REST request on a server that can hand the response over before the work. */
    private static function can_finish_early(): bool {
        $can = defined( "REST_REQUEST" ) && REST_REQUEST
            && ( function_exists( "fastcgi_finish_request" ) || function_exists( "litespeed_finish_request" ) );
        /**
         * Filter whether a background job that is not queued runs after the response.
         *
         * @param bool $defer Default: a REST request on PHP-FPM or LiteSpeed.
         */
        return (bool) apply_filters( "trinity_forms_defer_background_job", $can );
    }

    /** shutdown: finish the visitor's response, then run the jobs. */
    public static function run_deferred(): void {
        $jobs           = self::$deferred;
        self::$deferred = [];
        if ( ! $jobs ) {
            return;
        }
        if ( function_exists( "fastcgi_finish_request" ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( "litespeed_finish_request" ) ) {
            litespeed_finish_request();
        }
        ignore_user_abort( true );
        foreach ( $jobs as [ $hook, $args ] ) {
            do_action( $hook, ...$args );
        }
    }
}
