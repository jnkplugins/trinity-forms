<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Lightweight form analytics (free): a views → submissions funnel with a
 * completion rate, per form. Views are counted when a form renders on the front
 * end; submissions when an entry completes. Counters live in post meta, so there
 * is no extra table and no external service.
 */
class Analytics {

    private const VIEWS = "_trinity_forms_views";
    private const SUBS  = "_trinity_forms_submissions";

    public static function register(): void {
        add_action( "trinity_forms_form_rendered",  [ __CLASS__, "record_view" ], 10, 1 );
        add_action( "trinity_forms_entry_completed", [ __CLASS__, "record_submission" ], 5, 1 );

        // A compact funnel column on the Forms list.
        add_filter( "manage_trinity_forms_form_posts_columns",       [ __CLASS__, "add_column" ] );
        add_action( "manage_trinity_forms_form_posts_custom_column", [ __CLASS__, "render_column" ], 10, 2 );
    }

    public static function record_view( int $form_id ): void {
        if ( is_admin() || $form_id <= 0 ) {
            return; // count public views only
        }
        self::increment( $form_id, self::VIEWS );
    }

    public static function record_submission( int $entry_id ): void {
        if ( $entry_id <= 0 ) {
            return;
        }
        global $wpdb;
        $form_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT form_id FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $entry_id
        ) );
        if ( $form_id > 0 ) {
            self::increment( $form_id, self::SUBS );
        }
    }

    /** @return array{views:int,submissions:int,rate:float} */
    public static function stats( int $form_id ): array {
        global $wpdb;
        $views = (int) get_post_meta( $form_id, self::VIEWS, true );
        // Counted from the entries table rather than the running counter: the counter
        // never went down when an entry was marked spam or deleted, so the funnel and
        // the Entries column drifted apart.
        $counted = Entries::counted_statuses();
        $holds   = implode( ",", array_fill( 0, count( $counted ), "%s" ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $subs  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}trinity_forms_entries WHERE form_id = %d AND status IN ({$holds})", $form_id, ...$counted ) );
        $rate  = $views > 0 ? round( $subs / $views * 100, 1 ) : 0.0;
        return [ "views" => $views, "submissions" => $subs, "rate" => $rate ];
    }

    private static function increment( int $form_id, string $key ): void {
        $current = (int) get_post_meta( $form_id, $key, true );
        update_post_meta( $form_id, $key, $current + 1 );
    }

    public static function add_column( array $columns ): array {
        // Insert before the date column when present.
        $out = [];
        foreach ( $columns as $key => $label ) {
            if ( $key === "date" ) {
                $out["trinity_forms_stats"] = __( "Funnel", "trinity-forms" );
            }
            $out[ $key ] = $label;
        }
        if ( ! isset( $out["trinity_forms_stats"] ) ) {
            $out["trinity_forms_stats"] = __( "Funnel", "trinity-forms" );
        }
        return $out;
    }

    public static function render_column( string $column, int $post_id ): void {
        if ( $column !== "trinity_forms_stats" ) {
            return;
        }
        $s = self::stats( $post_id );
        // Views are only counted from the version that added them, so an older form
        // can have more completions than views. A rate from that ("0% completion"
        // next to 12 completed) is nonsense; say what is missing instead.
        if ( (int) $s["views"] < (int) $s["submissions"] ) {
            printf(
                /* translators: %d: submissions. */
                esc_html__( "%d completed · rate shows once views have been counted for a while", "trinity-forms" ),
                (int) $s["submissions"]
            );
            return;
        }
        printf(
            /* translators: 1: views, 2: submissions, 3: completion rate. */
            esc_html__( "%1\$d views · %2\$d completed · %3\$s%% completion", "trinity-forms" ),
            (int) $s["views"],
            (int) $s["submissions"],
            esc_html( (string) $s["rate"] )
        );
    }
}
