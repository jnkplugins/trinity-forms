<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Merge_Tags {

    /**
     * Every tag an editor should offer, grouped for a picker.
     *
     * One list, so the picker cannot drift from what replace() actually resolves —
     * an editor offering a tag that renders literally is worse than not offering it.
     *
     * @param array<string,string> $fields Field id => label, for the form being edited.
     * @return array<int,array{label:string,tags:array<int,array{tag:string,label:string}>}>
     */
    public static function catalogue( array $fields = [] ): array {
        $field_tags = [];
        foreach ( $fields as $id => $label ) {
            $field_tags[] = [ "tag" => "{" . $id . "}", "label" => (string) $label ];
        }
        $field_tags[] = [ "tag" => "{all_fields}", "label" => __( "Every answer", "trinity-forms" ) ];

        $groups = [
            [
                "label" => __( "This form", "trinity-forms" ),
                "tags"  => $field_tags,
            ],
            [
                "label" => __( "The entry", "trinity-forms" ),
                "tags"  => [
                    [ "tag" => "{entry_id}",  "label" => __( "Entry number", "trinity-forms" ) ],
                    [ "tag" => "{entry_url}", "label" => __( "Link to the entry in wp-admin", "trinity-forms" ) ],
                    [ "tag" => "{form_title}", "label" => __( "Form name", "trinity-forms" ) ],
                    [ "tag" => "{date}",      "label" => __( "Date submitted", "trinity-forms" ) ],
                    [ "tag" => "{today:d/m/Y}", "label" => __( "Today, in any date format", "trinity-forms" ) ],
                    [ "tag" => "{ip}",        "label" => __( "IP address", "trinity-forms" ) ],
                    [ "tag" => "{embed_url}", "label" => __( "Page it was submitted from", "trinity-forms" ) ],
                ],
            ],
            [
                "label" => __( "The person", "trinity-forms" ),
                "tags"  => [
                    [ "tag" => "{user:display_name}", "label" => __( "Their display name, if logged in", "trinity-forms" ) ],
                    [ "tag" => "{user:user_email}",   "label" => __( "Their account email, if logged in", "trinity-forms" ) ],
                    [ "tag" => "{user:first_name}",   "label" => __( "Their first name, if logged in", "trinity-forms" ) ],
                ],
            ],
            [
                "label" => __( "The site", "trinity-forms" ),
                "tags"  => [
                    [ "tag" => "{site_name}",   "label" => __( "Site name", "trinity-forms" ) ],
                    [ "tag" => "{site_url}",    "label" => __( "Site address", "trinity-forms" ) ],
                    [ "tag" => "{admin_email}", "label" => __( "Admin email", "trinity-forms" ) ],
                ],
            ],
        ];
        // Payment tags (and other premium tags) are added by the build that can fill
        // them. In the free build they could only ever print nothing.
        return (array) apply_filters( "trinity_forms_merge_tag_catalogue", $groups, $fields );
    }

    public static function replace( string $template, int $entry_id ): string {
        $entry = self::load_entry( $entry_id );
        if ( ! $entry ) {
            return $template;
        }
        // Choice fields store the option's value; the visitor picked its label. An
        // email reading "premium_finance_software" instead of "Premium finance
        // software" is the plugin showing its own plumbing to someone's customer.
        $entry["fields"] = self::label_choices( $entry["fields"], (int) $entry["form_id"] );

        // MED-01: HTML-escape user-supplied field values before inserting into
        // the notification template; emails are sent as text/html.
        // Build the field tags first so the reserved core tags below always win —
        // a field whose id happens to be e.g. "site_url" must not shadow {site_url}.
        $field_tags = [];
        foreach ( $entry["fields"] as $field_id => $row ) {
            $field_tags[ "{" . $field_id . "}" ] = self::field_html( $row );
        }

        $core_tags = self::core_tags( $entry, $entry_id );

        // Tags that carry an argument ({user:first_name}, {today:d/m/Y}) can't live
        // in a flat strtr map, so they are resolved by pattern first.
        $template = self::replace_dynamic( $template, $entry, $entry_id );

        // Reserved core tags override any same-named field tag.
        $tags = array_merge( $field_tags, $core_tags );

        $tags = apply_filters( "trinity_forms_merge_tags", $tags, $entry_id );

        return strtr( $template, $tags );
    }

    /**
     * The fixed tags — the ones with no argument.
     *
     * @param array<string,mixed> $entry
     * @return array<string,string>
     */
    private static function core_tags( array $entry, int $entry_id ): array {
        $form_id  = (int) $entry["form_id"];
        $admin_url = admin_url( "edit.php?post_type=trinity_forms_form&page=trinity-forms-entries&entry=" . $entry_id );

        return [
            "{site_url}"       => home_url(),
            "{site_name}"      => esc_html( get_bloginfo( "name" ) ),
            "{admin_email}"    => esc_html( get_bloginfo( "admin_email" ) ),
            "{date}"           => esc_html( Dates::human( $entry["submitted_at"], false ) ),
            "{ip}"             => esc_html( $entry["ip"] ),
            "{entry_id}"       => (string) (int) $entry_id,
            "{all_fields}"     => self::all_fields( $entry["fields"] ),

            "{form_id}"        => (string) $form_id,
            "{form_title}"     => esc_html( (string) get_the_title( $form_id ) ),
            "{entry_url}"      => esc_url( $admin_url ),
            "{entry_admin_url}"=> esc_url( $admin_url ),
            "{admin_url}"      => esc_url( admin_url() ),
            "{embed_url}"      => esc_url( (string) $entry["source_url"] ),
            "{referer}"        => esc_url( (string) $entry["referer"] ),
            "{user_agent}"     => esc_html( (string) $entry["user_agent"] ),

            "{payment_status}" => esc_html( (string) $entry["payment"]["status"] ),
            "{payment_amount}" => esc_html( trim( $entry["payment"]["amount"] . " " . $entry["payment"]["currency"] ) ),
            "{transaction_id}" => esc_html( (string) $entry["payment"]["id"] ),
        ];
    }

    /**
     * Tags that take an argument after a colon.
     *
     *   {user:first_name}   a property or meta key of the submitting user
     *   {post:post_title}   a property or meta key of the page it was submitted from
     *   {today:d/m/Y}       the current date in any PHP date format
     *   {field:<id>:label}  a field's label rather than its value
     *
     * @param array<string,mixed> $entry
     */
    private static function replace_dynamic( string $template, array $entry, int $entry_id ): string {
        return (string) preg_replace_callback(
            '/\{(user|post|today|field):([^}]+)\}/',
            static function ( $m ) use ( $entry ) {
                $kind = $m[1];
                $arg  = $m[2];

                if ( "today" === $kind ) {
                    return esc_html( wp_date( $arg ) );
                }

                if ( "user" === $kind ) {
                    $user_id = (int) $entry["user_id"];
                    if ( ! $user_id ) {
                        return "";
                    }
                    $user = get_userdata( $user_id );
                    if ( ! $user ) {
                        return "";
                    }
                    // A whitelist, not "any property": {user:user_pass} must never
                    // resolve to anything, and an email template is exactly the
                    // place that mistake would be invisible until it shipped.
                    $allowed = [ "ID", "user_login", "user_email", "user_url", "display_name", "first_name", "last_name", "nickname", "description" ];
                    if ( in_array( $arg, $allowed, true ) ) {
                        return esc_html( (string) $user->$arg );
                    }
                    $meta = get_user_meta( $user_id, $arg, true );
                    return is_scalar( $meta ) ? esc_html( (string) $meta ) : "";
                }

                if ( "post" === $kind ) {
                    $post_id = url_to_postid( (string) $entry["source_url"] );
                    if ( ! $post_id ) {
                        return "";
                    }
                    $post = get_post( $post_id );
                    if ( ! $post ) {
                        return "";
                    }
                    $allowed = [ "ID", "post_title", "post_name", "post_date", "post_excerpt" ];
                    if ( in_array( $arg, $allowed, true ) ) {
                        return esc_html( (string) $post->$arg );
                    }
                    if ( "post_url" === $arg ) {
                        return esc_url( (string) get_permalink( $post_id ) );
                    }
                    $meta = get_post_meta( $post_id, $arg, true );
                    return is_scalar( $meta ) ? esc_html( (string) $meta ) : "";
                }

                // {field:<id>} / {field:<id>:label}
                $parts    = explode( ":", $arg );
                $field_id = $parts[0];
                $modifier = $parts[1] ?? "value";
                $row      = $entry["fields"][ $field_id ] ?? null;
                if ( ! $row ) {
                    return "";
                }
                if ( "label" === $modifier ) {
                    return esc_html( (string) $row["label"] );
                }
                return self::field_html( (array) $row + [ "type" => "", "label" => "" ] );
            },
            $template
        );
    }

    /**
     * Swap stored option values for the labels the visitor actually saw.
     *
     * Values that no longer exist on the form (the option was renamed or removed
     * since) pass through untouched, so a stale entry still shows what was stored.
     *
     * @param array<string,array{label:string,type:string,value:mixed}> $fields
     * @return array<string,array{label:string,type:string,value:mixed}>
     */
    private static function label_choices( array $fields, int $form_id ): array {
        foreach ( Form_Manager::choice_labels( $form_id ) as $field_id => $map ) {
            if ( ! isset( $fields[ $field_id ] ) ) {
                continue;
            }
            $current = $fields[ $field_id ]["value"];
            $fields[ $field_id ]["value"] = is_array( $current )
                ? array_map( static fn( $v ) => $map[ (string) $v ] ?? $v, $current )
                : ( $map[ (string) $current ] ?? $current );
        }
        return $fields;
    }

    private static function load_entry( int $entry_id ): ?array {
        global $wpdb;
        $entry = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, form_id, submitted_at, ip_address, user_agent, user_id, referer, source_url,
                    payment_status, payment_amount, payment_currency, payment_id
               FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d",
            $entry_id
        ), ARRAY_A );
        if ( ! $entry ) {
            return null;
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, field_label, field_type, value FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d",
            $entry_id
        ), ARRAY_A );
        $fields = [];
        foreach ( $rows as $r ) {
            $value = $r["value"];
            $decoded = json_decode( (string) $value, true );
            if ( is_array( $decoded ) ) {
                $value = $decoded;
            }
            $fields[ $r["field_id"] ] = [
                "label" => $r["field_label"],
                "type"  => $r["field_type"],
                "value" => $value,
            ];
        }
        return [
            "form_id"      => (int) $entry["form_id"],
            "submitted_at" => $entry["submitted_at"],
            "ip"           => $entry["ip_address"] ? inet_ntop( $entry["ip_address"] ) : "",
            "user_agent"   => (string) ( $entry["user_agent"] ?? "" ),
            "user_id"      => (int) ( $entry["user_id"] ?? 0 ),
            "referer"      => (string) ( $entry["referer"] ?? "" ),
            "source_url"   => (string) ( $entry["source_url"] ?? "" ),
            "payment"      => [
                "status"   => (string) ( $entry["payment_status"] ?? "none" ),
                "amount"   => (string) ( $entry["payment_amount"] ?? "" ),
                "currency" => (string) ( $entry["payment_currency"] ?? "" ),
                "id"       => (string) ( $entry["payment_id"] ?? "" ),
            ],
            "fields"       => $fields,
        ];
    }

    private static function all_fields( array $fields ): string {
        $lines = [];
        foreach ( $fields as $field_id => $row ) {
            // Quiz score, created-post marker and friends are plumbing, not answers:
            // they printed as a label-less ": 55" line.
            if ( Entries::is_internal_field( (string) $field_id ) || "" === trim( (string) $row["label"] ) && "internal" === $row["type"] ) {
                continue;
            }
            $lines[] = esc_html( $row["label"] ) . ": " . self::field_html( $row );
        }
        return implode( "<br>\n", $lines );
    }

    /**
     * An answer as email/PDF-safe HTML. Choices are already swapped for labels by
     * label_choices(); this adds the per-type reading (consent Yes/No, a file as a
     * link without its server path, Rich Text kept formatted but kses-filtered).
     *
     * @param array{label:string,type:string,value:mixed} $row
     */
    private static function field_html( array $row ): string {
        $type = (string) $row["type"];
        if ( in_array( $type, [ "consent", "file", "rich_text", "repeater" ], true ) ) {
            return Entries::display_value( $type, $row["value"], [], true );
        }
        $value = $row["value"];
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) && $decoded && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) && is_array( reset( $decoded ) ) ) {
                return Entries::display_value( "repeater", $value, [], true );
            }
        }
        if ( is_array( $value ) && $value && is_array( reset( $value ) ) ) {
            return Entries::display_value( "repeater", $value, [], true );
        }
        $val = is_array( $value )
            ? implode( ", ", array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : "", $value ) )
            : (string) $value;
        // A multi-line answer keeps its line breaks in the email.
        return nl2br( esc_html( str_replace( [ "\r\n", "\r" ], "\n", $val ) ), false );
    }
}
