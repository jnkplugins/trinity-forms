<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counting another plugin's posts; works while Contact Form 7 is deactivated.

/**
 * Contact Form 7.
 *
 * A CF7 form is a `wpcf7_contact_form` post: the form template (HTML with
 * [type name options "values"] tags) in `_form`, the Mail tabs in `_mail` /
 * `_mail_2`, the messages in `_messages`. All read from post meta, so CF7 may be
 * deactivated. CF7 stores no submissions, so there are no entries to bring.
 */
class Contact_Form_7 extends Base_Importer {

    public const POST_TYPE = "wpcf7_contact_form";

    /** CF7 tag type => Trinity field type. */
    private const FIELD_MAP = [
        "text"       => "text",
        "email"      => "email",
        "url"        => "website",
        "tel"        => "phone",
        "number"     => "number",
        "range"      => "range",
        "date"       => "date",
        "textarea"   => "textarea",
        "select"     => "select",
        "checkbox"   => "checkbox",
        "radio"      => "radio",
        "acceptance" => "consent",
        "file"       => "file",
        "hidden"     => "hidden",
    ];

    /** CF7 special mail tags => ours. */
    private const MAIL_TAGS = [
        "_site_title"       => "{site_name}",
        "_site_description" => "{site_name}",
        "_site_url"         => "{site_url}",
        "_site_admin_email" => "{admin_email}",
        "_remote_ip"        => "{ip}",
        "_user_agent"       => "{user_agent}",
        "_url"              => "{embed_url}",
        "_post_url"         => "{embed_url}",
        "_post_title"       => "{post:post_title}",
        "_date"             => "{date}",
        "_time"             => "{date}",
        "_serial_number"    => "{entry_id}",
    ];

    public function slug(): string {
        return "cf7";
    }

    public function label(): string {
        return "Contact Form 7";
    }

    public function is_available(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' LIMIT 1", self::POST_TYPE ) );
    }

    public function forms(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY ID ASC", self::POST_TYPE ), ARRAY_A );
        $out  = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row["ID"] ] = [ "title" => (string) $row["post_title"], "entries" => null ];
        }
        return $out;
    }

    public function convert( $source_id ): ?array {
        $post = get_post( (int) $source_id );
        if ( ! $post || self::POST_TYPE !== $post->post_type ) {
            return null;
        }
        $template = (string) get_post_meta( $post->ID, "_form", true );
        $parsed   = self::convert_template( $template );

        $settings = [];
        if ( null !== $parsed["submit"] ) {
            $settings["submit_button_text"] = $parsed["submit"];
        }
        $messages = get_post_meta( $post->ID, "_messages", true );
        if ( is_array( $messages ) && "" !== trim( (string) ( $messages["mail_sent_ok"] ?? "" ) ) ) {
            $settings["confirmation_message"] = (string) $messages["mail_sent_ok"];
        }

        $skipped       = $parsed["skipped"];
        $notifications = [];
        foreach ( [ "_mail" => __( "Mail", "trinity-forms" ), "_mail_2" => __( "Mail (2)", "trinity-forms" ) ] as $key => $name ) {
            $mail = get_post_meta( $post->ID, $key, true );
            if ( ! is_array( $mail ) || ( "_mail_2" === $key && empty( $mail["active"] ) ) ) {
                continue;
            }
            $n = self::convert_mail( $mail, $name, $parsed["ids"], $skipped );
            if ( $n ) {
                $notifications[] = $n;
            }
        }

        foreach ( preg_split( '/\R/', (string) get_post_meta( $post->ID, "_additional_settings", true ) ) as $line ) {
            $line = trim( (string) $line );
            if ( "" !== $line && ! str_starts_with( $line, "#" ) ) {
                /* translators: %s: a Contact Form 7 "Additional settings" line, e.g. "demo_mode: on". */
                $skipped[] = sprintf( __( "Additional setting: %s", "trinity-forms" ), $line );
            }
        }

        return [
            "title"         => (string) $post->post_title,
            "fields"        => $parsed["fields"],
            "settings"      => $settings,
            "notifications" => $notifications,
            "skipped"       => array_values( array_unique( $skipped ) ),
        ];
    }

    /**
     * The form template's tags as Trinity fields.
     *
     * @return array{fields:array<int,array<string,mixed>>,skipped:array<int,string>,submit:?string,ids:array<string,string>}
     */
    public static function convert_template( string $template ): array {
        $fields  = [];
        $skipped = [];
        $submit  = null;
        $ids     = [];
        $prev    = 0;

        preg_match_all( '/\[([a-zA-Z_][\w-]*)(\*?)((?:\s+(?:"[^"]*"|\'[^\']*\'|[^\]\s"\']+))*)\s*\](?:(.*?)\[\/\1\])?/s', $template, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
        foreach ( $m as $tag ) {
            $type     = strtolower( $tag[1][0] );
            $required = "*" === $tag[2][0];
            $offset   = (int) $tag[0][1];
            $inner    = isset( $tag[4] ) ? (string) $tag[4][0] : "";
            $label    = self::label_for( $template, $offset, $prev );
            $prev     = $offset + strlen( $tag[0][0] );

            [ $name, $opts, $values ] = self::parse_args( (string) $tag[3][0] );

            if ( "submit" === $type ) {
                $submit = $values[0] ?? $submit;
                continue;
            }
            if ( ! isset( self::FIELD_MAP[ $type ] ) ) {
                $skipped[] = sprintf(
                    /* translators: 1: field name, 2: Contact Form 7 field type, e.g. "quiz". */
                    __( "%1\$s (%2\$s)", "trinity-forms" ),
                    "" !== $name ? $name : __( "Untitled field", "trinity-forms" ),
                    $type
                );
                continue;
            }

            $id    = self::unique_id( $name, "field_" . ( count( $fields ) + 1 ), array_column( $fields, "id" ) );
            $field = [
                "id"       => $id,
                "type"     => self::FIELD_MAP[ $type ],
                "label"    => "" !== $label ? $label : self::humanize( $name ),
                "required" => $required,
            ];
            if ( "" !== $name ) {
                $ids[ $name ] = $id;
            }

            $placeholder_flag = isset( $opts["placeholder"] ) || isset( $opts["watermark"] );
            $choices          = $values;

            switch ( $type ) {
                case "acceptance":
                    $text                  = trim( wp_strip_all_tags( $inner ) );
                    $field["consent_text"] = "" !== $text ? $text : $field["label"];
                    if ( "" !== $text && "" === $label ) {
                        $field["label"] = $text;
                    }
                    $field["required"] = ! isset( $opts["optional"] );
                    $choices           = [];
                    break;
                case "select":
                    if ( isset( $opts["multiple"] ) ) {
                        $field["type"] = "multiselect";
                    }
                    if ( isset( $opts["first_as_label"] ) && $choices ) {
                        $field["placeholder"] = self::pipe_label( (string) array_shift( $choices ) );
                    } elseif ( isset( $opts["include_blank"] ) ) {
                        $field["placeholder"] = "—";
                    }
                    break;
                case "checkbox":
                    if ( isset( $opts["exclusive"] ) ) {
                        $field["type"] = "radio";
                    }
                    break;
                case "file":
                    if ( isset( $opts["filetypes"] ) ) {
                        $field["accept"] = implode( ",", array_map( static fn( $e ) => "." . ltrim( $e, "." ), explode( "|", $opts["filetypes"] ) ) );
                    }
                    if ( isset( $opts["limit"] ) ) {
                        $field["max_size"] = self::megabytes( $opts["limit"] );
                    }
                    $choices = [];
                    break;
                case "number":
                case "range":
                case "date":
                    foreach ( [ "min", "max", "step" ] as $k ) {
                        if ( isset( $opts[ $k ] ) ) {
                            $field[ $k ] = $opts[ $k ];
                        }
                    }
                    break;
            }

            if ( in_array( $field["type"], [ "select", "multiselect", "checkbox", "radio" ], true ) ) {
                $field["options"] = array_map( static fn( $v ) => [ "label" => self::pipe_label( $v ), "value" => self::pipe_value( $v ) ], $choices );
                if ( isset( $opts["free_text"] ) ) {
                    /* translators: %s: field name. */
                    $skipped[] = sprintf( __( "The free-text box on the last choice of %s", "trinity-forms" ), $name );
                }
                if ( isset( $opts["default"] ) && preg_match( '/^\d+(_\d+)*$/', $opts["default"] ) ) {
                    $first = (int) strtok( $opts["default"], "_" ) - 1;
                    if ( isset( $field["options"][ $first ] ) ) {
                        $field["default_value"] = $field["options"][ $first ]["value"];
                    }
                }
            } elseif ( $choices && ! in_array( $type, [ "acceptance", "file" ], true ) ) {
                if ( $placeholder_flag ) {
                    $field["placeholder"] = (string) $choices[0];
                } elseif ( "hidden" === $type ) {
                    $field["default"] = (string) $choices[0];
                } else {
                    $field["default_value"] = (string) $choices[0];
                }
            }

            if ( isset( $opts["minlength"] ) ) {
                $field["min_length"] = (int) $opts["minlength"];
            }
            if ( isset( $opts["maxlength"] ) ) {
                $field["max_length"] = (int) $opts["maxlength"];
            }
            if ( isset( $opts["autocomplete"] ) ) {
                $field["autocomplete"] = $opts["autocomplete"];
            }
            if ( isset( $opts["class"] ) ) {
                $field["css_class"] = $opts["class"];
            }
            if ( isset( $opts["default"] ) && ! preg_match( '/^\d+(_\d+)*$/', $opts["default"] ) ) {
                /* translators: 1: field name, 2: Contact Form 7 option, e.g. "default:user_email". */
                $skipped[] = sprintf( __( "%1\$s: filled in from \"%2\$s\"", "trinity-forms" ), $name, "default:" . $opts["default"] );
            }

            $fields[] = $field;
        }

        return [ "fields" => $fields, "skipped" => $skipped, "submit" => $submit, "ids" => $ids ];
    }

    /**
     * Tag arguments: the first bare word is the field name, bare words are options
     * (name:value or flags), quoted strings are values.
     *
     * @return array{0:string,1:array<string,string>,2:array<int,string>}
     */
    private static function parse_args( string $args ): array {
        preg_match_all( '/"([^"]*)"|\'([^\']*)\'|(\S+)/', $args, $m, PREG_SET_ORDER );
        $name   = "";
        $opts   = [];
        $values = [];
        foreach ( $m as $t ) {
            if ( isset( $t[3] ) && "" !== $t[3] ) {
                $word = $t[3];
                if ( "" === $name && false === strpos( $word, ":" ) && ! in_array( $word, [ "placeholder", "watermark" ], true ) && ! $opts && ! $values ) {
                    $name = $word;
                    continue;
                }
                $pos = strpos( $word, ":" );
                if ( false === $pos ) {
                    $opts[ $word ] = "";
                } else {
                    $opts[ substr( $word, 0, $pos ) ] = substr( $word, $pos + 1 );
                }
            } else {
                $values[] = "" !== ( $t[1] ?? "" ) ? $t[1] : (string) ( $t[2] ?? "" );
            }
        }
        return [ $name, $opts, $values ];
    }

    /**
     * The text a person reads as this field's label: the rest of the <label> the tag
     * sits in, else the last line of text between the previous tag and this one.
     */
    private static function label_for( string $template, int $offset, int $prev ): string {
        $before = substr( $template, 0, $offset );
        $open   = strripos( $before, "<label" );
        $close  = strripos( $before, "</label>" );
        if ( false !== $open && ( false === $close || $close < $open ) ) {
            $end    = stripos( $template, "</label>", $offset );
            $inside = substr( $template, $open, ( false === $end ? strlen( $template ) : $end ) - $open );
            $inside = preg_replace( '/\[[^\]]*\](?:.*?\[\/[^\]]*\])?/s', " ", (string) $inside );
            $text   = trim( preg_replace( '/\s+/u', " ", wp_strip_all_tags( "<" === $inside[0] ? (string) preg_replace( '/^<label[^>]*>/i', "", (string) $inside ) : (string) $inside ) ) );
            if ( "" !== $text ) {
                return $text;
            }
        }
        $between = substr( $template, $prev, $offset - $prev );
        $lines   = preg_split( '/\R|<br\s*\/?>|<\/p>|<\/label>|<\/div>/i', (string) $between );
        for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
            $text = trim( preg_replace( '/\s+/u', " ", wp_strip_all_tags( (string) $lines[ $i ] ) ) );
            if ( "" !== $text ) {
                return $text;
            }
        }
        return "";
    }

    /**
     * A Mail tab as a Trinity notification.
     *
     * @param array<string,mixed>  $mail
     * @param array<string,string> $ids     CF7 field name => Trinity field id
     * @param array<int,string>    $skipped collects what could not be carried over
     * @return array<string,mixed>|null
     */
    private static function convert_mail( array $mail, string $name, array $ids, array &$skipped ): ?array {
        $to = self::detag( (string) ( $mail["recipient"] ?? "" ), $ids, $skipped );
        if ( "" === trim( $to ) ) {
            return null;
        }
        $n = [
            "id"       => sanitize_key( "cf7_" . $name ),
            "name"     => $name,
            "to"       => $to,
            "subject"  => self::detag( (string) ( $mail["subject"] ?? "" ), $ids, $skipped ),
            "body"     => self::detag( (string) ( $mail["body"] ?? "" ), $ids, $skipped ),
            "enabled"  => true,
            "event"    => "submission",
        ];

        $sender = trim( (string) ( $mail["sender"] ?? "" ) );
        if ( preg_match( '/^(.*?)\s*<([^>]+)>$/', $sender, $sm ) ) {
            $n["from_name"] = self::detag( trim( $sm[1] ), $ids, $skipped );
            $email          = trim( $sm[2] );
        } else {
            $email = $sender;
        }
        // Sending "as" the visitor ([your-email]) fails SPF: that address becomes Reply-To instead.
        if ( "" !== $email && false === strpos( $email, "[" ) && is_email( $email ) ) {
            $n["from_email"] = $email;
        } elseif ( "" !== $email ) {
            $n["reply_to"] = self::detag( $email, $ids, $skipped );
        }

        foreach ( preg_split( '/\R/', (string) ( $mail["additional_headers"] ?? "" ) ) as $line ) {
            if ( ! preg_match( '/^\s*([\w-]+)\s*:\s*(.+)$/', (string) $line, $hm ) ) {
                continue;
            }
            $header = strtolower( $hm[1] );
            $value  = self::detag( trim( $hm[2] ), $ids, $skipped );
            if ( "reply-to" === $header ) {
                $n["reply_to"] = $value;
            } elseif ( "cc" === $header || "bcc" === $header ) {
                $n[ $header ] = $value;
            } else {
                /* translators: %s: an email header line, e.g. "X-Priority: 1". */
                $skipped[] = sprintf( __( "Mail header: %s", "trinity-forms" ), trim( (string) $line ) );
            }
        }
        if ( "" !== trim( (string) ( $mail["attachments"] ?? "" ) ) ) {
            $n["attach_files"] = true;
        }
        if ( ! empty( $mail["exclude_blank"] ) ) {
            $skipped[] = __( "Mail: leave out lines whose tags are empty", "trinity-forms" );
        }
        return $n;
    }

    /**
     * CF7 mail tags ([your-name], [_site_title]) as Trinity merge tags.
     *
     * @param array<string,string> $ids
     * @param array<int,string>    $skipped
     */
    private static function detag( string $text, array $ids, array &$skipped ): string {
        return (string) preg_replace_callback( '/\[(_raw_|_format_)?([\w-]+)(?:\s+"[^"]*")?\]/', static function ( $m ) use ( $ids, &$skipped ) {
            $tag = $m[2];
            if ( "" !== $m[1] ) {
                $tag = ltrim( $tag, "_" );
            }
            if ( isset( $ids[ $tag ] ) ) {
                return "{" . $ids[ $tag ] . "}";
            }
            if ( isset( self::MAIL_TAGS[ "_" . ltrim( $tag, "_" ) ] ) && str_starts_with( $m[0], "[_" ) ) {
                return self::MAIL_TAGS[ "_" . ltrim( $tag, "_" ) ];
            }
            /* translators: %s: a Contact Form 7 mail tag, e.g. "[_post_author]". */
            $skipped[] = sprintf( __( "Mail tag %s", "trinity-forms" ), $m[0] );
            return $m[0];
        }, $text );
    }

    private static function pipe_label( string $value ): string {
        $pos = strpos( $value, "|" );
        return false === $pos ? $value : substr( $value, 0, $pos );
    }

    private static function pipe_value( string $value ): string {
        $pos = strpos( $value, "|" );
        return false === $pos ? $value : substr( $value, $pos + 1 );
    }

    /** "your-message" => "Your message". */
    private static function humanize( string $name ): string {
        $name = trim( str_replace( [ "-", "_" ], " ", $name ) );
        return "" === $name ? __( "Untitled field", "trinity-forms" ) : ucfirst( $name );
    }

    /** CF7 "limit:2mb" / "1024kb" / "500000" => megabytes. */
    private static function megabytes( string $limit ): int {
        if ( preg_match( '/^(\d+(?:\.\d+)?)\s*(kb|mb)?$/i', trim( $limit ), $m ) ) {
            $n = (float) $m[1];
            $u = strtolower( $m[2] ?? "" );
            return max( 1, (int) ceil( "mb" === $u ? $n : ( "kb" === $u ? $n / 1024 : $n / 1048576 ) ) );
        }
        return 2;
    }
}
