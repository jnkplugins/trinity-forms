<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required for entry counts.

/**
 * Per-form gates that decide whether a form may be filled in at all.
 *
 * Three separate questions, deliberately answered in one place so that the form
 * renderer and the submission pipeline can never disagree about them:
 *
 *   - Is it open yet / still open?  (schedule)
 *   - Has it taken enough entries?  (limits)
 *   - Are you allowed to see it?    (login)
 *
 * Both callers matter. The renderer uses this to replace the form with a message,
 * and the pipeline uses it to refuse a POST — because "the form wasn't on the page"
 * has never stopped anyone from posting to the endpoint directly.
 */
class Restrictions {

    /**
     * Why a form is closed, or null when it is open.
     *
     * @return array{reason:string,message:string}|null
     */
    public static function check( Form $form ): ?array {
        foreach ( [ "check_login", "check_schedule", "check_limits" ] as $gate ) {
            $blocked = self::$gate( $form );
            if ( $blocked ) {
                return $blocked;
            }
        }

        /**
         * Let an add-on close a form for its own reason. Returning an array of
         * {reason, message} blocks; null lets it through.
         */
        $extra = apply_filters( "trinity_forms_restrictions", null, $form );
        return is_array( $extra ) && ! empty( $extra["message"] ) ? $extra : null;
    }

    /** @return array{reason:string,message:string}|null */
    private static function check_login( Form $form ): ?array {
        if ( ! $form->get_setting( "require_login", false ) || is_user_logged_in() ) {
            return null;
        }
        return [
            "reason"  => "login_required",
            "message" => (string) ( $form->get_setting( "login_required_message", "" )
                ?: __( "Please log in to fill in this form.", "trinity-forms" ) ),
        ];
    }

    /** @return array{reason:string,message:string}|null */
    private static function check_schedule( Form $form ): ?array {
        $start = trim( (string) $form->get_setting( "schedule_start", "" ) );
        $end   = trim( (string) $form->get_setting( "schedule_end", "" ) );

        if ( "" === $start && "" === $end ) {
            return null;
        }

        // Compared in the site's timezone: an admin typing "closes on the 30th"
        // means their own calendar, not UTC. current_time() honours that.
        $now = current_time( "timestamp" ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- deliberate: schedules are authored and read in site-local time.

        if ( "" !== $start && $now < strtotime( $start ) ) {
            return [
                "reason"  => "not_open_yet",
                "message" => (string) ( $form->get_setting( "schedule_pending_message", "" )
                    ?: __( "This form is not open yet.", "trinity-forms" ) ),
            ];
        }

        if ( "" !== $end && $now > strtotime( $end ) ) {
            return [
                "reason"  => "closed",
                "message" => (string) ( $form->get_setting( "schedule_closed_message", "" )
                    ?: __( "This form is now closed.", "trinity-forms" ) ),
            ];
        }

        return null;
    }

    /** @return array{reason:string,message:string}|null */
    private static function check_limits( Form $form ): ?array {
        $total    = (int) $form->get_setting( "entry_limit", 0 );
        $per_user = (int) $form->get_setting( "entry_limit_per_user", 0 );
        $per_ip   = (int) $form->get_setting( "entry_limit_per_ip", 0 );

        if ( $total < 1 && $per_user < 1 && $per_ip < 1 ) {
            return null;
        }

        $message = (string) ( $form->get_setting( "entry_limit_message", "" )
            ?: __( "This form is no longer accepting submissions.", "trinity-forms" ) );

        if ( $total > 0 && self::count_entries( $form->id ) >= $total ) {
            return [ "reason" => "limit_reached", "message" => $message ];
        }

        if ( $per_user > 0 && is_user_logged_in() ) {
            if ( self::count_entries( $form->id, [ "user_id" => get_current_user_id() ] ) >= $per_user ) {
                return [
                    "reason"  => "user_limit_reached",
                    "message" => (string) ( $form->get_setting( "entry_limit_user_message", "" )
                        ?: __( "You have already submitted this form.", "trinity-forms" ) ),
                ];
            }
        }

        if ( $per_ip > 0 ) {
            $ip = Submission_Pipeline::client_ip();
            if ( $ip && self::count_entries( $form->id, [ "ip" => $ip ] ) >= $per_ip ) {
                return [
                    "reason"  => "ip_limit_reached",
                    "message" => (string) ( $form->get_setting( "entry_limit_user_message", "" )
                        ?: __( "You have already submitted this form.", "trinity-forms" ) ),
                ];
            }
        }

        return null;
    }

    /**
     * Count entries against a form.
     *
     * Spam and trash are excluded: a form capped at 100 entries should not be closed
     * by 100 pieces of spam, and deleting an entry should free its slot.
     *
     * @param array{user_id?:int,ip?:string} $scope
     */
    private static function count_entries( int $form_id, array $scope = [] ): int {
        global $wpdb;

        $table  = $wpdb->prefix . "trinity_forms_entries";
        $where  = "form_id = %d AND status NOT IN ('spam','trash')";
        $params = [ $form_id ];

        if ( ! empty( $scope["user_id"] ) ) {
            $where   .= " AND user_id = %d";
            $params[] = (int) $scope["user_id"];
        }
        if ( ! empty( $scope["ip"] ) ) {
            $where   .= " AND ip_address = %s";
            $params[] = inet_pton( (string) $scope["ip"] );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params ) );
    }
}
