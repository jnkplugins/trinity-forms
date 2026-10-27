<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time conversion of our own tables; table and column names come from the constant list below.

/**
 * One-time conversion of every stored time from site time to UTC (2.15.0).
 *
 * Up to 2.14 the tables held current_time( "mysql" ), so a time zone change, a
 * daylight-saving hour or data copied between sites shifted every date. From
 * 2.15 they hold UTC, the way WordPress keeps post_date_gmt.
 *
 * - Runs on admin_init (Activator::maybe_upgrade) until it is done; fresh
 *   installs skip it (Activator::activate sets the flag).
 * - Per column, one UPDATE whose CASE picks the offset from the site zone's
 *   transitions (wp_timezone()->getTransitions()), so summer and winter rows each
 *   get their own offset and there is no per-row PHP loop.
 * - Progress is saved after each column, so a timeout resumes at the next column
 *   instead of shifting one twice. A lock keeps two requests from running at once.
 * - Only rows that existed when 2.15 first loaded are converted (the boundary):
 *   anything written after that is already UTC.
 * - Entries imported from Gravity Forms kept GF's UTC date and are skipped.
 * - Dates::UTC_OPTION is set at the end; Entry Vault reads it to know which
 *   convention Trinity's data uses.
 */
class Utc_Migration {

    /** Progress: [ "boundary" => [table => max id], "done" => ["table.column", …] ]. */
    public const STATE_OPTION = "trinity_forms_utc_migration";
    public const LOCK_OPTION  = "trinity_forms_utc_lock";
    private const LOCK_TTL    = 600;

    /** Every DATETIME we write, in the order they are converted. */
    public const COLUMNS = [
        [ "entries", "submitted_at" ],
        [ "entries", "trashed_at" ],
        [ "entry_notes", "created_at" ],
        [ "files", "uploaded_at" ],
        [ "logs", "created_at" ],
    ];

    /** Post meta holding a copy of an entry time (written by the Pro delivery alerts). */
    private const QUIET_META = "_trinity_forms_quiet_alerted";

    /**
     * Remember the newest row of each table the first time this code runs, before
     * it writes anything: rows above that are UTC already. First writer wins.
     */
    public static function mark_boundary(): void {
        if ( Dates::stored_utc() ) {
            return;
        }
        $state = get_option( self::STATE_OPTION );
        if ( is_array( $state ) && isset( $state["boundary"] ) ) {
            return;
        }
        if ( "" === (string) get_option( "trinity_forms_version", "" ) ) {
            return; // Not installed yet: activation marks a fresh install as UTC.
        }
        global $wpdb;
        $boundary = [];
        $suppress = $wpdb->suppress_errors( true );
        foreach ( array_unique( array_column( self::COLUMNS, 0 ) ) as $table ) {
            $boundary[ $table ] = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}trinity_forms_{$table}" );
        }
        $wpdb->suppress_errors( $suppress );
        self::insert_once( self::STATE_OPTION, [ "boundary" => $boundary, "done" => [] ], "yes" );
    }

    /**
     * Convert what is left. Safe to call on every admin page: returns at once when
     * done, or when another request holds the lock.
     *
     * @return bool True when everything is UTC.
     */
    public static function run(): bool {
        if ( Dates::stored_utc() ) {
            return true;
        }
        if ( ! self::lock() ) {
            return false;
        }
        try {
            self::mark_boundary();
            $state = get_option( self::STATE_OPTION );
            if ( ! is_array( $state ) || ! isset( $state["boundary"] ) ) {
                return false;
            }
            $state["done"] = (array) ( $state["done"] ?? [] );
            if ( function_exists( "set_time_limit" ) ) {
                @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled; the work resumes either way.
            }

            foreach ( self::COLUMNS as [ $table, $column ] ) {
                $key = $table . "." . $column;
                if ( in_array( $key, $state["done"], true ) ) {
                    continue;
                }
                self::convert_column( $table, $column, (int) ( $state["boundary"][ $table ] ?? 0 ) );
                $state["done"][] = $key;
                update_option( self::STATE_OPTION, $state, true );
            }

            if ( ! in_array( "quiet_meta", $state["done"], true ) ) {
                self::convert_quiet_meta();
                $state["done"][] = "quiet_meta";
                update_option( self::STATE_OPTION, $state, true );
            }

            update_option( Dates::UTC_OPTION, 1, true );
            delete_option( self::STATE_OPTION );
            return true;
        } finally {
            self::unlock();
        }
    }

    /**
     * Shift one column from site time to UTC in a single UPDATE.
     *
     * @return int Rows changed.
     */
    public static function convert_column( string $table, string $column, int $max_id ): int {
        global $wpdb;
        if ( $max_id < 1 ) {
            return 0;
        }
        $t = $wpdb->prefix . "trinity_forms_" . $table;

        $where = $wpdb->prepare( "{$column} IS NOT NULL AND {$column} >= '1971-01-01' AND id <= %d", $max_id );
        if ( "entries" === $table && "submitted_at" === $column ) {
            // Imported from Gravity Forms with GF's UTC date: already right.
            $skip = array_map( "intval", GF_Importer::imported_entry_ids() );
            if ( $skip ) {
                $where .= " AND id NOT IN (" . implode( ",", $skip ) . ")";
            }
        }

        $range = $wpdb->get_row( "SELECT MIN({$column}) AS lo, MAX({$column}) AS hi FROM {$t} WHERE {$where}", ARRAY_A );
        if ( empty( $range["lo"] ) ) {
            return 0;
        }
        $utc = new \DateTimeZone( "UTC" );
        $lo  = ( new \DateTimeImmutable( (string) $range["lo"], $utc ) )->getTimestamp() - 2 * DAY_IN_SECONDS;
        $hi  = ( new \DateTimeImmutable( (string) $range["hi"], $utc ) )->getTimestamp() + 2 * DAY_IN_SECONDS;

        $ranges = self::ranges( wp_timezone(), $lo, $hi );
        if ( ! array_filter( array_column( $ranges, "offset" ) ) ) {
            return 0; // A UTC site: nothing to shift.
        }

        // CASE WHEN col < bound1 THEN offset0 WHEN col < bound2 THEN offset1 … ELSE offsetN.
        // Every row is shifted by the offset of its own range, read before the
        // UPDATE touches it, so a shifted value can never be shifted again.
        $last = count( $ranges ) - 1;
        if ( 0 === $last ) {
            $case = (string) (int) $ranges[0]["offset"];
        } else {
            $case = "CASE";
            foreach ( $ranges as $i => $r ) {
                $case .= $i === $last
                    ? $wpdb->prepare( " ELSE %d END", $r["offset"] )
                    : $wpdb->prepare( " WHEN {$column} < %s THEN %d", gmdate( "Y-m-d H:i:s", $r["until"] ), $r["offset"] );
            }
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value above went through prepare().
        $n = $wpdb->query( "UPDATE {$t} SET {$column} = DATE_SUB({$column}, INTERVAL ({$case}) SECOND) WHERE {$where}" );
        return (int) $n;
    }

    /**
     * The site zone's constant-offset ranges between two moments, as local wall
     * clock bounds: [ [ "offset" => seconds, "until" => local-time bound ], … ].
     * The last range has no bound.
     *
     * A local time that happens twice (clocks going back) or never (clocks going
     * forward) is read the way PHP, and so get_gmt_from_date(), reads it; PHP does
     * not pick the same side in every zone, so each bound is found by asking it.
     *
     * @return array<int,array{offset:int,until:int}>
     */
    public static function ranges( \DateTimeZone $tz, int $from, int $to ): array {
        $transitions = $tz->getTransitions( $from, $to );
        if ( ! is_array( $transitions ) || ! $transitions ) {
            // A fixed offset ("UTC+5:30"): one range.
            return [ [ "offset" => $tz->getOffset( new \DateTimeImmutable( "@" . $from ) ), "until" => 0 ] ];
        }
        $out = [];
        foreach ( $transitions as $i => $tr ) {
            $offset = (int) $tr["offset"];
            if ( $out && $out[ count( $out ) - 1 ]["offset"] === $offset ) {
                continue; // Only the abbreviation changed.
            }
            if ( $out ) {
                $prev                              = $out[ count( $out ) - 1 ]["offset"];
                $out[ count( $out ) - 1 ]["until"] = self::bound( $tz, (int) $tr["ts"], $prev, $offset );
            }
            $out[] = [ "offset" => $offset, "until" => 0 ];
        }
        return $out;
    }

    /**
     * The first local wall-clock time (as seconds, read as if UTC) that PHP reads
     * with the offset after a change at $ts. It lies within the hour around the
     * change; a binary search over that window costs a dozen lookups.
     */
    private static function bound( \DateTimeZone $tz, int $ts, int $prev, int $next ): int {
        $offset_at = static function ( int $local ) use ( $tz ): int {
            // The shift PHP applies, not getOffset(): a time inside the gap is moved
            // forward and reports the new offset, but its UTC moment uses the old one.
            return $local - ( new \DateTimeImmutable( gmdate( "Y-m-d H:i:s", $local ), $tz ) )->getTimestamp();
        };
        $lo = $ts + min( $prev, $next );
        $hi = $ts + max( $prev, $next );
        if ( $offset_at( $lo ) === $next ) {
            return $lo;
        }
        while ( $hi - $lo > 1 ) {
            $mid = intdiv( $lo + $hi, 2 );
            if ( $offset_at( $mid ) === $next ) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        return $hi;
    }

    /** Delivery alerts keep the last entry's time to avoid repeating an alert. */
    private static function convert_quiet_meta(): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::QUIET_META ), ARRAY_A );
        foreach ( (array) $rows as $r ) {
            $value = (string) $r["meta_value"];
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
                continue;
            }
            $wpdb->update( $wpdb->postmeta, [ "meta_value" => get_gmt_from_date( $value ) ], [ "meta_id" => (int) $r["meta_id"] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
            wp_cache_delete( (int) $r["post_id"], "post_meta" );
        }
    }

    /** INSERT IGNORE: only the first request to get here writes the option. */
    private static function insert_once( string $name, $value, string $autoload ): bool {
        global $wpdb;
        $ok = (int) $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $name,
            maybe_serialize( $value ),
            $autoload
        ) );
        wp_cache_delete( $name, "options" );
        wp_cache_delete( "alloptions", "options" );
        wp_cache_delete( "notoptions", "options" );
        return 1 === $ok;
    }

    private static function lock(): bool {
        if ( self::insert_once( self::LOCK_OPTION, time(), "no" ) ) {
            return true;
        }
        // A request that died holding the lock: take it over after a while.
        $held = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $held && time() - $held > self::LOCK_TTL ) {
            self::unlock();
            return self::insert_once( self::LOCK_OPTION, time(), "no" );
        }
        return false;
    }

    private static function unlock(): void {
        global $wpdb;
        $wpdb->delete( $wpdb->options, [ "option_name" => self::LOCK_OPTION ] );
        wp_cache_delete( self::LOCK_OPTION, "options" );
        wp_cache_delete( "notoptions", "options" );
    }
}
