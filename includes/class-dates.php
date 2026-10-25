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
     * Entries, notes and logs are stored in site time (current_time( "mysql" )).
     * strtotime() read them as UTC, because WordPress runs PHP in UTC, and
     * wp_date() then added the offset a second time: on an India-time site a new
     * entry said "in 5 hours". A value that names its own zone ("Z", "+05:30")
     * keeps it.
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
            $ts = ( new \DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
        } catch ( \Exception $e ) {
            $ts = strtotime( $value );
        }
        return $ts ?: 0;
    }
}
