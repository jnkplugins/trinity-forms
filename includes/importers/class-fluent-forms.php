<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading Fluent Forms' own tables; works while Fluent Forms is deactivated.

/**
 * Fluent Forms.
 *
 * Forms live in `fluentform_forms` (the field tree as JSON in form_fields), their
 * settings and notifications in `fluentform_form_meta`, and submissions in
 * `fluentform_submissions` (every answer in the `response` JSON, keyed by the
 * field's name). Columns ("container") are flattened in reading order.
 */
class Fluent_Forms extends Base_Importer {

    /** Fluent element => Trinity field type. */
    private const FIELD_MAP = [
        "input_name"          => "name",
        "input_email"         => "email",
        "input_text"          => "text",
        "input_mask"          => "text",
        "textarea"            => "textarea",
        "address"             => "address",
        "input_number"        => "number",
        "select"              => "select",
        "multi_select"        => "multiselect",
        "input_radio"         => "radio",
        "input_checkbox"      => "checkbox",
        "input_url"           => "website",
        "input_date"          => "date",
        "input_image"         => "file",
        "input_file"          => "file",
        "select_country"      => "country",
        "custom_html"         => "html",
        "ratings"             => "rating",
        "input_hidden"        => "hidden",
        "section_break"       => "section",
        "input_password"      => "password",
        "form_step"           => "page_break",
        "terms_and_condition" => "consent",
        "gdpr_agreement"      => "consent",
        "phone"               => "phone",
        "rangeslider"         => "range",
    ];

    /** Fluent operator => Trinity operator. */
    private const OPERATORS = [
        "="             => "equals",
        "!="            => "not_equals",
        ">"             => "greater_than",
        "<"             => "less_than",
        "contains"      => "contains",
        "doNotContains" => "not_contains",
        "startsWith"    => "starts_with",
        "endsWith"      => "ends_with",
        "test_regex"    => "matches_pattern",
    ];

    /** Fluent smart codes => ours. */
    private const TAGS = [
        "all_data"                       => "{all_fields}",
        "all_data_without_hidden_fields" => "{all_fields}",
        "wp.admin_email"                 => "{admin_email}",
        "wp.site_title"                  => "{site_name}",
        "wp.site_url"                    => "{site_url}",
        "form_title"                     => "{form_title}",
        "embed_post.permalink"           => "{embed_url}",
        "embed_post.post_title"          => "{post:post_title}",
        "embed_post.ID"                  => "{post:ID}",
        "ip"                             => "{ip}",
        "http_referer"                   => "{referer}",
        "submission.id"                  => "{entry_id}",
        "submission.serial_number"       => "{entry_id}",
        "submission.admin_view_url"      => "{entry_url}",
        "submission.created_at"          => "{date}",
        "user.ID"                        => "{user:ID}",
        "user.display_name"              => "{user:display_name}",
        "user.first_name"                => "{user:first_name}",
        "user.last_name"                 => "{user:last_name}",
        "user.user_email"                => "{user:user_email}",
        "user.user_login"                => "{user:user_login}",
        "browser.name"                   => "{user_agent}",
    ];

    public function slug(): string {
        return "fluent-forms";
    }

    public function label(): string {
        return "Fluent Forms";
    }

    private static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public function is_available(): bool {
        return self::table_readable( self::table( "fluentform_forms" ) );
    }

    public function forms(): array {
        global $wpdb;
        if ( ! $this->is_available() ) {
            return [];
        }
        $rows   = $wpdb->get_results( "SELECT id, title FROM " . self::table( "fluentform_forms" ) . " WHERE type IS NULL OR type <> 'post' ORDER BY id ASC", ARRAY_A );
        $counts = [];
        if ( self::table_readable( self::table( "fluentform_submissions" ) ) ) {
            foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS n FROM " . self::table( "fluentform_submissions" ) . " WHERE status NOT IN ('trashed','spam') GROUP BY form_id", ARRAY_A ) as $r ) {
                $counts[ (int) $r["form_id"] ] = (int) $r["n"];
            }
        }
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row["id"] ] = [ "title" => (string) $row["title"], "entries" => $counts[ (int) $row["id"] ] ?? 0 ];
        }
        return $out;
    }

    public function supports_entries(): bool {
        return true;
    }

    protected function count_entries( $source_id ): int {
        return (int) ( $this->forms()[ (int) $source_id ]["entries"] ?? 0 );
    }

    /**
     * The form row, through Fluent's own API when it is active.
     *
     * @return array{title:string,fields:array<string,mixed>}|null
     */
    private static function form_row( int $id ): ?array {
        global $wpdb;
        $row = null;
        if ( function_exists( "fluentFormApi" ) ) {
            try {
                $form = fluentFormApi( "forms" )->find( $id );
                if ( $form ) {
                    $row = [ "title" => (string) $form->title, "form_fields" => (string) $form->form_fields ];
                }
            } catch ( \Throwable $e ) {
                $row = null;
            }
        }
        if ( null === $row && self::table_readable( self::table( "fluentform_forms" ) ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT title, form_fields FROM " . self::table( "fluentform_forms" ) . " WHERE id = %d", $id ), ARRAY_A );
        }
        if ( ! $row ) {
            return null;
        }
        $fields = json_decode( (string) $row["form_fields"], true );
        return [ "title" => (string) $row["title"], "fields" => is_array( $fields ) ? $fields : [] ];
    }

    /** @return array<int,mixed> Every value stored under this meta key, decoded. */
    private static function meta( int $form_id, string $key ): array {
        global $wpdb;
        $values = $wpdb->get_col( $wpdb->prepare( "SELECT value FROM " . self::table( "fluentform_form_meta" ) . " WHERE form_id = %d AND meta_key = %s ORDER BY id ASC", $form_id, $key ) );
        return array_map( static fn( $v ) => json_decode( (string) $v, true ), (array) $values );
    }

    /**
     * Every element in reading order, columns flattened.
     *
     * @param array<int,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    private static function flatten( array $elements ): array {
        $out = [];
        foreach ( $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            if ( "container" === ( $el["element"] ?? "" ) ) {
                $columns = (array) ( $el["columns"] ?? [] );
                $width   = [ 2 => "50", 3 => "33", 4 => "25" ][ count( $columns ) ] ?? "";
                foreach ( $columns as $column ) {
                    foreach ( self::flatten( (array) ( $column["fields"] ?? [] ) ) as $inner ) {
                        $inner["__width"] = $inner["__width"] ?? $width;
                        $out[]            = $inner;
                    }
                }
                continue;
            }
            $out[] = $el;
        }
        return $out;
    }

    /**
     * Fluent field name => Trinity field id, for the elements that become fields.
     *
     * @param array<int,array<string,mixed>> $elements
     * @return array<string,string>
     */
    private static function ids( array $elements ): array {
        $ids = [];
        $n   = 0;
        foreach ( $elements as $el ) {
            $n++;
            $type = (string) ( $el["element"] ?? "" );
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                continue;
            }
            $name = (string) ( $el["attributes"]["name"] ?? "" );
            $key  = "" !== $name ? $name : "#" . $n;
            $ids[ $key ] = self::unique_id( "" !== $name ? $name : (string) ( $el["settings"]["label"] ?? "" ), "field_" . $n, array_values( $ids ) );
        }
        return $ids;
    }

    public function convert( $source_id ): ?array {
        $row = self::form_row( (int) $source_id );
        if ( null === $row ) {
            return null;
        }
        $elements = self::flatten( (array) ( $row["fields"]["fields"] ?? [] ) );
        $ids      = self::ids( $elements );
        $skipped  = [];
        $fields   = [];
        $submit   = (string) ( $row["fields"]["submitButton"]["settings"]["button_ui"]["text"] ?? "" );

        $n = 0;
        foreach ( $elements as $el ) {
            $n++;
            $type  = (string) ( $el["element"] ?? "" );
            $name  = (string) ( $el["attributes"]["name"] ?? "" );
            $label = self::label_of( $el );
            if ( "custom_submit_button" === $type ) {
                $submit = (string) ( $el["settings"]["button_ui"]["text"] ?? $submit );
                continue;
            }
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                $skipped[] = self::field_skipped( $label, $type );
                continue;
            }
            $field = self::convert_field( $el, $ids[ "" !== $name ? $name : "#" . $n ], $label, $skipped );
            $logic = $el["settings"]["conditional_logics"] ?? null;
            if ( is_array( $logic ) && ! empty( $logic["status"] ) ) {
                $rules = self::convert_rules( $logic, $ids );
                if ( $rules ) {
                    $field["conditional"] = $rules;
                } else {
                    $skipped[] = self::rules_skipped( $field["label"] );
                }
            }
            $fields[] = $field;
        }

        $settings = [];
        if ( "" !== trim( $submit ) ) {
            $settings["submit_button_text"] = $submit;
        }
        $form_settings = self::meta( (int) $source_id, "formSettings" )[0] ?? [];
        $confirmation  = is_array( $form_settings ) ? (array) ( $form_settings["confirmation"] ?? [] ) : [];
        switch ( (string) ( $confirmation["redirectTo"] ?? "samePage" ) ) {
            case "customPage":
                $url = ! empty( $confirmation["customPage"] ) ? (string) get_permalink( (int) $confirmation["customPage"] ) : "";
                if ( "" !== $url ) {
                    $settings["redirect_url"] = $url;
                }
                break;
            case "customUrl":
                if ( "" !== trim( (string) ( $confirmation["customUrl"] ?? "" ) ) ) {
                    $settings["redirect_url"] = self::detag( (string) $confirmation["customUrl"], $ids, $skipped );
                }
                break;
            default:
                if ( "" !== trim( wp_strip_all_tags( (string) ( $confirmation["messageToShow"] ?? "" ) ) ) ) {
                    $settings["confirmation_message"] = self::detag( (string) $confirmation["messageToShow"], $ids, $skipped );
                }
        }

        $notifications = [];
        foreach ( self::meta( (int) $source_id, "notifications" ) as $i => $n ) {
            if ( is_array( $n ) ) {
                foreach ( self::convert_notification( $n, $i, $ids, $skipped ) as $converted ) {
                    $notifications[] = $converted;
                }
            }
        }

        return [
            "title"         => $row["title"],
            "fields"        => $fields,
            "settings"      => $settings,
            "notifications" => $notifications,
            "skipped"       => array_values( array_unique( $skipped ) ),
        ];
    }

    private static function label_of( array $el ): string {
        $s = (array) ( $el["settings"] ?? [] );
        foreach ( [ "label", "admin_field_label" ] as $k ) {
            if ( "" !== trim( (string) ( $s[ $k ] ?? "" ) ) ) {
                return (string) $s[ $k ];
            }
        }
        return (string) ( $el["editor_options"]["title"] ?? "" );
    }

    /**
     * @param array<string,mixed> $el
     * @param array<int,string>   $skipped
     * @return array<string,mixed>
     */
    private static function convert_field( array $el, string $id, string $label, array &$skipped ): array {
        $type  = (string) $el["element"];
        $attr  = (array) ( $el["attributes"] ?? [] );
        $set   = (array) ( $el["settings"] ?? [] );
        $rules = (array) ( $set["validation_rules"] ?? [] );
        $field = [
            "id"       => $id,
            "type"     => self::FIELD_MAP[ $type ],
            "label"    => $label,
            "required" => ! empty( $rules["required"]["value"] ),
        ];
        if ( "" !== trim( (string) ( $attr["placeholder"] ?? "" ) ) ) {
            $field["placeholder"] = (string) $attr["placeholder"];
        }
        if ( "" !== trim( (string) ( $set["help_message"] ?? "" ) ) ) {
            $field["help"] = wp_strip_all_tags( (string) $set["help_message"] );
        }
        if ( "" !== trim( (string) ( $set["container_class"] ?? "" ) ) ) {
            $field["css_class"] = (string) $set["container_class"];
        }
        if ( "" !== trim( (string) ( $set["admin_field_label"] ?? "" ) ) && $set["admin_field_label"] !== $label ) {
            $field["admin_label"] = (string) $set["admin_field_label"];
        }
        if ( ! empty( $el["__width"] ) ) {
            $field["field_width"] = (string) $el["__width"];
        }
        $default = $attr["value"] ?? "";

        switch ( $type ) {
            case "input_name":
                $sub      = (array) ( $el["fields"] ?? [] );
                $required = false;
                foreach ( $sub as $part ) {
                    $required = $required || ( ! empty( $part["settings"]["visible"] ) && ! empty( $part["settings"]["validation_rules"]["required"]["value"] ) );
                }
                $field["required"] = $required;
                if ( ! empty( $sub["middle_name"]["settings"]["visible"] ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the middle name box", "trinity-forms" ), $label );
                }
                break;
            case "address":
                $required = false;
                foreach ( (array) ( $el["fields"] ?? [] ) as $part ) {
                    $required = $required || ( ! empty( $part["settings"]["visible"] ) && ! empty( $part["settings"]["validation_rules"]["required"]["value"] ) );
                }
                $field["required"] = $required;
                break;
            case "input_mask":
                /* translators: %s: field label. */
                $skipped[] = sprintf( __( "%s: the input mask", "trinity-forms" ), $label );
                break;
            case "textarea":
                if ( ! empty( $attr["rows"] ) ) {
                    $field["rows"] = (int) $attr["rows"];
                }
                break;
            case "input_number":
            case "rangeslider":
                foreach ( [ "min", "max" ] as $k ) {
                    $v = $rules[ $k ]["value"] ?? $attr[ $k ] ?? "";
                    if ( "" !== (string) $v ) {
                        $field[ $k ] = (string) $v;
                    }
                }
                $step = $set["number_step"] ?? $attr["step"] ?? "";
                if ( "" !== (string) $step && "any" !== $step ) {
                    $field["step"] = (string) $step;
                }
                if ( "rangeslider" === $type && "" !== (string) $default ) {
                    $field["default"] = (string) $default;
                    $default          = "";
                }
                break;
            case "select":
                if ( ! empty( $attr["multiple"] ) ) {
                    $field["type"] = "multiselect";
                }
                break;
            case "input_date":
                if ( preg_match( '/[HhGgis]/', (string) ( $set["date_format"] ?? "" ) ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the time part", "trinity-forms" ), $label );
                }
                break;
            case "input_image":
            case "input_file":
                $types = (array) ( $rules["allowed_file_types"]["value"] ?? $rules["allowed_image_types"]["value"] ?? [] );
                $ext   = [];
                foreach ( $types as $t ) {
                    foreach ( explode( "|", (string) $t ) as $e ) {
                        if ( "" !== trim( $e ) ) {
                            $ext[] = "." . strtolower( trim( $e ) );
                        }
                    }
                }
                if ( $ext ) {
                    $field["accept"] = implode( ",", array_unique( $ext ) );
                } elseif ( "input_image" === $type ) {
                    $field["accept"] = ".jpg,.jpeg,.png,.gif,.webp";
                }
                $bytes = (float) ( $rules["max_file_size"]["value"] ?? 0 );
                if ( $bytes > 0 ) {
                    $field["max_size"] = max( 1, (int) ceil( $bytes / 1048576 ) );
                }
                if ( (int) ( $rules["max_file_count"]["value"] ?? 1 ) > 1 ) {
                    $field["multiple"] = true;
                }
                break;
            case "custom_html":
                $field["html"] = (string) ( $set["html_codes"] ?? "" );
                unset( $field["required"] );
                break;
            case "section_break":
                $field["description"] = wp_strip_all_tags( (string) ( $set["description"] ?? "" ) );
                unset( $field["required"] );
                break;
            case "form_step":
                $field["label"] = "";
                foreach ( [ "next_btn" => "next_text", "prev_btn" => "prev_text" ] as $from => $to ) {
                    if ( "" !== trim( (string) ( $set[ $from ]["text"] ?? "" ) ) ) {
                        $field[ $to ] = (string) $set[ $from ]["text"];
                    }
                }
                unset( $field["required"] );
                break;
            case "terms_and_condition":
            case "gdpr_agreement":
                $field["consent_text"] = trim( wp_strip_all_tags( (string) ( $set["tnc_html"] ?? $label ) ) );
                if ( "terms_and_condition" === $type && wp_strip_all_tags( (string) ( $set["tnc_html"] ?? "" ) ) !== (string) ( $set["tnc_html"] ?? "" ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: links in the consent text", "trinity-forms" ), $label );
                }
                $default = "";
                break;
            case "ratings":
                $field["max"] = (string) max( 2, count( (array) ( $el["options"] ?? [] ) ) );
                $default      = "";
                break;
            case "input_hidden":
                $field["default"] = (string) $default;
                $default          = "";
                unset( $field["required"] );
                break;
        }

        if ( in_array( $field["type"], [ "select", "multiselect", "radio", "checkbox" ], true ) ) {
            $field["options"] = [];
            foreach ( (array) ( $set["advanced_options"] ?? [] ) as $o ) {
                $field["options"][] = [ "label" => (string) ( $o["label"] ?? "" ), "value" => (string) ( $o["value"] ?? $o["label"] ?? "" ) ];
            }
            // Forms made from Fluent's older templates keep "options" as value => label.
            if ( ! $field["options"] && is_array( $el["options"] ?? null ) ) {
                foreach ( $el["options"] as $value => $text ) {
                    $field["options"][] = [ "label" => (string) $text, "value" => (string) $value ];
                }
            }
            if ( "yes" === ( $set["enable_other_option"] ?? "no" ) ) {
                /* translators: %s: field label. */
                $skipped[] = sprintf( __( "%s: the \"Other\" choice with its own text box", "trinity-forms" ), $label );
            }
            if ( "select" !== $field["type"] && "multiselect" !== $field["type"] ) {
                unset( $field["placeholder"] );
            }
            if ( "" !== trim( (string) ( $set["placeholder"] ?? "" ) ) && in_array( $field["type"], [ "select", "multiselect" ], true ) ) {
                $field["placeholder"] = (string) $set["placeholder"];
            }
            if ( ! empty( $rules["max_selection"]["value"] ) ) {
                if ( "multiselect" === $field["type"] ) {
                    $field["max_choices"] = (int) $rules["max_selection"]["value"];
                } else {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the limit on how many choices can be ticked", "trinity-forms" ), $label );
                }
            }
        }
        if ( is_array( $default ) ) {
            $default = implode( ",", array_map( "strval", $default ) );
        }
        if ( "" !== trim( (string) $default ) ) {
            $field["default_value"] = (string) $default;
        }
        return $field;
    }

    /**
     * @param array<string,mixed>  $logic
     * @param array<string,string> $ids
     * @return array<string,mixed>|null
     */
    private static function convert_rules( array $logic, array $ids, string $action = "show" ): ?array {
        $type   = (string) ( $logic["type"] ?? "any" );
        $groups = [];
        if ( "group" === $type ) {
            foreach ( (array) ( $logic["condition_groups"] ?? [] ) as $group ) {
                $groups[] = (array) ( $group["rules"] ?? [] );
            }
        } elseif ( "all" === $type ) {
            $groups[] = (array) ( $logic["conditions"] ?? [] );
        } else {
            foreach ( (array) ( $logic["conditions"] ?? [] ) as $c ) {
                $groups[] = [ $c ];
            }
        }
        $out = [];
        foreach ( $groups as $group ) {
            $rules = [];
            foreach ( $group as $c ) {
                $target = (string) ( $c["field"] ?? "" );
                if ( "" === $target ) {
                    continue;
                }
                $target = (string) strtok( $target, "[" );
                $op     = (string) ( $c["operator"] ?? "=" );
                if ( ! isset( $ids[ $target ], self::OPERATORS[ $op ] ) ) {
                    return null;
                }
                $value   = $c["value"] ?? "";
                $rules[] = [ "field" => $ids[ $target ], "operator" => self::OPERATORS[ $op ], "value" => is_array( $value ) ? implode( ",", $value ) : (string) $value ];
            }
            if ( $rules ) {
                $out[] = $rules;
            }
        }
        return self::rule_set( $action, $out );
    }

    /**
     * One Fluent notification; routing ("send to the address the answers pick")
     * becomes one notification per route, each sent only when its rule holds.
     *
     * @param array<string,mixed>  $n
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     * @return array<int,array<string,mixed>>
     */
    private static function convert_notification( array $n, int $index, array $ids, array &$skipped ): array {
        $name = (string) ( $n["name"] ?? "" );
        $base = [
            "id"      => "fluent_" . ( $index + 1 ),
            "name"    => "" !== $name ? $name : __( "Admin notification", "trinity-forms" ),
            "subject" => self::detag( (string) ( $n["subject"] ?? "" ), $ids, $skipped ),
            "body"    => self::detag( (string) ( $n["message"] ?? "{all_data}" ), $ids, $skipped ),
            "enabled" => ! empty( $n["enabled"] ),
            "event"   => "submission",
        ];
        foreach ( [ "fromName" => "from_name", "fromEmail" => "from_email", "replyTo" => "reply_to", "cc" => "cc", "bcc" => "bcc" ] as $from => $to ) {
            if ( "" !== trim( (string) ( $n[ $from ] ?? "" ) ) ) {
                $base[ $to ] = self::detag( (string) $n[ $from ], $ids, $skipped );
            }
        }
        if ( ! empty( $n["attachments"] ) || ! empty( $n["media_attachments"] ) ) {
            $base["attach_files"] = true;
        }
        $logic = $n["conditionals"] ?? null;
        if ( is_array( $logic ) && ! empty( $logic["status"] ) ) {
            $rules = self::convert_rules( $logic, $ids );
            if ( $rules ) {
                $base["conditional"] = $rules;
            } else {
                $skipped[] = self::rules_skipped( $base["name"] );
            }
        }

        $send = (array) ( $n["sendTo"] ?? [] );
        $type = (string) ( $send["type"] ?? "email" );
        if ( "field" === $type ) {
            $field = (string) ( $send["field"] ?? "" );
            if ( ! isset( $ids[ $field ] ) ) {
                return [];
            }
            return [ $base + [ "to" => "{" . $ids[ $field ] . "}" ] ];
        }
        if ( "routing" === $type ) {
            $out = [];
            foreach ( (array) ( $send["routing"] ?? [] ) as $i => $route ) {
                $email = trim( (string) ( $route["input_value"] ?? $route["email"] ?? "" ) );
                $rules = self::convert_rules( [ "type" => "all", "conditions" => [ $route ] ], $ids );
                if ( "" === $email || ! $rules || isset( $base["conditional"] ) ) {
                    $skipped[] = self::rules_skipped( $base["name"] );
                    continue;
                }
                $out[] = array_merge( $base, [ "id" => $base["id"] . "_" . ( $i + 1 ), "to" => self::detag( $email, $ids, $skipped ), "conditional" => $rules ] );
            }
            return $out;
        }
        $to = self::detag( (string) ( $send["email"] ?? "" ), $ids, $skipped );
        return "" === trim( $to ) ? [] : [ $base + [ "to" => $to ] ];
    }

    /**
     * Fluent smart codes ({inputs.email}, {wp.admin_email}, {date.d/m/Y}) as ours.
     *
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $ids, array &$skipped ): string {
        return (string) preg_replace_callback( '/\{([a-zA-Z_]+(?:\.[^{}\s]+)?)\}/', static function ( $m ) use ( $ids, &$skipped ) {
            $tag = $m[1];
            if ( str_starts_with( $tag, "inputs." ) ) {
                $name = substr( $tag, 7 );
                if ( isset( $ids[ $name ] ) ) {
                    return "{" . $ids[ $name ] . "}";
                }
            } elseif ( str_starts_with( $tag, "date." ) ) {
                return "{today:" . substr( $tag, 5 ) . "}";
            } elseif ( isset( self::TAGS[ $tag ] ) ) {
                return self::TAGS[ $tag ];
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Submissions, oldest first. Fluent stores local time; answers are keyed by
     * field name, with names and addresses as their parts.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        global $wpdb;
        $row = self::form_row( (int) $source_id );
        if ( null === $row || ! self::table_readable( self::table( "fluentform_submissions" ) ) ) {
            return 0;
        }
        $ids   = self::ids( self::flatten( (array) ( $row["fields"]["fields"] ?? [] ) ) );
        $types = array_column( $fields, "type", "id" );
        $count = 0;
        $last  = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, response, source_url, user_id, status, is_favourite, ip, browser, created_at FROM " . self::table( "fluentform_submissions" ) . "
                  WHERE form_id = %d AND id > %d AND status NOT IN ('trashed','spam') ORDER BY id ASC LIMIT 200",
                (int) $source_id,
                $last
            ), ARRAY_A );
            foreach ( (array) $rows as $sub ) {
                $last   = (int) $sub["id"];
                $values = [];
                foreach ( (array) json_decode( (string) $sub["response"], true ) as $name => $value ) {
                    if ( ! isset( $ids[ $name ] ) ) {
                        continue;
                    }
                    $tid  = $ids[ $name ];
                    $type = $types[ $tid ] ?? "text";
                    if ( is_array( $value ) && in_array( $type, [ "name", "address" ], true ) ) {
                        $value = implode( "name" === $type ? " " : ", ", array_filter( array_map( "strval", $value ), static fn( $v ) => "" !== trim( $v ) ) );
                    } elseif ( "consent" === $type ) {
                        $value = ! empty( $value ) ? "1" : "";
                    } elseif ( is_array( $value ) && ! in_array( $type, [ "checkbox", "multiselect", "file" ], true ) ) {
                        $value = implode( ", ", array_map( "strval", $value ) );
                    } elseif ( is_array( $value ) && "file" === $type ) {
                        $value = implode( "\n", array_map( "strval", $value ) );
                    }
                    $values[ $tid ] = $value;
                }
                if ( self::add_entry( $form_id, [
                    "source_entry_id" => $last,
                    "submitted_at" => self::to_utc( (string) $sub["created_at"] ),
                    "ip"           => (string) $sub["ip"],
                    "user_agent"   => (string) $sub["browser"],
                    "user_id"      => (int) $sub["user_id"],
                    "source_url"   => (string) $sub["source_url"],
                    "is_read"      => "read" === $sub["status"],
                    "is_starred"   => ! empty( $sub["is_favourite"] ),
                ], $values, $fields ) ) {
                    $count++;
                }
            }
        } while ( count( (array) $rows ) === 200 );
        return $count;
    }
}
