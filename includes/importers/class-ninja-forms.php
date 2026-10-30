<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading Ninja Forms' own tables; works while Ninja Forms is deactivated.

/**
 * Ninja Forms 3.
 *
 * Forms, fields and actions each have a row in nf3_forms / nf3_fields /
 * nf3_actions and their settings as key/value rows in the matching *_meta table.
 * Read through Ninja_Forms()->form() while Ninja Forms is active (it serves the
 * same settings, from its cache), else from those tables. Emails, the success
 * message and redirects are "actions". Submissions are `nf_sub` posts with one
 * `_field_<id>` meta per answer.
 *
 * Conditional logic and multi-part forms come from paid add-ons; their stored
 * settings are read when present.
 */
class Ninja_Forms extends Base_Importer {

    /** Ninja field type => Trinity field type. */
    private const FIELD_MAP = [
        "textbox"         => "text",
        "firstname"       => "text",
        "lastname"        => "text",
        "address"         => "text",
        "address2"        => "text",
        "city"            => "text",
        "zip"             => "text",
        "email"           => "email",
        "phone"           => "phone",
        "textarea"        => "textarea",
        "number"          => "number",
        "date"            => "date",
        "listselect"      => "select",
        "liststate"       => "select",
        "listradio"       => "radio",
        "listcheckbox"    => "checkbox",
        "listmultiselect" => "multiselect",
        "listcountry"     => "country",
        "checkbox"        => "consent",
        "hidden"          => "hidden",
        "html"            => "html",
        "hr"              => "section",
        "starrating"      => "rating",
        "password"        => "password",
        "file_upload"     => "file",
    ];

    /** Ninja comparator (Conditional Logic add-on) => Trinity operator. */
    private const OPERATORS = [
        "equal"       => "equals",
        "notequal"    => "not_equals",
        "contains"    => "contains",
        "notcontains" => "not_contains",
        "greater"     => "greater_than",
        "less"        => "less_than",
        "empty"       => "empty",
        "notempty"    => "not_empty",
        "checked"     => "not_empty",
        "unchecked"   => "empty",
    ];

    /** Ninja merge tags => ours. */
    private const TAGS = [
        "fields_table"          => "{all_fields}",
        "all_fields_table"      => "{all_fields}",
        "wp:admin_email"        => "{admin_email}",
        "system:admin_email"    => "{admin_email}",
        "wp:site_title"         => "{site_name}",
        "system:site_title"     => "{site_name}",
        "wp:site_url"           => "{site_url}",
        "system:site_url"       => "{site_url}",
        "wp:post_title"         => "{post:post_title}",
        "wp:post_url"           => "{embed_url}",
        "wp:post_id"            => "{post:ID}",
        "wp:user_id"            => "{user:ID}",
        "wp:user_email"         => "{user:user_email}",
        "wp:user_display_name"  => "{user:display_name}",
        "wp:user_first_name"    => "{user:first_name}",
        "wp:user_last_name"     => "{user:last_name}",
        "wp:user_username"      => "{user:user_login}",
        "other:user_ip"         => "{ip}",
        "system:ip"             => "{ip}",
        "other:date"            => "{date}",
        "system:date"           => "{date}",
        "form:title"            => "{form_title}",
        "submission:sequence"   => "{entry_id}",
    ];

    public function slug(): string {
        return "ninja-forms";
    }

    public function label(): string {
        return "Ninja Forms";
    }

    private static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public function is_available(): bool {
        return self::table_readable( self::table( "nf3_forms" ) );
    }

    public function forms(): array {
        global $wpdb;
        if ( ! $this->is_available() ) {
            return [];
        }
        $out = [];
        foreach ( (array) $wpdb->get_results( "SELECT id, title FROM " . self::table( "nf3_forms" ) . " ORDER BY id ASC", ARRAY_A ) as $row ) {
            $out[ (int) $row["id"] ] = [ "title" => (string) $row["title"], "entries" => $this->count_entries( (int) $row["id"] ) ];
        }
        return $out;
    }

    public function supports_entries(): bool {
        return true;
    }

    protected function count_entries( $source_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_form_id'
              WHERE p.post_type = 'nf_sub' AND p.post_status = 'publish' AND m.meta_value = %s",
            (string) (int) $source_id
        ) );
    }

    /**
     * The form's settings, fields (in display order) and actions.
     *
     * @return array{settings:array<string,mixed>,fields:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>}|null
     */
    public static function form_data( int $id ): ?array {
        $data = function_exists( "Ninja_Forms" ) ? self::from_api( $id ) : null;
        if ( null === $data ) {
            $data = self::from_tables( $id );
        }
        if ( null === $data ) {
            return null;
        }
        $data["fields"] = self::ordered( $data["fields"], $data["settings"] );
        return $data;
    }

    /** @return array{settings:array<string,mixed>,fields:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>}|null */
    private static function from_api( int $id ): ?array {
        try {
            $form = \Ninja_Forms()->form( $id )->get();
            if ( ! $form || ! $form->get_id() ) {
                return null;
            }
            $fields = [];
            foreach ( (array) \Ninja_Forms()->form( $id )->get_fields() as $f ) {
                $fields[] = [ "id" => (int) $f->get_id() ] + (array) $f->get_settings();
            }
            $actions = [];
            foreach ( (array) \Ninja_Forms()->form( $id )->get_actions() as $a ) {
                $actions[] = (array) $a->get_settings();
            }
            return [ "settings" => (array) $form->get_settings(), "fields" => $fields, "actions" => $actions ];
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /** @return array{settings:array<string,mixed>,fields:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>}|null */
    private static function from_tables( int $id ): ?array {
        global $wpdb;
        if ( ! self::table_readable( self::table( "nf3_forms" ) ) ) {
            return null;
        }
        $title = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM " . self::table( "nf3_forms" ) . " WHERE id = %d", $id ) );
        if ( null === $title ) {
            return null;
        }
        $settings          = self::meta_of( "nf3_form_meta", [ $id ] )[ $id ] ?? [];
        $settings["title"] = (string) ( $settings["title"] ?? $title );

        // The row holds the key, type and label; everything else is meta.
        $rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, `key`, type, label FROM " . self::table( "nf3_fields" ) . " WHERE parent_id = %d ORDER BY id", $id ), ARRAY_A );
        $metas  = self::meta_of( "nf3_field_meta", array_map( "intval", array_column( $rows, "id" ) ) );
        $fields = [];
        foreach ( $rows as $r ) {
            $fid      = (int) $r["id"];
            $fields[] = array_merge( array_filter( [ "key" => $r["key"], "type" => $r["type"], "label" => $r["label"] ], static fn( $v ) => null !== $v ), $metas[ $fid ] ?? [], [ "id" => $fid ] );
        }
        $arows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, type, label FROM " . self::table( "nf3_actions" ) . " WHERE parent_id = %d ORDER BY id", $id ), ARRAY_A );
        $ametas  = self::meta_of( "nf3_action_meta", array_map( "intval", array_column( $arows, "id" ) ) );
        $actions = [];
        foreach ( $arows as $r ) {
            $actions[] = array_merge( array_filter( [ "type" => $r["type"], "label" => $r["label"] ], static fn( $v ) => null !== $v ), $ametas[ (int) $r["id"] ] ?? [] );
        }
        return [ "settings" => $settings, "fields" => $fields, "actions" => $actions ];
    }

    /**
     * @param array<int,int> $parents
     * @return array<int,array<string,mixed>> parent id => [ key => value ]
     */
    private static function meta_of( string $table, array $parents ): array {
        global $wpdb;
        if ( ! $parents ) {
            return [];
        }
        $in   = implode( ",", array_map( "intval", $parents ) );
        $rows = $wpdb->get_results( "SELECT parent_id, `key`, value FROM " . self::table( $table ) . " WHERE parent_id IN ({$in}) ORDER BY id ASC", ARRAY_A );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r["parent_id"] ][ (string) $r["key"] ] = maybe_unserialize( $r["value"] );
        }
        return $out;
    }

    /**
     * Display order: the form's layout list when it has one (plain, or parts of a
     * multi-part form, or rows of the Layout add-on), else each field's "order".
     * A new part starts with a "__part" marker that becomes a page break.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed>            $settings
     * @return array<int,array<string,mixed>>
     */
    private static function ordered( array $fields, array $settings ): array {
        usort( $fields, static fn( $a, $b ) => (int) ( $a["order"] ?? 0 ) <=> (int) ( $b["order"] ?? 0 ) );
        $layout = $settings["formContentData"] ?? null;
        if ( ! is_array( $layout ) || ! $layout ) {
            return $fields;
        }
        $keys = [];
        $part = 0;
        $walk = static function ( $items ) use ( &$walk, &$keys, &$part ) {
            foreach ( (array) $items as $item ) {
                if ( is_string( $item ) ) {
                    $keys[] = $item;
                } elseif ( is_array( $item ) && isset( $item["formContentData"] ) ) {
                    if ( $part++ > 0 ) {
                        $keys[] = [ "__part" => (string) ( $item["title"] ?? "" ) ];
                    }
                    $walk( $item["formContentData"] );
                } elseif ( is_array( $item ) && isset( $item["cells"] ) ) {
                    foreach ( (array) $item["cells"] as $cell ) {
                        $walk( $cell["fields"] ?? [] );
                    }
                }
            }
        };
        $walk( $layout );

        $by_key = [];
        foreach ( $fields as $f ) {
            $by_key[ (string) ( $f["key"] ?? "" ) ] = $f;
        }
        $out = [];
        foreach ( $keys as $k ) {
            if ( is_array( $k ) ) {
                $out[] = [ "type" => "__part", "label" => $k["__part"], "key" => "part_" . count( $out ) ];
            } elseif ( isset( $by_key[ $k ] ) ) {
                $out[] = $by_key[ $k ];
                unset( $by_key[ $k ] );
            }
        }
        // Anything the layout did not place (e.g. the submit button) keeps its order at the end.
        return array_merge( $out, array_values( $by_key ) );
    }

    /**
     * Ninja field key => Trinity field id.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,string>
     */
    private static function ids( array $fields ): array {
        $ids = [];
        foreach ( $fields as $i => $f ) {
            $type = (string) ( $f["type"] ?? "" );
            if ( isset( self::FIELD_MAP[ $type ] ) || "__part" === $type ) {
                $key         = (string) ( $f["key"] ?? "" );
                $ids[ $key ] = self::unique_id( preg_replace( '/_\d{10,}$/', "", $key ) ?: (string) ( $f["label"] ?? "" ), "field_" . ( $i + 1 ), array_values( $ids ) );
            }
        }
        return $ids;
    }

    public function convert( $source_id ): ?array {
        $data = self::form_data( (int) $source_id );
        if ( null === $data ) {
            return null;
        }
        $ids     = self::ids( $data["fields"] );
        $by_id   = [];
        foreach ( $data["fields"] as $f ) {
            if ( isset( $f["id"] ) ) {
                $by_id[ (string) $f["id"] ] = (string) ( $f["key"] ?? "" );
            }
        }
        $skipped = [];
        $fields  = [];
        $submit  = "";
        foreach ( $data["fields"] as $f ) {
            $type  = (string) ( $f["type"] ?? "" );
            $label = (string) ( $f["label"] ?? "" );
            if ( "submit" === $type ) {
                $submit = (string) ( $f["label"] ?? "" );
                continue;
            }
            if ( in_array( $type, [ "spam", "recaptcha", "recaptcha_v3", "hcaptcha", "turnstile", "note" ], true ) ) {
                continue;
            }
            if ( "__part" === $type ) {
                $fields[] = [ "id" => $ids[ $f["key"] ], "type" => "page_break", "label" => $label ];
                continue;
            }
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                $skipped[] = self::field_skipped( $label, $type );
                continue;
            }
            $fields[] = self::convert_field( $f, $ids[ (string) ( $f["key"] ?? "" ) ], $skipped );
        }

        // Conditional Logic add-on: "when these hold, then show/hide that field".
        foreach ( (array) ( $data["settings"]["conditions"] ?? [] ) as $cond ) {
            self::apply_condition( (array) $cond, $fields, $ids, $by_id, $skipped );
        }

        $settings = [];
        if ( "" !== trim( $submit ) ) {
            $settings["submit_button_text"] = $submit;
        }
        $notifications = [];
        foreach ( $data["actions"] as $i => $a ) {
            $type   = (string) ( $a["type"] ?? "" );
            $active = ! isset( $a["active"] ) || ! empty( $a["active"] );
            if ( "successmessage" === $type && $active && ! isset( $settings["confirmation_message"] ) ) {
                $message = self::detag( (string) ( $a["success_msg"] ?? "" ), $ids, $by_id, $skipped );
                if ( "" !== trim( wp_strip_all_tags( $message ) ) ) {
                    $settings["confirmation_message"] = $message;
                }
            } elseif ( "redirect" === $type && $active && "" !== trim( (string) ( $a["redirect_url"] ?? "" ) ) ) {
                $settings["redirect_url"] = self::detag( (string) $a["redirect_url"], $ids, $by_id, $skipped );
            } elseif ( "email" === $type ) {
                $n = self::convert_email( $a, $i, $ids, $by_id, $skipped );
                if ( $n ) {
                    $notifications[] = $n;
                }
            } elseif ( ! in_array( $type, [ "save", "successmessage", "redirect", "" ], true ) && $active ) {
                /* translators: %s: the other plugin's action name, e.g. a newsletter sign-up. */
                $skipped[] = sprintf( __( "Action: %s", "trinity-forms" ), (string) ( $a["label"] ?? $type ) );
            }
        }

        return [
            "title"         => (string) ( $data["settings"]["title"] ?? "" ),
            "fields"        => $fields,
            "settings"      => $settings,
            "notifications" => $notifications,
            "skipped"       => array_values( array_unique( $skipped ) ),
        ];
    }

    /**
     * @param array<string,mixed> $f
     * @param array<int,string>   $skipped
     * @return array<string,mixed>
     */
    private static function convert_field( array $f, string $id, array &$skipped ): array {
        $type  = (string) $f["type"];
        $label = (string) ( $f["label"] ?? "" );
        $field = [
            "id"       => $id,
            "type"     => self::FIELD_MAP[ $type ],
            "label"    => $label,
            "required" => ! empty( $f["required"] ),
        ];
        if ( "" !== trim( (string) ( $f["placeholder"] ?? "" ) ) ) {
            $field["placeholder"] = (string) $f["placeholder"];
        }
        $help = trim( wp_strip_all_tags( (string) ( $f["desc_text"] ?? "" ) ) );
        if ( "" === $help ) {
            $help = trim( wp_strip_all_tags( (string) ( $f["help_text"] ?? "" ) ) );
        }
        if ( "" !== $help ) {
            $field["help"] = $help;
        }
        if ( "" !== trim( (string) ( $f["container_class"] ?? "" ) ) ) {
            $field["css_class"] = (string) $f["container_class"];
        }
        if ( "" !== trim( (string) ( $f["admin_label"] ?? "" ) ) ) {
            $field["admin_label"] = (string) $f["admin_label"];
        }
        $default = (string) ( $f["default"] ?? "" );

        switch ( $type ) {
            case "textbox":
            case "textarea":
                if ( ! empty( $f["input_limit"] ) && "characters" === ( $f["input_limit_type"] ?? "characters" ) ) {
                    $field["max_length"] = (int) $f["input_limit"];
                }
                break;
            case "number":
                foreach ( [ "num_min" => "min", "num_max" => "max", "num_step" => "step" ] as $from => $to ) {
                    if ( isset( $f[ $from ] ) && "" !== (string) $f[ $from ] ) {
                        $field[ $to ] = (string) $f[ $from ];
                    }
                }
                break;
            case "checkbox":
                $field["consent_text"] = $label;
                $default               = "";
                break;
            case "hidden":
                $field["default"] = $default;
                $default          = "";
                unset( $field["required"] );
                break;
            case "html":
                $field["html"] = (string) ( $f["default"] ?? "" );
                $default       = "";
                unset( $field["required"] );
                break;
            case "hr":
                $field["label"] = "";
                unset( $field["required"] );
                break;
            case "starrating":
                $field["max"] = (string) max( 2, (int) ( $f["number_of_stars"] ?? 5 ) );
                $default      = "";
                break;
            case "file_upload":
                $ext = array_filter( array_map( "trim", explode( ",", (string) ( $f["upload_types"] ?? "" ) ) ) );
                if ( $ext ) {
                    $field["accept"] = implode( ",", array_map( static fn( $e ) => "." . ltrim( strtolower( $e ), "." ), $ext ) );
                }
                if ( is_numeric( $f["max_file_size"] ?? null ) && (float) $f["max_file_size"] > 0 ) {
                    $field["max_size"] = max( 1, (int) ceil( (float) $f["max_file_size"] ) );
                }
                if ( ! empty( $f["upload_multi"] ) ) {
                    $field["multiple"] = true;
                }
                break;
        }

        $field = self::width_from_class( $field );
        if ( in_array( $field["type"], [ "select", "multiselect", "radio", "checkbox" ], true ) ) {
            $field["options"] = [];
            $selected         = [];
            foreach ( (array) ( $f["options"] ?? [] ) as $o ) {
                if ( ! is_array( $o ) ) {
                    continue;
                }
                $value              = (string) ( $o["value"] ?? $o["label"] ?? "" );
                $field["options"][] = [ "label" => (string) ( $o["label"] ?? "" ), "value" => $value ];
                if ( ! empty( $o["selected"] ) ) {
                    $selected[] = $value;
                }
            }
            if ( $selected ) {
                $default = implode( ",", $selected );
            }
        }
        if ( "" !== trim( $default ) ) {
            $field["default_value"] = $default;
        }
        return $field;
    }

    /**
     * @param array<string,mixed>            $cond
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,string>           $ids    Ninja key => Trinity id
     * @param array<string,string>           $by_id  Ninja field id => key
     * @param array<int,string>              $skipped
     */
    private static function apply_condition( array $cond, array &$fields, array $ids, array $by_id, array &$skipped ): void {
        $rules      = [];
        $connectors = [];
        $ok         = true;
        foreach ( (array) ( $cond["when"] ?? [] ) as $i => $w ) {
            $key = (string) ( $w["key"] ?? "" );
            $key = isset( $ids[ $key ] ) ? $key : ( $by_id[ $key ] ?? $key );
            $op  = (string) ( $w["comparator"] ?? "" );
            if ( ! isset( $ids[ $key ], self::OPERATORS[ $op ] ) ) {
                $ok = false;
                break;
            }
            if ( $i > 0 ) {
                $connectors[] = strtoupper( (string) ( $w["connector"] ?? "AND" ) );
            }
            $rules[] = [ "field" => $ids[ $key ], "operator" => self::OPERATORS[ $op ], "value" => in_array( $op, [ "empty", "notempty", "checked", "unchecked" ], true ) ? "" : (string) ( $w["value"] ?? "" ) ];
        }
        $connectors = array_unique( $connectors );
        $match      = [ "OR" ] === array_values( $connectors ) ? "any" : "all";
        if ( count( $connectors ) > 1 ) {
            $ok = false;
        }
        foreach ( (array) ( $cond["then"] ?? [] ) as $then ) {
            $key     = (string) ( $then["key"] ?? "" );
            $key     = isset( $ids[ $key ] ) ? $key : ( $by_id[ $key ] ?? $key );
            $trigger = (string) ( $then["trigger"] ?? "" );
            foreach ( $fields as $i => $field ) {
                if ( ! isset( $ids[ $key ] ) || $field["id"] !== $ids[ $key ] ) {
                    continue;
                }
                if ( $ok && $rules && in_array( $trigger, [ "show_field", "hide_field" ], true ) && ! isset( $field["conditional"] ) ) {
                    $fields[ $i ]["conditional"] = [ "action" => "hide_field" === $trigger ? "hide" : "show", "match" => $match, "conditions" => $rules ];
                } else {
                    $skipped[] = self::rules_skipped( $field["label"] );
                }
            }
        }
    }

    /**
     * @param array<string,mixed>  $a
     * @param array<string,string> $ids
     * @param array<string,string> $by_id
     * @param array<int,string>    $skipped
     * @return array<string,mixed>|null
     */
    private static function convert_email( array $a, int $index, array $ids, array $by_id, array &$skipped ): ?array {
        $to = self::detag( (string) ( $a["to"] ?? "" ), $ids, $by_id, $skipped );
        if ( "" === trim( $to ) ) {
            return null;
        }
        $body = (string) ( "plain" === ( $a["email_format"] ?? "html" ) && "" !== trim( (string) ( $a["email_message_plain"] ?? "" ) ) ? $a["email_message_plain"] : ( $a["email_message"] ?? "" ) );
        $name = (string) ( $a["label"] ?? "" );
        $out  = [
            "id"      => "ninja_" . ( $index + 1 ),
            "name"    => "" !== $name ? $name : __( "Admin notification", "trinity-forms" ),
            "to"      => $to,
            "subject" => self::detag( (string) ( $a["email_subject"] ?? "" ), $ids, $by_id, $skipped ),
            "body"    => self::detag( "" !== trim( $body ) ? $body : "{fields_table}", $ids, $by_id, $skipped ),
            "enabled" => ! isset( $a["active"] ) || ! empty( $a["active"] ),
            "event"   => "submission",
        ];
        foreach ( [ "from_name" => "from_name", "from_address" => "from_email", "reply_to" => "reply_to", "cc" => "cc", "bcc" => "bcc" ] as $from => $to_key ) {
            if ( "" !== trim( (string) ( $a[ $from ] ?? "" ) ) ) {
                $out[ $to_key ] = self::detag( (string) $a[ $from ], $ids, $by_id, $skipped );
            }
        }
        if ( ! empty( $a["file_attachments"] ) || ! empty( $a["field_list_attachments"] ) ) {
            $out["attach_files"] = true;
        }
        if ( array_filter( (array) ( $a["conditions"]["when"] ?? [] ), static fn( $w ) => is_array( $w ) && "" !== (string) ( $w["key"] ?? "" ) ) ) {
            $skipped[] = self::rules_skipped( $out["name"] );
        }
        return $out;
    }

    /**
     * Ninja merge tags ({field:email}, {wp:admin_email}, {all_fields_table}) as ours.
     *
     * @param array<string,string> $ids
     * @param array<string,string> $by_id
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $ids, array $by_id, array &$skipped ): string {
        return (string) preg_replace_callback( '/\{([a-z_]+(?::[^{}\s]+)?)\}/i', static function ( $m ) use ( $ids, $by_id, &$skipped ) {
            $tag = $m[1];
            if ( str_starts_with( $tag, "field:" ) ) {
                $key = substr( $tag, 6 );
                $key = isset( $ids[ $key ] ) ? $key : ( $by_id[ $key ] ?? $key );
                if ( isset( $ids[ $key ] ) ) {
                    return "{" . $ids[ $key ] . "}";
                }
            } elseif ( isset( self::TAGS[ $tag ] ) ) {
                return self::TAGS[ $tag ];
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Submissions (nf_sub posts), oldest first, with the post's GMT date.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        global $wpdb;
        $data = self::form_data( (int) $source_id );
        if ( null === $data ) {
            return 0;
        }
        $ids   = self::ids( $data["fields"] );
        $types = array_column( $fields, "type", "id" );
        $map   = [];
        foreach ( $data["fields"] as $f ) {
            if ( isset( $f["id"], $ids[ (string) ( $f["key"] ?? "" ) ] ) ) {
                $map[ "_field_" . $f["id"] ] = $ids[ (string) $f["key"] ];
            }
        }
        $count = 0;
        $last  = 0;
        do {
            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, p.post_date_gmt, p.post_author FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_form_id'
                  WHERE p.post_type = 'nf_sub' AND p.post_status = 'publish' AND m.meta_value = %s AND p.ID > %d ORDER BY p.ID ASC LIMIT 200",
                (string) (int) $source_id,
                $last
            ), ARRAY_A );
            foreach ( (array) $posts as $post ) {
                $last   = (int) $post["ID"];
                $values = [];
                foreach ( (array) get_post_meta( $last ) as $key => $stored ) {
                    if ( ! isset( $map[ $key ] ) ) {
                        continue;
                    }
                    $tid   = $map[ $key ];
                    $value = maybe_unserialize( $stored[0] ?? "" );
                    $type  = $types[ $tid ] ?? "text";
                    if ( "consent" === $type ) {
                        $value = ! empty( $value ) && "unchecked" !== $value ? "1" : "";
                    } elseif ( is_array( $value ) && ! in_array( $type, [ "checkbox", "multiselect" ], true ) ) {
                        $value = implode( ", ", array_map( "strval", $value ) );
                    }
                    $values[ $tid ] = $value;
                }
                if ( self::add_entry( $form_id, [
                    "source_entry_id" => $last,
                    "submitted_at" => (string) $post["post_date_gmt"],
                    "user_id"      => (int) $post["post_author"],
                    "ip"           => (string) get_post_meta( $last, "_sub_ip", true ),
                ], $values, $fields ) ) {
                    $count++;
                }
            }
        } while ( count( (array) $posts ) === 200 );
        return $count;
    }
}
