<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required.

/**
 * Free front-end "my submissions" list: [trinity_forms_my_submissions].
 *
 * Shows the CURRENTLY logged-in user their own past submissions (entries whose
 * user_id matches them). Read-only and strictly self-scoped — a user can only ever
 * see their own rows — so it's safe in the free tier. Optionally limit to one form
 * with [trinity_forms_my_submissions form="12"].
 */
class My_Submissions {

    public static function render( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return '<p class="trinity-forms-my-submissions-empty">'
                . esc_html__( "Please log in to see your submissions.", "trinity-forms" ) . '</p>';
        }

        $atts = shortcode_atts( [ "form" => 0, "limit" => 50 ], (array) $atts, "trinity_forms_my_submissions" );
        $user_id = get_current_user_id();
        $limit   = max( 1, min( 200, (int) $atts["limit"] ) );
        $form_id = (int) $atts["form"];

        global $wpdb;
        $table = $wpdb->prefix . "trinity_forms_entries";
        if ( $form_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, form_id, submitted_at, status FROM {$table} WHERE user_id = %d AND form_id = %d AND status NOT IN ('spam','trash','partial') ORDER BY submitted_at DESC LIMIT %d",
                $user_id, $form_id, $limit
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, form_id, submitted_at, status FROM {$table} WHERE user_id = %d AND status NOT IN ('spam','trash','partial') ORDER BY submitted_at DESC LIMIT %d",
                $user_id, $limit
            ), ARRAY_A );
        }

        if ( empty( $rows ) ) {
            return '<p class="trinity-forms-my-submissions-empty">'
                . esc_html__( "You have no submissions yet.", "trinity-forms" ) . '</p>';
        }

        $titles = [];
        $out  = '<table class="trinity-forms-my-submissions"><thead><tr>';
        $out .= '<th>' . esc_html__( "Date", "trinity-forms" ) . '</th>';
        $out .= '<th>' . esc_html__( "Form", "trinity-forms" ) . '</th>';
        $out .= '<th>' . esc_html__( "Status", "trinity-forms" ) . '</th>';
        $out .= '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $fid = (int) $r["form_id"];
            if ( ! isset( $titles[ $fid ] ) ) {
                $titles[ $fid ] = get_the_title( $fid ) ?: __( "Form", "trinity-forms" );
            }
            $out .= '<tr>';
            $out .= '<td>' . esc_html( Dates::human( (string) $r["submitted_at"], false ) ) . '</td>';
            $out .= '<td>' . esc_html( $titles[ $fid ] ) . '</td>';
            $out .= '<td>' . esc_html( self::status_label( (string) $r["status"] ) ) . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';

        return $out;
    }

    private static function status_label( string $status ): string {
        return Entries::status_label( $status );
    }
}
