<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table lookups, admin-side and uncached by design.

/**
 * REST reads for entry notes and a form's integration feeds (TF-32).
 *
 *   GET /submissions/{id}/notes            every note on an entry, oldest first
 *   GET /submissions/{id}/notes/{note_id}  one note
 *   GET /forms/{id}/feeds                  the form's integration feeds
 *   GET /forms/{id}/feeds/{slug}           one feed
 *
 * Reads are free; the writes (add/edit/delete a note, change a feed) come from
 * the Pro module on the same trinity_forms_register_rest_routes action. Every
 * route asks the same capability as the Trinity Forms screens (REST_API::can_manage).
 *
 * Feed secrets (API keys, passwords) never leave the site: they come back as
 * "********" with the key named in "secrets_set".
 */
class REST_Resources {

    public const MASK = "********";

    public static function register( string $ns ): void {
        $api = new REST_API();
        $can = [ $api, "can_manage" ];

        // Notes are free to read and write, as on the entry screen.
        register_rest_route( $ns, "/submissions/(?P<id>\d+)/notes", [
            [
                "methods"             => "GET",
                "callback"            => [ self::class, "list_notes" ],
                "permission_callback" => $can,
            ],
            [
                "methods"             => "POST",
                "callback"            => [ self::class, "add_note" ],
                "permission_callback" => $can,
            ],
        ] );
        register_rest_route( $ns, "/submissions/(?P<id>\d+)/notes/(?P<note_id>\d+)", [
            [
                "methods"             => "GET",
                "callback"            => [ self::class, "get_note" ],
                "permission_callback" => $can,
            ],
            [
                "methods"             => "PUT, PATCH",
                "callback"            => [ self::class, "update_note" ],
                "permission_callback" => $can,
            ],
            [
                "methods"             => "DELETE",
                "callback"            => [ self::class, "delete_note" ],
                "permission_callback" => $can,
            ],
        ] );
        register_rest_route( $ns, "/forms/(?P<id>\d+)/feeds", [
            "methods"             => "GET",
            "callback"            => [ self::class, "list_feeds" ],
            "permission_callback" => $can,
        ] );
        register_rest_route( $ns, "/forms/(?P<id>\d+)/feeds/(?P<slug>[a-z0-9_-]+)", [
            "methods"             => "GET",
            "callback"            => [ self::class, "get_feed" ],
            "permission_callback" => $can,
        ] );
    }

    /* ---------------------------------------------------------- note writes */

    public static function add_note( \WP_REST_Request $request ) {
        $entry_id = (int) $request["id"];
        if ( ! self::entry_exists( $entry_id ) ) {
            return self::error( 404, __( "Entry not found.", "trinity-forms" ) );
        }
        $text = sanitize_textarea_field( (string) $request->get_param( "note" ) );
        if ( "" === trim( $text ) ) {
            return self::error( 400, __( "The note is empty.", "trinity-forms" ) );
        }
        $id  = Entry_Notes::add( $entry_id, $text );
        $row = $id ? self::note_row( $entry_id, $id ) : null;
        if ( ! $row ) {
            return self::error( 500, __( "The note could not be saved.", "trinity-forms" ) );
        }
        return new \WP_REST_Response( self::envelope( self::note_out( $row ) ), 201 );
    }

    public static function update_note( \WP_REST_Request $request ) {
        $entry_id = (int) $request["id"];
        $note_id  = (int) $request["note_id"];
        $row      = self::note_row( $entry_id, $note_id );
        if ( ! $row ) {
            return self::error( 404, __( "Note not found.", "trinity-forms" ) );
        }
        if ( Entry_Notes::TYPE_USER !== $row["note_type"] ) {
            return self::error( 403, __( "Notes the plugin wrote are the entry's history and cannot be changed.", "trinity-forms" ) );
        }
        $text = sanitize_textarea_field( (string) $request->get_param( "note" ) );
        if ( "" === trim( $text ) ) {
            return self::error( 400, __( "The note is empty.", "trinity-forms" ) );
        }
        Entry_Notes::update( $note_id, $text );
        return self::envelope( self::note_out( (array) self::note_row( $entry_id, $note_id ) ) );
    }

    public static function delete_note( \WP_REST_Request $request ) {
        $entry_id = (int) $request["id"];
        $note_id  = (int) $request["note_id"];
        $row      = self::note_row( $entry_id, $note_id );
        if ( ! $row ) {
            return self::error( 404, __( "Note not found.", "trinity-forms" ) );
        }
        if ( Entry_Notes::TYPE_USER !== $row["note_type"] ) {
            return self::error( 403, __( "Notes the plugin wrote are the entry's history and cannot be deleted.", "trinity-forms" ) );
        }
        Entry_Notes::delete( $note_id );
        return self::envelope( [ "id" => $note_id, "deleted" => true ] );
    }

    /* ---------------------------------------------------------------- helpers */

    public static function envelope( $data, array $errors = [], array $meta = [] ): array {
        return [ "success" => empty( $errors ), "data" => $data, "errors" => $errors, "meta" => $meta ];
    }

    public static function error( int $status, string $message ): \WP_REST_Response {
        return new \WP_REST_Response( self::envelope( null, [ [ "code" => $status, "message" => $message ] ] ), $status );
    }

    public static function entry_exists( int $entry_id ): bool {
        global $wpdb;
        return $entry_id > 0 && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d", $entry_id ) );
    }

    /** @return array<string,mixed>|null */
    public static function note_row( int $entry_id, int $note_id ): ?array {
        global $wpdb;
        $table = Entry_Notes::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND entry_id = %d", $note_id, $entry_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** One note as the API shows it (UTC and site time, like submissions). */
    public static function note_out( array $n ): array {
        $author = ! empty( $n["author_id"] ) ? (int) $n["author_id"] : null;
        return [
            "id"             => (int) $n["id"],
            "entry_id"       => (int) $n["entry_id"],
            "type"           => (string) $n["note_type"],
            "note"           => (string) $n["note"],
            "author_id"      => $author,
            "author_name"    => Entry_Notes::author_name( $author ),
            "created_at_gmt" => (string) $n["created_at"],
            "created_at"     => Dates::to_site( (string) $n["created_at"] ),
            "editable"       => Entry_Notes::TYPE_USER === $n["note_type"],
        ];
    }

    /** @return array<int,array<string,mixed>>|null null when the form does not exist. */
    public static function feeds_of( int $form_id ): ?array {
        $post = get_post( $form_id );
        if ( ! $post || "trinity_forms_form" !== $post->post_type ) {
            return null;
        }
        $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
        $list = is_array( $meta ) && is_array( $meta["integrations"] ?? null ) ? $meta["integrations"] : [];
        return array_values( array_filter( $list, static fn( $c ) => is_array( $c ) && ! empty( $c["slug"] ) ) );
    }

    /** One feed with every secret masked. */
    public static function feed_out( array $cfg ): array {
        $slug    = (string) $cfg["slug"];
        $label   = (string) apply_filters( "trinity_forms_feed_label", $slug, $slug );
        $config  = [];
        $secrets = [];
        foreach ( $cfg as $k => $v ) {
            if ( in_array( $k, [ "slug", "enabled", "conditional" ], true ) ) {
                continue;
            }
            $secret = Secrets::looks_secret( (string) $k ) || ( is_string( $v ) && Secrets::is_encrypted( $v ) );
            if ( $secret && is_string( $v ) ) {
                if ( "" !== $v ) {
                    $secrets[] = (string) $k;
                    $v         = self::MASK;
                }
            }
            $config[ $k ] = $v;
        }
        return [
            "slug"        => $slug,
            "label"       => $label,
            "enabled"     => ! empty( $cfg["enabled"] ),
            "config"      => $config,
            "secrets_set" => $secrets,
            "conditional" => is_array( $cfg["conditional"] ?? null ) ? $cfg["conditional"] : null,
        ];
    }

    /* ---------------------------------------------------------------- notes */

    public static function list_notes( \WP_REST_Request $request ) {
        $entry_id = (int) $request["id"];
        if ( ! self::entry_exists( $entry_id ) ) {
            return self::error( 404, __( "Entry not found.", "trinity-forms" ) );
        }
        $type  = (string) $request->get_param( "type" );
        $notes = Entry_Notes::for_entry( $entry_id );
        if ( in_array( $type, [ Entry_Notes::TYPE_USER, Entry_Notes::TYPE_SYSTEM ], true ) ) {
            $notes = array_values( array_filter( $notes, static fn( $n ) => $type === $n["note_type"] ) );
        }
        return self::envelope( array_map( [ self::class, "note_out" ], $notes ), [], [ "total" => count( $notes ) ] );
    }

    public static function get_note( \WP_REST_Request $request ) {
        $row = self::note_row( (int) $request["id"], (int) $request["note_id"] );
        return $row ? self::envelope( self::note_out( $row ) ) : self::error( 404, __( "Note not found.", "trinity-forms" ) );
    }

    /* ---------------------------------------------------------------- feeds */

    public static function list_feeds( \WP_REST_Request $request ) {
        $feeds = self::feeds_of( (int) $request["id"] );
        if ( null === $feeds ) {
            return self::error( 404, __( "Form not found.", "trinity-forms" ) );
        }
        return self::envelope( array_map( [ self::class, "feed_out" ], $feeds ), [], [ "total" => count( $feeds ) ] );
    }

    public static function get_feed( \WP_REST_Request $request ) {
        $feeds = self::feeds_of( (int) $request["id"] );
        if ( null === $feeds ) {
            return self::error( 404, __( "Form not found.", "trinity-forms" ) );
        }
        foreach ( $feeds as $cfg ) {
            if ( (string) $cfg["slug"] === (string) $request["slug"] ) {
                return self::envelope( self::feed_out( $cfg ) );
            }
        }
        return self::error( 404, __( "This form has no feed of that kind.", "trinity-forms" ) );
    }
}
