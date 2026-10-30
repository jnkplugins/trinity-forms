<?php
namespace TrinityForms\Importers;

use TrinityForms\Form;
use TrinityForms\Form_Manager;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reading other plugins' tables and writing imported entries into ours.

/**
 * The shared import(): convert, create the form, store its notifications, then let
 * the provider copy entries. A provider that keeps entries overrides
 * supports_entries(), count_entries() and import_entries().
 */
abstract class Base_Importer implements Importer {

    /**
     * Internal entry-meta rows (the leading "_" keeps them out of every list,
     * export and merge tag) naming where an imported entry came from: the
     * importer slug ("fluent-forms") and that plugin's entry id. EntryVault reads
     * them so an entry it already has from the other plugin does not arrive twice.
     */
    public const ORIGIN_SOURCE = "_imported_from";
    public const ORIGIN_ENTRY  = "_imported_entry_id";

    /** The slug of the importer whose entries add_entry() is storing. */
    private static string $importing = "";

    public function supports_entries(): bool {
        return false;
    }

    /** @param int|string $source_id */
    protected function count_entries( $source_id ): int {
        return 0;
    }

    /**
     * @param int|string $source_id
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        return 0;
    }

    public function import( $source_id, array $options = [] ): array {
        $with_entries = ! empty( $options["with_entries"] ) && $this->supports_entries();
        $dry_run      = ! empty( $options["dry_run"] );

        $report = [ "form_id" => 0, "title" => "", "fields" => 0, "entries" => 0, "skipped" => [], "notifications" => 0 ];

        $converted = $this->convert( $source_id );
        if ( null === $converted ) {
            return $report;
        }
        $report["title"]         = (string) $converted["title"];
        $report["fields"]        = count( $converted["fields"] );
        $report["skipped"]       = array_values( $converted["skipped"] );
        $report["notifications"] = count( $converted["notifications"] );

        if ( $dry_run ) {
            $report["entries"] = $with_entries ? $this->count_entries( $source_id ) : 0;
            return $report;
        }

        $converted["fields"] = self::fit_fields( $converted["fields"] );

        $schema             = Form_Manager::default_schema();
        $schema["settings"] = array_merge( $schema["settings"], (array) $converted["settings"] );
        $schema["steps"]    = [ [ "id" => "step_1", "title" => "", "fields" => array_values( $converted["fields"] ) ] ];

        $form_id = Form_Manager::create( "" !== $report["title"] ? $report["title"] : $this->label(), $schema );
        if ( ! $form_id ) {
            return $report;
        }
        $report["form_id"] = $form_id;

        if ( $converted["notifications"] ) {
            $meta                  = get_post_meta( $form_id, Form::SETTINGS_META, true );
            $meta                  = is_array( $meta ) ? $meta : [];
            $meta["notifications"] = array_values( $converted["notifications"] );
            update_post_meta( $form_id, Form::SETTINGS_META, $meta );
        }

        if ( $with_entries ) {
            self::$importing = $this->slug();
            try {
                $report["entries"] = $this->import_entries( $source_id, $form_id, $converted["fields"] );
            } finally {
                self::$importing = "";
            }
        }

        do_action( "trinity_forms_form_imported", $form_id, $this->slug(), $source_id, $report );

        return $report;
    }

    /**
     * Drop the settings a field's block does not have, so the form is saved as
     * blocks (the editors can open it) and an editor save keeps what we wrote.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    public static function fit_fields( array $fields ): array {
        $registry = class_exists( "WP_Block_Type_Registry" ) ? \WP_Block_Type_Registry::get_instance() : null;
        $map      = [ "id" => "fieldId", "type" => "", "sub_fields" => "subFieldsJson" ];
        foreach ( $fields as $i => $field ) {
            $block = $registry ? $registry->get_registered( "trinity-forms/field-" . str_replace( "_", "-", (string) ( $field["type"] ?? "" ) ) ) : null;
            if ( ! $block || ! is_array( $block->attributes ) ) {
                continue;
            }
            foreach ( array_keys( $field ) as $key ) {
                if ( isset( $map[ $key ] ) ) {
                    continue;
                }
                $attr = lcfirst( str_replace( " ", "", ucwords( str_replace( "_", " ", (string) $key ) ) ) );
                if ( ! array_key_exists( $attr, $block->attributes ) ) {
                    unset( $fields[ $i ][ $key ] );
                }
            }
        }
        return array_values( $fields );
    }

    /**
     * Put a builder's layout class ("one-half", "wpforms-one-third", "frm_fourth")
     * into Trinity's field width, keeping the other classes. Returns the field.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    protected static function width_from_class( array $field ): array {
        $classes = preg_split( '/\s+/', trim( (string) ( $field["css_class"] ?? "" ) ) );
        $keep    = [];
        foreach ( (array) $classes as $class ) {
            if ( preg_match( '/(?:^|[-_])(half|third|fourth|quarter)$/', (string) $class, $m ) ) {
                $field["field_width"] = [ "half" => "50", "third" => "33", "fourth" => "25", "quarter" => "25" ][ $m[1] ];
            } elseif ( ! in_array( $class, [ "", "first", "wpforms-first", "frm_first" ], true ) ) {
                $keep[] = $class;
            }
        }
        if ( $keep ) {
            $field["css_class"] = implode( " ", $keep );
        } else {
            unset( $field["css_class"] );
        }
        return $field;
    }

    /** "Label (type)": a field with no Trinity equivalent, for the skipped list. */
    protected static function field_skipped( string $label, string $type ): string {
        return sprintf(
            /* translators: 1: field label, 2: the other plugin's field type, e.g. "likert". */
            __( "%1\$s (%2\$s)", "trinity-forms" ),
            "" !== trim( $label ) ? $label : __( "Untitled field", "trinity-forms" ),
            $type
        );
    }

    /** Show/hide rules that could not be carried over, for the skipped list. */
    protected static function rules_skipped( string $label ): string {
        /* translators: %s: field label or notification name. */
        return sprintf( __( "Show/hide rules on %s", "trinity-forms" ), "" !== trim( $label ) ? $label : __( "Untitled field", "trinity-forms" ) );
    }

    /**
     * A Trinity rule set from the usual builder shape: any of these groups, where
     * every rule in a group must hold. Null when nothing is left.
     *
     * @param array<int,array<int,array{field:string,operator:string,value:string}>> $groups
     * @return array<string,mixed>|null
     */
    protected static function rule_set( string $action, array $groups ): ?array {
        $groups = array_values( array_filter( $groups ) );
        if ( ! $groups ) {
            return null;
        }
        $action = "hide" === $action ? "hide" : "show";
        if ( 1 === count( $groups ) ) {
            return [ "action" => $action, "match" => "all", "conditions" => array_values( $groups[0] ) ];
        }
        if ( count( $groups ) === count( array_filter( $groups, static fn( $g ) => 1 === count( $g ) ) ) ) {
            return [ "action" => $action, "match" => "any", "conditions" => array_map( static fn( $g ) => array_values( $g )[0], $groups ) ];
        }
        return [
            "action"     => $action,
            "match"      => "any",
            "conditions" => array_map( static fn( $g ) => [ "match" => "all", "conditions" => array_values( $g ) ], $groups ),
        ];
    }

    /** A local "Y-m-d H:i:s" from the other plugin, as the UTC Trinity stores. */
    protected static function to_utc( string $local ): string {
        return "" === trim( $local ) ? gmdate( "Y-m-d H:i:s" ) : get_gmt_from_date( $local );
    }

    /**
     * Whether another plugin's table can be read (it may be gone, or the plugin
     * never created it, as WPForms Lite does with its entries table).
     */
    protected static function table_readable( string $table ): bool {
        global $wpdb;
        $show     = $wpdb->show_errors( false );
        $suppress = $wpdb->suppress_errors( true );
        $previous = $wpdb->last_error;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built from $wpdb->prefix.
        $wpdb->get_var( "SELECT 1 FROM `{$table}` LIMIT 1" );
        $ok = "" === (string) $wpdb->last_error;
        $wpdb->suppress_errors( $suppress );
        $wpdb->show_errors( $show );
        $wpdb->last_error = $previous;
        return $ok;
    }

    /**
     * Store one imported submission. Values are keyed by Trinity field id; arrays
     * are stored as JSON, the way a live submission stores them.
     *
     * @param array{submitted_at?:string,ip?:string,user_agent?:string,user_id?:int,source_url?:string,referer?:string,is_read?:bool,is_starred?:bool,source_entry_id?:int|string} $entry submitted_at in UTC; source_entry_id = the other plugin's entry id
     * @param array<string,mixed>                $values
     * @param array<int,array<string,mixed>>     $fields the converted fields
     */
    protected static function add_entry( int $form_id, array $entry, array $values, array $fields ): int {
        global $wpdb;
        $ip = trim( (string) ( $entry["ip"] ?? "" ) );
        $wpdb->insert( $wpdb->prefix . "trinity_forms_entries", [
            "form_id"      => $form_id,
            "status"       => "completed",
            "submitted_at" => (string) ( $entry["submitted_at"] ?? gmdate( "Y-m-d H:i:s" ) ),
            "ip_address"   => "" !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : null,
            "user_agent"   => substr( (string) ( $entry["user_agent"] ?? "" ), 0, 500 ),
            "user_id"      => (int) ( $entry["user_id"] ?? 0 ) ?: null,
            "referer"      => substr( (string) ( $entry["referer"] ?? "" ), 0, 500 ),
            "source_url"   => substr( (string) ( $entry["source_url"] ?? "" ), 0, 500 ),
            "is_read"      => ! empty( $entry["is_read"] ) ? 1 : 0,
            "is_starred"   => ! empty( $entry["is_starred"] ) ? 1 : 0,
        ] );
        $entry_id = (int) $wpdb->insert_id;
        if ( ! $entry_id ) {
            return 0;
        }
        if ( "" !== self::$importing && isset( $entry["source_entry_id"] ) ) {
            self::record_origin( $entry_id, self::$importing, (string) $entry["source_entry_id"] );
        }
        $by_id = array_column( $fields, null, "id" );
        foreach ( $values as $field_id => $value ) {
            if ( ! isset( $by_id[ $field_id ] ) || null === $value || "" === $value || [] === $value ) {
                continue;
            }
            $field = $by_id[ $field_id ];
            $wpdb->insert( $wpdb->prefix . "trinity_forms_entry_meta", [
                "entry_id"    => $entry_id,
                "field_id"    => (string) $field_id,
                "field_label" => (string) ( $field["label"] ?? $field_id ),
                "field_type"  => (string) ( $field["type"] ?? "text" ),
                "value"       => is_array( $value ) ? (string) wp_json_encode( array_values( $value ) ) : (string) $value,
            ], [ "%d", "%s", "%s", "%s", "%s" ] );
        }
        return $entry_id;
    }

    /** Note on an imported entry which plugin and entry it came from. */
    public static function record_origin( int $entry_id, string $source, string $source_entry_id ): void {
        global $wpdb;
        if ( $entry_id <= 0 || "" === $source || "" === $source_entry_id ) {
            return;
        }
        foreach ( [ self::ORIGIN_SOURCE => $source, self::ORIGIN_ENTRY => $source_entry_id ] as $key => $value ) {
            $wpdb->insert( $wpdb->prefix . "trinity_forms_entry_meta", [
                "entry_id"    => $entry_id,
                "field_id"    => $key,
                "field_label" => $key,
                "field_type"  => "internal",
                "value"       => $value,
            ], [ "%d", "%s", "%s", "%s", "%s" ] );
        }
    }

    /**
     * A Trinity field id (letters, digits, underscores) from the builder's field name
     * or label, unique among $used.
     *
     * @param array<int,string> $used
     */
    public static function unique_id( string $name, string $fallback, array $used ): string {
        $base = strtolower( remove_accents( $name ) );
        $base = preg_replace( '/[^a-z0-9]+/', "_", $base );
        $base = trim( substr( (string) $base, 0, 32 ), "_" );
        if ( "" === $base ) {
            $base = $fallback;
        }
        $candidate = $base;
        $n         = 2;
        while ( in_array( $candidate, $used, true ) ) {
            $candidate = $base . "_" . $n;
            $n++;
        }
        return $candidate;
    }
}
