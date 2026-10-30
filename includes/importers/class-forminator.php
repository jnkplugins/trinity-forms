<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading Forminator's own entry tables; works while Forminator is deactivated.

/**
 * Forminator.
 *
 * A form is a `forminator_forms` post; the whole model (fields in display order,
 * each with its row "wrapper" and column width, settings, notifications and
 * submission behaviours) is the `forminator_form_meta` post meta, which is what
 * Forminator's own Forminator_Form_Model loads. Entries are in
 * `frmt_form_entry` with one `frmt_form_entry_meta` row per answer, keyed by the
 * field's element id.
 */
class Forminator extends Base_Importer {

    public const POST_TYPE = "forminator_forms";

    /** Forminator field type => Trinity field type. */
    private const FIELD_MAP = [
        "name"         => "name",
        "email"        => "email",
        "phone"        => "phone",
        "text"         => "text",
        "textarea"     => "textarea",
        "number"       => "number",
        "currency"     => "number",
        "website"      => "website",
        "url"          => "website",
        "address"      => "address",
        "date"         => "date",
        "time"         => "time",
        "select"       => "select",
        "radio"        => "radio",
        "checkbox"     => "checkbox",
        "upload"       => "file",
        "hidden"       => "hidden",
        "html"         => "html",
        "section"      => "section",
        "page-break"   => "page_break",
        "consent"      => "consent",
        "gdprcheckbox" => "consent",
        "slider"       => "range",
        "rating"       => "rating",
        "password"     => "password",
    ];

    private const SILENT = [ "captcha" ];

    /** Forminator rule => Trinity operator. */
    private const OPERATORS = [
        "is"               => "equals",
        "is_not"           => "not_equals",
        "is_great"         => "greater_than",
        "is_less"          => "less_than",
        "contains"         => "contains",
        "does_not_contain" => "not_contains",
        "starts"           => "starts_with",
        "ends"             => "ends_with",
        "is_before"        => "is_before",
        "is_after"         => "is_after",
    ];

    /** Forminator placeholders => ours. */
    private const TAGS = [
        "all_fields"           => "{all_fields}",
        "all_non_empty_fields" => "{all_fields}",
        "admin_email"          => "{admin_email}",
        "site_url"             => "{site_url}",
        "site_title"           => "{site_name}",
        "form_name"            => "{form_title}",
        "submission_id"        => "{entry_id}",
        "user_ip"              => "{ip}",
        "embed_url"            => "{embed_url}",
        "embed_title"          => "{post:post_title}",
        "embed_id"             => "{post:ID}",
        "page_title"           => "{post:post_title}",
        "refer_url"            => "{referer}",
        "referer_url"          => "{referer}",
        "user_agent"           => "{user_agent}",
        "date_mdy"             => "{today:m/d/Y}",
        "date_dmy"             => "{today:d/m/Y}",
        "submission_time"      => "{date}",
        "user_name"            => "{user:display_name}",
        "user_email"           => "{user:user_email}",
        "user_login"           => "{user:user_login}",
        "user_id"              => "{user:ID}",
    ];

    public function slug(): string {
        return "forminator";
    }

    public function label(): string {
        return "Forminator";
    }

    public function is_available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' LIMIT 1", self::POST_TYPE ) );
    }

    public function forms(): array {
        global $wpdb;
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", self::POST_TYPE ), ARRAY_A );
        $counts = [];
        if ( self::table_readable( $wpdb->prefix . "frmt_form_entry" ) ) {
            foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS n FROM {$wpdb->prefix}frmt_form_entry WHERE " . self::live_entries() . " GROUP BY form_id", ARRAY_A ) as $r ) {
                $counts[ (int) $r["form_id"] ] = (int) $r["n"];
            }
        }
        $out = [];
        foreach ( (array) $rows as $row ) {
            $meta  = get_post_meta( (int) $row["ID"], "forminator_form_meta", true );
            $title = is_array( $meta ) && "" !== (string) ( $meta["settings"]["formName"] ?? "" ) ? (string) $meta["settings"]["formName"] : (string) $row["post_title"];
            $out[ (int) $row["ID"] ] = [ "title" => $title, "entries" => $counts[ (int) $row["ID"] ] ?? 0 ];
        }
        return $out;
    }

    /** Real submissions: not spam, not a saved draft, not abandoned (newer versions have a status). */
    private static function live_entries(): string {
        global $wpdb;
        static $has_status = null;
        if ( null === $has_status ) {
            $has_status = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}frmt_form_entry LIKE 'status'" );
        }
        return "entry_type = 'custom-forms' AND is_spam = 0 AND ( draft_id IS NULL OR draft_id = '' )" . ( $has_status ? " AND ( status IS NULL OR status = 'active' )" : "" );
    }

    public function supports_entries(): bool {
        return true;
    }

    protected function count_entries( $source_id ): int {
        return (int) ( $this->forms()[ (int) $source_id ]["entries"] ?? 0 );
    }

    /** @return array<string,mixed>|null */
    private static function model( int $id ): ?array {
        $post = get_post( $id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return null;
        }
        $meta = get_post_meta( $id, "forminator_form_meta", true );
        if ( ! is_array( $meta ) ) {
            return null;
        }
        $meta["fields"]   = array_values( array_filter( (array) ( $meta["fields"] ?? [] ), "is_array" ) );
        $meta["settings"] = (array) ( $meta["settings"] ?? [] );
        $meta["title"]    = "" !== (string) ( $meta["settings"]["formName"] ?? "" ) ? (string) $meta["settings"]["formName"] : (string) $post->post_title;
        return $meta;
    }

    /**
     * Element id ("email-1") => Trinity field id ("email").
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,string>
     */
    private static function ids( array $fields ): array {
        $ids = [];
        foreach ( $fields as $i => $f ) {
            $type = (string) ( $f["type"] ?? "" );
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                continue;
            }
            $element = (string) ( $f["element_id"] ?? $f["id"] ?? "" );
            $label   = (string) ( $f["field_label"] ?? $f["section_title"] ?? "" );
            $ids[ $element ] = self::unique_id( "" !== trim( $label ) && "page-break" !== $type ? $label : preg_replace( '/-\d+$/', "", $element ), "field_" . ( $i + 1 ), array_values( $ids ) );
        }
        return $ids;
    }

    public function convert( $source_id ): ?array {
        $model = self::model( (int) $source_id );
        if ( null === $model ) {
            return null;
        }
        $ids     = self::ids( $model["fields"] );
        $skipped = [];
        $fields  = [];
        foreach ( $model["fields"] as $f ) {
            $type    = (string) ( $f["type"] ?? "" );
            $element = (string) ( $f["element_id"] ?? $f["id"] ?? "" );
            $label   = (string) ( $f["field_label"] ?? "" );
            if ( in_array( $type, self::SILENT, true ) ) {
                continue;
            }
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                $skipped[] = self::field_skipped( $label, $type );
                continue;
            }
            $field = self::convert_field( $f, $ids[ $element ], $ids, $skipped );
            if ( ! empty( $f["conditions"] ) && is_array( $f["conditions"] ) ) {
                $rules = self::convert_rules( $f["conditions"], $ids, $model["fields"] );
                if ( $rules ) {
                    $field["conditional"] = [ "action" => "hide" === ( $f["condition_action"] ?? "show" ) ? "hide" : "show", "match" => "any" === ( $f["condition_rule"] ?? "all" ) ? "any" : "all", "conditions" => $rules ];
                } else {
                    $skipped[] = self::rules_skipped( $field["label"] );
                }
            }
            $fields[] = $field;
        }

        $s        = $model["settings"];
        $settings = [];
        if ( "" !== trim( (string) ( $s["submitData"]["custom-submit-text"] ?? "" ) ) ) {
            $settings["submit_button_text"] = (string) $s["submitData"]["custom-submit-text"];
        }
        // Newer versions keep the after-submit choice as "behaviours" (first one
        // without conditions wins); older ones as plain settings.
        $behaviors = array_values( array_filter( (array) ( $model["behaviors"] ?? [] ), "is_array" ) );
        if ( ! $behaviors ) {
            $behaviors = [ $s ];
        }
        $extra = [];
        foreach ( $behaviors as $b ) {
            $row = self::behavior( $b, $ids, $skipped );
            if ( null === $row ) {
                continue;
            }
            if ( ! empty( $b["conditions"] ) ) {
                $rules = self::convert_rules( (array) $b["conditions"], $ids, $model["fields"] );
                if ( $rules ) {
                    $row["conditional"] = [ "action" => "show", "match" => "any" === ( $b["condition_rule"] ?? "all" ) ? "any" : "all", "conditions" => $rules ];
                    $extra[]            = $row;
                } else {
                    $skipped[] = self::rules_skipped( __( "Confirmation message", "trinity-forms" ) );
                }
                continue;
            }
            if ( "redirect" === $row["type"] ) {
                $settings["redirect_url"] = $row["url"];
            } elseif ( "" !== trim( wp_strip_all_tags( $row["message"] ) ) ) {
                $settings["confirmation_message"] = $row["message"];
            }
            break;
        }
        if ( $extra ) {
            $settings["confirmations"] = $extra;
        }

        $notifications = [];
        foreach ( (array) ( $model["notifications"] ?? [] ) as $i => $n ) {
            if ( is_array( $n ) ) {
                foreach ( self::convert_notification( $n, (int) $i, $ids, $model["fields"], $skipped ) as $converted ) {
                    $notifications[] = $converted;
                }
            }
        }

        return [
            "title"         => $model["title"],
            "fields"        => $fields,
            "settings"      => $settings,
            "notifications" => $notifications,
            "skipped"       => array_values( array_unique( $skipped ) ),
        ];
    }

    private static function truthy( $v ): bool {
        return true === $v || 1 === $v || "1" === $v || "true" === $v;
    }

    /**
     * @param array<string,mixed>  $f
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     * @return array<string,mixed>
     */
    private static function convert_field( array $f, string $id, array $ids, array &$skipped ): array {
        $type  = (string) $f["type"];
        $label = (string) ( $f["field_label"] ?? "" );
        $field = [
            "id"       => $id,
            "type"     => self::FIELD_MAP[ $type ],
            "label"    => $label,
            "required" => self::truthy( $f["required"] ?? false ),
        ];
        if ( "" !== trim( (string) ( $f["placeholder"] ?? "" ) ) ) {
            $field["placeholder"] = (string) $f["placeholder"];
        }
        if ( "" !== trim( wp_strip_all_tags( (string) ( $f["description"] ?? "" ) ) ) ) {
            $field["help"] = trim( wp_strip_all_tags( (string) $f["description"] ) );
        }
        if ( "" !== trim( (string) ( $f["custom-class"] ?? "" ) ) ) {
            $field["css_class"] = (string) $f["custom-class"];
        }
        $cols = (int) ( $f["cols"] ?? 12 );
        if ( isset( [ 6 => 1, 4 => 1, 3 => 1 ][ $cols ] ) ) {
            $field["field_width"] = [ 6 => "50", 4 => "33", 3 => "25" ][ $cols ];
        }
        $default = (string) ( $f["default_value"] ?? "" );

        switch ( $type ) {
            case "name":
                if ( self::truthy( $f["multiple_name"] ?? false ) ) {
                    $field["required"] = $field["required"] || ! empty( $f["fname_required"] ) || ! empty( $f["lname_required"] );
                    if ( self::truthy( $f["mname"] ?? false ) || self::truthy( $f["prefix"] ?? false ) ) {
                        /* translators: %s: field label. */
                        $skipped[] = sprintf( __( "%s: the middle name box", "trinity-forms" ), $label );
                    }
                } else {
                    $field["type"] = "text";
                }
                break;
            case "text":
            case "textarea":
                if ( ! empty( $f["limit"] ) && "characters" === ( $f["limit_type"] ?? "characters" ) ) {
                    $field["max_length"] = (int) $f["limit"];
                }
                if ( "text" === $type && "paragraph" === ( $f["input_type"] ?? "line" ) ) {
                    $field["type"] = "textarea";
                }
                break;
            case "number":
            case "currency":
                foreach ( [ "limit_min" => "min", "limit_max" => "max" ] as $from => $to ) {
                    if ( isset( $f[ $from ] ) && "" !== (string) $f[ $from ] ) {
                        $field[ $to ] = (string) $f[ $from ];
                    }
                }
                break;
            case "slider":
                foreach ( [ "slider_min" => "min", "slider_max" => "max", "slider_step" => "step" ] as $from => $to ) {
                    if ( isset( $f[ $from ] ) && "" !== (string) $f[ $from ] ) {
                        $field[ $to ] = (string) $f[ $from ];
                    }
                }
                if ( isset( $f["slider_value"] ) && "" !== (string) $f["slider_value"] ) {
                    $field["default"] = (string) $f["slider_value"];
                }
                $default = "";
                break;
            case "rating":
                $field["max"] = (string) max( 2, (int) ( $f["max_rating"] ?? 5 ) );
                $default      = "";
                break;
            case "select":
                if ( "multiselect" === ( $f["value_type"] ?? "single" ) ) {
                    $field["type"] = "multiselect";
                }
                break;
            case "upload":
                $ext = array_filter( (array) ( $f["filetypes"] ?? [] ), static fn( $e ) => is_string( $e ) && ! str_starts_with( $e, "all" ) );
                if ( $ext ) {
                    $field["accept"] = implode( ",", array_map( static fn( $e ) => "." . strtolower( (string) strtok( $e, "|" ) ), $ext ) );
                }
                $limit = (float) ( $f["upload-limit"] ?? 0 );
                if ( $limit > 0 ) {
                    $unit              = strtoupper( (string) ( $f["filesize"] ?? "MB" ) );
                    $field["max_size"] = max( 1, (int) ceil( "KB" === $unit ? $limit / 1024 : ( "GB" === $unit ? $limit * 1024 : $limit ) ) );
                }
                if ( "multiple" === ( $f["file-type"] ?? "single" ) ) {
                    $field["multiple"] = true;
                }
                break;
            case "hidden":
                $field["default"] = "custom_value" === $default ? (string) ( $f["custom_value"] ?? "" ) : "";
                if ( "custom_value" !== $default && "" !== $default ) {
                    /* translators: 1: field label, 2: what the other plugin filled the hidden field with, e.g. "user_ip". */
                    $skipped[] = sprintf( __( "%1\$s: filled in from \"%2\$s\"", "trinity-forms" ), $label, $default );
                }
                $default = "";
                unset( $field["required"] );
                break;
            case "html":
                $field["html"] = (string) ( $f["variations"] ?? "" );
                unset( $field["required"] );
                break;
            case "section":
                $field["label"]       = (string) ( $f["section_title"] ?? $label );
                $field["description"] = trim( wp_strip_all_tags( (string) ( $f["section_subtitle"] ?? "" ) ) );
                unset( $field["required"] );
                break;
            case "page-break":
                $field["label"] = "";
                if ( "" !== trim( (string) ( $f["btn_right"] ?? "" ) ) ) {
                    $field["next_text"] = (string) $f["btn_right"];
                }
                if ( "" !== trim( (string) ( $f["btn_left"] ?? "" ) ) ) {
                    $field["prev_text"] = (string) $f["btn_left"];
                }
                unset( $field["required"], $field["field_width"] );
                break;
            case "consent":
            case "gdprcheckbox":
                $html                  = (string) ( $f["consent_description"] ?? $f["gdpr_description"] ?? $label );
                $field["consent_text"] = trim( wp_strip_all_tags( $html ) );
                if ( wp_strip_all_tags( $html ) !== $html && preg_match( '/<a\s/i', $html ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: links in the consent text", "trinity-forms" ), $label );
                }
                $default = "";
                break;
        }

        if ( in_array( $field["type"], [ "select", "multiselect", "radio", "checkbox" ], true ) ) {
            $field["options"] = [];
            $selected         = [];
            foreach ( (array) ( $f["options"] ?? [] ) as $o ) {
                if ( ! is_array( $o ) ) {
                    continue;
                }
                $value              = (string) ( "" !== (string) ( $o["value"] ?? "" ) ? $o["value"] : ( $o["label"] ?? "" ) );
                $field["options"][] = [ "label" => (string) ( $o["label"] ?? "" ), "value" => $value ];
                if ( self::truthy( $o["default"] ?? false ) ) {
                    $selected[] = $value;
                }
            }
            if ( $selected ) {
                $default = implode( ",", $selected );
            }
            if ( ! in_array( $field["type"], [ "select", "multiselect" ], true ) ) {
                unset( $field["placeholder"] );
            }
        }
        if ( "" !== trim( $default ) ) {
            $field["default_value"] = $default;
        }
        return $field;
    }

    /**
     * @param array<int,mixed>               $conditions
     * @param array<string,string>           $ids
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array{field:string,operator:string,value:string}>|null
     */
    private static function convert_rules( array $conditions, array $ids, array $fields ): ?array {
        $rules = [];
        foreach ( $conditions as $c ) {
            $target = (string) ( $c["element_id"] ?? "" );
            $op     = (string) ( $c["rule"] ?? "is" );
            if ( "" === $target ) {
                continue;
            }
            if ( ! isset( $ids[ $target ], self::OPERATORS[ $op ] ) ) {
                return null;
            }
            $rules[] = [ "field" => $ids[ $target ], "operator" => self::OPERATORS[ $op ], "value" => is_array( $c["value"] ?? "" ) ? implode( ",", $c["value"] ) : (string) ( $c["value"] ?? "" ) ];
        }
        return $rules ?: null;
    }

    /**
     * @param array<string,mixed>  $b
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     * @return array<string,mixed>|null
     */
    private static function behavior( array $b, array $ids, array &$skipped ): ?array {
        $type = (string) ( $b["submission-behaviour"] ?? "behaviour-thankyou" );
        $row  = [ "name" => (string) ( $b["label"] ?? "" ), "type" => "text", "message" => "", "page_id" => 0, "url" => "" ];
        if ( "behaviour-redirect" === $type ) {
            $url = trim( (string) ( $b["redirect-url"] ?? "" ) );
            if ( "" === $url ) {
                return null;
            }
            $row["type"] = "redirect";
            $row["url"]  = self::detag( $url, $ids, $skipped );
            return $row;
        }
        if ( "behaviour-hide" === $type ) {
            return null;
        }
        $row["message"] = self::detag( (string) ( $b["thankyou-message"] ?? "" ), $ids, $skipped );
        return $row;
    }

    /**
     * @param array<string,mixed>            $n
     * @param array<string,string>           $ids
     * @param array<int,array<string,mixed>> $fields
     * @param array<int,string>              $skipped
     * @return array<int,array<string,mixed>>
     */
    private static function convert_notification( array $n, int $index, array $ids, array $fields, array &$skipped ): array {
        $name = (string) ( $n["label"] ?? "" );
        $base = [
            "id"      => "forminator_" . ( $index + 1 ),
            "name"    => "" !== $name ? $name : __( "Admin notification", "trinity-forms" ),
            "subject" => self::detag( (string) ( $n["email-subject"] ?? "" ), $ids, $skipped ),
            "body"    => self::detag( (string) ( $n["email-editor"] ?? "{all_fields}" ), $ids, $skipped ),
            "enabled" => true,
            "event"   => "submission",
        ];
        foreach ( [ "from-name" => "from_name", "form-email" => "from_email", "replyto-email" => "reply_to", "cc-email" => "cc", "bcc-email" => "bcc" ] as $from => $to ) {
            if ( "" !== trim( (string) ( $n[ $from ] ?? "" ) ) ) {
                $base[ $to ] = self::detag( (string) $n[ $from ], $ids, $skipped );
            }
        }
        if ( self::truthy( $n["email-attachment"] ?? false ) ) {
            $base["attach_files"] = true;
        }
        if ( ! empty( $n["conditions"] ) && is_array( $n["conditions"] ) ) {
            $rules = self::convert_rules( $n["conditions"], $ids, $fields );
            if ( $rules ) {
                $base["conditional"] = [ "action" => "send" === ( $n["condition_action"] ?? "send" ) ? "show" : "hide", "match" => "any" === ( $n["condition_rule"] ?? "all" ) ? "any" : "all", "conditions" => $rules ];
            } else {
                $skipped[] = self::rules_skipped( $base["name"] );
            }
        }
        if ( "routing" === ( $n["email-recipients"] ?? "default" ) ) {
            $out = [];
            foreach ( (array) ( $n["routing"] ?? [] ) as $i => $route ) {
                $rules = is_array( $route ) ? self::convert_rules( [ $route ], $ids, $fields ) : null;
                $email = trim( (string) ( $route["email"] ?? "" ) );
                if ( ! $rules || "" === $email || isset( $base["conditional"] ) ) {
                    $skipped[] = self::rules_skipped( $base["name"] );
                    continue;
                }
                $out[] = array_merge( $base, [ "id" => $base["id"] . "_" . ( $i + 1 ), "to" => self::detag( $email, $ids, $skipped ), "conditional" => [ "action" => "show", "match" => "all", "conditions" => $rules ] ] );
            }
            return $out;
        }
        $to = self::detag( (string) ( $n["recipients"] ?? "" ), $ids, $skipped );
        return "" === trim( $to ) ? [] : [ $base + [ "to" => $to ] ];
    }

    /**
     * Forminator placeholders ({email-1}, {all_fields}, {site_title}) as ours.
     *
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $ids, array &$skipped ): string {
        return (string) preg_replace_callback( '/\{([a-z0-9_-]+)\}/i', static function ( $m ) use ( $ids, &$skipped ) {
            $tag = $m[1];
            if ( isset( $ids[ $tag ] ) ) {
                return "{" . $ids[ $tag ] . "}";
            }
            if ( isset( self::TAGS[ $tag ] ) ) {
                return self::TAGS[ $tag ];
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Entries (not spam, not drafts), oldest first. Forminator stores local time.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        global $wpdb;
        $model = self::model( (int) $source_id );
        if ( null === $model || ! self::table_readable( $wpdb->prefix . "frmt_form_entry" ) ) {
            return 0;
        }
        $ids   = self::ids( $model["fields"] );
        $types = array_column( $fields, "type", "id" );
        $count = 0;
        $last  = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT entry_id, date_created FROM {$wpdb->prefix}frmt_form_entry
                  WHERE form_id = %d AND " . self::live_entries() . " AND entry_id > %d ORDER BY entry_id ASC LIMIT 200",
                (int) $source_id,
                $last
            ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $last   = (int) $row["entry_id"];
                $values = [];
                $ip     = "";
                foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}frmt_form_entry_meta WHERE entry_id = %d", $last ), ARRAY_A ) as $m ) {
                    $key = (string) $m["meta_key"];
                    if ( "_forminator_user_ip" === $key ) {
                        $ip = (string) $m["meta_value"];
                        continue;
                    }
                    if ( ! isset( $ids[ $key ] ) ) {
                        continue;
                    }
                    $tid   = $ids[ $key ];
                    $type  = $types[ $tid ] ?? "text";
                    $value = maybe_unserialize( $m["meta_value"] );
                    if ( "file" === $type && is_array( $value ) ) {
                        $urls  = (array) ( $value["file"]["file_url"] ?? [] );
                        $value = implode( "\n", array_map( "strval", $urls ) );
                    } elseif ( is_array( $value ) && ( "name" === $type || isset( $value["first-name"] ) ) ) {
                        $value = trim( implode( " ", array_filter( [ $value["first-name"] ?? "", $value["middle-name"] ?? "", $value["last-name"] ?? "" ], static fn( $v ) => "" !== trim( (string) $v ) ) ) );
                    } elseif ( is_array( $value ) && "address" === $type ) {
                        $value = implode( ", ", array_filter( array_map( "strval", $value ), static fn( $v ) => "" !== trim( $v ) ) );
                    } elseif ( in_array( $type, [ "checkbox", "multiselect" ], true ) && ! is_array( $value ) ) {
                        $value = array_values( array_filter( array_map( "trim", explode( ",", (string) $value ) ), "strlen" ) );
                    } elseif ( "consent" === $type ) {
                        $value = ! empty( $value ) ? "1" : "";
                    } elseif ( is_array( $value ) && ! in_array( $type, [ "checkbox", "multiselect" ], true ) ) {
                        $value = implode( ", ", array_map( "strval", $value ) );
                    }
                    $values[ $tid ] = $value;
                }
                if ( self::add_entry( $form_id, [ "submitted_at" => self::to_utc( (string) $row["date_created"] ), "ip" => $ip, "source_entry_id" => $last ], $values, $fields ) ) {
                    $count++;
                }
            }
        } while ( count( (array) $rows ) === 200 );
        return $count;
    }
}
