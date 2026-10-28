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
 *
 * Jobs queued while the queue still looked alive (WP-Cron switched off, or a
 * server cron that died, less than STALE_AFTER ago) would still wait for wp-admin.
 * So whenever a job runs here, a few of those still-pending Trinity actions run
 * with it, claimed through Action Scheduler's own store first so a runner that
 * starts at the same moment cannot send them again.
 */
class Background {

    /**
     * Action Scheduler's WP-Cron event is due every minute. With WP-Cron off,
     * an event this many seconds overdue means no server cron is running it.
     */
    public const STALE_AFTER = 300;

    /** The hooks run() queues: only these are picked up from the queue. */
    public const HOOKS = [ "trinity_forms_run_integrations", "trinity_forms_geolocate_entry", "trinity_forms_send_alert" ];

    /** At most this many queued Trinity actions are picked up on one request. */
    public const PICK_UP_LIMIT = 5;

    /** @var array<int, array{0: string, 1: array<int, mixed>}> Jobs waiting for shutdown. */
    private static array $deferred = [];

    /** Pick up queued actions after the deferred jobs. */
    private static bool $pick_up = false;

    /** Queued actions were already picked up on this request. */
    private static bool $picked_up = false;

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
        $pick_up = self::should_pick_up();
        // Already in shutdown (a deferred notification failed and raised an
        // alert): the response is out, run now.
        if ( doing_action( "shutdown" ) || ! self::can_finish_early() ) {
            do_action( $hook, ...$args );
            if ( $pick_up ) {
                self::run_pending();
            }
            return;
        }
        if ( ! self::$deferred ) {
            // After the notifications (priority 2), so mail goes first, as when queued.
            add_action( "shutdown", [ self::class, "run_deferred" ], 3 );
        }
        self::$deferred[] = [ $hook, $args ];
        self::$pick_up    = self::$pick_up || $pick_up;
    }

    /**
     * Should this request also run Trinity actions left on the queue? Only when
     * nothing runs the queue (judged without the filter, so a site that opts out
     * of queueing while its cron works never competes with that cron), and once.
     */
    private static function should_pick_up(): bool {
        return ! self::$picked_up
            && function_exists( "as_enqueue_async_action" )
            && is_callable( [ "ActionScheduler", "store" ] )
            && is_callable( [ "ActionScheduler", "runner" ] )
            && ! self::queue_runs( defined( "DISABLE_WP_CRON" ) && DISABLE_WP_CRON, self::queue_next_run(), time() );
    }

    /**
     * Run Trinity actions that are due and still pending on Action Scheduler.
     * They are claimed first (the same claim a queue runner takes, so a runner
     * starting now skips them and an action a live runner holds is left alone),
     * then run through Action Scheduler, which marks them complete or failed.
     *
     * @param object|null $store  ActionScheduler_Store; tests pass a stand-in.
     * @param object|null $runner ActionScheduler_Abstract_QueueRunner; tests pass a stand-in.
     * @return int How many actions ran.
     */
    public static function run_pending( $store = null, $runner = null ): int {
        self::$picked_up = true;
        /**
         * Filter how many queued Trinity actions one request may pick up when
         * nothing runs Action Scheduler's queue. 0 turns the pick-up off.
         *
         * @param int $limit Default 5.
         */
        $limit = (int) apply_filters( "trinity_forms_background_pick_up_limit", self::PICK_UP_LIMIT );
        if ( $limit < 1 ) {
            return 0;
        }
        try {
            $store  = $store ?? \ActionScheduler::store();
            $runner = $runner ?? \ActionScheduler::runner();
            // stake_claim() keeps the hooks as the store's claim filter; put back
            // what was there so a later claim on this request is not narrowed.
            self::release_abandoned_claims( $store, $limit );
            $before = method_exists( $store, "get_claim_filter" ) ? $store->get_claim_filter( "hooks" ) : null;
            try {
                $claim = $store->stake_claim( $limit, null, self::HOOKS );
            } finally {
                if ( null !== $before && method_exists( $store, "set_claim_filter" ) ) {
                    $store->set_claim_filter( "hooks", $before );
                }
            }
        } catch ( \Throwable $e ) {
            return 0;
        }
        $ran      = 0;
        $claim_id = $claim->get_id();
        try {
            foreach ( $claim->get_actions() as $action_id ) {
                if ( $claim_id !== $store->get_claim_id( $action_id ) ) {
                    break; // Lost the claim (a cleaner reset it): leave the rest.
                }
                // A store that ignored the hook list must not make us run another plugin's work.
                $action = $store->fetch_action( $action_id );
                if ( ! in_array( $action->get_hook(), self::HOOKS, true ) ) {
                    $store->unclaim_action( $action_id );
                    continue;
                }
                $runner->process_action( $action_id, "Trinity Forms" );
                $ran++;
            }
        } catch ( \Throwable $e ) {
            // process_action() records an action's own failure; this is the store failing.
        } finally {
            $store->release_claim( $claim );
        }
        return $ran;
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

    /**
     * A runner that died mid-batch (the server restarted, a cron request was
     * killed) leaves its actions claimed and pending. Action Scheduler's own
     * cleaner frees such claims once they are older than its timeout (5 minutes),
     * but only when the queue runs, which is the thing that stopped. Do the same
     * here, for Trinity's hooks only and with the same timeout filter, so those
     * actions can be claimed below. A live runner renews nothing on an action it
     * still holds, but it never holds one that long: its batches end after 30 s.
     *
     * @param object $store ActionScheduler_Store.
     */
    private static function release_abandoned_claims( $store, int $limit ): void {
        if ( ! method_exists( $store, "query_actions" ) || ! method_exists( $store, "unclaim_action" ) ) {
            return;
        }
        $timeout = (int) apply_filters( "action_scheduler_timeout_period", 300 );
        if ( $timeout < 0 ) {
            return; // Action Scheduler's "never reset" setting.
        }
        $cutoff = new \DateTime( "@" . ( time() - $timeout ) );
        foreach ( self::HOOKS as $hook ) {
            $ids = $store->query_actions( [
                "hook"             => $hook,
                "status"           => "pending",
                "claimed"          => true,
                "modified"         => $cutoff,
                "modified_compare" => "<=",
                "per_page"         => $limit,
                "orderby"          => "none",
            ] );
            foreach ( (array) $ids as $action_id ) {
                $store->unclaim_action( $action_id );
                do_action( "action_scheduler_reset_action", $action_id );
            }
        }
    }

    /** shutdown: finish the visitor's response, then run the jobs. */
    public static function run_deferred(): void {
        $jobs           = self::$deferred;
        $pick_up        = self::$pick_up;
        self::$deferred = [];
        self::$pick_up  = false;
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
        if ( $pick_up ) {
            self::run_pending();
        }
    }
}
