<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Submission_Pipeline {

    /**
     * Values a field type refuses to store, held for the rest of the request.
     *
     * A password is the case this exists for: it must never reach entry_meta, but
     * the premium User Registration feature runs *after* storage and still needs it.
     * Request-scoped and never persisted, so nothing outlives the submission that
     * produced it.
     *
     * @var array<int,array<string,mixed>>
     */
    private static array $unstored = [];

    public static function bootstrap(): void {
        add_filter( "trinity_forms_unstored_values", static function ( array $values, int $entry_id ): array {
            return array_merge( $values, self::$unstored[ $entry_id ] ?? [] );
        }, 10, 2 );
    }

    /**
     * Set by the REST route while a form is past its spam cap for the minute: spam
     * is then answered as usual but not stored, so a bot wave cannot fill the
     * entries table. Real entries are never affected.
     */
    public static bool $drop_spam = false;

    /** The payload being processed, for listeners that need a value beyond the fields. */
    private static array $current_payload = [];

    /** A top-level value of the submission in progress ("" when absent). */
    public static function current_value( string $key ): string {
        $v = self::$current_payload[ $key ] ?? "";
        return is_scalar( $v ) ? (string) $v : "";
    }

    public function handle( int $form_id, array $payload ): array {
        self::$current_payload = $payload;
        do_action( "trinity_forms_before_submit", $form_id, $payload );

        $form = Form_Manager::get( $form_id );
        if ( ! $form ) {
            return $this->error( 404, __( "Form not found.", "trinity-forms" ) );
        }

        // Before anti-spam and before any work: a closed, expired or full form must
        // refuse the POST too. Hiding the form on the page stops nobody from posting
        // straight at the REST endpoint.
        $blocked = Restrictions::check( $form );
        if ( $blocked ) {
            return $this->error( 403, $blocked["message"], [], [ "reason" => $blocked["reason"] ] );
        }

        // Honeypot first: a filled trap is a bot whether or not a token came along.
        $honeypot_hit = Anti_Spam::honeypot_hit( $form, $payload );
        if ( ! $honeypot_hit && Anti_Spam::captcha_token_missing( $form, $payload ) ) {
            return $this->error( 422, __( "Please complete the bot check (CAPTCHA) and submit again. If you can't see it, reload the page or allow its script.", "trinity-forms" ), [], [ "reason" => "captcha_missing" ] );
        }

        // A time token too old to time the visitor (a cached page, or a page open a
        // long time): not spam and not stored. Asked to send again from a fresh
        // page; the page script and the no-JS page both keep the answers.
        if ( ! $honeypot_hit && Anti_Spam::token_stale( $form, $payload ) ) {
            return $this->error( 409, __( "This page was open for a while, so the form needs one more press. Check your answers and send it again.", "trinity-forms" ), [], [ "reason" => "stale_page" ] );
        }

        // A page token that already vouched for its share of entries (a bot
        // replaying a cached page's HTML): the same answer as a stale page.
        if ( ! $honeypot_hit && Anti_Spam::token_used_up( $form, $payload ) ) {
            return $this->error( 409, __( "This form needs one more press. Check your answers and send it again.", "trinity-forms" ), [], [ "reason" => "stale_page" ] );
        }

        $is_spam = ! Anti_Spam::check( $form, $payload );

        if ( $is_spam && self::$drop_spam ) {
            return $this->ok( [
                "entry_id"     => 0,
                "confirmation" => Confirmations::spam_message( $form, 0 ),
                "redirect"     => null,
                "spam"         => true,
            ] );
        }

        [ $payload, $upload_errors ] = $this->process_uploads( $form, $payload );
        if ( $upload_errors ) {
            return $this->error( 422, __( "Upload failed.", "trinity-forms" ), $upload_errors );
        }

        [ $sanitized, $errors ] = $this->validate( $form, $payload );
        if ( $errors ) {
            do_action( "trinity_forms_validation_failed", $form_id, $errors );
            return $this->error( 422, __( "Validation failed.", "trinity-forms" ), $errors );
        }

        $entry_id = $this->store( $form, $sanitized, (string) ( $payload["_trinity_forms_source_url"] ?? "" ) );
        $this->record_files( $entry_id, $form, $sanitized );
        if ( "" !== Anti_Spam::note() ) {
            Logger::info( "spam", Anti_Spam::note(), $entry_id );
        }

        // Spam is quarantined, not discarded. Two reasons: the filter is sometimes
        // wrong, and a real message thrown away is unrecoverable — the Spam tab on
        // the entries screen exists so somebody can find it. Nothing is emailed and
        // no integration runs, so a false positive costs a click, not a lead.
        if ( $is_spam ) {
            Entries::set_status( [ $entry_id ], "spam" );
            // Say which rule caught it, so a false positive is one look and one click.
            $spam_reason = Anti_Spam::reason();
            Logger::info( "spam", "" !== $spam_reason
                /* translators: %s: why the entry was judged spam. */
                ? sprintf( __( "Marked as spam: %s", "trinity-forms" ), $spam_reason )
                : __( "Marked as spam by the spam filter", "trinity-forms" ), $entry_id );
            do_action( "trinity_forms_spam_rejected", $entry_id, $form_id );

            // trinity_forms_entry_created is not fired for spam, so a quiz form was
            // never scored here and the confirmation below showed a raw
            // "{quiz_score}/{quiz_max}". Score it so the wording matches a real submit.
            Scoring::score_entry( $entry_id, $form_id );

            // Answered as a success, with the form's spam wording. Saying "that
            // looked like spam" is free tuning feedback for whoever is actually
            // spamming, and an accusation for the humans the filter gets wrong.
            return $this->ok( [
                "entry_id"     => $entry_id,
                "confirmation" => Confirmations::spam_message( $form, $entry_id ),
                "redirect"     => null,
                "spam"         => true,
            ] );
        }

        Anti_Spam::count_use( $form, $payload );

        do_action( "trinity_forms_entry_created", $entry_id, $form_id );

        // Save-&-continue is premium; the resume manager (pro/ only) subscribes to
        // this action to discard the now-consumed token. No-op in the free build.
        $resume_token = (string) ( $payload["_trinity_forms_resume_token"] ?? "" );
        if ( $resume_token ) {
            do_action( "trinity_forms_resume_consumed", $resume_token );
        }

        if ( $form->payment_enabled() ) {
            // Payments are premium. The payment adapter is resolved through the
            // trinity_forms_payment_providers filter, which pro/ populates; free core
            // never references the premium payment classes. If no provider is wired
            // (free build, or lapsed beyond grace), we fall through to a normal
            // free submission so the entry is still captured and notified.
            $result = $this->maybe_start_payment( $entry_id, $form, $sanitized );
            if ( $result !== null ) {
                return $result;
            }
        }

        self::send_notifications( $entry_id );

        // Premium ESP/webhook integrations (pro/ only) and add-ons subscribe to this
        // action. Start a job only when something listens: the free build used to
        // leave a "failed: no callbacks are registered" row in Scheduled Actions for
        // every single submission. Background queues it only where a queue runner
        // works; with WP-Cron off it runs here, so a webhook is not held until the
        // owner opens wp-admin.
        if ( has_action( "trinity_forms_run_integrations" ) ) {
            Background::run( "trinity_forms_run_integrations", [ $entry_id ] );
        }

        do_action( "trinity_forms_entry_completed", $entry_id );

        // Resolved against the sanitized answers, so a confirmation can branch on
        // what was actually submitted ("budget over 10k → send them to /enterprise").
        $confirmation = Confirmations::resolve( $form, $entry_id, $sanitized );

        return $this->ok( [
            "entry_id"     => $entry_id,
            "confirmation" => $confirmation["message"],
            "redirect"     => $confirmation["redirect"],
        ] );
    }

    /** @return array{0: array<string,mixed>, 1: array<string, string[]>} */
    private function validate( Form $form, array $payload ): array {
        $errors    = [];
        $sanitized = [];
        foreach ( $form->fields() as $field ) {
            if ( ! Conditional_Logic::is_visible( $field, $payload, $form ) ) {
                continue;
            }
            $raw   = $payload[ $field->id ] ?? null;
            $clean = $field->sanitize( $raw );
            $clean = apply_filters( "trinity_forms_field_value", $clean, $field, $payload );
            // LOW-02: re-sanitize so filter callbacks cannot reintroduce unsanitized data.
            $clean = $field->sanitize( $clean );
            $errs  = $field->validate( $clean );

            // "No duplicates" is checked here rather than in Field::validate() because
            // it is the only rule that needs to look at other entries, and Field has
            // no business knowing the entries table exists.
            if ( ! $errs && ! empty( $field->config["prevent_duplicates"] ) && ! is_array( $clean ) && "" !== (string) $clean ) {
                if ( self::value_already_used( $form->id, $field->id, (string) $clean ) ) {
                    $errs = [ (string) ( $field->config["validation_message"] ?? "" )
                        ?: __( "This value has already been used.", "trinity-forms" ) ];
                }
            }

            // Extra rules that need more than the field itself (Pro choice limits
            // count other entries). Runs after the field's own checks.
            $errs = (array) apply_filters( "trinity_forms_field_errors", $errs, $field, $clean, $form );

            if ( $errs ) {
                $errors[ $field->id ] = $errs;
            }
            $sanitized[ $field->id ] = $clean;
            // Mirror sanitized value into payload so later conditional checks see clean data.
            $payload[ $field->id ]   = $clean;
        }

        // Recompute every total from the form's own configuration. Whatever the
        // browser posted into a total field is discarded — that number is a display
        // convenience, and trusting it is how "$0.00" orders happen.
        if ( ! $errors && Pricing::has_pricing( $form ) ) {
            $order = Pricing::calculate( $form, $sanitized );
            foreach ( $form->fields() as $field ) {
                if ( "total" === $field->type ) {
                    $sanitized[ $field->id ] = number_format( $order["total"], 2, ".", "" );
                }
            }
        }

        return [ $sanitized, $errors ];
    }

    /**
     * Has another entry on this form already stored this exact value for this field?
     *
     * Spam and trash are excluded so that a bin full of junk cannot permanently
     * reserve an email address, and deleting an entry frees its value again.
     */
    private static function value_already_used( int $form_id, string $field_id, string $value ): bool {
        global $wpdb;

        $entries = $wpdb->prefix . "trinity_forms_entries";
        $meta    = $wpdb->prefix . "trinity_forms_entry_meta";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$meta} m
               INNER JOIN {$entries} e ON e.id = m.entry_id
              WHERE e.form_id = %d
                AND e.status NOT IN ('spam','trash')
                AND m.field_id = %s
                AND m.value = %s
              LIMIT 1",
            $form_id,
            $field_id,
            $value
        ) );
    }

    private function store( Form $form, array $sanitized, string $source_url = "" ): int {
        global $wpdb;

        $ip = self::client_ip();
        $wpdb->insert(
            $wpdb->prefix . "trinity_forms_entries",
            [
                "form_id"      => $form->id,
                "status"       => $form->payment_enabled() ? "pending" : "completed",
                "submitted_at" => Dates::now(),
                "ip_address"   => $ip ? inet_pton( $ip ) : null,
                "user_agent"   => substr( sanitize_text_field( wp_unslash( (string) ( $_SERVER["HTTP_USER_AGENT"] ?? "" ) ) ), 0, 500 ),
                "user_id"      => get_current_user_id() ?: null,
                "referer"      => substr( sanitize_text_field( wp_unslash( (string) ( $_SERVER["HTTP_REFERER"] ?? "" ) ) ), 0, 500 ),
                // Stamped into the form at render time (Form_Renderer): the page the
                // form was on, which the submit request's own referer can't tell us.
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the submission nonce is verified by the caller before store() runs.
                "source_url"   => substr( esc_url_raw( "" !== $source_url ? $source_url : wp_unslash( (string) ( $_POST["_trinity_forms_source_url"] ?? "" ) ) ), 0, 500 ),
                "payment_status" => $form->payment_enabled() ? "pending" : "none",
            ],
            [ "%d", "%s", "%s", "%s", "%s", "%d", "%s", "%s", "%s" ]
        );
        $entry_id = (int) $wpdb->insert_id;

        foreach ( $form->fields() as $field ) {
            if ( ! array_key_exists( $field->id, $sanitized ) ) {
                continue;
            }
            // Some field types must never reach the entries table. A password is the
            // obvious one: the premium User Registration feature reads it from the
            // payload, and a form plugin quietly keeping plaintext passwords in its
            // own table would be a liability, not a feature.
            $handler = $field->handler();
            if ( $handler && defined( get_class( $handler ) . "::NEVER_STORED" ) ) {
                // Kept for the rest of this request only, so a consumer that runs
                // after storage (premium User Registration) can still see it. Never
                // written anywhere, and gone the moment the request ends.
                self::$unstored[ $entry_id ][ $field->id ] = $sanitized[ $field->id ];
                continue;
            }
            $value  = $sanitized[ $field->id ];
            if ( $value === null ) {
                continue;
            }
            $stored = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;

            $row = apply_filters( "trinity_forms_entry_meta_pre_save", [
                "entry_id"    => $entry_id,
                "field_id"    => $field->id,
                "field_label" => $field->display_label(),
                "field_type"  => $field->type,
                "value"       => $stored,
            ], $field, $sanitized );

            $wpdb->insert( $wpdb->prefix . "trinity_forms_entry_meta", $row, [ "%d", "%s", "%s", "%s", "%s" ] );
        }

        return $entry_id;
    }

    /**
     * Record one row per uploaded file in wp_trinity_forms_files for retention/audit.
     * Multi-file fields produce multiple rows.
     */
    private function record_files( int $entry_id, Form $form, array $sanitized ): void {
        global $wpdb;
        foreach ( $form->fields() as $field ) {
            if ( $field->type !== "file" || ! isset( $sanitized[ $field->id ] ) ) {
                continue;
            }
            $value = $sanitized[ $field->id ];
            $items = isset( $value[0] ) ? $value : [ $value ];
            foreach ( $items as $file ) {
                if ( ! is_array( $file ) || empty( $file["filename"] ) ) {
                    continue;
                }
                $wpdb->insert(
                    $wpdb->prefix . "trinity_forms_files",
                    [
                        "entry_id"      => $entry_id,
                        "field_id"      => $field->id,
                        "attachment_id" => null,
                        "filename"      => substr( (string) $file["filename"], 0, 255 ),
                        "filepath"      => substr( (string) ( $file["path"] ?? "" ), 0, 500 ),
                        "mime_type"     => substr( (string) ( $file["type"] ?? "" ), 0, 100 ),
                        "size_bytes"    => (int) ( $file["size"] ?? 0 ),
                        "uploaded_at"   => Dates::now(),
                    ],
                    [ "%d", "%s", "%d", "%s", "%s", "%s", "%d", "%s" ]
                );
            }
        }
    }

    public static function cleanup_entry_files( int $entry_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT filepath FROM {$wpdb->prefix}trinity_forms_files WHERE entry_id = %d",
            $entry_id
        ) );
        foreach ( $rows as $row ) {
            $path = (string) $row->filepath;
            // Only unlink files this plugin manages: the private upload root, or the
            // uploads directory for entries stored before 2.4.1.
            if ( $path && Private_Files::is_managed_path( $path ) && is_file( $path ) ) {
                wp_delete_file( $path );
            }
        }
        $wpdb->delete( $wpdb->prefix . "trinity_forms_files", [ "entry_id" => $entry_id ], [ "%d" ] );
    }

    /**
     * Attempt to start a premium payment checkout for a payment-enabled form.
     *
     * Resolves the payment adapter purely through the trinity_forms_payment_providers
     * filter (pro/ registers the gateways there). Free core never references the
     * premium payment classes. Returns:
     *   - a redirect envelope when a checkout was started,
     *   - null when no provider is available (free build / lapsed beyond grace),
     *     so the caller falls through to a normal free submission,
     *   - an error envelope when the checkout itself failed.
     *
     * @return array<string,mixed>|null
     */
    /**
     * Tell the site admin that payments are failing (at most once an hour per form):
     * a wrong key used to fail silently for every customer, with the reason only in
     * one entry's log.
     */
    public static function alert_admin_payment_failure( Form $form, int $entry_id, string $reason ): void {
        $key = "trinity_forms_payment_alert_" . $form->id;
        if ( get_transient( $key ) ) {
            return;
        }
        set_transient( $key, 1, HOUR_IN_SECONDS );
        update_option( "trinity_forms_payment_failing", [
            "form_id" => $form->id,
            "entry"   => $entry_id,
            "reason"  => substr( $reason, 0, 300 ),
            "at"      => time(),
        ], false );
        $to = (string) get_option( "admin_email" );
        if ( ! is_email( $to ) ) {
            return;
        }
        wp_mail(
            $to,
            /* translators: %s: form title. */
            sprintf( __( "[Trinity Forms] Payments are failing on \"%s\"", "trinity-forms" ), $form->title ),
            sprintf(
                /* translators: 1: form title, 2: provider error, 3: entry admin URL. */
                __( "A visitor could not start a payment on \"%1\$s\". Nothing was charged.\n\nReason from the payment provider: %2\$s\n\nCheck the payment keys under Trinity Forms > Settings. The entry: %3\$s", "trinity-forms" ),
                $form->title,
                $reason,
                admin_url( "edit.php?post_type=trinity_forms_form&page=trinity-forms-entries&entry=" . $entry_id )
            )
        );
    }

    private function maybe_start_payment( int $entry_id, Form $form, array $sanitized ): ?array {
        // Payments are what visitors use, so they keep working on any site that was
        // ever licensed, whatever has happened to the licence since. A site that was
        // never licensed falls through to free handling.
        if ( ! License::is_pro_runtime() ) {
            Logger::warn( "payment", __( "Payment skipped: this site has never had a Pro licence.", "trinity-forms" ), $entry_id );
            return null;
        }

        $providers = (array) apply_filters( "trinity_forms_payment_providers", [], $form );
        $provider  = $form->payment_provider();
        $adapter   = $providers[ $provider ] ?? null;
        if ( $adapter === null ) {
            // Payment enabled but no premium adapter wired — capture the entry as a
            // normal submission rather than failing the user.
            Logger::warn( "payment", sprintf(
                /* translators: %s: payment provider key, e.g. stripe. */
                __( "Payment is on, but the provider \"%s\" is not available.", "trinity-forms" ),
                $provider
            ), $entry_id );
            return null;
        }

        try {
            $checkout_url = (string) call_user_func( $adapter, $entry_id, $form, $sanitized );
        } catch ( \Throwable $e ) {
            Logger::error( "payment", $e->getMessage(), $entry_id );
            self::alert_admin_payment_failure( $form, $entry_id, $e->getMessage() );
            do_action( "trinity_forms_payment_start_failed", $entry_id, $form, $e->getMessage() );
            return $this->error( 503, __( "Payment could not be started, so nothing was charged. Please try again later, or contact the site owner if it keeps happening.", "trinity-forms" ) );
        }

        return $this->ok( [ "redirect" => $checkout_url, "entry_id" => $entry_id ] );
    }

    /**
     * Move file uploads into the WP uploads dir, record rows in trinity_forms_files,
     * and replace the field's payload value with metadata. Returns [ $payload, $errors ].
     */
    private function process_uploads( Form $form, array $payload ): array {
        // Public REST endpoint — wp_rest nonce + permission_callback handle authn at the route layer,
        // and wp_handle_upload() validates each file. WPCS doesn't recognise REST as the security boundary,
        // so we suppress NonceVerification + ValidatedSanitizedInput across this $_FILES traversal.
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        $errors = [];
        if ( empty( $_FILES ) ) {
            return [ $payload, $errors ];
        }
        if ( ! function_exists( "wp_handle_upload" ) ) {
            require_once ABSPATH . "wp-admin/includes/file.php";
        }

        foreach ( $form->fields() as $field ) {
            if ( $field->type !== "file" ) {
                continue;
            }
            $key = "trinity_forms_fields";
            if ( empty( $_FILES[ $key ]["name"][ $field->id ] ) ) {
                continue;
            }
            $names = (array) $_FILES[ $key ]["name"][ $field->id ];
            $multi = isset( $_FILES[ $key ]["name"][ $field->id ] ) && is_array( $_FILES[ $key ]["name"][ $field->id ] );

            $stored = [];
            $iter   = $multi ? $names : [ $names ];
            foreach ( $iter as $idx => $original_name ) {
                if ( ! $original_name ) { continue; }
                // The client-supplied filename and MIME type are untrusted: sanitize
                // the name and derive the type from the extension (never trust the
                // browser-reported type). wp_handle_upload re-validates below.
                $raw_name  = $multi ? $_FILES[ $key ]["name"][ $field->id ][ $idx ] : $_FILES[ $key ]["name"][ $field->id ];
                $safe_name = sanitize_file_name( wp_unslash( (string) $raw_name ) );
                $checked   = wp_check_filetype( $safe_name );
                $file = [
                    "name"     => $safe_name,
                    "type"     => (string) ( $checked["type"] ?? "" ),
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP-populated upload temp path, re-validated by wp_handle_upload().
                    "tmp_name" => $multi ? $_FILES[ $key ]["tmp_name"][ $field->id ][ $idx ] : $_FILES[ $key ]["tmp_name"][ $field->id ],
                    "error"    => (int) ( $multi ? $_FILES[ $key ]["error"][ $field->id ][ $idx ] : $_FILES[ $key ]["error"][ $field->id ] ),
                    "size"     => (int) ( $multi ? $_FILES[ $key ]["size"][ $field->id ][ $idx ] : $_FILES[ $key ]["size"][ $field->id ] ),
                ];
                if ( UPLOAD_ERR_NO_FILE === (int) $file["error"] ) {
                    continue;
                }
                // Say which limit was hit: "Upload error" alone left the visitor
                // trying the same photo again.
                if ( in_array( (int) $file["error"], [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) {
                    $errors[ $field->id ][] = Fields\Field_File::too_big_message( $file["name"], Fields\Field_File::max_bytes( $field->config ) );
                    continue;
                }
                if ( (int) $file["error"] !== UPLOAD_ERR_OK ) {
                    /* translators: %s: uploaded file name. */
                    $errors[ $field->id ][] = sprintf( __( "Upload error for %s.", "trinity-forms" ), $file["name"] );
                    continue;
                }
                // Core's own text for this one talks about php.ini to the visitor.
                if ( 0 === (int) $file["size"] ) {
                    /* translators: %s: uploaded file name. */
                    $errors[ $field->id ][] = sprintf( __( "%s is empty.", "trinity-forms" ), $file["name"] );
                    continue;
                }
                // Private folder + gated URL: form uploads are not media-library files.
                $result = Private_Files::with_private_dir( static fn() => wp_handle_upload( $file, [ "test_form" => false, "action" => "trinity_forms_upload" ] ) );
                if ( isset( $result["error"] ) ) {
                    $errors[ $field->id ][] = (string) $result["error"];
                    continue;
                }
                $stored[] = [
                    "filename" => basename( $result["file"] ),
                    "url"      => Private_Files::url_for( (string) $result["file"] ),
                    "type"     => $result["type"],
                    "size"     => (int) $file["size"],
                    "path"     => $result["file"],
                ];
            }

            if ( $stored ) {
                $payload[ $field->id ] = ! empty( $field->config["multiple"] ) ? $stored : $stored[0];
            }
        }

        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        return [ $payload, $errors ];
    }

    /** Entries whose notifications go out once the visitor has the response. */
    private static array $deferred = [];

    /**
     * Send the entry's notifications, after the response when the server can hand
     * it over early (PHP-FPM, LiteSpeed). With a dead SMTP host the visitor used to
     * watch the spinner for 139 s per notification, past Cloudflare's 100 s limit,
     * although the entry was long saved. Elsewhere it sends at once, as before.
     */
    public static function send_notifications( int $entry_id ): void {
        $can_finish_early = defined( "REST_REQUEST" ) && REST_REQUEST
            && ( function_exists( "fastcgi_finish_request" ) || function_exists( "litespeed_finish_request" ) );
        if ( ! apply_filters( "trinity_forms_defer_notifications", $can_finish_early, $entry_id ) ) {
            Notification_Engine::dispatch( $entry_id );
            return;
        }
        if ( ! self::$deferred ) {
            // After wp_ob_end_flush_all (priority 1), so the JSON is out of WordPress's buffers.
            add_action( "shutdown", [ self::class, "send_deferred" ], 2 );
        }
        self::$deferred[] = $entry_id;
    }

    /** shutdown: finish the visitor's response, then talk to the mail server. */
    public static function send_deferred(): void {
        $ids            = self::$deferred;
        self::$deferred = [];
        if ( ! $ids ) {
            return;
        }
        if ( function_exists( "fastcgi_finish_request" ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( "litespeed_finish_request" ) ) {
            litespeed_finish_request();
        }
        ignore_user_abort( true );
        foreach ( $ids as $id ) {
            Notification_Engine::dispatch( (int) $id );
        }
    }

    public static function client_ip(): string {
        $remote_addr = sanitize_text_field( wp_unslash( (string) ( $_SERVER["REMOTE_ADDR"] ?? "" ) ) );

        // HIGH-01: only trust proxy headers when REMOTE_ADDR is an explicitly
        // configured trusted proxy; prevents IP spoofing on servers not behind a proxy.
        $trusted = (array) apply_filters( "trinity_forms_trusted_proxies", [] );
        if ( $remote_addr !== "" && in_array( $remote_addr, $trusted, true ) ) {
            foreach ( [ "HTTP_CF_CONNECTING_IP", "HTTP_X_FORWARDED_FOR" ] as $key ) {
                if ( empty( $_SERVER[ $key ] ) ) {
                    continue;
                }
                $ip = trim( explode( ",", sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return ( $remote_addr !== "" && filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) ? $remote_addr : "";
    }

    private function ok( array $data ): array {
        // The confirmation as the visitor should see it: an imported WPForms or
        // Fluent message is HTML ("<p>Thanks…</p>") and was printed as text.
        if ( isset( $data["confirmation"] ) ) {
            $data["confirmation_html"] = Confirmations::html( (string) $data["confirmation"] );
        }
        return [ "success" => true, "data" => $data, "errors" => [], "meta" => [] ];
    }

    /**
     * @param array<string,array<int,string>> $errors  Per-field messages.
     * @param array<string,mixed>             $meta    Extra envelope meta, e.g. a
     *                                                 machine-readable reason code
     *                                                 so the client can tell "form
     *                                                 closed" from "you typed that
     *                                                 wrong".
     */
    private function error( int $status, string $message, array $errors = [], array $meta = [] ): array {
        return [
            "success" => false,
            "data"    => null,
            "errors"  => array_merge( [ [ "code" => $status, "message" => $message ] ], self::flatten_errors( $errors ) ),
            "meta"    => array_merge( [ "status" => $status ], $meta ),
        ];
    }

    private static function flatten_errors( array $by_field ): array {
        $out = [];
        foreach ( $by_field as $field_id => $list ) {
            foreach ( $list as $msg ) {
                $out[] = [ "field" => $field_id, "message" => $msg ];
            }
        }
        return $out;
    }
}
