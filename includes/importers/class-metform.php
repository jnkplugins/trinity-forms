<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only counts of MetForm's entry posts; works while MetForm is deactivated.

/**
 * MetForm.
 *
 * A `metform-form` post built in Elementor: its fields are mf-* widgets in the
 * `_elementor_data` JSON (label, name, required, placeholder, choices as widget
 * settings), the submit text is the mf-button widget's, and the form settings
 * (success message, redirect, admin email) are the `metform_form__form_setting`
 * post meta. Entries are `metform-entry` posts with the answers in
 * `metform_entries__form_data` (field name => value) and the form id in
 * `metform_entries__form_id`, dated in local time. MetForm's merge tags look
 * like shortcodes: [mf-name].
 *
 * Forms made in MetForm's newer non-Elementor builder keep their fields
 * elsewhere (`_metform_builder_document`); those are listed, and the report
 * says the fields could not be read.
 */
class MetForm extends Base_Importer {

    public const POST_TYPE  = "metform-form";
    public const ENTRY_TYPE = "metform-entry";

    /** MetForm widget => Trinity field type. */
    private const FIELD_MAP = [
        "mf-text"           => "text",
        "mf-email"          => "email",
        "mf-textarea"       => "textarea",
        "mf-telephone"      => "phone",
        "mf-number"         => "number",
        "mf-url"            => "website",
        "mf-select"         => "select",
        "mf-multi-select"   => "multiselect",
        "mf-radio"          => "radio",
        "mf-checkbox"       => "checkbox",
        "mf-switch"         => "consent",
        "mf-gdpr-consent"   => "consent",
        "mf-listing-optin"  => "consent",
        "mf-date"           => "date",
        "mf-time"           => "time",
        "mf-file-upload"    => "file",
        "mf-rating"         => "rating",
        "mf-range"          => "range",
        "mf-password"       => "password",
        "mf-listing-fname"  => "text",
        "mf-listing-lname"  => "text",
    ];

    /** Widgets that hold no answer. */
    private const SILENT = [ "mf-button", "mf-recaptcha", "mf-simple-captcha", "mf-summary" ];

    public function slug(): string {
        return "metform";
    }

    public function label(): string {
        return "MetForm";
    }

    public function is_available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' LIMIT 1", self::POST_TYPE ) );
    }

    public function forms(): array {
        global $wpdb;
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", self::POST_TYPE ), ARRAY_A );
        $counts = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT m.meta_value AS form_id, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
              WHERE m.meta_key = 'metform_entries__form_id' AND p.post_type = %s AND p.post_status = 'publish' GROUP BY m.meta_value",
            self::ENTRY_TYPE
        ), ARRAY_A ) as $r ) {
            $counts[ (int) $r["form_id"] ] = (int) $r["n"];
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
     * Every widget in the Elementor tree, in order.
     *
     * @return array<int,array{type:string,id:string,settings:array<string,mixed>}>
     */
    private static function widgets( int $post_id ): array {
        $data = json_decode( (string) get_post_meta( $post_id, "_elementor_data", true ), true );
        $out  = [];
        $walk = static function ( $elements ) use ( &$walk, &$out ): void {
            foreach ( (array) $elements as $el ) {
                if ( ! is_array( $el ) ) {
                    continue;
                }
                if ( "widget" === ( $el["elType"] ?? "" ) ) {
                    $out[] = [ "type" => (string) ( $el["widgetType"] ?? "" ), "id" => (string) ( $el["id"] ?? "" ), "settings" => is_array( $el["settings"] ?? null ) ? $el["settings"] : [] ];
                }
                if ( ! empty( $el["elements"] ) ) {
                    $walk( $el["elements"] );
                }
            }
        };
        $walk( is_array( $data ) ? $data : [] );
        return $out;
    }

    /** MetForm's own answer key for a widget (what the entry stores). */
    private static function input_name( array $w ): string {
        $name = (string) ( $w["settings"]["mf_input_name"] ?? "" );
        $name = (string) preg_replace( '/[^A-Za-z0-9_-]/', "", $name );
        return "" !== $name ? $name : $w["type"] . "-" . $w["id"];
    }

    /**
     * @param array<int,array<string,mixed>> $widgets
     * @return array<string,string> MetForm input name => Trinity field id
     */
    private static function ids( array $widgets ): array {
        $ids = [];
        foreach ( $widgets as $i => $w ) {
            if ( isset( self::FIELD_MAP[ $w["type"] ] ) ) {
                $name = self::input_name( $w );
                // MetForm names a new field after its widget ("mf-textarea"); the
                // label says more ("What would you like to stock?" => what_would_...).
                $base = $name === $w["type"] || 0 === strpos( $name, $w["type"] . "-" )
                    ? (string) ( $w["settings"]["mf_input_label"] ?? "" )
                    : "";
                $base = "" !== trim( $base ) ? $base : (string) preg_replace( '/^mf-/', "", $name );
                $ids[ $name ] = self::unique_id( $base, "field_" . ( $i + 1 ), array_values( $ids ) );
            }
        }
        return $ids;
    }

    public function convert( $source_id ): ?array {
        $post = get_post( (int) $source_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return null;
        }
        $widgets = self::widgets( (int) $post->ID );
        $ids     = self::ids( $widgets );
        $skipped = [];
        $fields  = [];
        $submit  = "";
        if ( ! $widgets && get_post_meta( (int) $post->ID, "_metform_builder_document", true ) ) {
            $skipped[] = __( "The fields: this form was made in MetForm's own builder, not in Elementor, and its fields could not be read.", "trinity-forms" );
        }
        foreach ( $widgets as $w ) {
            $s = $w["settings"];
            if ( "mf-button" === $w["type"] && "" !== trim( (string) ( $s["mf_btn_text"] ?? "" ) ) ) {
                $submit = (string) $s["mf_btn_text"];
            }
            if ( in_array( $w["type"], self::SILENT, true ) || ! str_starts_with( $w["type"], "mf-" ) ) {
                continue;
            }
            $label = (string) ( $s["mf_input_label"] ?? "" );
            if ( ! isset( self::FIELD_MAP[ $w["type"] ] ) ) {
                $skipped[] = self::field_skipped( $label, substr( $w["type"], 3 ) );
                continue;
            }
            $fields[] = self::convert_field( $w, $ids[ self::input_name( $w ) ] );
            if ( ! empty( $s["mf_conditional_logic_form_list"] ) && "yes" === ( $s["mf_conditional_logic_form_enable"] ?? "" ) ) {
                $skipped[] = self::rules_skipped( "" !== $label ? $label : self::input_name( $w ) );
            }
        }

        $config   = get_post_meta( (int) $post->ID, "metform_form__form_setting", true );
        $config   = is_array( $config ) ? $config : [];
        $settings = [];
        if ( "" !== trim( $submit ) ) {
            $settings["submit_button_text"] = $submit;
        }
        if ( "" !== trim( (string) ( $config["redirect_to"] ?? "" ) ) ) {
            $settings["redirect_url"] = self::detag( (string) $config["redirect_to"], $ids, $skipped );
        }
        if ( "" !== trim( wp_strip_all_tags( (string) ( $config["success_message"] ?? "" ) ) ) ) {
            $settings["confirmation_message"] = self::detag( (string) $config["success_message"], $ids, $skipped );
        }

        $notifications = [];
        if ( ! empty( $config["enable_admin_notification"] ) && "" !== trim( (string) ( $config["admin_email_to"] ?? "" ) ) ) {
            $n = [
                "id"      => "metform_admin",
                "name"    => __( "Admin notification", "trinity-forms" ),
                "to"      => self::detag( (string) $config["admin_email_to"], $ids, $skipped ),
                /* translators: {site_name} is a merge tag and must stay untranslated. */
                "subject" => "" !== trim( (string) ( $config["admin_email_subject"] ?? "" ) ) ? self::detag( (string) $config["admin_email_subject"], $ids, $skipped ) : __( "New form entry on {site_name}", "trinity-forms" ),
                // MetForm adds every answer under the owner's own text.
                "body"    => trim( self::detag( (string) ( $config["admin_email_body"] ?? "" ), $ids, $skipped ) . "\n\n{all_fields}" ),
                "enabled" => true,
                "event"   => "submission",
            ];
            foreach ( [ "admin_email_from" => "from_email", "admin_email_reply_to" => "reply_to" ] as $from => $key ) {
                if ( "" !== trim( (string) ( $config[ $from ] ?? "" ) ) ) {
                    $n[ $key ] = self::detag( (string) $config[ $from ], $ids, $skipped );
                }
            }
            $notifications[] = $n;
        }
        if ( ! empty( $config["enable_user_notification"] ) ) {
            $skipped[] = __( "The confirmation email to the visitor: add it under Notifications, sent to the email field.", "trinity-forms" );
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
     * @param array{type:string,id:string,settings:array<string,mixed>} $w
     * @return array<string,mixed>
     */
    private static function convert_field( array $w, string $id ): array {
        $s     = $w["settings"];
        $label = (string) ( $s["mf_input_label"] ?? "" );
        $field = [
            "id"       => $id,
            "type"     => self::FIELD_MAP[ $w["type"] ],
            "label"    => "" !== $label ? $label : ucfirst( str_replace( [ "mf-", "-" ], [ "", " " ], self::input_name( $w ) ) ),
            "required" => "yes" === ( $s["mf_input_required"] ?? "" ),
        ];
        if ( "" !== trim( (string) ( $s["mf_input_placeholder"] ?? "" ) ) ) {
            $field["placeholder"] = (string) $s["mf_input_placeholder"];
        }
        if ( "" !== trim( wp_strip_all_tags( (string) ( $s["mf_input_help_text"] ?? "" ) ) ) ) {
            $field["help"] = trim( wp_strip_all_tags( (string) $s["mf_input_help_text"] ) );
        }
        if ( in_array( $field["type"], [ "select", "multiselect", "radio", "checkbox" ], true ) ) {
            $field["options"] = [];
            foreach ( (array) ( $s["mf_input_list"] ?? [] ) as $o ) {
                if ( ! is_array( $o ) ) {
                    continue;
                }
                $text = (string) ( $o["mf_input_option_text"] ?? $o["label"] ?? "" );
                $val  = (string) ( $o["mf_input_option_value"] ?? $o["value"] ?? $text );
                $field["options"][] = [ "label" => $text, "value" => "" !== $val ? $val : $text ];
            }
            if ( ! in_array( $field["type"], [ "select", "multiselect" ], true ) ) {
                unset( $field["placeholder"] );
            }
        }
        if ( "consent" === $field["type"] ) {
            $text                  = (string) ( $s["mf_gdpr_consent_option_text"] ?? $s["mf_listing_optin_option_text"] ?? $s["mf_swtich_enable_text"] ?? $label );
            $field["consent_text"] = trim( wp_strip_all_tags( "" !== $text ? $text : $field["label"] ) );
            unset( $field["placeholder"] );
        }
        if ( in_array( $field["type"], [ "text", "textarea" ], true ) && ! empty( $s["mf_input_max_length"] ) ) {
            $field["max_length"] = (int) $s["mf_input_max_length"];
        }
        return $field;
    }

    /**
     * MetForm merge tags ([mf-name]) as ours ({name}).
     *
     * @param array<string,string> $ids MetForm input name => Trinity id
     * @param array<int,string>    $skipped
     */
    public static function detag( string $text, array $ids, array &$skipped ): string {
        return (string) preg_replace_callback( '/\[([A-Za-z0-9_-]+)\]/', static function ( $m ) use ( $ids, &$skipped ) {
            if ( isset( $ids[ $m[1] ] ) ) {
                return "{" . $ids[ $m[1] ] . "}";
            }
            /* translators: %s: a merge tag from the other plugin, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    /**
     * Entries, oldest first. MetForm dates its entry posts in local time.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    protected function import_entries( $source_id, int $form_id, array $fields ): int {
        $ids   = self::ids( self::widgets( (int) $source_id ) );
        $types = array_column( $fields, "type", "id" );
        $count = 0;
        $page  = 1;
        do {
            $posts = get_posts( [
                "post_type"        => self::ENTRY_TYPE,
                "post_status"      => "publish",
                "meta_key"         => "metform_entries__form_id", // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off import.
                "meta_value"       => (string) (int) $source_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-off import.
                "orderby"          => "ID",
                "order"            => "ASC",
                "posts_per_page"   => 200,
                "paged"            => $page,
                "suppress_filters" => true,
            ] );
            foreach ( $posts as $entry ) {
                $data   = get_post_meta( $entry->ID, "metform_entries__form_data", true );
                $values = [];
                foreach ( is_array( $data ) ? $data : [] as $name => $value ) {
                    $tid = $ids[ (string) $name ] ?? null;
                    if ( null === $tid ) {
                        continue;
                    }
                    $type = $types[ $tid ] ?? "text";
                    if ( in_array( $type, [ "checkbox", "multiselect" ], true ) && ! is_array( $value ) ) {
                        $value = array_values( array_filter( array_map( "trim", explode( ",", (string) $value ) ), "strlen" ) );
                    } elseif ( "consent" === $type ) {
                        $value = ! empty( $value ) && "off" !== $value ? "1" : "";
                    } elseif ( is_array( $value ) ) {
                        $value = implode( ", ", array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : "", $value ) );
                    }
                    $values[ $tid ] = $value;
                }
                $browser = get_post_meta( $entry->ID, "metform_form__entry_browser_data", true );
                $page_id = (int) get_post_meta( $entry->ID, "mf_page_id", true );
                if ( self::add_entry( $form_id, [
                    "source_entry_id" => $entry->ID,
                    "submitted_at" => self::to_utc( (string) $entry->post_date ),
                    "user_id"      => (int) $entry->post_author,
                    "ip"           => is_array( $browser ) ? (string) ( $browser["IP"] ?? $browser["ip"] ?? "" ) : "",
                    "user_agent"   => is_array( $browser ) ? (string) ( $browser["User_Agent"] ?? $browser["user_agent"] ?? "" ) : "",
                    "source_url"   => $page_id ? (string) get_permalink( $page_id ) : "",
                    "is_read"      => true,
                ], $values, $fields ) ) {
                    $count++;
                }
            }
            $page++;
        } while ( count( $posts ) === 200 );
        return $count;
    }
}
