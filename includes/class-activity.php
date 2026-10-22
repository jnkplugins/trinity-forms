<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Entry activity log.
 *
 * The entry screen's Activity log reads the logs table. Only submissions,
 * notifications, payments and integrations used to write there, so an edit, a
 * note, starring or moving an entry, approval and account creation never showed
 * up. This listens for those events and records one line each.
 */
class Activity {

    public const TYPE = "entry";

    public static function register(): void {
        add_action( "trinity_forms_entry_note_added", [ self::class, "on_note_added" ], 10, 3 );
        add_action( "trinity_forms_entry_updated", [ self::class, "on_updated" ], 10, 2 );
        add_action( "trinity_forms_entry_moderated", [ self::class, "on_moderated" ], 10, 2 );
        add_action( "trinity_forms_user_registered", [ self::class, "on_user_registered" ], 10, 3 );
    }

    /** The person doing it, or a neutral word when nobody is logged in. */
    private static function who(): string {
        $user = wp_get_current_user();
        return $user && $user->exists() ? (string) $user->display_name : __( "a visitor", "trinity-forms" );
    }

    public static function on_note_added( $note_id, $entry_id, $type = "" ): void {
        // System notes are written by the plugin itself (edits, approval...), which
        // are logged from their own events below; logging the note too would double up.
        if ( Entry_Notes::TYPE_USER !== $type ) {
            return;
        }
        /* translators: %s: display name. */
        Logger::info( self::TYPE, sprintf( __( "Note added by %s", "trinity-forms" ), self::who() ), (int) $entry_id );
    }

    public static function on_updated( $entry_id, $values = [] ): void {
        $who = self::who();
        $msg = is_admin()
            /* translators: %s: display name. */
            ? sprintf( __( "Edited by %s", "trinity-forms" ), $who )
            : __( "Edited from the front end", "trinity-forms" );
        Logger::info( self::TYPE, $msg, (int) $entry_id );
    }

    public static function on_moderated( $entry_id, $status ): void {
        $msg = "approved" === $status
            /* translators: %s: display name. */
            ? sprintf( __( "Approved by %s", "trinity-forms" ), self::who() )
            /* translators: %s: display name. */
            : sprintf( __( "Rejected by %s", "trinity-forms" ), self::who() );
        Logger::info( self::TYPE, $msg, (int) $entry_id );
    }

    public static function on_user_registered( $user_id, $entry_id, $form_id = 0 ): void {
        $user  = get_userdata( (int) $user_id );
        $login = $user ? $user->user_login : "#" . (int) $user_id;
        /* translators: %s: user login name. */
        Logger::info( self::TYPE, sprintf( __( "Created the account %s", "trinity-forms" ), $login ), (int) $entry_id );
    }

    /**
     * Record an action taken on entries from the admin (single or bulk).
     *
     * @param array<int,int> $ids
     */
    public static function record( array $ids, string $verb ): void {
        $messages = [
            /* translators: %s: display name. */
            "star"    => __( "Starred by %s", "trinity-forms" ),
            /* translators: %s: display name. */
            "unstar"  => __( "Star removed by %s", "trinity-forms" ),
            /* translators: %s: display name. */
            "read"    => __( "Marked as read by %s", "trinity-forms" ),
            /* translators: %s: display name. */
            "unread"  => __( "Marked as unread by %s", "trinity-forms" ),
            /* translators: %s: display name. */
            "spam"    => __( "Marked as spam by %s", "trinity-forms" ),
            /* translators: %s: display name. */
            "trash"   => __( "Moved to trash by %s", "trinity-forms" ),
            /* translators: %s: display name. */
            "restore" => __( "Restored by %s", "trinity-forms" ),
        ];
        if ( ! isset( $messages[ $verb ] ) ) {
            return;
        }
        $msg = sprintf( $messages[ $verb ], self::who() );
        foreach ( array_filter( array_map( "absint", $ids ) ) as $id ) {
            Logger::info( self::TYPE, $msg, $id );
        }
    }
}
