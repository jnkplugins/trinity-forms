<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Activator {
    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . "wp-admin/includes/upgrade.php";
        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix . "trinity_forms_";

        $entries = "CREATE TABLE {$prefix}entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            submitted_at DATETIME NOT NULL,
            ip_address VARBINARY(16) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            referer VARCHAR(500) DEFAULT NULL,
            payment_status VARCHAR(20) NOT NULL DEFAULT 'none',
            payment_amount DECIMAL(10,2) DEFAULT NULL,
            payment_currency CHAR(3) DEFAULT NULL,
            payment_id VARCHAR(100) DEFAULT NULL,
            resume_token VARCHAR(64) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            is_starred TINYINT(1) NOT NULL DEFAULT 0,
            source_url VARCHAR(500) DEFAULT NULL,
            trashed_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_form (form_id),
            KEY idx_status (status),
            KEY idx_submitted (submitted_at),
            KEY idx_payment (payment_status),
            KEY idx_resume (resume_token),
            KEY idx_read_star (is_read, is_starred),
            KEY idx_trashed (trashed_at)
        ) {$charset_collate};";

        // Multi-note audit trail. The older single `entries.notes` TEXT column is
        // left in place for back-compat but is no longer read or written — one text
        // blob can't carry an author, a timestamp, or an edit record.
        $entry_notes = "CREATE TABLE {$prefix}entry_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED DEFAULT NULL,
            note_type VARCHAR(20) NOT NULL DEFAULT 'user',
            note LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_entry (entry_id),
            KEY idx_type (note_type)
        ) {$charset_collate};";

        $entry_meta = "CREATE TABLE {$prefix}entry_meta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED NOT NULL,
            field_id VARCHAR(64) NOT NULL,
            field_label VARCHAR(255) NOT NULL,
            field_type VARCHAR(40) NOT NULL,
            value LONGTEXT DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_entry (entry_id),
            KEY idx_field (field_id)
        ) {$charset_collate};";

        $files = "CREATE TABLE {$prefix}files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED NOT NULL,
            field_id VARCHAR(64) NOT NULL,
            attachment_id BIGINT UNSIGNED DEFAULT NULL,
            filename VARCHAR(255) NOT NULL,
            filepath VARCHAR(500) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL,
            uploaded_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_entry (entry_id)
        ) {$charset_collate};";

        $logs = "CREATE TABLE {$prefix}logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED DEFAULT NULL,
            level VARCHAR(10) NOT NULL DEFAULT 'info',
            type VARCHAR(40) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_type (type),
            KEY idx_entry (entry_id),
            KEY idx_level (level)
        ) {$charset_collate};";

        dbDelta( $entries );
        dbDelta( $entry_meta );
        dbDelta( $entry_notes );
        dbDelta( $files );
        dbDelta( $logs );
        // dbDelta() says nothing when the database refuses a table; check, and
        // keep the database's own error for the notice and Site Health.
        Schema_Check::record();

        // Add FK separately — dbDelta doesn't parse FOREIGN KEY clauses.
        $fk_name = "fk_{$wpdb->prefix}trinity_forms_entry_meta_entry";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-shot activation check, caching not relevant.
        $exists  = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s",
            "{$prefix}entry_meta",
            $fk_name
        ) );
        if ( ! $exists ) {
            // Some shared hosts deny REFERENCES privilege; swallow + log rather than spew on activation.
            // Cascade integrity is also enforced in PHP via Submission_Pipeline::cleanup_entry_files() and
            // delete_submission(), so a missing FK is degraded behaviour, not a correctness break.
            $previous_error  = $wpdb->last_error;
            $previous_show   = $wpdb->show_errors( false );
            $previous_supp   = $wpdb->suppress_errors( true );
            try {
                // DDL with literal identifiers ($prefix and $fk_name come from $wpdb->prefix + a literal); no user data is interpolated.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( "ALTER TABLE {$prefix}entry_meta
                    ADD CONSTRAINT {$fk_name}
                    FOREIGN KEY (entry_id) REFERENCES {$prefix}entries (id) ON DELETE CASCADE" );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter
                if ( $wpdb->last_error && class_exists( __NAMESPACE__ . "\\Logger" ) ) {
                    Logger::warn( "activation", "Could not add FK on entry_meta: " . $wpdb->last_error );
                }
            } catch ( \Throwable $e ) {
                if ( class_exists( __NAMESPACE__ . "\\Logger" ) ) {
                    Logger::warn( "activation", "FK ALTER threw: " . $e->getMessage() );
                }
            } finally {
                $wpdb->suppress_errors( $previous_supp );
                $wpdb->show_errors( $previous_show );
                $wpdb->last_error = $previous_error;
            }
        }

        if ( ! get_option( "trinity_forms_settings" ) ) {
            add_option( "trinity_forms_settings", [
                "integrations"           => [],
                "default_retention_days" => 365,
            ] );
        }
        update_option( "trinity_forms_version", TRINITY_FORMS_VERSION );

        if ( ! wp_next_scheduled( "trinity_forms_daily_cron" ) ) {
            wp_schedule_event( time(), "daily", "trinity_forms_daily_cron" );
        }
    }

    /**
     * Run pending schema migrations without a manual deactivate/reactivate.
     *
     * Hooked on admin_init. The activation hook does NOT fire on plugin update
     * (e.g. when a free install is upgraded in place to Pro by uploading the Pro
     * zip), so new tables/columns would otherwise be missing until reactivation.
     * activate() is idempotent (dbDelta, existence-checked FK, guarded settings +
     * cron), so re-running it once per release — gated on the stored version — is
     * safe.
     */
    public static function maybe_upgrade(): void {
        $stored = (string) get_option( "trinity_forms_version", "" );
        if ( $stored === TRINITY_FORMS_VERSION ) {
            return;
        }

        // Order matters: activate() stamps the version unconditionally, so anything
        // that needs to know the OLD version has to run first.
        //
        // An empty $stored means a fresh install, not an upgrade — back-stamping there
        // would pin brand-new forms to the block editor, which is the opposite of what
        // 2.1 is for.
        if ( "" !== $stored && version_compare( $stored, "2.1.0", "<" ) ) {
            self::grandfather_existing_forms();
        }

        self::activate();
    }

    /**
     * One-time: pin every form that predates 2.1 to the block editor.
     *
     * 2.1 makes the visual builder the site default. Without this, upgrading would
     * silently change the editing experience of forms people have already built.
     * An explicit per-form choice always beats the site default in
     * Form_Builder::editor_for(), so stamping "block" here freezes existing forms
     * while leaving forms created from now on to follow the new default.
     *
     * Idempotent: a form that already carries an explicit choice — either because a
     * user made one or because this ran before — is skipped, never overwritten.
     */
    public static function grandfather_existing_forms(): void {
        $per_page = 200;
        $offset   = 0;

        do {
            $ids = get_posts( [
                "post_type"              => "trinity_forms_form",
                "post_status"            => "any",
                "posts_per_page"         => $per_page,
                "offset"                 => $offset,
                "fields"                 => "ids",
                "orderby"                => "ID",
                "order"                  => "ASC",
                "no_found_rows"          => true,
                "update_post_meta_cache" => false,
                "update_post_term_cache" => false,
                "suppress_filters"       => true,
            ] );

            foreach ( $ids as $id ) {
                $id   = (int) $id;
                $meta = get_post_meta( $id, Form::SETTINGS_META, true );
                $meta = is_array( $meta ) ? $meta : [];

                $settings = isset( $meta["settings"] ) && is_array( $meta["settings"] )
                    ? $meta["settings"]
                    : [];

                if ( in_array( (string) ( $settings["editor"] ?? "" ), [ "canvas", "block" ], true ) ) {
                    continue;
                }

                $settings["editor"] = "block";
                $meta["settings"]   = $settings;
                update_post_meta( $id, Form::SETTINGS_META, $meta );
            }

            $offset += $per_page;
        } while ( count( $ids ) === $per_page );
    }
}
