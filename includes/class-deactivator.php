<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( "trinity_forms_daily_cron" );
        wp_clear_scheduled_hook( "trinity_forms_weekly_summary" );
    }
}
