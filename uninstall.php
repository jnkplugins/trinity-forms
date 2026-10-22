<?php
defined( "WP_UNINSTALL_PLUGIN" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

global $wpdb;

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

delete_option( "trinity_forms_settings" );
delete_option( "trinity_forms_version" );
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
