<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Human-friendly timestamp formatting shared across the admin UI.
 */
class Dates {

    /**
     * Format a timestamp as a friendly US date/time, optionally with a relative
     * suffix, e.g. "Jul 23, 2026 7:59 PM (13 days ago)".
     *
     * @param int|string|null $value     Unix timestamp, MySQL datetime, or ISO-8601.
     * @param bool            $relative  Append "(… ago)" / "(in …)".
     * @param string          $empty     Returned when the value is empty/unparseable.
     */
    public static function human( $value, bool $relative = true, string $empty = "—" ): string {
        $ts = self::timestamp( $value );
        if ( ! $ts ) {
            return $empty;
        }
        // The site's own Settings → General formats, not a hard-coded US one.
        $format = trim( get_option( "date_format", "M j, Y" ) . " " . get_option( "time_format", "g:i A" ) );
        $abs    = function_exists( "wp_date" )
            ? wp_date( $format, $ts )
            : date_i18n( $format, $ts );
        return $relative ? $abs . " (" . self::relative_from_ts( $ts ) . ")" : $abs;
    }

    /** Just the relative phrase, e.g. "13 days ago" / "in 3 months". */
    public static function relative( $value ): string {
        $ts = self::timestamp( $value );
        return $ts ? self::relative_from_ts( $ts ) : "";
    }

    private static function relative_from_ts( int $ts ): string {
        $now = time();
        // A note added a moment ago, with the DB clock a second ahead of PHP's, read
        // "(in 1 second)" as if it were in the future.
        if ( abs( $now - $ts ) < MINUTE_IN_SECONDS ) {
            return __( "just now", "trinity-forms" );
        }
        return $ts >= $now
            /* translators: %s: human-readable time difference, e.g. "3 months". */
            ? sprintf( __( "in %s", "trinity-forms" ), human_time_diff( $now, $ts ) )
            /* translators: %s: human-readable time difference, e.g. "13 days". */
            : sprintf( __( "%s ago", "trinity-forms" ), human_time_diff( $ts, $now ) );
    }

    /**
     * A Unix timestamp for a value from our tables.
     *
     * Since 2.15.0 every time in our tables is UTC (see Utc_Migration), the way
     * WordPress keeps post_date_gmt; wp_date() then shows it in the site's zone.
     * A value that names its own zone ("Z", "+05:30") keeps it.
     */
    public static function timestamp( $value ): int {
        if ( $value === null || $value === "" ) {
            return 0;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }
        $value = (string) $value;
        if ( "0000-00-00" === substr( $value, 0, 10 ) ) {
            return 0;
        }
        try {
            $ts = ( new \DateTimeImmutable( $value, new \DateTimeZone( "UTC" ) ) )->getTimestamp();
        } catch ( \Exception $e ) {
            $ts = strtotime( $value );
        }
        return $ts ?: 0;
    }

    /** Option that says the tables hold UTC. Entry Vault reads it too: keep the name. */
    public const UTC_OPTION = "trinity_forms_times_utc";

    /** True once every stored time is UTC (fresh install, or the upgrade finished). */
    public static function stored_utc(): bool {
        return (bool) get_option( self::UTC_OPTION );
    }

    /** "Now" for a database column: always UTC. */
    public static function now(): string {
        return gmdate( "Y-m-d H:i:s" );
    }

    /**
     * A stored value as the site-time string 2.14 and older gave out (REST, CSV,
     * webhooks): outward values keep their meaning, *_gmt fields carry UTC.
     */
    public static function to_site( $value ): string {
        $value = (string) $value;
        if ( "" === $value || "0000-00-00" === substr( $value, 0, 10 ) ) {
            return $value;
        }
        return get_date_from_gmt( $value );
    }

    /**
     * A database cutoff for "older than $seconds". UTC; until the one-time
     * conversion has run the rows are still site time, so the cutoff is too.
     */
    public static function cutoff( int $seconds ): string {
        $ts = time() - $seconds;
        return self::stored_utc() ? gmdate( "Y-m-d H:i:s", $ts ) : wp_date( "Y-m-d H:i:s", $ts );
    }

    /**
     * A day the owner typed (Y-m-d, a site day) as a UTC bound for a query:
     * its first second, or with $end its last.
     */
    public static function day_bound( string $day, bool $end = false ): string {
        $local = $day . ( $end ? " 23:59:59" : " 00:00:00" );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! self::stored_utc() ) {
            return $local;
        }
        return get_gmt_from_date( $local );
    }

    /**
     * Stored times are UTC; the fields keep the site time they always had, and
     * a *_gmt field beside each carries the UTC value.
     *
     * @param array<string,mixed> $entry A row from the entries table (or part of one).
     * @return array<string,mixed>
     */
    public static function with_site_times( array $entry ): array {
        foreach ( [ "submitted_at", "trashed_at" ] as $col ) {
            if ( ! array_key_exists( $col, $entry ) ) {
                continue;
            }
            $raw                    = $entry[ $col ];
            $entry[ $col ]          = null === $raw ? null : self::to_site( $raw );
            $entry[ $col . "_gmt" ] = $raw;
        }
        return $entry;
    }
}
