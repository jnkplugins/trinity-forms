<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading Formidable's own tables; works while Formidable is deactivated.

/**
 * Formidable Forms (Lite and Pro).
 *
 * Forms in `frm_forms` (settings serialized in options), fields in `frm_fields`
 * (choices in options, everything else in field_options), entries in `frm_items`
 * with one `frm_item_metas` row per answer. Emails and, in newer versions, the
 * confirmation are `frm_form_actions` posts (post_excerpt = action type,
 * menu_order = form id, settings as JSON in post_content).
 */
class Formidable extends Base_Importer {

    /** Formidable field type => Trinity field type. */
    private const FIELD_MAP = [
        "text"     => "text",
        "textarea" => "textarea",
        "rte"      => "textarea",
        "email"    => "email",
        "url"      => "website",
        "number"   => "number",
        "phone"    => "phone",
        "name"     => "name",
        "address"  => "address",
        "checkbox" => "checkbox",
        "radio"    => "radio",
        "select"   => "select",
        "hidden"   => "hidden",
        "html"     => "html",
        "divider"  => "section",
        "break"    => "page_break",
        "date"     => "date",
        "time"     => "time",
        "file"     => "file",
        "star"     => "rating",
        "range"    => "range",
        "password" => "password",
    ];

    /** Fields that hold no answer and need no note. */
    private const SILENT = [ "end_divider", "captcha", "user_id", "submit", "summary" ];

    /** Formidable condition => Trinity operator. */
    private const OPERATORS = [
        "=="        => "equals",
        "!="        => "not_equals",
        ">"         => "greater_than",
        "<"         => "less_than",
        "LIKE"      => "contains",
        "not LIKE"  => "not_contains",
        "LIKE%"     => "starts_with",
        "%LIKE"     => "ends_with",
    ];

    /** Formidable shortcodes => ours. */
    private const TAGS = [
        "default-message" => "{all_fields}",
        "default-html"    => "{all_fields}",
        "default-plain"   => "{all_fields}",
        "admin_email"     => "{admin_email}",
        "sitename"        => "{site_name}",
        "siteurl"         => "{site_url}",
        "form_name"       => "{form_title}",
        "id"              => "{entry_id}",
        "ip"              => "{ip}",
        "date"            => "{date}",
        "created-at"      => "{date}",
        "user_agent"      => "{user_agent}",
        "referer"         => "{referer}",
    ];

    public function slug(): string {
        return "formidable";
    }

    public function label(): string {
        return "Formidable Forms";
    }

    private static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public function is_available(): bool {
        return self::table_readable( self::table( "frm_forms" ) );
    }

    public function forms(): array {
        global $wpdb;
        if ( ! $this->is_available() ) {
            return [];
        }
        $rows   = $wpdb->get_results( "SELECT id, name FROM " . self::table( "frm_forms" ) . " WHERE is_template = 0 AND status = 'published' AND ( parent_form_id = 0 OR parent_form_id IS NULL ) ORDER BY id ASC", ARRAY_A );
        $counts = [];
        if ( self::table_readable( self::table( "frm_items" ) ) ) {
            foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS n FROM " . self::table( "frm_items" ) . " WHERE is_draft = 0 GROUP BY form_id", ARRAY_A ) as $r ) {
                $counts[ (int) $r["form_id"] ] = (int) $r["n"];
            }
        }
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row["id"] ] = [ "title" => (string) $row["name"], "entries" => $counts[ (int) $row["id"] ] ?? 0 ];
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
     * The form row and its fields, unserialized, in order.
     *
     * @return array{name:string,options:array<string,mixed>,fields:array<int,array<string,mixed>>}|null
     */
    private static function form_data( int $id ): ?array {
        global $wpdb;
        if ( ! self::table_readable( self::table( "frm_forms" ) ) ) {
            return null;
        }
        $form = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, options FROM " . self::table( "frm_forms" ) . " WHERE id = %d", $id ), ARRAY_A );
        if ( ! $form ) {
            return null;
        }
        $fields = [];
        if ( class_exists( "FrmField" ) && method_exists( "FrmField", "get_all_for_form" ) ) {
            foreach ( (array) \FrmField::get_all_for_form( $id, "", "exclude" ) as $f ) {
                $fields[] = (array) $f;
            }
        }
        if ( ! $fields ) {
            $fields = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . self::table( "frm_fields" ) . " WHERE form_id = %d ORDER BY field_order ASC, id ASC", $id ), ARRAY_A );
        }
        foreach ( $fields as $i => $f ) {
            foreach ( [ "options", "field_options", "default_value" ] as $k ) {
                $fields[ $i ][ $k ] = maybe_unserialize( $f[ $k ] ?? "" );
            }
            $fields[ $i ]["field_options"] = is_array( $fields[ $i ]["field_options"] ) ? $fields[ $i ]["field_options"] : [];
        }
        $options = maybe_unserialize( $form["options"] );
        return [ "name" => (string) $form["name"], "options" => is_array( $options ) ? $options : [], "fields" => $fields ];
    }

    /**
     * Formidable field id and key => Trinity field id.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array<string,string>
     */
    private static function ids( array $fields ): array {
        $ids  = [];
        $used = [];
        foreach ( $fields as $f ) {
            if ( ! isset( self::FIELD_MAP[ (string) $f["type"] ] ) ) {
                continue;
            }
            $key  = (string) ( $f["field_key"] ?? "" );
            $base = preg_match( '/^[a-z0-9]{6}$/', $key ) && preg_match( '/\d/', $key ) ? (string) $f["name"] : $key; // generated keys ("qh4icy") read worse than the label
            $id   = self::unique_id( $base, "field_" . $f["id"], $used );
            $used[]                   = $id;
            $ids[ (string) $f["id"] ] = $id;
            if ( "" !== $key ) {
                $ids[ $key ] = $id;
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
        $choices = [];
        foreach ( $data["fields"] as $f ) {
            $choices[ (string) $f["id"] ] = $f;
        }
        $skipped = [];
        $fields  = [];
        foreach ( $data["fields"] as $f ) {
            $type = (string) $f["type"];
            if ( in_array( $type, self::SILENT, true ) ) {
                continue;
            }
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                $skipped[] = self::field_skipped( (string) $f["name"], $type );
                continue;
            }
            $field = self::convert_field( $f, $ids[ (string) $f["id"] ], $skipped );
            $fo    = $f["field_options"];
            if ( ! empty( $fo["hide_field"] ) && is_array( $fo["hide_field"] ) ) {
                $rules = self::convert_rules( (array) $fo["hide_field"], (array) ( $fo["hide_field_cond"] ?? [] ), (array) ( $fo["hide_opt"] ?? [] ), $ids, $choices );
                if ( $rules ) {
                    $field["conditional"] = [ "action" => "hide" === ( $fo["show_hide"] ?? "show" ) ? "hide" : "show", "match" => "any" === ( $fo["any_all"] ?? "all" ) ? "any" : "all", "conditions" => $rules ];
                } else {
                    $skipped[] = self::rules_skipped( $field["label"] );
                }
            }
            $fields[] = $field;
        }

        $opts     = $data["options"];
        $settings = [];
        if ( "" !== trim( (string) ( $opts["submit_value"] ?? "" ) ) ) {
            $settings["submit_button_text"] = (string) $opts["submit_value"];
        }
        $on_submit = self::actions( (int) $source_id, "on_submit" );
        $confirm   = $on_submit ? $on_submit[0]["settings"] : $opts;
        switch ( (string) ( $confirm["success_action"] ?? "message" ) ) {
            case "page":
                $url = ! empty( $confirm["success_page_id"] ) ? (string) get_permalink( (int) $confirm["success_page_id"] ) : "";
                if ( "" !== $url ) {
                    $settings["redirect_url"] = $url;
                }
                break;
            case "redirect":
                if ( "" !== trim( (string) ( $confirm["success_url"] ?? "" ) ) ) {
                    $settings["redirect_url"] = self::detag( (string) $confirm["success_url"], $ids, $skipped );
                }
                break;
            default:
                if ( "" !== trim( wp_strip_all_tags( (string) ( $confirm["success_msg"] ?? "" ) ) ) ) {
                    $settings["confirmation_message"] = self::detag( (string) $confirm["success_msg"], $ids, $skipped );
                }
        }
        if ( count( $on_submit ) > 1 ) {
            $skipped[] = self::rules_skipped( __( "Confirmation message", "trinity-forms" ) );
        }

        $notifications = [];
        foreach ( self::actions( (int) $source_id, "email" ) as $i => $action ) {
            $n = self::convert_email( $action, $i, $ids, $choices, (string) $data["name"], $skipped );
            if ( $n ) {
                $notifications[] = $n;
            }
        }

        return [
            "title"         => (string) $data["name"],
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
        $fo    = $f["field_options"];
        $label = (string) $f["name"];
        $field = [
            "id"       => $id,
            "type"     => self::FIELD_MAP[ $type ],
            "label"    => $label,
            "required" => ! empty( $f["required"] ),
        ];
        if ( "" !== trim( (string) ( $fo["placeholder"] ?? "" ) ) ) {
            $field["placeholder"] = (string) $fo["placeholder"];
        }
        $help = trim( wp_strip_all_tags( (string) ( $f["description"] ?? "" ) ) );
        if ( "" !== $help && ! in_array( $type, [ "html", "divider" ], true ) ) {
            $field["help"] = $help;
        }
        if ( "" !== trim( (string) ( $fo["classes"] ?? "" ) ) ) {
            $field["css_class"] = (string) $fo["classes"];
            $field              = self::width_from_class( $field );
            if ( "frm_full" === ( $field["css_class"] ?? "" ) ) {
                unset( $field["css_class"] );
            }
        }
        $default = $f["default_value"];

        switch ( $type ) {
            case "text":
                if ( ! empty( $fo["max"] ) ) {
                    $field["max_length"] = (int) $fo["max"];
                }
                break;
            case "textarea":
            case "rte":
                if ( ! empty( $fo["max"] ) ) {
                    $field["rows"] = (int) $fo["max"];
                }
                break;
            case "number":
            case "range":
                foreach ( [ "minnum" => "min", "maxnum" => "max", "step" => "step" ] as $from => $to ) {
                    if ( isset( $fo[ $from ] ) && "" !== (string) $fo[ $from ] ) {
                        $field[ $to ] = (string) $fo[ $from ];
                    }
                }
                if ( "range" === $type && is_scalar( $default ) && "" !== (string) $default ) {
                    $field["default"] = (string) $default;
                    $default          = "";
                }
                break;
            case "star":
                $field["max"] = (string) max( 2, (int) ( $fo["maxnum"] ?? 5 ) );
                $default      = "";
                break;
            case "hidden":
                $field["default"] = is_scalar( $default ) ? (string) $default : "";
                $default          = "";
                unset( $field["required"] );
                break;
            case "html":
                $field["html"] = (string) ( $f["description"] ?? "" );
                unset( $field["required"] );
                break;
            case "divider":
                $field["description"] = trim( wp_strip_all_tags( (string) ( $f["description"] ?? "" ) ) );
                unset( $field["required"] );
                break;
            case "break":
                $field["label"] = "";
                if ( "" !== trim( $label ) ) {
                    $field["next_text"] = $label;
                }
                unset( $field["required"] );
                break;
            case "file":
                $ext = [];
                foreach ( (array) ( $fo["ftypes"] ?? [] ) as $k => $mime ) {
                    foreach ( explode( "|", (string) $k ) as $e ) {
                        if ( "" !== trim( $e ) ) {
                            $ext[] = "." . strtolower( trim( $e ) );
                        }
                    }
                }
                if ( $ext ) {
                    $field["accept"] = implode( ",", $ext );
                }
                if ( is_numeric( $fo["size"] ?? null ) && (float) $fo["size"] > 0 ) {
                    $field["max_size"] = max( 1, (int) ceil( (float) $fo["size"] ) );
                }
                if ( ! empty( $fo["multiple"] ) ) {
                    $field["multiple"] = true;
                }
                break;
            case "rte":
                break;
        }
        if ( "rte" === $type ) {
            /* translators: %s: field label. */
            $skipped[] = sprintf( __( "%s: rich-text formatting (now a plain text box)", "trinity-forms" ), $label );
        }

        if ( in_array( $field["type"], [ "select", "radio", "checkbox" ], true ) ) {
            if ( "select" === $field["type"] && ! empty( $fo["multiple"] ) ) {
                $field["type"] = "multiselect";
            }
            $field["options"] = [];
            foreach ( (array) $f["options"] as $o ) {
                $o = is_array( $o ) ? $o : [ "label" => (string) $o, "value" => (string) $o ];
                if ( "" === trim( (string) ( $o["label"] ?? "" ) ) && "" === trim( (string) ( $o["value"] ?? "" ) ) ) {
                    continue; // the blank first option of a dropdown
                }
                $field["options"][] = [ "label" => (string) ( $o["label"] ?? "" ), "value" => (string) ( ! empty( $fo["separate_value"] ) ? ( $o["value"] ?? $o["label"] ?? "" ) : ( $o["label"] ?? "" ) ) ];
            }
            if ( ! in_array( $field["type"], [ "select", "multiselect" ], true ) ) {
                unset( $field["placeholder"] );
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
     * @param array<int,mixed>              $targets
     * @param array<int,mixed>              $conds
     * @param array<int,mixed>              $values
     * @param array<string,string>          $ids
     * @param array<string,array<string,mixed>> $fields Formidable id => field
     * @return array<int,array{field:string,operator:string,value:string}>|null
     */
    private static function convert_rules( array $targets, array $conds, array $values, array $ids, array $fields ): ?array {
        $rules = [];
        foreach ( array_values( $targets ) as $i => $target ) {
            $target = (string) $target;
            $op     = (string) ( array_values( $conds )[ $i ] ?? "==" );
            if ( "" === $target ) {
                continue;
            }
            if ( ! isset( $ids[ $target ], self::OPERATORS[ $op ] ) ) {
                return null;
            }
            $value = array_values( $values )[ $i ] ?? "";
            $value = is_array( $value ) ? implode( ",", $value ) : (string) $value;
            $op    = self::OPERATORS[ $op ];
            if ( "" === $value && in_array( $op, [ "equals", "not_equals" ], true ) ) {
                $op = "equals" === $op ? "empty" : "not_empty";
            }
            $rules[] = [ "field" => $ids[ $target ], "operator" => $op, "value" => $value ];
        }
        return $rules ?: null;
    }

    /**
     * Form actions of one type, in their order, active ones and paused ones.
     *
     * @return array<int,array{title:string,active:bool,settings:array<string,mixed>}>
     */
    private static function actions( int $form_id, string $type ): array {
        $posts = get_posts( [
            "post_type"        => "frm_form_actions",
            "post_status"      => [ "publish", "draft" ],
            "menu_order"       => $form_id,
            "numberposts"      => -1,
            "orderby"          => "ID",
            "order"            => "ASC",
            "suppress_filters" => true,
        ] );
        $out = [];
        foreach ( $posts as $post ) {
            if ( $type !== $post->post_excerpt ) {
                continue;
            }
            $settings = json_decode( (string) $post->post_content, true );
            $out[]    = [ "title" => (string) $post->post_title, "active" => "publish" === $post->post_status, "settings" => is_array( $settings ) ? $settings : [] ];
        }
        return $out;
    }

    /**
     * @param array{title:string,active:bool,settings:array<string,mixed>} $action
     * @param array<string,string>                $ids
     * @param array<string,array<string,mixed>>   $fields
     * @param array<int,string>                   $skipped
     * @return array<string,mixed>|null
     */
    private static function convert_email( array $action, int $index, array $ids, array $fields, string $form_name, array &$skipped ): ?array {
        $s  = $action["settings"];
        $to = self::detag( (string) ( $s["email_to"] ?? "" ), $ids, $skipped );
        if ( "" === trim( $to ) ) {
            return null;
        }
        $name    = "" !== $action["title"] ? $action["title"] : __( "Admin notification", "trinity-forms" );
        $subject = trim( (string) ( $s["email_subject"] ?? "" ) );
        $out     = [
            "id"      => "formidable_" . ( $index + 1 ),
            "name"    => $name,
            "to"      => $to,
            /* translators: {site_name} is a merge tag and must stay untranslated. */
            "subject" => "" !== $subject ? self::detag( $subject, $ids, $skipped ) : __( "New form entry on {site_name}", "trinity-forms" ),
            "body"    => self::detag( "" !== trim( (string) ( $s["email_message"] ?? "" ) ) ? (string) $s["email_message"] : "[default-message]", $ids, $skipped ),
            "enabled" => $action["active"],
            "event"   => "submission",
        ];
        foreach ( [ "cc" => "cc", "bcc" => "bcc", "reply_to" => "reply_to" ] as $from => $key ) {
            if ( "" !== trim( (string) ( $s[ $from ] ?? "" ) ) ) {
                $out[ $key ] = self::detag( (string) $s[ $from ], $ids, $skipped );
            }
        }
        $from = trim( (string) ( $s["from"] ?? "" ) );
        if ( preg_match( '/^(.*?)\s*<([^>]+)>$/', $from, $m ) ) {
            if ( "" !== trim( $m[1] ) ) {
                $out["from_name"] = self::detag( trim( $m[1] ), $ids, $skipped );
            }
            $from = trim( $m[2] );
        }
        if ( "" !== $from ) {
            $out["from_email"] = self::detag( $from, $ids, $skipped );
        }
        if ( ! in_array( "create", (array) ( $s["event"] ?? [ "create" ] ), true ) ) {
            $out["enabled"] = false;
        }
        $conds   = (array) ( $s["conditions"] ?? [] );
        $targets = [];
        $ops     = [];
        $values  = [];
        foreach ( $conds as $k => $c ) {
            if ( is_numeric( $k ) && is_array( $c ) && "" !== (string) ( $c["hide_field"] ?? "" ) ) {
                $targets[] = $c["hide_field"];
                $ops[]     = $c["hide_field_cond"] ?? "==";
                $values[]  = $c["hide_opt"] ?? "";
            }
        }
        if ( $targets ) {
            $rules = self::convert_rules( $targets, $ops, $values, $ids, $fields );
            if ( $rules ) {
                $out["conditional"] = [ "action" => "stop" === ( $conds["send_stop"] ?? "send" ) ? "hide" : "show", "match" => "any" === ( $conds["any_all"] ?? "all" ) ? "any" : "all", "conditions" => $rules ];
            } else {
                $skipped[] = self::rules_skipped( $name );
            }
        }
        return $out;
    }

    /**
     * Formidable shortcodes ([3], [email], [default-message], [sitename]) as ours.
     *
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $ids, array &$skipped ): string {
        return (string) preg_replace_callback( '/\[([a-z0-9_-]+)(\s[^\]]*)?\]/i', static function ( $m ) use ( $ids, &$skipped ) {
            $tag = $m[1];
            if ( empty( $m[2] ) && isset( $ids[ $tag ] ) ) {
                return "{" . $ids[ $tag ] . "}";
            }
            if ( empty( $m[2] ) && isset( self::TAGS[ $tag ] ) ) {
                return self::TAGS[ $tag ];
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Entries (not drafts), oldest first; Formidable stores UTC.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        global $wpdb;
        $data = self::form_data( (int) $source_id );
        if ( null === $data || ! self::table_readable( self::table( "frm_items" ) ) ) {
            return 0;
        }
        $ids   = self::ids( $data["fields"] );
        $types = array_column( $fields, "type", "id" );
        $count = 0;
        $last  = 0;
        do {
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, ip, user_id, description, created_at FROM " . self::table( "frm_items" ) . " WHERE form_id = %d AND is_draft = 0 AND id > %d ORDER BY id ASC LIMIT 200",
                (int) $source_id,
                $last
            ), ARRAY_A );
            foreach ( (array) $items as $item ) {
                $last   = (int) $item["id"];
                $values = [];
                $metas  = $wpdb->get_results( $wpdb->prepare( "SELECT field_id, meta_value FROM " . self::table( "frm_item_metas" ) . " WHERE item_id = %d", $last ), ARRAY_A );
                foreach ( (array) $metas as $m ) {
                    $tid = $ids[ (string) $m["field_id"] ] ?? null;
                    if ( null === $tid ) {
                        continue;
                    }
                    $value = maybe_unserialize( $m["meta_value"] );
                    $type  = $types[ $tid ] ?? "text";
                    if ( is_array( $value ) && in_array( $type, [ "name", "address" ], true ) ) {
                        $value = implode( "name" === $type ? " " : ", ", array_filter( array_map( "strval", $value ), static fn( $v ) => "" !== trim( $v ) ) );
                    } elseif ( is_array( $value ) && ! in_array( $type, [ "checkbox", "multiselect" ], true ) ) {
                        $value = implode( ", ", array_map( "strval", $value ) );
                    }
                    $values[ $tid ] = $value;
                }
                // JSON in current versions, a serialized array in older ones.
                $about = json_decode( (string) $item["description"], true );
                $about = is_array( $about ) ? $about : maybe_unserialize( $item["description"] );
                if ( self::add_entry( $form_id, [
                    "source_entry_id" => $last,
                    "submitted_at" => (string) $item["created_at"],
                    "ip"           => (string) $item["ip"],
                    "user_id"      => (int) $item["user_id"],
                    "user_agent"   => is_array( $about ) ? (string) ( $about["browser"] ?? "" ) : "",
                    "referer"      => is_array( $about ) ? (string) ( $about["referrer"] ?? "" ) : "",
                    "source_url"   => is_array( $about ) ? (string) ( $about["referrer"] ?? "" ) : "",
                ], $values, $fields ) ) {
                    $count++;
                }
            }
        } while ( count( (array) $items ) === 200 );
        return $count;
    }
}
