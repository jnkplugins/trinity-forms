<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Cron {

    public static function enforce_retention_policies(): void {
        global $wpdb;
        foreach ( Form_Manager::all_with_retention() as $form ) {
            $days = $form->retention_days();
            if ( $days <= 0 ) {
                continue;
            }
            $cutoff = gmdate( "Y-m-d H:i:s", time() - $days * DAY_IN_SECONDS );

            // Collect the doomed entry ids first, then delete each one the same way
            // the manual paths do (Admin::handle_delete_entry, REST_API::delete_submission):
            // remove the entry + its meta and fire trinity_forms_entry_deleted( $id ) so
            // Submission_Pipeline::cleanup_entry_files() can purge the uploaded files. A
            // bulk DELETE can't do this — it has no per-entry id, so files and (on hosts
            // without the FK cascade) entry_meta rows would be orphaned.
            $ids = array_map( "intval", (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}trinity_forms_entries WHERE form_id = %d AND submitted_at < %s",
                $form->id,
                $cutoff
            ) ) );
            if ( ! $ids ) {
                continue;
            }

            // Entries::delete() is the one place that knows the full child-row set
            // (meta, notes) and fires the before/after hooks in the right order.
            // Doing it inline here is how entry_notes rows would get orphaned the
            // day a new child table is added.
            $deleted = Entries::delete( $ids );

            Logger::info( "retention", "Deleted {$deleted} entries (form {$form->id}, >{$days}d)", null, [
                "form_id" => $form->id,
                "deleted" => $deleted,
            ] );
        }
    }

    /**
     * Empty the trash on a delay, the way WordPress does with posts.
     *
     * Trashing has to be undoable to be worth offering — otherwise it is just a
     * slower delete. The window is configurable; 0 disables auto-purge entirely
     * and leaves emptying the trash a manual job.
     */
    public static function purge_trashed_entries(): void {
        $settings = get_option( "trinity_forms_settings", [] );
        $days     = isset( $settings["trash_purge_days"] ) ? (int) $settings["trash_purge_days"] : 30;

        if ( $days < 1 ) {
            return;
        }

        $deleted = Entries::purge_trash( $days );
        if ( $deleted > 0 ) {
            Logger::info( "retention", "Purged {$deleted} trashed entries (>{$days}d)", null, [
                "deleted" => $deleted,
            ] );
        }
    }
}
