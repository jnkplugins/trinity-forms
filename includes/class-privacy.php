<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required.

/**
 * GDPR / privacy tooling (free): plugs Trinity Forms entries into WordPress'
 * built-in Personal Data Export and Erase requests (Tools → Export/Erase Personal
 * Data). A submission is matched to a person by any email-type field whose value
 * equals the requested address, plus entries owned by a logged-in user with that
 * email. Export lists every field value; erase deletes the entry (its per-field
 * meta cascades) and any uploaded files.
 */
class Privacy {

    private const PER_PAGE = 50;

    public static function register(): void {
        add_filter( "wp_privacy_personal_data_exporters", [ __CLASS__, "register_exporter" ] );
        add_filter( "wp_privacy_personal_data_erasers",   [ __CLASS__, "register_eraser" ] );
    }

    public static function register_exporter( array $exporters ): array {
        $exporters["trinity-forms"] = [
            "exporter_friendly_name" => __( "Trinity Forms submissions", "trinity-forms" ),
            "callback"               => [ __CLASS__, "export" ],
        ];
        return $exporters;
    }

    public static function register_eraser( array $erasers ): array {
        $erasers["trinity-forms"] = [
            "eraser_friendly_name" => __( "Trinity Forms submissions", "trinity-forms" ),
            "callback"             => [ __CLASS__, "erase" ],
        ];
        return $erasers;
    }

    /** @return array{data:array,done:bool} */
    public static function export( string $email, int $page = 1 ): array {
        $page  = max( 1, $page );
        $ids   = self::entry_ids_for_email( $email, $page );
        $items = [];

        foreach ( $ids as $entry_id ) {
            $data = [];
            foreach ( self::entry_fields( $entry_id ) as $row ) {
                $data[] = [ "name" => $row["label"], "value" => $row["value"] ];
            }
            $items[] = [
                "group_id"    => "trinity-forms-entries",
                "group_label" => __( "Form submissions", "trinity-forms" ),
                "item_id"     => "trinity-forms-entry-" . $entry_id,
                "data"        => $data,
            ];
        }

        return [ "data" => $items, "done" => count( $ids ) < self::PER_PAGE ];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:array,done:bool} */
    public static function erase( string $email, int $page = 1 ): array {
        $ids     = self::entry_ids_for_email( $email, 1 ); // always take the first page; we delete as we go
        $removed = false;

        global $wpdb;
        foreach ( $ids as $entry_id ) {
            // Let listeners (file cleanup) run before the row disappears.
            do_action( "trinity_forms_entry_deleted", $entry_id );
            $wpdb->delete( $wpdb->prefix . "trinity_forms_entries", [ "id" => $entry_id ], [ "%d" ] );
            // Meta cascades via FK, but delete defensively in case the FK is absent.
            $wpdb->delete( $wpdb->prefix . "trinity_forms_entry_meta", [ "entry_id" => $entry_id ], [ "%d" ] );
            $removed = true;
        }

        return [
            "items_removed"  => $removed,
            "items_retained" => false,
            "messages"       => [],
            "done"           => count( $ids ) < self::PER_PAGE,
        ];
    }

    /**
     * Entry IDs linked to an email: any email-type field value that matches, plus
     * entries owned by a user account with that address.
     *
     * @return int[]
     */
    private static function entry_ids_for_email( string $email, int $page ): array {
        $email = sanitize_email( $email );
        if ( $email === "" ) {
            return [];
        }
        global $wpdb;
        $offset = ( $page - 1 ) * self::PER_PAGE;

        $user     = get_user_by( "email", $email );
        $user_id  = $user ? (int) $user->ID : 0;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT e.id
               FROM {$wpdb->prefix}trinity_forms_entries e
               LEFT JOIN {$wpdb->prefix}trinity_forms_entry_meta m
                 ON m.entry_id = e.id AND m.field_type = 'email'
              WHERE m.value = %s OR e.user_id = %d
              ORDER BY e.id ASC
              LIMIT %d OFFSET %d",
            $email, $user_id, self::PER_PAGE, $offset
        ) );

        return array_map( "intval", (array) $ids );
    }

    /** @return array<int,array{label:string,value:string}> */
    private static function entry_fields( int $entry_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, field_label, field_type, value FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d",
            $entry_id
        ), ARRAY_A );

        $form_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT form_id FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d", $entry_id ) );
        $labels  = Form_Manager::choice_labels( $form_id );

        $out = [];
        foreach ( (array) $rows as $r ) {
            if ( Entries::is_internal_field( (string) $r["field_id"] ) ) {
                continue; // internal meta
            }
            $value = Entries::display_value( (string) $r["field_type"], $r["value"], $labels[ $r["field_id"] ] ?? [] );
            $out[] = [ "label" => (string) ( $r["field_label"] ?: $r["field_id"] ), "value" => $value ];
        }
        return $out;
    }
}
