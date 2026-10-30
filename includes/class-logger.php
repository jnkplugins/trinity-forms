<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Logger {

    public static function info( string $type, string $message, ?int $entry_id = null, array $context = [] ): void {
        self::write( "info", $type, $message, $entry_id, $context );
    }

    public static function warn( string $type, string $message, ?int $entry_id = null, array $context = [] ): void {
        self::write( "warn", $type, $message, $entry_id, $context );
    }

    public static function error( string $type, string $message, ?int $entry_id = null, array $context = [] ): void {
        self::write( "error", $type, $message, $entry_id, $context );
    }

    /**
     * A log type as the entry screen shows it.
     *
     * The stored type stays the key ("notification", "payment"...) because code
     * reads it; the pill showed that key in English on an Arabic or Korean site.
     * An add-on's own slug has no label here and is shown as it is.
     */
    public static function type_label( string $type ): string {
        switch ( $type ) {
            case "entry":         return __( "entry", "trinity-forms" );
            case "notification":  return _x( "email", "activity log type", "trinity-forms" );
            case "spam":          return _x( "spam", "activity log type", "trinity-forms" );
            case "payment":       return _x( "payment", "activity log type", "trinity-forms" );
            case "integration":   return _x( "integration", "activity log type", "trinity-forms" );
            case "post_creation": return _x( "post", "activity log type", "trinity-forms" );
            case "retention":     return _x( "cleanup", "activity log type", "trinity-forms" );
            case "geolocation":   return _x( "location", "activity log type", "trinity-forms" );
            case "alert":         return _x( "alert", "activity log type", "trinity-forms" );
            case "activation":    return _x( "setup", "activity log type", "trinity-forms" );
            case "paypal":        return "PayPal";
            case "stripe":        return "Stripe";
            case "mollie":        return "Mollie";
        }
        return $type;
    }

    private static function write( string $level, string $type, string $message, ?int $entry_id, array $context ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . "trinity_forms_logs",
            [
                "entry_id"   => $entry_id,
                "level"      => $level,
                "type"       => $type,
                "message"    => $message,
                "context"    => $context ? wp_json_encode( $context ) : null,
                "created_at" => Dates::now(),
            ],
            [ "%d", "%s", "%s", "%s", "%s", "%s" ]
        );
    }
}
