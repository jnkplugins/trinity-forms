<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading Everest Forms' posts and entries; works while Everest Forms is deactivated.

/**
 * Everest Forms.
 *
 * An `everest_form` post holds the whole form as JSON in post_content: fields
 * keyed by id, a "structure" of rows and grid columns that gives their order and
 * layout, and settings with the email "connections". Read through
 * evf()->form->get() while Everest Forms is active, else from the post.
 * Entries are in `evf_entries` (every answer in its `fields` JSON, UTC date).
 */
class Everest_Forms extends Base_Importer {

    public const POST_TYPE = "everest_form";

    /** Everest field type => Trinity field type. */
    private const FIELD_MAP = [
        "first-name"     => "text",
        "last-name"      => "text",
        "text"           => "text",
        "textarea"       => "textarea",
        "wysiwyg"        => "textarea",
        "email"          => "email",
        "url"            => "website",
        "phone"          => "phone",
        "number"         => "number",
        "select"         => "select",
        "radio"          => "radio",
        "checkbox"       => "checkbox",
        "country"        => "country",
        "address"        => "address",
        "date-time"      => "date",
        "privacy-policy" => "consent",
        "hidden"         => "hidden",
        "html"           => "html",
        "title"          => "section",
        "divider"        => "section",
        "file-upload"    => "file",
        "image-upload"   => "file",
        "range-slider"   => "range",
        "rating"         => "rating",
        "password"       => "password",
    ];

    private const SILENT = [ "captcha", "recaptcha", "hcaptcha", "turnstile", "reset", "private-note", "progress" ];

    /** Everest (Conditional Logic add-on) operator => Trinity operator. */
    private const OPERATORS = [
        "is"               => "equals",
        "=="               => "equals",
        "is_not"           => "not_equals",
        "!="               => "not_equals",
        "empty"            => "empty",
        "not_empty"        => "not_empty",
        "greater_than"     => "greater_than",
        ">"                => "greater_than",
        "less_than"        => "less_than",
        "<"                => "less_than",
        "contains"         => "contains",
        "does_not_contain" => "not_contains",
        "starts_with"      => "starts_with",
        "ends_with"        => "ends_with",
    ];

    /** Everest smart tags => ours. */
    private const TAGS = [
        "all_fields"      => "{all_fields}",
        "admin_email"     => "{admin_email}",
        "site_name"       => "{site_name}",
        "site_url"        => "{site_url}",
        "page_title"      => "{post:post_title}",
        "post_title"      => "{post:post_title}",
        "page_url"        => "{embed_url}",
        "page_id"         => "{post:ID}",
        "form_name"       => "{form_title}",
        "form_id"         => "{form_id}",
        "entry_id"        => "{entry_id}",
        "user_ip_address" => "{ip}",
        "user_agent"      => "{user_agent}",
        "referrer_url"    => "{referer}",
        "current_date"    => "{date}",
        "user_id"         => "{user:ID}",
        "user_name"       => "{user:user_login}",
        "display_name"    => "{user:display_name}",
        "first_name"      => "{user:first_name}",
        "last_name"       => "{user:last_name}",
        "user_email"      => "{user:user_email}",
    ];

    public function slug(): string {
        return "everest-forms";
    }

    public function label(): string {
        return "Everest Forms";
    }

    public function is_available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' LIMIT 1", self::POST_TYPE ) );
    }

    public function forms(): array {
        global $wpdb;
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", self::POST_TYPE ), ARRAY_A );
        $counts = [];
        if ( self::table_readable( $wpdb->prefix . "evf_entries" ) ) {
            foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS n FROM {$wpdb->prefix}evf_entries WHERE status = 'publish' GROUP BY form_id", ARRAY_A ) as $r ) {
                $counts[ (int) $r["form_id"] ] = (int) $r["n"];
            }
        }
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row["ID"] ] = [ "title" => (string) $row["post_title"], "entries" => $counts[ (int) $row["ID"] ] ?? 0 ];
        }
        return $out;
    }

    public function supports_entries(): bool {
        return true;
    }

    protected function count_entries( $source_id ): int {
        return (int) ( $this->forms()[ (int) $source_id ]["entries"] ?? 0 );
    }

    /** @return array<string,mixed>|null */
    private static function form_data( int $id ): ?array {
        $post = get_post( $id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return null;
        }
        $data = null;
        if ( function_exists( "evf" ) && isset( evf()->form ) && method_exists( evf()->form, "get" ) ) {
            $data = evf()->form->get( $id, [ "content_only" => true ] );
        }
        if ( ! is_array( $data ) ) {
            $data = json_decode( (string) $post->post_content, true );
        }
        if ( ! is_array( $data ) ) {
            return null;
        }
        $data["form_fields"] = array_filter( (array) ( $data["form_fields"] ?? [] ), "is_array" );
        $data["settings"]    = (array) ( $data["settings"] ?? [] );
        $data["title"]       = "" !== (string) ( $data["settings"]["form_title"] ?? "" ) ? (string) $data["settings"]["form_title"] : (string) $post->post_title;
        return $data;
    }

    /**
     * Fields in the order of the rows and columns, each with the width of its
     * column ("" when the row has one column).
     *
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    private static function ordered( array $data ): array {
        $fields = $data["form_fields"];
        $out    = [];
        foreach ( (array) ( $data["structure"] ?? [] ) as $row ) {
            $grids = array_values( (array) $row );
            $width = [ 2 => "50", 3 => "33", 4 => "25" ][ count( $grids ) ] ?? "";
            foreach ( $grids as $grid ) {
                foreach ( (array) $grid as $key ) {
                    if ( isset( $fields[ $key ] ) ) {
                        $out[] = $fields[ $key ] + [ "id" => $key, "__width" => $width ];
                        unset( $fields[ $key ] );
                    }
                }
            }
        }
        foreach ( $fields as $key => $f ) {
            $out[] = $f + [ "id" => $key, "__width" => "" ];
        }
        return $out;
    }

    /**
     * Everest field id => Trinity field id (from the field's meta key).
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,string>
     */
    private static function ids( array $fields ): array {
        $ids = [];
        foreach ( $fields as $f ) {
            if ( isset( self::FIELD_MAP[ (string) ( $f["type"] ?? "" ) ] ) ) {
                $meta = (string) ( $f["meta-key"] ?? "" );
                $base = "" !== $meta ? preg_replace( '/_\d{3,}$/', "", $meta ) : (string) ( $f["label"] ?? "" );
                $ids[ (string) $f["id"] ] = self::unique_id( (string) $base, "field_" . count( $ids ), array_values( $ids ) );
            }
        }
        return $ids;
    }

    public function convert( $source_id ): ?array {
        $data = self::form_data( (int) $source_id );
        if ( null === $data ) {
            return null;
        }
        $ordered = self::ordered( $data );
        $ids     = self::ids( $ordered );
        $by_id   = array_column( $ordered, null, "id" );
        $skipped = [];
        $fields  = [];
        foreach ( $ordered as $f ) {
            $type  = (string) ( $f["type"] ?? "" );
            $label = (string) ( $f["label"] ?? "" );
            if ( in_array( $type, self::SILENT, true ) ) {
                continue;
            }
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                $skipped[] = self::field_skipped( $label, $type );
                continue;
            }
            $field = self::convert_field( $f, $ids[ (string) $f["id"] ], $skipped );
            if ( ! empty( $f["conditional_logic_status"] ) && ! empty( $f["conditionals"] ) ) {
                $rules = self::convert_rules( (array) $f["conditionals"], "hide" === ( $f["conditional_option"] ?? "show" ) ? "hide" : "show", $ids, $by_id );
                if ( $rules ) {
                    $field["conditional"] = $rules;
                } else {
                    $skipped[] = self::rules_skipped( $field["label"] );
                }
            }
            $fields[] = $field;
        }

        $s        = $data["settings"];
        $settings = [];
        if ( "" !== trim( (string) ( $s["submit_button_text"] ?? "" ) ) ) {
            $settings["submit_button_text"] = (string) $s["submit_button_text"];
        }
        switch ( (string) ( $s["redirect_to"] ?? "same" ) ) {
            case "custom_page":
                $url = ! empty( $s["custom_page"] ) ? (string) get_permalink( (int) $s["custom_page"] ) : "";
                if ( "" !== $url ) {
                    $settings["redirect_url"] = $url;
                }
                break;
            case "external_url":
                if ( "" !== trim( (string) ( $s["external_url"] ?? "" ) ) ) {
                    $settings["redirect_url"] = self::detag( (string) $s["external_url"], $ids, $skipped );
                }
                break;
            default:
                if ( "" !== trim( wp_strip_all_tags( (string) ( $s["successful_form_submission_message"] ?? "" ) ) ) ) {
                    $settings["confirmation_message"] = self::detag( (string) $s["successful_form_submission_message"], $ids, $skipped );
                }
        }

        $notifications = [];
        $all_on        = ! isset( $s["email"]["enable_email_notification"] ) || ! empty( $s["email"]["enable_email_notification"] );
        foreach ( (array) ( $s["email"] ?? [] ) as $key => $c ) {
            if ( ! is_array( $c ) ) {
                continue;
            }
            $to = self::detag( (string) ( $c["evf_to_email"] ?? "" ), $ids, $skipped );
            if ( "" === trim( $to ) ) {
                continue;
            }
            $name = (string) ( $c["connection_name"] ?? "" );
            $n    = [
                "id"      => sanitize_key( "everest_" . $key ),
                "name"    => "" !== $name ? $name : __( "Admin notification", "trinity-forms" ),
                "to"      => $to,
                /* translators: {site_name} is a merge tag and must stay untranslated. */
                "subject" => "" !== trim( (string) ( $c["evf_email_subject"] ?? "" ) ) ? self::detag( trim( (string) $c["evf_email_subject"] ), $ids, $skipped ) : __( "New form entry on {site_name}", "trinity-forms" ),
                "body"    => self::detag( (string) ( $c["evf_email_message"] ?? "{all_fields}" ), $ids, $skipped ),
                "enabled" => $all_on && ( ! isset( $c["enable_email_notification"] ) || ! empty( $c["enable_email_notification"] ) ),
                "event"   => "submission",
            ];
            foreach ( [ "evf_from_name" => "from_name", "evf_from_email" => "from_email", "evf_reply_to" => "reply_to", "evf_carboncopy" => "cc", "evf_blindcarboncopy" => "bcc" ] as $from => $to_key ) {
                if ( "" !== trim( (string) ( $c[ $from ] ?? "" ) ) ) {
                    $n[ $to_key ] = self::detag( (string) $c[ $from ], $ids, $skipped );
                }
            }
            if ( ! empty( $c["file-email-attachments"] ) ) {
                $n["attach_files"] = true;
            }
            if ( ! empty( $c["conditional_logic_status"] ) && ! empty( $c["conditionals"] ) ) {
                $rules = self::convert_rules( (array) $c["conditionals"], "dont_send" === ( $c["conditional_option"] ?? "send" ) ? "hide" : "show", $ids, $by_id );
                if ( $rules ) {
                    $n["conditional"] = $rules;
                } else {
                    $skipped[] = self::rules_skipped( $n["name"] );
                }
            }
            $notifications[] = $n;
        }

        return [
            "title"         => $data["title"],
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
        if ( "" !== trim( wp_strip_all_tags( (string) ( $f["description"] ?? "" ) ) ) ) {
            $field["help"] = trim( wp_strip_all_tags( (string) $f["description"] ) );
        }
        if ( "" !== trim( (string) ( $f["css"] ?? "" ) ) ) {
            $field["css_class"] = (string) $f["css"];
        }
        if ( ! empty( $f["__width"] ) ) {
            $field["field_width"] = (string) $f["__width"];
        }
        $default = (string) ( $f["default_value"] ?? "" );

        switch ( $type ) {
            case "text":
            case "textarea":
                if ( ! empty( $f["limit_enabled"] ) && ! empty( $f["limit_count"] ) && "characters" === ( $f["limit_mode"] ?? "characters" ) ) {
                    $field["max_length"] = (int) $f["limit_count"];
                }
                break;
            case "number":
            case "range-slider":
                foreach ( [ "min_value" => "min", "max_value" => "max", "step" => "step" ] as $from => $to ) {
                    if ( isset( $f[ $from ] ) && "" !== (string) $f[ $from ] ) {
                        $field[ $to ] = (string) $f[ $from ];
                    }
                }
                if ( "range-slider" === $type && "" !== $default ) {
                    $field["default"] = $default;
                    $default          = "";
                }
                break;
            case "date-time":
                if ( "time" === ( $f["datetime_format"] ?? "date" ) ) {
                    $field["type"] = "time";
                } elseif ( "date-time" === ( $f["datetime_format"] ?? "date" ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the time part", "trinity-forms" ), $label );
                }
                break;
            case "select":
                if ( ! empty( $f["multiple_choices"] ) ) {
                    $field["type"] = "multiselect";
                }
                break;
            case "privacy-policy":
                $field["consent_text"] = trim( wp_strip_all_tags( (string) ( $f["consent_message"] ?? $label ) ) );
                $default               = "";
                break;
            case "file-upload":
            case "image-upload":
                $ext = array_filter( array_map( "trim", explode( ",", (string) ( $f["extensions"] ?? "" ) ) ) );
                if ( $ext ) {
                    $field["accept"] = implode( ",", array_map( static fn( $e ) => "." . ltrim( strtolower( $e ), "." ), $ext ) );
                } elseif ( "image-upload" === $type ) {
                    $field["accept"] = ".jpg,.jpeg,.png,.gif";
                }
                if ( is_numeric( $f["max_size"] ?? null ) && (float) $f["max_size"] > 0 ) {
                    $field["max_size"] = max( 1, (int) ceil( (float) $f["max_size"] ) );
                }
                if ( (int) ( $f["max_file_number"] ?? 1 ) > 1 ) {
                    $field["multiple"] = true;
                }
                break;
            case "hidden":
                $field["default"] = $default;
                $default          = "";
                unset( $field["required"] );
                break;
            case "html":
                $field["html"] = (string) ( $f["code"] ?? "" );
                unset( $field["required"] );
                break;
            case "title":
            case "divider":
                $field["description"] = trim( wp_strip_all_tags( (string) ( $f["description"] ?? "" ) ) );
                unset( $field["required"], $field["help"] );
                break;
            case "rating":
                $field["max"] = (string) max( 2, (int) ( $f["number_of_stars"] ?? 5 ) );
                $default      = "";
                break;
            case "wysiwyg":
                /* translators: %s: field label. */
                $skipped[] = sprintf( __( "%s: rich-text formatting (now a plain text box)", "trinity-forms" ), $label );
                break;
        }

        if ( in_array( $field["type"], [ "select", "multiselect", "radio", "checkbox" ], true ) ) {
            $field["options"] = [];
            $selected         = [];
            foreach ( (array) ( $f["choices"] ?? [] ) as $choice ) {
                if ( ! is_array( $choice ) ) {
                    continue;
                }
                $value              = self::choice_value( $f, $choice );
                $field["options"][] = [ "label" => (string) ( $choice["label"] ?? "" ), "value" => $value ];
                if ( ! empty( $choice["default"] ) ) {
                    $selected[] = $value;
                }
            }
            if ( $selected ) {
                $default = implode( ",", $selected );
            }
            if ( ! in_array( $field["type"], [ "select", "multiselect" ], true ) ) {
                unset( $field["placeholder"] );
            }
            if ( "checkbox" === $field["type"] && ! empty( $f["choice_limit"] ) ) {
                /* translators: %s: field label. */
                $skipped[] = sprintf( __( "%s: the limit on how many choices can be ticked", "trinity-forms" ), $label );
            }
        }
        if ( "" !== trim( $default ) ) {
            $field["default_value"] = $default;
        }
        return $field;
    }

    /** What Everest stores for a choice: its value when values are shown, else its label. */
    private static function choice_value( array $f, array $choice ): string {
        $value = (string) ( $choice["value"] ?? "" );
        return ! empty( $f["show_values"] ) && "" !== $value ? $value : (string) ( $choice["label"] ?? "" );
    }

    /**
     * @param array<int|string,mixed>             $groups
     * @param array<string,string>                $ids
     * @param array<string,array<string,mixed>>   $by_id
     * @return array<string,mixed>|null
     */
    private static function convert_rules( array $groups, string $action, array $ids, array $by_id ): ?array {
        $out = [];
        foreach ( $groups as $group ) {
            $rules = [];
            foreach ( (array) $group as $rule ) {
                $target = (string) ( $rule["field"] ?? "" );
                $op     = (string) ( $rule["operator"] ?? "is" );
                if ( "" === $target ) {
                    continue;
                }
                if ( ! isset( $ids[ $target ], self::OPERATORS[ $op ] ) ) {
                    return null;
                }
                $value  = (string) ( $rule["value"] ?? "" );
                $choice = $by_id[ $target ]["choices"][ $value ] ?? null;
                if ( is_array( $choice ) ) {
                    $value = self::choice_value( $by_id[ $target ], $choice );
                }
                $rules[] = [ "field" => $ids[ $target ], "operator" => self::OPERATORS[ $op ], "value" => in_array( self::OPERATORS[ $op ], [ "empty", "not_empty" ], true ) ? "" : $value ];
            }
            if ( $rules ) {
                $out[] = $rules;
            }
        }
        return self::rule_set( $action, $out );
    }

    /**
     * Everest smart tags ({field_id="email"}, {admin_email}) as ours.
     *
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $ids, array &$skipped ): string {
        return (string) preg_replace_callback( '/\{([a-z_]+)(?:="([^"]*)")?\}/', static function ( $m ) use ( $ids, &$skipped ) {
            $tag = $m[1];
            if ( "field_id" === $tag ) {
                $key = (string) strtok( (string) ( $m[2] ?? "" ), "|" );
                if ( isset( $ids[ $key ] ) ) {
                    return "{" . $ids[ $key ] . "}";
                }
            } elseif ( ! isset( $m[2] ) && isset( self::TAGS[ $tag ] ) ) {
                return self::TAGS[ $tag ];
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Entries, oldest first (UTC, like ours).
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        global $wpdb;
        $data = self::form_data( (int) $source_id );
        if ( null === $data || ! self::table_readable( $wpdb->prefix . "evf_entries" ) ) {
            return 0;
        }
        $ids   = self::ids( self::ordered( $data ) );
        $types = array_column( $fields, "type", "id" );
        $count = 0;
        $last  = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT entry_id, user_id, user_device, user_ip_address, referer, fields, viewed, starred, date_created FROM {$wpdb->prefix}evf_entries
                  WHERE form_id = %d AND status = 'publish' AND entry_id > %d ORDER BY entry_id ASC LIMIT 200",
                (int) $source_id,
                $last
            ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $last   = (int) $row["entry_id"];
                $values = [];
                foreach ( (array) json_decode( (string) $row["fields"], true ) as $key => $answer ) {
                    $eid = (string) ( $answer["id"] ?? $key );
                    if ( ! isset( $ids[ $eid ] ) || ! array_key_exists( "value", (array) $answer ) ) {
                        continue;
                    }
                    $tid   = $ids[ $eid ];
                    $type  = $types[ $tid ] ?? "text";
                    $value = $answer["value"];
                    // Choices keep what was posted (the choice value) in value_raw, and labels in value.
                    if ( in_array( $type, [ "radio", "select", "checkbox", "multiselect" ], true ) && isset( $answer["value_raw"] ) && "" !== $answer["value_raw"] ) {
                        $value = $answer["value_raw"];
                    } elseif ( is_array( $value ) && isset( $value["label"] ) ) {
                        $value = $value["label"];
                    }
                    if ( in_array( $type, [ "checkbox", "multiselect" ], true ) && ! is_array( $value ) ) {
                        $value = array_values( array_filter( array_map( "trim", preg_split( '/\R/', (string) $value ) ), "strlen" ) );
                    } elseif ( "consent" === $type ) {
                        $value = ! empty( $value ) ? "1" : "";
                    } elseif ( is_array( $value ) && ! in_array( $type, [ "checkbox", "multiselect" ], true ) ) {
                        $value = implode( ", ", array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : "", $value ) );
                    }
                    $values[ $tid ] = $value;
                }
                if ( self::add_entry( $form_id, [
                    "source_entry_id" => $last,
                    "submitted_at" => (string) $row["date_created"],
                    "ip"           => (string) $row["user_ip_address"],
                    "user_agent"   => (string) $row["user_device"],
                    "user_id"      => (int) $row["user_id"],
                    "referer"      => (string) $row["referer"],
                    "is_read"      => ! empty( $row["viewed"] ),
                    "is_starred"   => ! empty( $row["starred"] ),
                ], $values, $fields ) ) {
                    $count++;
                }
            }
        } while ( count( (array) $rows ) === 200 );
        return $count;
    }
}
