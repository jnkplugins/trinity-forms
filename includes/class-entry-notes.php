<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required and these are admin-side, uncached by design.

/**
 * Notes and the audit trail attached to a single entry.
 *
 * Two kinds of row share this table, told apart by note_type:
 *   - "user"   — somebody typed it on the entry screen.
 *   - "system" — the plugin recorded something (an admin edit, a resent
 *                notification, a status change). Not editable, not deletable.
 *
 * The older `entries.notes` TEXT column could hold exactly one anonymous,
 * undated string, which is why nothing ever wrote to it.
 */
class Entry_Notes {

    public const TYPE_USER   = "user";
    public const TYPE_SYSTEM = "system";

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . "trinity_forms_entry_notes";
    }

    /**
     * @return int The new note id, or 0 on failure.
     */
    public static function add( int $entry_id, string $note, string $type = self::TYPE_USER, ?int $author_id = null ): int {
        global $wpdb;

        $entry_id = absint( $entry_id );
        $note     = trim( $note );
        if ( $entry_id < 1 || "" === $note ) {
            return 0;
        }

        if ( ! in_array( $type, [ self::TYPE_USER, self::TYPE_SYSTEM ], true ) ) {
            $type = self::TYPE_USER;
        }

        // A system note has no human author unless one is passed explicitly; a user
        // note defaults to whoever is logged in.
        if ( null === $author_id ) {
            $author_id = self::TYPE_USER === $type ? get_current_user_id() : 0;
        }

        $ok = $wpdb->insert(
            self::table(),
            [
                "entry_id"   => $entry_id,
                "author_id"  => $author_id ?: null,
                "note_type"  => $type,
                "note"       => $note,
                "created_at" => current_time( "mysql" ),
            ],
            [ "%d", "%d", "%s", "%s", "%s" ]
        );

        if ( ! $ok ) {
            return 0;
        }

        $id = (int) $wpdb->insert_id;
        do_action( "trinity_forms_entry_note_added", $id, $entry_id, $type );
        return $id;
    }

    /** Convenience wrapper so callers don't have to remember the constant. */
    public static function log( int $entry_id, string $note ): int {
        return self::add( $entry_id, $note, self::TYPE_SYSTEM );
    }

    /**
     * @return array<int,array<string,mixed>> Oldest first — a thread reads downwards.
     */
    public static function for_entry( int $entry_id ): array {
        global $wpdb;

        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE entry_id = %d ORDER BY created_at ASC, id ASC",
            absint( $entry_id )
        ), ARRAY_A );

        return (array) $rows;
    }

    /**
     * Delete a single note. System notes are the audit trail, so they stay.
     */
    public static function delete( int $note_id ): bool {
        global $wpdb;

        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $type = (string) $wpdb->get_var( $wpdb->prepare( "SELECT note_type FROM {$table} WHERE id = %d", absint( $note_id ) ) );
        if ( self::TYPE_USER !== $type ) {
            return false;
        }

        return (bool) $wpdb->delete( $table, [ "id" => absint( $note_id ) ], [ "%d" ] );
    }

    /** Display name for a note's author, falling back to something honest. */
    public static function author_name( ?int $author_id ): string {
        if ( ! $author_id ) {
            return __( "Trinity Forms", "trinity-forms" );
        }
        $user = get_userdata( $author_id );
        return $user ? $user->display_name : __( "Deleted user", "trinity-forms" );
    }
}
