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
