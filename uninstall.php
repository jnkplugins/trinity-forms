<?php
defined( "WP_UNINSTALL_PLUGIN" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

global $wpdb;

// Deleting the plugin frees this site's licence seat on the licensing server,
// whether or not its data goes too. Only builds with the licence client ship it.
$uninstall_seat_slug = "trinity-forms";
if ( is_readable( __DIR__ . "/pro/licensorpro-client/uninstall-seat.php" ) ) {
    include __DIR__ . "/pro/licensorpro-client/uninstall-seat.php";
}

$settings = get_option( "trinity_forms_settings", [] );
if ( empty( $settings["delete_data_on_uninstall"] ) ) {
    return;
}

// Children before parents: entry_meta carries an FK to entries on hosts that
// allowed the constraint, and DROP order decides whether that succeeds.
$tables = [
    "trinity_forms_entry_meta",
    "trinity_forms_entry_notes",
    "trinity_forms_files",
    "trinity_forms_logs",
    "trinity_forms_entries",
];
foreach ( $tables as $t ) {
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $wpdb->prefix . $t ) );
}

// The forms themselves (and Pro's Views), with their meta, revisions and any
// trashed or auto-draft copies.
foreach ( [ "trinity_forms_form", "trinity_forms_view" ] as $post_type ) {
    $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );
    foreach ( $ids as $id ) {
        wp_delete_post( (int) $id, true );
    }
}

// Every option and transient under our prefix: settings, the landing-page
// rewrite flag, the mail transport, rate-limit and page-token counters, import
// reports, and the SDK's licence state (licensorpro_trinity_forms_*).
foreach ( [ "trinity_forms\\_%", "\\_transient\\_trinity\\_forms\\_%", "\\_transient\\_timeout\\_trinity\\_forms\\_%", "licensorpro\\_trinity\\_forms\\_%", "\\_transient\\_licensorpro\\_trinity\\_forms\\_%", "\\_transient\\_timeout\\_licensorpro\\_trinity\\_forms\\_%", "licensorpro\\_trinity-forms\\_%", "\\_transient\\_licensorpro\\_trinity-forms\\_%", "\\_transient\\_timeout\\_licensorpro\\_trinity-forms\\_%" ] as $like ) {
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ) as $name ) {
        delete_option( $name ); // Through the API, so the options cache forgets it too.
    }
}
delete_option( "widget_trinity_forms_widget" );
// Per-user screen choices (entry columns, a dismissed mail notice).
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "trinity\\_forms\\_%" ) );

wp_clear_scheduled_hook( "trinity_forms_daily_cron" );
wp_clear_scheduled_hook( "trinity_forms_weekly_summary" );

delete_option( "trinity_forms_settings" );
delete_option( "trinity_forms_version" );
delete_option( "trinity_forms_ready_notice" );
delete_option( "trinity_forms_times_utc" );
delete_option( "trinity_forms_utc_migration" );
delete_option( "trinity_forms_utc_lock" );
delete_option( "trinity_forms_onboarding" );
delete_option( "trinity_forms_mail_failures" );
delete_option( "trinity_forms_mail_test" );
delete_option( "trinity_forms_mail_last_ok" );

// LicensorPro SDK state (license key, cached license/update info).
delete_option( "licensorpro_trinity_forms_key" );
delete_option( "licensorpro_trinity_forms_license" );
delete_option( "licensorpro_trinity_forms_remote" );
delete_transient( "licensorpro_trinity_forms_validate" );
delete_transient( "licensorpro_trinity_forms_update" );
