<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reading SureForms' entries table; works while SureForms is deactivated.

/**
 * SureForms.
 *
 * A `sureforms_form` post whose content is blocks (srfm/input, srfm/email, ...),
 * one per field, with the field settings as block attributes. Submit text,
 * confirmations and email notifications are post meta (_srfm_*). Entries are in
 * `srfm_entries`: every answer in the `form_data` JSON under a key that carries
 * the block id ("srfm-input-65dfc7bf-lbl-...-name"), local time.
 */
class SureForms extends Base_Importer {

    public const POST_TYPE = "sureforms_form";

    /** SureForms block => Trinity field type. */
    private const FIELD_MAP = [
        "srfm/input"         => "text",
        "srfm/email"         => "email",
        "srfm/textarea"      => "textarea",
        "srfm/phone"         => "phone",
        "srfm/url"           => "website",
        "srfm/number"        => "number",
        "srfm/dropdown"      => "select",
        "srfm/multi-choice"  => "radio",
        "srfm/checkbox"      => "consent",
        "srfm/gdpr"          => "consent",
        "srfm/address"       => "address",
        "srfm/hidden"        => "hidden",
        "srfm/date-picker"   => "date",
        "srfm/time-picker"   => "time",
        "srfm/upload"        => "file",
        "srfm/rating"        => "rating",
        "srfm/slider"        => "range",
        "srfm/page-break"    => "page_break",
        "srfm/separator"     => "section",
        "srfm/advanced-heading" => "html",
        "srfm/password"      => "password",
    ];

    /** Blocks that hold no answer or live inside another field. */
    private const SILENT = [ "srfm/inline-button", "srfm/payment" ];

    /** SureForms smart tags => ours. */
    private const TAGS = [
        "all_data"           => "{all_fields}",
        "admin_email"        => "{admin_email}",
        "site_title"         => "{site_name}",
        "site_url"           => "{site_url}",
        "form_title"         => "{form_title}",
        "entry_id"           => "{entry_id}",
        "current_page_url"   => "{embed_url}",
        "embed_post_url"     => "{embed_url}",
        "embed_post_title"   => "{post:post_title}",
        "embed_post_id"      => "{post:ID}",
        "http_referer"       => "{referer}",
        "user_ip"            => "{ip}",
        "browser_name"       => "{user_agent}",
        "date_mdy"           => "{today:m/d/Y}",
        "date_dmy"           => "{today:d/m/Y}",
        "user_id"            => "{user:ID}",
        "user_email"         => "{user:user_email}",
        "user_display_name"  => "{user:display_name}",
        "user_first_name"    => "{user:first_name}",
        "user_last_name"     => "{user:last_name}",
        "user_login"         => "{user:user_login}",
    ];

    public function slug(): string {
        return "sureforms";
    }

    public function label(): string {
        return "SureForms";
    }

    public function is_available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' LIMIT 1", self::POST_TYPE ) );
    }

    public function forms(): array {
        global $wpdb;
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", self::POST_TYPE ), ARRAY_A );
        $counts = [];
        if ( self::table_readable( $wpdb->prefix . "srfm_entries" ) ) {
            foreach ( (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS n FROM {$wpdb->prefix}srfm_entries WHERE status IN ('read','unread') GROUP BY form_id", ARRAY_A ) as $r ) {
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

    /**
     * Every field block, inner blocks included, in order.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @return array<int,array<string,mixed>>
     */
    private static function field_blocks( array $blocks ): array {
        $out = [];
        foreach ( $blocks as $b ) {
            $name = (string) ( $b["blockName"] ?? "" );
            if ( str_starts_with( $name, "srfm/" ) ) {
                $out[] = [ "name" => $name, "attrs" => (array) $b["attrs"] ];
                if ( "srfm/address" === $name ) {
                    continue; // its parts are inner blocks of the one address field
                }
            }
            if ( ! empty( $b["innerBlocks"] ) ) {
                $out = array_merge( $out, self::field_blocks( (array) $b["innerBlocks"] ) );
            }
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @return array<string,string> block id => Trinity field id
     */
    private static function ids( array $blocks ): array {
        $ids = [];
        foreach ( $blocks as $i => $b ) {
            if ( isset( self::FIELD_MAP[ $b["name"] ] ) ) {
                $a     = $b["attrs"];
                $key   = (string) ( $a["block_id"] ?? "#" . $i );
                $ids[ $key ] = self::unique_id( (string) ( $a["slug"] ?? $a["label"] ?? "" ), "field_" . ( $i + 1 ), array_values( $ids ) );
            }
        }
        return $ids;
    }

    public function convert( $source_id ): ?array {
        $post = get_post( (int) $source_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return null;
        }
        $blocks  = self::field_blocks( parse_blocks( (string) $post->post_content ) );
        $ids     = self::ids( $blocks );
        $slugs   = [];
        foreach ( $blocks as $i => $b ) {
            $key = (string) ( $b["attrs"]["block_id"] ?? "#" . $i );
            if ( isset( $ids[ $key ] ) && "" !== (string) ( $b["attrs"]["slug"] ?? "" ) ) {
                $slugs[ (string) $b["attrs"]["slug"] ] = $ids[ $key ];
            }
        }
        $logic   = (string) wp_json_encode( get_post_meta( $post->ID, "_srfm_conditional_logic", true ) );
        $skipped = [];
        $fields  = [];
        foreach ( $blocks as $i => $b ) {
            $a     = $b["attrs"];
            $label = (string) ( $a["label"] ?? "" );
            if ( in_array( $b["name"], self::SILENT, true ) ) {
                continue;
            }
            if ( ! isset( self::FIELD_MAP[ $b["name"] ] ) ) {
                $skipped[] = self::field_skipped( $label, substr( $b["name"], 5 ) );
                continue;
            }
            $field = self::convert_field( $b["name"], $a, $ids[ (string) ( $a["block_id"] ?? "#" . $i ) ], $skipped );
            if ( ! empty( $a["block_id"] ) && false !== strpos( $logic, (string) $a["block_id"] ) ) {
                $skipped[] = self::rules_skipped( $field["label"] );
            }
            $fields[] = $field;
        }

        $settings = [];
        $submit   = (string) get_post_meta( $post->ID, "_srfm_submit_button_text", true );
        if ( "" !== trim( $submit ) ) {
            $settings["submit_button_text"] = $submit;
        }
        $confirmations = get_post_meta( $post->ID, "_srfm_form_confirmation", true );
        $first         = is_array( $confirmations ) ? reset( $confirmations ) : null;
        if ( is_array( $first ) ) {
            $type = (string) ( $first["confirmation_type"] ?? "same page" );
            if ( "different page" === $type && "" !== trim( (string) ( $first["page_url"] ?? "" ) ) ) {
                $settings["redirect_url"] = (string) $first["page_url"];
            } elseif ( "custom url" === $type && "" !== trim( (string) ( $first["custom_url"] ?? "" ) ) ) {
                $settings["redirect_url"] = self::detag( (string) $first["custom_url"], $slugs, $skipped );
            } elseif ( "" !== trim( wp_strip_all_tags( (string) ( $first["message"] ?? "" ) ) ) ) {
                // The template messages start with SureForms' own check-mark image.
                $message                          = (string) preg_replace( '#<p[^>]*>\s*<img[^>]+sureforms/images/[^>]*>\s*</p>#i', "", (string) $first["message"] );
                $settings["confirmation_message"] = self::detag( trim( $message ), $slugs, $skipped );
            }
        }

        $notifications = [];
        foreach ( (array) get_post_meta( $post->ID, "_srfm_email_notification", true ) as $i => $n ) {
            if ( ! is_array( $n ) ) {
                continue;
            }
            $to = self::detag( (string) ( $n["email_to"] ?? "" ), $slugs, $skipped );
            if ( "" === trim( $to ) ) {
                continue;
            }
            $name  = (string) ( $n["name"] ?? "" );
            $entry = [
                "id"      => "sureforms_" . ( (int) ( $n["id"] ?? $i ) ),
                "name"    => "" !== $name ? $name : __( "Admin notification", "trinity-forms" ),
                "to"      => $to,
                /* translators: {site_name} is a merge tag and must stay untranslated. */
                "subject" => "" !== trim( (string) ( $n["subject"] ?? "" ) ) ? self::detag( (string) $n["subject"], $slugs, $skipped ) : __( "New form entry on {site_name}", "trinity-forms" ),
                "body"    => self::detag( (string) ( $n["email_body"] ?? "{all_data}" ), $slugs, $skipped ),
                "enabled" => ! isset( $n["status"] ) || ! empty( $n["status"] ),
                "event"   => "submission",
            ];
            foreach ( [ "from_name" => "from_name", "from_email" => "from_email", "email_reply_to" => "reply_to", "email_cc" => "cc", "email_bcc" => "bcc" ] as $from => $key ) {
                if ( "" !== trim( (string) ( $n[ $from ] ?? "" ) ) ) {
                    $entry[ $key ] = self::detag( (string) $n[ $from ], $slugs, $skipped );
                }
            }
            $notifications[] = $entry;
        }

        return [
            "title"         => (string) $post->post_title,
            "fields"        => $fields,
            "settings"      => $settings,
            "notifications" => $notifications,
            "skipped"       => array_values( array_unique( $skipped ) ),
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<int,string>   $skipped
     * @return array<string,mixed>
     */
    private static function convert_field( string $block, array $a, string $id, array &$skipped ): array {
        $label = (string) ( $a["label"] ?? "" );
        $field = [
            "id"       => $id,
            "type"     => self::FIELD_MAP[ $block ],
            "label"    => $label,
            "required" => ! empty( $a["required"] ),
        ];
        if ( "" !== trim( (string) ( $a["placeholder"] ?? "" ) ) ) {
            $field["placeholder"] = (string) $a["placeholder"];
        }
        if ( "" !== trim( wp_strip_all_tags( (string) ( $a["help"] ?? "" ) ) ) ) {
            $field["help"] = trim( wp_strip_all_tags( (string) $a["help"] ) );
        }
        if ( ! empty( $a["fieldWidth"] ) && (float) $a["fieldWidth"] < 100 ) {
            $w                    = (float) $a["fieldWidth"];
            $field["field_width"] = $w <= 25 ? "25" : ( $w < 50 ? "33" : "50" );
        }
        $default = (string) ( is_scalar( $a["defaultValue"] ?? "" ) ? ( $a["defaultValue"] ?? "" ) : "" );

        switch ( $block ) {
            case "srfm/input":
                if ( ! empty( $a["textLength"] ) ) {
                    $field["max_length"] = (int) $a["textLength"];
                }
                if ( ! empty( $a["inputMask"] ) && "none" !== $a["inputMask"] ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the input mask", "trinity-forms" ), $label );
                }
                break;
            case "srfm/email":
                if ( ! empty( $a["isConfirmEmail"] ) ) {
                    /* translators: %s: field label. */
                    $skipped[] = sprintf( __( "%s: the second box that repeats the email", "trinity-forms" ), $label );
                }
                break;
            case "srfm/textarea":
                if ( ! empty( $a["rows"] ) ) {
                    $field["rows"] = (int) $a["rows"];
                }
                if ( ! empty( $a["maxLength"] ) ) {
                    $field["max_length"] = (int) $a["maxLength"];
                }
                break;
            case "srfm/number":
                foreach ( [ "minValue" => "min", "maxValue" => "max" ] as $from => $to ) {
                    if ( isset( $a[ $from ] ) && "" !== (string) $a[ $from ] ) {
                        $field[ $to ] = (string) $a[ $from ];
                    }
                }
                break;
            case "srfm/dropdown":
                if ( ! empty( $a["multiSelect"] ) ) {
                    $field["type"] = "multiselect";
                }
                break;
            case "srfm/multi-choice":
                if ( empty( $a["singleSelection"] ) ) {
                    $field["type"] = "checkbox";
                }
                break;
            case "srfm/checkbox":
            case "srfm/gdpr":
                $field["consent_text"] = trim( wp_strip_all_tags( $label ) );
                if ( "srfm/gdpr" === $block ) {
                    $field["required"] = true;
                }
                $default = "";
                break;
            case "srfm/hidden":
                $field["default"] = $default;
                $default          = "";
                unset( $field["required"] );
                break;
            case "srfm/upload":
                $ext = array_filter( array_map( static fn( $t ) => is_array( $t ) ? (string) ( $t["value"] ?? "" ) : (string) $t, (array) ( $a["allowedFormats"] ?? [] ) ) );
                if ( $ext ) {
                    $field["accept"] = implode( ",", array_map( static fn( $e ) => "." . ltrim( strtolower( $e ), "." ), $ext ) );
                }
                if ( ! empty( $a["fileSizeLimit"] ) ) {
                    $field["max_size"] = max( 1, (int) $a["fileSizeLimit"] );
                }
                if ( ! empty( $a["multiple"] ) ) {
                    $field["multiple"] = true;
                }
                break;
            case "srfm/advanced-heading":
                $field["html"] = "<p>" . esc_html( (string) ( $a["headingTitle"] ?? $label ) ) . "</p>";
                unset( $field["required"] );
                break;
            case "srfm/separator":
            case "srfm/page-break":
                unset( $field["required"] );
                break;
        }

        if ( in_array( $field["type"], [ "select", "multiselect", "radio", "checkbox" ], true ) ) {
            $field["options"] = [];
            foreach ( (array) ( $a["options"] ?? [] ) as $o ) {
                $text = (string) ( $o["label"] ?? $o["optionTitle"] ?? "" );
                $val  = ! empty( $a["showValues"] ) && "" !== (string) ( $o["value"] ?? "" ) ? (string) $o["value"] : $text;
                $field["options"][] = [ "label" => $text, "value" => $val ];
            }
            $pre = array_filter( (array) ( $a["preselectedOptions"] ?? [] ), "is_numeric" );
            if ( $pre ) {
                $values  = array_column( $field["options"], "value" );
                $default = implode( ",", array_filter( array_map( static fn( $i ) => $values[ (int) $i ] ?? "", $pre ) ) );
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
     * SureForms smart tags ({form:email}, {all_data}, {site_title}) as ours.
     *
     * @param array<string,string> $slugs field slug => Trinity id
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $slugs, array &$skipped ): string {
        return (string) preg_replace_callback( '/\{([a-z_]+)(?::([^{}\s]+))?\}/', static function ( $m ) use ( $slugs, &$skipped ) {
            if ( "form" === $m[1] && isset( $m[2], $slugs[ $m[2] ] ) ) {
                return "{" . $slugs[ $m[2] ] . "}";
            }
            if ( ! isset( $m[2] ) && isset( self::TAGS[ $m[1] ] ) ) {
                return self::TAGS[ $m[1] ];
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Entries (read and unread), oldest first. SureForms stores local time.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        global $wpdb;
        $post = get_post( (int) $source_id );
        if ( ! $post || ! self::table_readable( $wpdb->prefix . "srfm_entries" ) ) {
            return 0;
        }
        $ids   = self::ids( self::field_blocks( parse_blocks( (string) $post->post_content ) ) );
        $types = array_column( $fields, "type", "id" );
        $count = 0;
        $last  = 0;
        do {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, user_id, form_data, submission_info, status, created_at FROM {$wpdb->prefix}srfm_entries
                  WHERE form_id = %d AND status IN ('read','unread') AND ID > %d ORDER BY ID ASC LIMIT 200",
                (int) $source_id,
                $last
            ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $last   = (int) $row["ID"];
                $values = [];
                foreach ( (array) json_decode( (string) $row["form_data"], true ) as $key => $value ) {
                    $tid = null;
                    foreach ( $ids as $block_id => $candidate ) {
                        if ( false !== strpos( (string) $key, "-" . $block_id . "-" ) ) {
                            $tid = $candidate;
                            break;
                        }
                    }
                    if ( null === $tid ) {
                        continue;
                    }
                    $type = $types[ $tid ] ?? "text";
                    if ( in_array( $type, [ "checkbox", "multiselect" ], true ) && ! is_array( $value ) ) {
                        $value = array_values( array_filter( array_map( "trim", explode( ",", (string) $value ) ), "strlen" ) );
                    } elseif ( "consent" === $type ) {
                        $value = ! empty( $value ) && "false" !== $value ? "1" : "";
                    } elseif ( is_array( $value ) ) {
                        $value = implode( "file" === $type ? "\n" : ", ", array_map( static fn( $v ) => is_scalar( $v ) ? urldecode( (string) $v ) : "", $value ) );
                    }
                    $values[ $tid ] = $value;
                }
                $info = json_decode( (string) $row["submission_info"], true );
                if ( self::add_entry( $form_id, [
                    "source_entry_id" => $last,
                    "submitted_at" => self::to_utc( (string) $row["created_at"] ),
                    "user_id"      => (int) $row["user_id"],
                    "ip"           => is_array( $info ) ? (string) ( $info["user_ip"] ?? "" ) : "",
                    "user_agent"   => is_array( $info ) ? trim( ( $info["browser_name"] ?? "" ) . " " . ( $info["device_name"] ?? "" ) ) : "",
                    "is_read"      => "read" === $row["status"],
                    "source_url"   => is_array( $info ) ? (string) ( $info["submission_url"] ?? "" ) : "",
                ], $values, $fields ) ) {
                    $count++;
                }
            }
        } while ( count( (array) $rows ) === 200 );
        return $count;
    }
}
