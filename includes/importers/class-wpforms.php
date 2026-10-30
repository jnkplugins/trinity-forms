<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reading WPForms' posts and entries table; works while WPForms is deactivated.

/**
 * WPForms (Lite and Pro).
 *
 * A form is a `wpforms` post whose post_content is the whole form as JSON: fields
 * keyed by numeric id, settings with notifications and confirmations. Read through
 * wpforms()->obj( "form" ) while WPForms is active, else from the post itself.
 *
 * Lite keeps no entries (only a count). Pro keeps them in `wpforms_entries`, one row
 * per entry with every answer in its `fields` JSON; those are brought across.
 */
class WPForms extends Base_Importer {

    public const POST_TYPE = "wpforms";

    /** WPForms field type => Trinity field type. */
    private const FIELD_MAP = [
        "text"          => "text",
        "textarea"      => "textarea",
        "richtext"      => "textarea",
        "email"         => "email",
        "name"          => "name",
        "number"        => "number",
        "number-slider" => "range",
        "phone"         => "phone",
        "url"           => "website",
        "address"       => "address",
        "date-time"     => "date",
        "select"        => "select",
        "radio"         => "radio",
        "checkbox"      => "checkbox",
        "gdpr-checkbox" => "consent",
        "file-upload"   => "file",
        "hidden"        => "hidden",
        "html"          => "html",
        "content"       => "html",
        "divider"       => "section",
        "pagebreak"     => "page_break",
        "password"      => "password",
        "rating"        => "rating",
    ];

    /** WPForms operator => Trinity operator. */
    private const OPERATORS = [
        "=="  => "equals",
        "!="  => "not_equals",
        "c"   => "contains",
        "!c"  => "not_contains",
        "^"   => "starts_with",
        "~"   => "ends_with",
        ">"   => "greater_than",
        "<"   => "less_than",
        "e"   => "empty",
        "!e"  => "not_empty",
    ];

    /** WPForms smart tags => ours. */
    private const TAGS = [
        "all_fields"        => "{all_fields}",
        "admin_email"       => "{admin_email}",
        "form_name"         => "{form_title}",
        "form_id"           => "{form_id}",
        "entry_id"          => "{entry_id}",
        "entry_details_url" => "{entry_url}",
        "entry_date"        => "{date}",
        "page_url"          => "{embed_url}",
        "page_title"        => "{post:post_title}",
        "page_id"           => "{post:ID}",
        "url_referer"       => "{referer}",
        "user_ip"           => "{ip}",
        "user_agent"        => "{user_agent}",
        "site_name"         => "{site_name}",
        "user_id"           => "{user:ID}",
        "user_email"        => "{user:user_email}",
        "user_display"      => "{user:display_name}",
        "user_first_name"   => "{user:first_name}",
        "user_last_name"    => "{user:last_name}",
        "user_full_name"    => "{user:display_name}",
    ];

    public function slug(): string {
        return "wpforms";
    }

    public function label(): string {
        return "WPForms";
    }

    public function is_available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' LIMIT 1", self::POST_TYPE ) );
    }

    public function forms(): array {
        global $wpdb;
        $rows    = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", self::POST_TYPE ), ARRAY_A );
        $entries = $this->supports_entries();
        $out     = [];
        foreach ( (array) $rows as $row ) {
            $id         = (int) $row["ID"];
            $out[ $id ] = [ "title" => (string) $row["post_title"], "entries" => $entries ? $this->count_entries( $id ) : null ];
        }
        return $out;
    }

    /** Only WPForms Pro keeps entries, in a table Lite never creates. */
    public function supports_entries(): bool {
        global $wpdb;
        return self::table_readable( $wpdb->prefix . "wpforms_entries" );
    }

    protected function count_entries( $source_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforms_entries WHERE form_id = %d AND status NOT IN ('spam','trash','partial','abandoned')", (int) $source_id ) );
    }

    /**
     * The form as WPForms stores it.
     *
     * @return array<string,mixed>|null
     */
    public static function form_data( int $id ): ?array {
        $post = get_post( $id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return null;
        }
        $data = null;
        if ( function_exists( "wpforms" ) && function_exists( "wpforms_decode" ) ) {
            $obj  = method_exists( wpforms(), "obj" ) ? wpforms()->obj( "form" ) : null;
            $data = $obj ? $obj->get( $id, [ "content_only" => true ] ) : null;
        }
        if ( ! is_array( $data ) ) {
            $data = json_decode( (string) $post->post_content, true );
        }
        if ( ! is_array( $data ) ) {
            return null;
        }
        $data["fields"]   = isset( $data["fields"] ) && is_array( $data["fields"] ) ? $data["fields"] : [];
        $data["settings"] = isset( $data["settings"] ) && is_array( $data["settings"] ) ? $data["settings"] : [];
        if ( "" === (string) ( $data["settings"]["form_title"] ?? "" ) ) {
            $data["settings"]["form_title"] = (string) $post->post_title;
        }
        return $data;
    }

    public function convert( $source_id ): ?array {
        $data = self::form_data( (int) $source_id );
        if ( null === $data ) {
            return null;
        }
        $settings_in = $data["settings"];
        $skipped     = [];

        // WPForms keys fields by id (an object); some stores have a plain list.
        $source = [];
        foreach ( $data["fields"] as $key => $f ) {
            if ( is_array( $f ) ) {
                $source[ (string) ( $f["id"] ?? $key ) ] = $f;
            }
        }

        // Ids first, so a rule can point at a field further down the form.
        $ids = [];
        foreach ( $source as $wid => $f ) {
            if ( isset( self::FIELD_MAP[ (string) ( $f["type"] ?? "" ) ] ) && ! self::is_wrapper_break( $f ) ) {
                $ids[ $wid ] = self::unique_id( self::name_of( $f ), "field_" . $wid, array_values( $ids ) );
            }
        }

        $fields = [];
        foreach ( $source as $wid => $f ) {
            $type = (string) ( $f["type"] ?? "" );
            if ( self::is_wrapper_break( $f ) ) {
                continue;
            }
            if ( ! isset( $ids[ $wid ] ) ) {
                $skipped[] = self::field_skipped( (string) ( $f["label"] ?? "" ), $type );
                continue;
            }
            $field = self::convert_field( $f, $ids[ $wid ], $skipped );

            if ( ! empty( $f["conditional_logic"] ) && ! empty( $f["conditionals"] ) ) {
                $rules = self::convert_rules( (array) $f["conditionals"], "hide" === ( $f["conditional_type"] ?? "show" ) ? "hide" : "show", $source, $ids );
                if ( $rules ) {
                    $field["conditional"] = $rules;
                } else {
                    $skipped[] = self::rules_skipped( $field["label"] );
                }
            }
            $fields[] = $field;
        }

        $settings = [];
        if ( "" !== trim( (string) ( $settings_in["submit_text"] ?? "" ) ) ) {
            $settings["submit_button_text"] = (string) $settings_in["submit_text"];
        }
        self::convert_confirmations( (array) ( $settings_in["confirmations"] ?? [] ), $settings, $source, $ids, $skipped );

        $notifications = [];
        if ( ! isset( $settings_in["notification_enable"] ) || ! empty( $settings_in["notification_enable"] ) ) {
            foreach ( (array) ( $settings_in["notifications"] ?? [] ) as $key => $n ) {
                if ( is_array( $n ) ) {
                    $converted = self::convert_notification( $n, (string) $key, $source, $ids, $skipped );
                    if ( $converted ) {
                        $notifications[] = $converted;
                    }
                }
            }
        }

        return [
            "title"         => (string) $settings_in["form_title"],
            "fields"        => $fields,
            "settings"      => $settings,
            "notifications" => $notifications,
            "skipped"       => array_values( array_unique( $skipped ) ),
        ];
    }

    /** What a field is called: its label, or a page break's title. */
    private static function name_of( array $f ): string {
        return (string) ( "pagebreak" === ( $f["type"] ?? "" ) ? ( $f["title"] ?? "" ) : ( $f["label"] ?? "" ) );
    }

    /** The page breaks WPForms adds at the very top and bottom only hold navigation settings. */
    private static function is_wrapper_break( array $f ): bool {
        return "pagebreak" === ( $f["type"] ?? "" ) && in_array( (string) ( $f["position"] ?? "" ), [ "top", "bottom" ], true );
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
        if ( "" !== trim( (string) ( $f["description"] ?? "" ) ) ) {
            $field["help"] = wp_strip_all_tags( (string) $f["description"] );
        }
        if ( "" !== trim( (string) ( $f["placeholder"] ?? "" ) ) ) {
            $field["placeholder"] = (string) $f["placeholder"];
        }
        if ( "" !== trim( (string) ( $f["css"] ?? "" ) ) ) {
            $field["css_class"] = (string) $f["css"];
        }
        $field   = self::width_from_class( $field );
        $default = (string) ( $f["default_value"] ?? "" );

        switch ( $type ) {
            case "name":
                if ( "simple" === ( $f["format"] ?? "" ) ) {
                    $field["type"] = "text";
                } elseif ( "first-middle-last" === ( $f["format"] ?? "" ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the middle name box", "trinity-forms" ), $label );
                }
                break;
            case "email":
                if ( ! empty( $f["confirmation"] ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the second box that repeats the email", "trinity-forms" ), $label );
                }
                break;
            case "number-slider":
                foreach ( [ "min", "max", "step" ] as $k ) {
                    if ( isset( $f[ $k ] ) && "" !== (string) $f[ $k ] ) {
                        $field[ $k ] = (string) $f[ $k ];
                    }
                }
                if ( "" !== $default ) {
                    $field["default"] = $default;
                    $default          = "";
                }
                break;
            case "date-time":
                if ( "time" === ( $f["format"] ?? "" ) ) {
                    $field["type"] = "time";
                } elseif ( "date-time" === ( $f["format"] ?? "date-time" ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the time part", "trinity-forms" ), $label );
                }
                break;
            case "select":
                if ( ! empty( $f["multiple"] ) ) {
                    $field["type"] = "multiselect";
                }
                break;
            case "checkbox":
                if ( ! empty( $f["choice_limit"] ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the limit on how many choices can be ticked", "trinity-forms" ), $label );
                }
                break;
            case "gdpr-checkbox":
                $choice                = is_array( $f["choices"] ?? null ) ? reset( $f["choices"] ) : [];
                $field["consent_text"] = wp_strip_all_tags( (string) ( $choice["label"] ?? $label ) );
                $field["required"]     = true;
                break;
            case "file-upload":
                $ext = array_filter( array_map( "trim", explode( ",", (string) ( $f["extensions"] ?? "" ) ) ) );
                if ( $ext ) {
                    $field["accept"] = implode( ",", array_map( static fn( $e ) => "." . ltrim( strtolower( $e ), "." ), $ext ) );
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
            case "content":
                $field["html"] = (string) ( $f["code"] ?? $f["content"] ?? "" );
                unset( $field["required"] );
                break;
            case "divider":
                if ( "" !== trim( (string) ( $f["description"] ?? "" ) ) ) {
                    $field["description"] = wp_strip_all_tags( (string) $f["description"] );
                    unset( $field["help"] );
                }
                unset( $field["required"] );
                break;
            case "pagebreak":
                $field["label"] = (string) ( $f["title"] ?? "" );
                if ( "" !== trim( (string) ( $f["next"] ?? "" ) ) ) {
                    $field["next_text"] = (string) $f["next"];
                }
                if ( "" !== trim( (string) ( $f["prev"] ?? "" ) ) ) {
                    $field["prev_text"] = (string) $f["prev"];
                }
                unset( $field["required"] );
                break;
            case "rating":
                $field["max"] = (string) max( 2, (int) ( $f["scale"] ?? 5 ) );
                break;
            case "richtext":
                /* translators: %s: field label. */
                $skipped[] = sprintf( __( "%s: rich-text formatting (now a plain text box)", "trinity-forms" ), $label );
                break;
        }

        if ( in_array( $field["type"], [ "select", "multiselect", "radio", "checkbox" ], true ) ) {
            $field["options"] = self::options( $f );
            $defaults         = [];
            foreach ( (array) ( $f["choices"] ?? [] ) as $choice ) {
                if ( is_array( $choice ) && ! empty( $choice["default"] ) ) {
                    $defaults[] = self::choice_value( $f, $choice );
                }
            }
            if ( $defaults ) {
                $field["default_value"] = implode( ",", $defaults );
            }
            if ( ! empty( $f["dynamic_choices"] ) ) {
                /* translators: %s: field label. */
                $skipped[] = sprintf( __( "%s: choices filled from posts or categories", "trinity-forms" ), $label );
            }
        } elseif ( "" !== $default ) {
            $field["default_value"] = $default;
        }

        return $field;
    }

    /** @return array<int,array{label:string,value:string}> */
    private static function options( array $f ): array {
        $out = [];
        foreach ( (array) ( $f["choices"] ?? [] ) as $choice ) {
            if ( is_array( $choice ) ) {
                $out[] = [ "label" => (string) ( $choice["label"] ?? "" ), "value" => self::choice_value( $f, $choice ) ];
            }
        }
        return $out;
    }

    /** What WPForms stores for a choice: its value when "show values" is on, else its label. */
    private static function choice_value( array $f, array $choice ): string {
        $value = (string) ( $choice["value"] ?? "" );
        return ! empty( $f["show_values"] ) && "" !== $value ? $value : (string) ( $choice["label"] ?? "" );
    }

    /**
     * WPForms rule groups (any group; every rule in a group) as ours. Rules on a
     * choice field hold the choice's key, so they become that choice's value.
     *
     * @param array<int|string,mixed>            $groups
     * @param array<string,array<string,mixed>>  $source
     * @param array<string,string>               $ids
     * @return array<string,mixed>|null
     */
    private static function convert_rules( array $groups, string $action, array $source, array $ids ): ?array {
        $out = [];
        foreach ( $groups as $group ) {
            $rules = [];
            foreach ( (array) $group as $rule ) {
                $target = (string) ( $rule["field"] ?? "" );
                $op     = (string) ( $rule["operator"] ?? "==" );
                if ( ! isset( $ids[ $target ], self::OPERATORS[ $op ] ) ) {
                    return null;
                }
                $value  = (string) ( $rule["value"] ?? "" );
                $choice = $source[ $target ]["choices"][ $value ] ?? null;
                if ( is_array( $choice ) && in_array( $op, [ "==", "!=" ], true ) ) {
                    $value = self::choice_value( $source[ $target ], $choice );
                }
                $rules[] = [ "field" => $ids[ $target ], "operator" => self::OPERATORS[ $op ], "value" => in_array( $op, [ "e", "!e" ], true ) ? "" : $value ];
            }
            if ( $rules ) {
                $out[] = $rules;
            }
        }
        return self::rule_set( $action, $out );
    }

    /**
     * The first confirmation without conditions is the default; ones with
     * conditions become extra confirmations, tried in order.
     *
     * @param array<int|string,mixed>           $confirmations
     * @param array<string,mixed>               $settings
     * @param array<string,array<string,mixed>> $source
     * @param array<string,string>              $ids
     * @param array<int,string>                 $skipped
     */
    private static function convert_confirmations( array $confirmations, array &$settings, array $source, array $ids, array &$skipped ): void {
        $extra       = [];
        $has_default = false;
        foreach ( $confirmations as $c ) {
            if ( ! is_array( $c ) ) {
                continue;
            }
            $type = (string) ( $c["type"] ?? "message" );
            $row  = [ "name" => (string) ( $c["name"] ?? "" ), "type" => "text", "message" => "", "page_id" => 0, "url" => "" ];
            if ( "page" === $type ) {
                $row["type"]    = "page";
                $row["page_id"] = (int) ( $c["page"] ?? 0 );
            } elseif ( "redirect" === $type ) {
                $row["type"] = "redirect";
                $row["url"]  = self::detag( (string) ( $c["redirect"] ?? "" ), $ids, $skipped );
            } else {
                $row["message"] = self::detag( (string) ( $c["message"] ?? "" ), $ids, $skipped );
            }

            $conditional = ! empty( $c["conditional_logic"] ) && ! empty( $c["conditionals"] );
            if ( $conditional ) {
                $rules = self::convert_rules( (array) $c["conditionals"], "go" === ( $c["conditional_type"] ?? "go" ) ? "show" : "hide", $source, $ids );
                if ( $rules && "show" === $rules["action"] ) {
                    $row["conditional"] = $rules;
                    $extra[]            = $row;
                    continue;
                }
                $skipped[] = self::rules_skipped( "" !== $row["name"] ? $row["name"] : __( "Confirmation message", "trinity-forms" ) );
            }
            if ( $has_default ) {
                continue;
            }
            $has_default = true;
            if ( "page" === $row["type"] ) {
                $url = $row["page_id"] ? (string) get_permalink( $row["page_id"] ) : "";
                if ( "" !== $url ) {
                    $settings["redirect_url"] = $url;
                }
            } elseif ( "redirect" === $row["type"] ) {
                if ( "" !== $row["url"] ) {
                    $settings["redirect_url"] = $row["url"];
                }
            } elseif ( "" !== trim( wp_strip_all_tags( $row["message"] ) ) ) {
                $settings["confirmation_message"] = $row["message"];
            }
        }
        if ( $extra ) {
            $settings["confirmations"] = $extra;
        }
    }

    /**
     * @param array<string,mixed>               $n
     * @param array<string,array<string,mixed>> $source
     * @param array<string,string>              $ids
     * @param array<int,string>                 $skipped
     * @return array<string,mixed>|null
     */
    private static function convert_notification( array $n, string $key, array $source, array $ids, array &$skipped ): ?array {
        $to = self::detag( (string) ( $n["email"] ?? "" ), $ids, $skipped );
        if ( "" === trim( $to ) ) {
            return null;
        }
        $name = (string) ( $n["notification_name"] ?? "" );
        $out  = [
            "id"      => sanitize_key( "wpforms_" . $key ),
            "name"    => "" !== $name ? $name : __( "Admin notification", "trinity-forms" ),
            "to"      => $to,
            /* translators: {site_name} is a merge tag and must stay untranslated. */
            "subject" => "" !== trim( (string) ( $n["subject"] ?? "" ) ) ? self::detag( (string) $n["subject"], $ids, $skipped ) : __( "New form entry on {site_name}", "trinity-forms" ),
            "body"    => self::detag( (string) ( $n["message"] ?? "{all_fields}" ), $ids, $skipped ),
            "enabled" => ! isset( $n["enable"] ) || ! empty( $n["enable"] ),
            "event"   => "submission",
        ];
        if ( "" !== trim( (string) ( $n["carboncopy"] ?? "" ) ) ) {
            $out["cc"] = self::detag( (string) $n["carboncopy"], $ids, $skipped );
        }
        if ( "" !== trim( (string) ( $n["sender_name"] ?? "" ) ) ) {
            $out["from_name"] = self::detag( (string) $n["sender_name"], $ids, $skipped );
        }
        $from = trim( (string) ( $n["sender_address"] ?? "" ) );
        if ( "" !== $from ) {
            $out["from_email"] = self::detag( $from, $ids, $skipped );
        }
        if ( "" !== trim( (string) ( $n["replyto"] ?? "" ) ) ) {
            $out["reply_to"] = self::detag( (string) $n["replyto"], $ids, $skipped );
        }
        if ( ! empty( $n["file_upload_attachment_enable"] ) ) {
            $out["attach_files"] = true;
        }
        if ( ! empty( $n["conditional_logic"] ) && ! empty( $n["conditionals"] ) ) {
            $rules = self::convert_rules( (array) $n["conditionals"], "send" === ( $n["conditional_type"] ?? "send" ) ? "show" : "hide", $source, $ids );
            if ( $rules ) {
                $out["conditional"] = $rules;
            } else {
                $skipped[] = self::rules_skipped( $out["name"] );
            }
        }
        return $out;
    }

    /**
     * WPForms smart tags ({field_id="3"}, {admin_email}, {date format="d/m/Y"}) as ours.
     *
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $ids, array &$skipped ): string {
        return (string) preg_replace_callback( '/\{([a-z_]+)(?:="([^"]*)"|\s+[a-z_]+="([^"]*)")?\}/', static function ( $m ) use ( $ids, &$skipped ) {
            $tag = $m[1];
            $arg = (string) ( ( $m[2] ?? "" ) . ( $m[3] ?? "" ) );
            if ( in_array( $tag, [ "field_id", "field_value_id", "field_html_id" ], true ) ) {
                $wid = (string) strtok( $arg, "|" );
                if ( isset( $ids[ $wid ] ) ) {
                    return "{" . $ids[ $wid ] . "}";
                }
            } elseif ( "date" === $tag ) {
                return "" !== $arg ? "{today:" . $arg . "}" : "{date}";
            } elseif ( isset( self::TAGS[ $tag ] ) ) {
                return self::TAGS[ $tag ];
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Pro entries, oldest first. WPForms stores the date in UTC and each answer
     * under the field's id, with "value" as the text it shows.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        global $wpdb;
        $data = self::form_data( (int) $source_id );
        if ( null === $data ) {
            return 0;
        }
        $ids  = [];
        $used = [];
        foreach ( $data["fields"] as $key => $f ) {
            $wid = (string) ( $f["id"] ?? $key );
            if ( isset( self::FIELD_MAP[ (string) ( $f["type"] ?? "" ) ] ) && ! self::is_wrapper_break( $f ) ) {
                $ids[ $wid ] = self::unique_id( self::name_of( $f ), "field_" . $wid, $used );
                $used[]      = $ids[ $wid ];
            }
        }
        $types = array_column( $fields, "type", "id" );

        $count = 0;
        $last  = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT entry_id, fields, date, ip_address, user_agent, user_id, viewed, starred FROM {$wpdb->prefix}wpforms_entries
                  WHERE form_id = %d AND entry_id > %d AND status NOT IN ('spam','trash','partial','abandoned') ORDER BY entry_id ASC LIMIT 200",
                (int) $source_id,
                $last
            ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $last   = (int) $row["entry_id"];
                $values = [];
                foreach ( (array) json_decode( (string) $row["fields"], true ) as $key => $answer ) {
                    $wid = (string) ( $answer["id"] ?? $key );
                    if ( ! isset( $ids[ $wid ] ) || ! isset( $answer["value"] ) ) {
                        continue;
                    }
                    $tid   = $ids[ $wid ];
                    $value = (string) $answer["value"];
                    // With "show values" on, the label is in "value" and the stored choice value in "value_raw".
                    if ( in_array( $types[ $tid ] ?? "", [ "radio", "select", "checkbox", "multiselect" ], true ) && isset( $answer["value_raw"] ) && "" !== (string) $answer["value_raw"] ) {
                        $value = (string) $answer["value_raw"];
                    }
                    if ( in_array( $types[ $tid ] ?? "", [ "checkbox", "multiselect" ], true ) ) {
                        $value = array_values( array_filter( array_map( "trim", preg_split( '/\R/', $value ) ), "strlen" ) );
                    } elseif ( "consent" === ( $types[ $tid ] ?? "" ) ) {
                        $value = "" !== trim( $value ) ? "1" : "";
                    }
                    $values[ $tid ] = $value;
                }
                if ( self::add_entry( $form_id, [
                    "source_entry_id" => $last,
                    "submitted_at" => (string) $row["date"],
                    "ip"           => (string) $row["ip_address"],
                    "user_agent"   => (string) $row["user_agent"],
                    "user_id"      => (int) $row["user_id"],
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
