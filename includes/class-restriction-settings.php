<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * The Restrictions settings (schedule, entry limits, login) in the shape both
 * editors use: the block editor's Restrictions tab and the Visual Builder's
 * Settings.
 *
 * Until 2.23 only the block editor's meta box had them, so someone who built a
 * form in the Visual Builder thought the feature did not exist (tutorial
 * recordings, 3 Nov 2026). Both editors read and write them through this one
 * class, and Restrictions::check() reads the same keys, so they cannot drift.
 */
final class Restriction_Settings {

    public const KEYS = [
        "schedule_start", "schedule_end", "schedule_pending_message", "schedule_closed_message",
        "entry_limit", "entry_limit_per_user", "entry_limit_per_ip", "entry_limit_message", "entry_limit_user_message",
        "require_login", "login_required_message",
    ];

    /**
     * What a form has stored, ready for the editors' controls (dates as the
     * "2026-08-30T17:00" a datetime-local input expects).
     *
     * @param array<string,mixed> $settings A form's settings.
     * @return array<string,mixed>
     */
    public static function for_editor( array $settings ): array {
        return [
            "schedule_start"           => self::to_local_input( (string) ( $settings["schedule_start"] ?? "" ) ),
            "schedule_end"             => self::to_local_input( (string) ( $settings["schedule_end"] ?? "" ) ),
            "schedule_pending_message" => (string) ( $settings["schedule_pending_message"] ?? "" ),
            "schedule_closed_message"  => (string) ( $settings["schedule_closed_message"] ?? "" ),
            "entry_limit"              => max( 0, (int) ( $settings["entry_limit"] ?? 0 ) ),
            "entry_limit_per_user"     => max( 0, (int) ( $settings["entry_limit_per_user"] ?? 0 ) ),
            "entry_limit_per_ip"       => max( 0, (int) ( $settings["entry_limit_per_ip"] ?? 0 ) ),
            "entry_limit_message"      => (string) ( $settings["entry_limit_message"] ?? "" ),
            "entry_limit_user_message" => (string) ( $settings["entry_limit_user_message"] ?? "" ),
            "require_login"            => ! empty( $settings["require_login"] ),
            "login_required_message"   => (string) ( $settings["login_required_message"] ?? "" ),
        ];
    }

    /** @return array<string,mixed> */
    public static function get( int $form_id ): array {
        // Through Form, so a form whose settings live in its content shows what
        // visitors actually get.
        $form = Form_Manager::get( $form_id );
        return self::for_editor( $form ? $form->settings() : [] );
    }

    /**
     * Clean what an editor sent: every key, a missing one meaning "off".
     *
     * @return array<string,mixed>
     */
    public static function sanitize( array $raw ): array {
        return [
            "schedule_start"           => self::from_local_input( (string) ( $raw["schedule_start"] ?? "" ) ),
            "schedule_end"             => self::from_local_input( (string) ( $raw["schedule_end"] ?? "" ) ),
            "schedule_pending_message" => sanitize_text_field( (string) ( $raw["schedule_pending_message"] ?? "" ) ),
            "schedule_closed_message"  => sanitize_text_field( (string) ( $raw["schedule_closed_message"] ?? "" ) ),
            "entry_limit"              => max( 0, (int) ( $raw["entry_limit"] ?? 0 ) ),
            "entry_limit_per_user"     => max( 0, (int) ( $raw["entry_limit_per_user"] ?? 0 ) ),
            "entry_limit_per_ip"       => max( 0, (int) ( $raw["entry_limit_per_ip"] ?? 0 ) ),
            "entry_limit_message"      => sanitize_text_field( (string) ( $raw["entry_limit_message"] ?? "" ) ),
            "entry_limit_user_message" => sanitize_text_field( (string) ( $raw["entry_limit_user_message"] ?? "" ) ),
            "require_login"            => ! empty( $raw["require_login"] ) && "0" !== (string) $raw["require_login"] && "false" !== (string) $raw["require_login"],
            "login_required_message"   => sanitize_text_field( (string) ( $raw["login_required_message"] ?? "" ) ),
        ];
    }

    /**
     * Store the ones the editor sent, leaving every other setting as it was. A
     * client that sent none of them (an old builder still open in a tab) changes
     * nothing, rather than switching every rule off.
     */
    public static function save( int $form_id, array $raw ): void {
        $sent = array_intersect_key( $raw, array_flip( self::KEYS ) );
        if ( ! $sent ) {
            return;
        }
        $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
        $meta = is_array( $meta ) ? $meta : [];
        if ( is_array( $meta["settings"] ?? null ) ) {
            $base = $meta["settings"];
        } else {
            $form = Form_Manager::get( $form_id );
            $base = $form ? $form->settings() : [];
        }
        $clean            = array_intersect_key( self::sanitize( $raw ), $sent );
        $meta["settings"] = array_merge( $base, $clean );
        update_post_meta( $form_id, Form::SETTINGS_META, $meta );
    }

    /**
     * Store a datetime-local value ("2026-08-30T17:00") as the plain "Y-m-d H:i:s"
     * this codebase uses everywhere else. Site-local time on purpose: an admin
     * typing "closes on the 30th" means their own calendar (Restrictions compares
     * with current_time()).
     *
     * Plain, never ISO-8601: an ISO string with a timezone offset once reached the
     * database through a payment webhook and every date comparison downstream broke.
     */
    public static function from_local_input( string $value ): string {
        $value = trim( $value );
        if ( "" === $value ) {
            return "";
        }
        $ts = strtotime( str_replace( "T", " ", $value ) );
        return $ts ? gmdate( "Y-m-d H:i:s", $ts ) : "";
    }

    /** The inverse: stored "Y-m-d H:i:s" back into what datetime-local expects. */
    public static function to_local_input( string $value ): string {
        $value = trim( $value );
        if ( "" === $value ) {
            return "";
        }
        $ts = strtotime( $value );
        return $ts ? gmdate( "Y-m-d\TH:i", $ts ) : "";
    }
}
