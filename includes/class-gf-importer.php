<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reading another plugin's custom tables; there is no API to use when Gravity Forms is deactivated, which is exactly when people import.

/**
 * Import forms and entries from Gravity Forms.
 *
 * Free on purpose: this is the thing that makes switching possible at all, and
 * charging to let someone bring their own data in would be a strange place to put a
 * paywall.
 *
 * Reads the `gf_form` / `gf_form_meta` / `gf_entry` / `gf_entry_meta` tables
 * directly rather than calling GFAPI. That is deliberate — people usually import
 * *after* deactivating Gravity Forms, when the API is gone but the tables are still
 * there. It also means an import never triggers GF's own hooks.
 *
 * Nothing is modified on the Gravity Forms side. The import is additive and can be
 * run twice; the second run creates second copies rather than corrupting the first.
 */
class GF_Importer {

    /** GF field type => Trinity field type. */
    private const FIELD_MAP = [
        "text"           => "text",
        "textarea"       => "textarea",
        "select"         => "select",
        "multiselect"    => "multiselect",
        "number"         => "number",
        "checkbox"       => "checkbox",
        "radio"          => "radio",
        "hidden"         => "hidden",
        "html"           => "html",
        "section"        => "section",
        "page"           => "page_break",
        "name"           => "name",
        "date"           => "date",
        "time"           => "time",
        "phone"          => "phone",
        "address"        => "address",
        "website"        => "website",
        "email"          => "email",
        "fileupload"     => "file",
        "list"           => "list",
        "consent"        => "consent",
        "signature"      => "signature",
        "password"       => "password",
        "product"        => "product",
        "quantity"       => "quantity",
        "option"         => "option",
        "shipping"       => "shipping",
        "total"          => "total",
        "post_title"     => "post_field",
        "post_content"   => "post_field",
        "post_excerpt"   => "post_field",
        "post_tags"      => "post_field",
        "post_category"  => "post_field",
        "post_custom_field" => "post_field",
        "chainedselect"  => "chained_select",
        // Deliberately unmapped: captcha (ours is a form setting, not a field),
        // and page/section variants handled above.
    ];

    /** GF operator => Trinity operator. */
    private const OPERATOR_MAP = [
        "is"          => "equals",
        "isnot"       => "not_equals",
        "contains"    => "contains",
        "starts_with" => "starts_with",
        "ends_with"   => "ends_with",
        ">"           => "greater_than",
        "<"           => "less_than",
    ];

    /**
     * Is there anything to import?
     *
     * Asked by reading the table rather than by looking it up in the catalogue.
     * Both SHOW TABLES and information_schema are answered from the transaction's
     * snapshot on MySQL 8, so a table created after the transaction began is
     * invisible to them while ordinary reads see it perfectly well — which made this
     * answer "no" under the test harness. Reading is also the question that actually
     * matters: a catalogue entry we have no permission to select from is no use.
     */
    public static function is_available(): bool {
        global $wpdb;

        $table = $wpdb->prefix . "gf_form";

        $previous_show    = $wpdb->show_errors( false );
        $previous_suppress = $wpdb->suppress_errors( true );
        $previous_error   = $wpdb->last_error;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );
        $failed = "" !== (string) $wpdb->last_error;

        $wpdb->suppress_errors( $previous_suppress );
        $wpdb->show_errors( $previous_show );
        $wpdb->last_error = $previous_error;

        return ! $failed;
    }

    /**
     * The forms available to import, as id => [title, entry_count].
     *
     * @return array<int,array{title:string,entries:int}>
     */
    public static function available_forms(): array {
        global $wpdb;

        if ( ! self::is_available() ) {
            return [];
        }

        $form_table  = $wpdb->prefix . "gf_form";
        $entry_table = $wpdb->prefix . "gf_entry";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT id, title FROM {$form_table} ORDER BY id ASC", ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $id = (int) $row["id"];
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$entry_table} WHERE form_id = %d AND status = 'active'",
                $id
            ) );
            $out[ $id ] = [ "title" => (string) $row["title"], "entries" => $count ];
        }
        return $out;
    }

    /**
     * Import one Gravity Forms form.
     *
     * @param bool $dry_run Report what would happen and change nothing.
     * @return array{form_id:int,title:string,fields:int,entries:int,skipped:array<int,string>,notifications:int}
     */
    public static function import_form( int $gf_form_id, bool $with_entries = true, bool $dry_run = false ): array {
        global $wpdb;

        $report = [
            "form_id"       => 0,
            "title"         => "",
            "fields"        => 0,
            "entries"       => 0,
            "skipped"       => [],
            "notifications" => 0,
        ];

        $form_table = $wpdb->prefix . "gf_form";
        $meta_table = $wpdb->prefix . "gf_form_meta";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $title = (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$form_table} WHERE id = %d", $gf_form_id ) );
        if ( "" === $title ) {
            return $report;
        }
        $report["title"] = $title;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT display_meta FROM {$meta_table} WHERE form_id = %d", $gf_form_id ) );
        $gf  = json_decode( $raw, true );
        if ( ! is_array( $gf ) ) {
            return $report;
        }

        [ $fields, $skipped ] = self::convert_fields( (array) ( $gf["fields"] ?? [] ) );
        $report["fields"]     = count( $fields );
        $report["skipped"]    = $skipped;

        $notifications         = self::convert_notifications( (array) ( $gf["notifications"] ?? [] ), $fields );
        $report["notifications"] = count( $notifications );

        if ( $dry_run ) {
            $report["entries"] = $with_entries ? self::count_entries( $gf_form_id ) : 0;
            return $report;
        }

        $schema = Form_Manager::default_schema();
        $schema["settings"] = array_merge( $schema["settings"], [
            "submit_button_text"   => (string) ( $gf["button"]["text"] ?? __( "Submit", "trinity-forms" ) ),
            "confirmation_message" => self::first_confirmation( $gf ),
        ] );
        $schema["steps"] = [ [ "id" => "step_1", "title" => "", "fields" => $fields ] ];

        $form_id = Form_Manager::create( $title, $schema );
        if ( ! $form_id ) {
            return $report;
        }
        $report["form_id"] = $form_id;

        if ( $notifications ) {
            $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
            $meta = is_array( $meta ) ? $meta : [];
            $meta["notifications"] = $notifications;
            update_post_meta( $form_id, Form::SETTINGS_META, $meta );
        }

        if ( $with_entries ) {
            $report["entries"] = self::import_entries( $gf_form_id, $form_id, $fields );
        }

        do_action( "trinity_forms_gf_form_imported", $form_id, $gf_form_id, $report );

        return $report;
    }

    /**
     * @param array<int,array<string,mixed>> $gf_fields
     * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
     */
    private static function convert_fields( array $gf_fields ): array {
        $fields  = [];
        $skipped = [];

        foreach ( $gf_fields as $gf_field ) {
            $gf_type = (string) ( $gf_field["type"] ?? "" );
            $label   = (string) ( $gf_field["label"] ?? "" );

            if ( ! isset( self::FIELD_MAP[ $gf_type ] ) ) {
                // Named rather than silently dropped: an import that quietly loses a
                // field is worse than one that says which.
                $skipped[] = sprintf(
                    /* translators: 1: field label, 2: Gravity Forms field type. */
                    __( "%1\$s (%2\$s)", "trinity-forms" ),
                    $label ?: __( "Untitled field", "trinity-forms" ),
                    $gf_type
                );
                continue;
            }

            $field = [
                // GF ids are numeric; ours are merge tags, so derive something
                // readable and fall back to the number.
                "id"       => self::field_id( $label, (int) ( $gf_field["id"] ?? 0 ), $fields ),
                "type"     => self::FIELD_MAP[ $gf_type ],
                "label"    => $label,
                "required" => ! empty( $gf_field["isRequired"] ),
            ];

            if ( ! empty( $gf_field["placeholder"] ) ) {
                $field["placeholder"] = (string) $gf_field["placeholder"];
            }
            if ( ! empty( $gf_field["description"] ) ) {
                $field["help"] = wp_strip_all_tags( (string) $gf_field["description"] );
            }
            if ( ! empty( $gf_field["adminLabel"] ) ) {
                $field["admin_label"] = (string) $gf_field["adminLabel"];
            }
            if ( ! empty( $gf_field["allowsPrepopulate"] ) ) {
                $field["allow_prefill"] = true;
                $field["prefill_param"] = sanitize_key( (string) ( $gf_field["inputName"] ?? $field["id"] ) );
            }
            if ( ! empty( $gf_field["defaultValue"] ) ) {
                $field["default_value"] = (string) $gf_field["defaultValue"];
            }
            if ( ! empty( $gf_field["cssClass"] ) ) {
                $field["css_class"] = (string) $gf_field["cssClass"];
            }
            if ( ! empty( $gf_field["choices"] ) && is_array( $gf_field["choices"] ) ) {
                $field["options"] = array_values( array_map( static fn( $c ) => [
                    "label" => (string) ( $c["text"] ?? "" ),
                    "value" => (string) ( $c["value"] ?? $c["text"] ?? "" ),
                ], $gf_field["choices"] ) );
            }
            if ( "post_field" === $field["type"] ) {
                $field["post_target"] = "post_custom_field" === $gf_type ? "post_custom" : $gf_type;
            }

            $rules = self::convert_conditional( $gf_field, $gf_fields );
            if ( $rules ) {
                $field["conditional"] = $rules;
            }

            $fields[] = $field;
        }

        return [ $fields, $skipped ];
    }

    /**
     * A readable, unique field id derived from the label.
     *
     * @param array<int,array<string,mixed>> $existing
     */
    private static function field_id( string $label, int $gf_id, array $existing ): string {
        $base = sanitize_key( str_replace( " ", "_", strtolower( $label ) ) );
        $base = preg_replace( '/[^a-z0-9_]/', "", (string) $base );
        $base = trim( (string) $base, "_" );
        if ( "" === $base ) {
            $base = "field_" . $gf_id;
        }
        $base = substr( $base, 0, 32 );

        $used      = array_column( $existing, "id" );
        $candidate = $base;
        $n         = 2;
        while ( in_array( $candidate, $used, true ) ) {
            $candidate = $base . "_" . $n;
            $n++;
        }
        return $candidate;
    }

    /**
     * GF conditional logic → ours.
     *
     * GF references fields by numeric id, so the rules have to be re-pointed at the
     * ids we just generated — which is why this needs the whole original field list.
     *
     * @param array<string,mixed>            $gf_field
     * @param array<int,array<string,mixed>> $gf_fields
     * @return array<string,mixed>|null
     */
    private static function convert_conditional( array $gf_field, array $gf_fields ): ?array {
        if ( empty( $gf_field["conditionalLogic"]["rules"] ) ) {
            return null;
        }
        $logic = $gf_field["conditionalLogic"];

        // Build the same id mapping convert_fields() will produce, so a rule points
        // at the field it pointed at before.
        $by_gf_id = [];
        $seen     = [];
        foreach ( $gf_fields as $f ) {
            if ( ! isset( self::FIELD_MAP[ (string) ( $f["type"] ?? "" ) ] ) ) {
                continue;
            }
            $id = self::field_id( (string) ( $f["label"] ?? "" ), (int) ( $f["id"] ?? 0 ), $seen );
            $by_gf_id[ (string) ( $f["id"] ?? "" ) ] = $id;
            $seen[] = [ "id" => $id ];
        }

        $conditions = [];
        foreach ( (array) $logic["rules"] as $rule ) {
            // GF sub-field ids look like "3.6"; the parent is what we imported.
            $target = (string) ( $rule["fieldId"] ?? "" );
            $parent = strtok( $target, "." );
            if ( ! isset( $by_gf_id[ $parent ] ) ) {
                continue;
            }
            $operator = (string) ( $rule["operator"] ?? "is" );
            $conditions[] = [
                "field"    => $by_gf_id[ $parent ],
                "operator" => self::OPERATOR_MAP[ $operator ] ?? "equals",
                "value"    => (string) ( $rule["value"] ?? "" ),
            ];
        }

        if ( ! $conditions ) {
            return null;
        }

        return [
            "action"     => "hide" === ( $logic["actionType"] ?? "show" ) ? "hide" : "show",
            "match"      => "any" === ( $logic["logicType"] ?? "all" ) ? "any" : "all",
            "conditions" => $conditions,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $gf_notifications
     * @param array<int,array<string,mixed>> $fields
     * @return array<int,array<string,mixed>>
     */
    private static function convert_notifications( array $gf_notifications, array $fields ): array {
        $out = [];
        foreach ( $gf_notifications as $n ) {
            if ( ! is_array( $n ) || empty( $n["to"] ) ) {
                continue;
            }
            $out[] = [
                "id"         => sanitize_key( (string) ( $n["id"] ?? uniqid( "n_" ) ) ),
                "name"       => (string) ( $n["name"] ?? "" ),
                "to"         => self::detag( (string) $n["to"] ),
                "cc"         => self::detag( (string) ( $n["cc"] ?? "" ) ),
                "bcc"        => self::detag( (string) ( $n["bcc"] ?? "" ) ),
                "from_email" => self::detag( (string) ( $n["from"] ?? "" ) ),
                "from_name"  => (string) ( $n["fromName"] ?? "" ),
                "reply_to"   => self::detag( (string) ( $n["replyTo"] ?? "" ) ),
                "subject"    => self::detag( (string) ( $n["subject"] ?? "" ) ),
                "body"       => self::detag( (string) ( $n["message"] ?? "" ) ),
                "enabled"    => ! isset( $n["isActive"] ) || ! empty( $n["isActive"] ),
                "event"      => "submission",
            ];
        }
        return $out;
    }

    /**
     * Translate the GF merge tags we have equivalents for.
     *
     * Field tags ({:3}) are left alone: they reference numeric ids that mean nothing
     * here, and a wrong substitution is worse than a visible one somebody can fix.
     */
    private static function detag( string $text ): string {
        return strtr( $text, [
            "{admin_email}" => "{admin_email}",
            "{all_fields}"  => "{all_fields}",
            "{form_title}"  => "{form_title}",
            "{entry_id}"    => "{entry_id}",
            "{date_mdy}"    => "{date}",
            "{date_dmy}"    => "{date}",
            "{ip}"          => "{ip}",
            "{user_agent}"  => "{user_agent}",
            "{referer}"     => "{referer}",
        ] );
    }

    /** @param array<string,mixed> $gf */
    private static function first_confirmation( array $gf ): string {
        foreach ( (array) ( $gf["confirmations"] ?? [] ) as $c ) {
            if ( ! empty( $c["isDefault"] ) && ! empty( $c["message"] ) ) {
                return wp_kses_post( (string) $c["message"] );
            }
        }
        return __( "Thanks — we have your submission.", "trinity-forms" );
    }

    /**
     * Entries that came from Gravity Forms with its UTC date_created kept as is,
     * so the one-time conversion to UTC (Utc_Migration) leaves them alone. Rows
     * are recognised by an exact match with a GF entry (date, source URL, user
     * agent); only possible while the GF tables are still there.
     *
     * @return int[] Entry ids.
     */
    public static function imported_entry_ids(): array {
        global $wpdb;
        $gf_entry = $wpdb->prefix . "gf_entry";
        $entries  = $wpdb->prefix . "trinity_forms_entries";
        if ( ! self::is_available() ) {
            return [];
        }
        // Joined on the date only: the GF tables may use another collation, and
        // comparing text across them fails ("Illegal mix of collations").
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT e.id, e.source_url, e.user_agent,
                    g.source_url AS gf_url, g.user_agent AS gf_agent
               FROM {$entries} e
               JOIN {$gf_entry} g ON g.date_created = e.submitted_at",
            ARRAY_A
        );
        $ids = [];
        foreach ( (array) $rows as $row ) {
            if ( substr( (string) $row["gf_url"], 0, 500 ) === (string) $row["source_url"]
                && substr( (string) $row["gf_agent"], 0, 500 ) === (string) $row["user_agent"] ) {
                $ids[ (int) $row["id"] ] = true;
            }
        }
        return array_keys( $ids );
    }

    private static function count_entries( int $gf_form_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . "gf_entry";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND status = 'active'",
            $gf_form_id
        ) );
    }

    /**
     * Copy the entries across, in batches.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    private static function import_entries( int $gf_form_id, int $form_id, array $fields ): int {
        global $wpdb;

        $gf_entry      = $wpdb->prefix . "gf_entry";
        $gf_entry_meta = $wpdb->prefix . "gf_entry_meta";
        $entries       = $wpdb->prefix . "trinity_forms_entries";
        $entry_meta    = $wpdb->prefix . "trinity_forms_entry_meta";

        // GF numeric id => our field, so a stored value lands in the right column.
        $gf_meta = (array) json_decode(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT display_meta FROM {$wpdb->prefix}gf_form_meta WHERE form_id = %d",
                $gf_form_id
            ) ),
            true
        );

        $map  = [];
        $seen = [];
        foreach ( (array) ( $gf_meta["fields"] ?? [] ) as $f ) {
            if ( ! isset( self::FIELD_MAP[ (string) ( $f["type"] ?? "" ) ] ) ) {
                continue;
            }
            $id = self::field_id( (string) ( $f["label"] ?? "" ), (int) ( $f["id"] ?? 0 ), $seen );
            $map[ (string) ( $f["id"] ?? "" ) ] = $id;
            $seen[] = [ "id" => $id ];
        }

        $by_id = [];
        foreach ( $fields as $f ) {
            $by_id[ $f["id"] ] = $f;
        }

        $imported = 0;
        $offset   = 0;
        $batch    = 200;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, date_created, ip, source_url, user_agent, created_by
                   FROM {$gf_entry}
                  WHERE form_id = %d AND status = 'active'
               ORDER BY id ASC LIMIT %d OFFSET %d",
                $gf_form_id,
                $batch,
                $offset
            ), ARRAY_A );

            foreach ( (array) $rows as $row ) {
                $wpdb->insert( $entries, [
                    "form_id"      => $form_id,
                    "status"       => "completed",
                    // Keep the original timestamp: an import that stamps everything
                    // "today" destroys the only thing entries are usually sorted by.
                    // GF stores UTC, and so do we.
                    "submitted_at" => (string) $row["date_created"],
                    "ip_address"   => ! empty( $row["ip"] ) ? @inet_pton( (string) $row["ip"] ) : null,
                    "user_agent"   => substr( (string) $row["user_agent"], 0, 500 ),
                    "user_id"      => (int) $row["created_by"] ?: null,
                    "source_url"   => substr( (string) $row["source_url"], 0, 500 ),
                ] );
                $entry_id = (int) $wpdb->insert_id;
                if ( ! $entry_id ) {
                    continue;
                }

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $meta_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$gf_entry_meta} WHERE entry_id = %d",
                    (int) $row["id"]
                ), ARRAY_A );

                // GF stores composite fields as separate "3.3"/"3.6" rows; collect
                // them under the parent so a name arrives as one value, not three.
                $collected = [];
                foreach ( (array) $meta_rows as $m ) {
                    $key    = (string) $m["meta_key"];
                    $parent = strtok( $key, "." );
                    if ( ! isset( $map[ $parent ] ) ) {
                        continue;
                    }
                    $value = (string) $m["meta_value"];
                    if ( "" === $value ) {
                        continue;
                    }
                    $collected[ $map[ $parent ] ][] = $value;
                }

                foreach ( $collected as $field_id => $parts ) {
                    $field = $by_id[ $field_id ] ?? [ "label" => $field_id, "type" => "text" ];
                    $value = count( $parts ) === 1 ? $parts[0] : implode( " ", $parts );

                    $wpdb->insert( $entry_meta, [
                        "entry_id"    => $entry_id,
                        "field_id"    => $field_id,
                        "field_label" => (string) ( $field["label"] ?? $field_id ),
                        "field_type"  => (string) ( $field["type"] ?? "text" ),
                        "value"       => $value,
                    ], [ "%d", "%s", "%s", "%s", "%s" ] );
                }

                $imported++;
            }

            $offset += $batch;
        } while ( count( (array) $rows ) === $batch );

        return $imported;
    }
}
