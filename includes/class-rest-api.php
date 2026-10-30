<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class REST_API {

    private const NS = "trinity-forms/v1";

    public function register_routes(): void {
        register_rest_route( self::NS, "/submit/(?P<form_id>\d+)", [
            "methods"             => "POST",
            "callback"            => [ $this, "submit" ],
            "permission_callback" => "__return_true",
        ] );

        // Premium REST routes — integration test, save-progress, and payment
        // webhooks — self-register here from pro/. Nothing listens in the free build.
        do_action( "trinity_forms_register_rest_routes", self::NS );

        register_rest_route( self::NS, "/forms", [
            [
                "methods"             => "GET",
                "callback"            => [ $this, "list_forms" ],
                "permission_callback" => [ $this, "can_manage" ],
            ],
            [
                "methods"             => "POST",
                "callback"            => [ $this, "create_form" ],
                "permission_callback" => [ $this, "can_manage" ],
            ],
        ] );

        register_rest_route( self::NS, "/forms/(?P<id>\d+)", [
            [
                "methods"             => "GET",
                "callback"            => [ $this, "get_form" ],
                "permission_callback" => [ $this, "can_manage" ],
            ],
            [
                "methods"             => "PUT",
                "callback"            => [ $this, "update_form" ],
                "permission_callback" => [ $this, "can_manage" ],
            ],
            [
                "methods"             => "DELETE",
                "callback"            => [ $this, "delete_form" ],
                "permission_callback" => [ $this, "can_manage" ],
            ],
        ] );

        register_rest_route( self::NS, "/submissions", [
            "methods"             => "GET",
            "callback"            => [ $this, "list_submissions" ],
            "permission_callback" => [ $this, "can_manage" ],
        ] );

        register_rest_route( self::NS, "/submissions/(?P<id>\d+)", [
            [
                "methods"             => "GET",
                "callback"            => [ $this, "get_submission" ],
                "permission_callback" => [ $this, "can_manage" ],
            ],
            [
                "methods"             => "DELETE",
                "callback"            => [ $this, "delete_submission" ],
                "permission_callback" => [ $this, "can_manage" ],
            ],
        ] );

        register_rest_route( self::NS, "/submissions/export.csv", [
            "methods"             => "GET",
            "callback"            => [ $this, "export_submissions" ],
            // The Export button is a plain <a href> GET that passes the nonce as a
            // ?_wpnonce= query param (no X-WP-Nonce header), so use the param-aware
            // capability check here.
            "permission_callback" => [ $this, "can_manage_param" ],
        ] );
    }

    public function can_manage( \WP_REST_Request $request ): bool {
        // Managing forms and viewing/exporting submissions (which hold submitter
        // PII) requires an administrator-level capability, not just post-editing.
        if ( ! current_user_can( "manage_options" ) ) {
            return false;
        }
        // Accept the wp_rest nonce from either the X-WP-Nonce header or the
        // _wpnonce query param (the latter for plain GET <a href> links).
        $nonce = (string) ( $request->get_header( "x_wp_nonce" ) ?: $request->get_param( "_wpnonce" ) );
        return $nonce && wp_verify_nonce( $nonce, "wp_rest" );
    }

    /** Param-aware alias for GET links that carry the nonce as ?_wpnonce=. */
    public function can_manage_param( \WP_REST_Request $request ): bool {
        return $this->can_manage( $request );
    }

    /* ---------- Public ---------- */

    public function submit( \WP_REST_Request $request ) {
        $form_id = (int) $request["form_id"];
        // With JavaScript off every refusal below goes back to the page with its
        // message, not to a page of raw JSON. A body over post_max_size arrives
        // empty, so the page comes from the Referer then.
        $plain      = self::is_plain_form_post( $request );
        $source_url = (string) ( $request->get_param( "_trinity_forms_source_url" ) ?: $request->get_header( "referer" ) );
        $refuse     = function ( \WP_REST_Response $response ) use ( $plain, $form_id, $source_url ): \WP_REST_Response {
            return $plain ? self::back_to_the_page( $form_id, (array) $response->get_data(), $source_url ) : $response;
        };

        // Drafts are not public: the same rule the shortcode and block apply.
        $form = Form_Manager::get_public( $form_id );

        // Checked before the nonce: PHP threw the body away, nonce included, and
        // "too big" is the thing the visitor can act on.
        if ( $form ) {
            $too_large = $this->body_too_large( $request, $form );
            if ( $too_large ) {
                return $refuse( $too_large );
            }
        }

        // CRIT-01: verify the wp_rest nonce embedded by Form_Renderer. The page's
        // field is named _trinity_forms_nonce so WordPress's own cookie check
        // (which reads _wpnonce) cannot answer a stale page with raw JSON first.
        $nonce = (string) ( $request->get_param( "_trinity_forms_nonce" ) ?: $request->get_param( "_wpnonce" ) ?: $request->get_header( "x_wp_nonce" ) );
        if ( ! wp_verify_nonce( $nonce, "wp_rest" ) ) {
            // "reason" lets the page script tell a stale page (cached, or a tab left
            // open overnight) from other refusals: it fetches a fresh nonce and retries.
            $message = $plain ? __( "This page is out of date. Please reload it and try again.", "trinity-forms" ) : __( "Invalid or missing nonce.", "trinity-forms" );
            return $refuse( new \WP_REST_Response( $this->envelope( null, [ [ "code" => 403, "reason" => "invalid_nonce", "message" => $message ] ] ), 403 ) );
        }

        // Reject unknown form IDs before rate-limiting, so unauthenticated requests
        // can't create arbitrary per-form transients (storage bloat).
        if ( ! $form ) {
            return $refuse( new \WP_REST_Response( $this->envelope( null, [ [ "code" => 404, "message" => __( "Form not found.", "trinity-forms" ) ] ] ), 404 ) );
        }

        // CRIT-02: rate-limit public submissions.
        $rate = $this->check_submit_rate_limit( $form_id );
        if ( $rate !== true ) {
            return $refuse( $rate );
        }

        $payload = (array) $request->get_param( "trinity_forms_fields" );

        // Honeypot + time-trap fields are at the top level.
        $payload[ Anti_Spam::HONEYPOT_FIELD ] = (string) $request->get_param( Anti_Spam::HONEYPOT_FIELD );
        $payload[ Anti_Spam::TIMETRAP_FIELD ] = (string) $request->get_param( Anti_Spam::TIMETRAP_FIELD );
        $payload[ Anti_Spam::HUMAN_FIELD ]    = (string) $request->get_param( Anti_Spam::HUMAN_FIELD );

        // The page the form was on. The browser sends JSON, so this never reached
        // $_POST and every entry's "Submitted from" stayed empty.
        $sent_from = (string) $request->get_param( "_trinity_forms_source_url" );
        if ( "" !== $sent_from ) {
            $payload["_trinity_forms_source_url"] = $sent_from;
        }

        // Abandoned-form capture token, so the finished submission can replace the
        // partial row (JSON bodies never reach $_POST).
        $partial_token = (string) $request->get_param( "partial_token" );
        if ( "" !== $partial_token ) {
            $payload["partial_token"] = $partial_token;
        }

        $resume_token = (string) $request->get_param( "_trinity_forms_resume_token" );
        if ( $resume_token ) {
            $payload["_trinity_forms_resume_token"] = $resume_token;
        }

        // Forward the CAPTCHA response token into the payload (the field name
        // varies by provider: Turnstile / reCAPTCHA / hCaptcha).
        foreach ( [ "cf-turnstile-response", "g-recaptcha-response", "h-captcha-response" ] as $captcha_field ) {
            $tok = (string) ( $request->get_param( $captcha_field ) ?? $request->get_param( str_replace( "-", "_", $captcha_field ) ) ?? "" );
            if ( $tok !== "" ) {
                $payload[ $captcha_field ] = $tok;
            }
        }

        // The form-wide cap counts real entries and spam apart (see
        // check_submit_rate_limit): past the spam cap, more spam is answered as
        // usual but not stored.
        $limits = $this->submit_limits();
        $spam_key = "trinity_forms_rate_submit_spam_" . $form_id;
        Submission_Pipeline::$drop_spam = $limits["per_form"] > 0 && self::rate_peek( $spam_key, $limits["per_form"] ) > 0;
        try {
            $result = ( new Submission_Pipeline() )->handle( $form_id, $payload );
        } finally {
            Submission_Pipeline::$drop_spam = false;
        }
        if ( $limits["per_form"] > 0 && ! empty( $result["success"] ) && ! empty( $result["data"]["entry_id"] ) ) {
            self::rate_hit( empty( $result["data"]["spam"] ) ? "trinity_forms_rate_submit_form_" . $form_id : $spam_key, $limits["per_form"], $limits["window"] );
        }
        $status = $result["meta"]["status"] ?? ( $result["success"] ? 200 : 400 );
        if ( $plain ) {
            // Too old a page without JavaScript: the Back button would bring back the
            // same old page, so the fresh page shows the form filled in again.
            $values = "stale_page" === ( $result["meta"]["reason"] ?? "" ) ? (array) $request->get_param( "trinity_forms_fields" ) : [];
            return self::back_to_the_page( $form_id, $result, $source_url, $values );
        }
        return new \WP_REST_Response( $result, (int) $status );
    }

    /**
     * A browser with JavaScript off posts the form itself: no X-WP-Nonce header and
     * no JSON Accept, which the page script always sends.
     */
    private static function is_plain_form_post( \WP_REST_Request $request ): bool {
        if ( "" !== (string) $request->get_header( "x_wp_nonce" ) ) {
            return false;
        }
        if ( false !== stripos( (string) $request->get_header( "accept" ), "json" ) ) {
            return false;
        }
        $type = strtolower( (string) $request->get_header( "content_type" ) );
        return 0 === strpos( $type, "multipart/form-data" ) || 0 === strpos( $type, "application/x-www-form-urlencoded" );
    }

    /**
     * With JavaScript off the visitor used to land on a page of raw JSON. Send them
     * back to the form's page (or the form's redirect) and say what happened there.
     * The message rides in a short-lived transient, so no visitor text is put in
     * the URL. Form_Renderer::plain_post_notice() shows it.
     */
    private static function back_to_the_page( int $form_id, array $result, string $source_url, array $values = [] ): \WP_REST_Response {
        $redirect = (string) ( $result["data"]["redirect"] ?? "" );
        if ( ! empty( $result["success"] ) && "" !== $redirect ) {
            // The owner's redirect or the payment page, which may be another host.
            $to = wp_sanitize_redirect( $redirect );
        } else {
            if ( ! empty( $result["success"] ) ) {
                $messages = [ (string) ( $result["data"]["confirmation_html"] ?? $result["data"]["confirmation"] ?? "" ) ];
            } elseif ( $values ) {
                $messages = [ (string) ( $result["errors"][0]["message"] ?? "" ) ];
            } else {
                $messages = self::plain_errors( $form_id, (array) ( $result["errors"] ?? [] ) );
            }
            $token = wp_generate_password( 16, false );
            set_transient( "trinity_forms_plain_" . $token, [
                "form_id"  => $form_id,
                "ok"       => ! empty( $result["success"] ),
                "messages" => $messages,
                "values"   => $values,
            ], 10 * MINUTE_IN_SECONDS );
            // The page URL comes from the visitor's POST: only this site's own.
            $page = wp_validate_redirect( $source_url, home_url( "/" ) );
            $to   = add_query_arg( "tf_result", $token, $page ) . "#trinity-forms-" . $form_id;
        }
        $response = new \WP_REST_Response( null, 303 );
        $response->header( "Location", $to );
        return $response;
    }

    /**
     * Error lines for the no-JS page. A field error names its field ("Email: This
     * field is required."), since the page has no field to paint it next to, and a
     * closing line says how to get the typed answers back.
     *
     * @return string[]
     */
    private static function plain_errors( int $form_id, array $errors ): array {
        $labels = [];
        $form   = Form_Manager::get( $form_id );
        if ( $form ) {
            foreach ( $form->fields() as $field ) {
                $labels[ (string) $field->id ] = wp_strip_all_tags( (string) ( $field->config["label"] ?? "" ) );
            }
        }
        $lines = [];
        foreach ( $errors as $e ) {
            $message = (string) ( $e["message"] ?? "" );
            if ( "" === $message ) {
                continue;
            }
            $label = $labels[ (string) ( $e["field"] ?? "" ) ] ?? "";
            /* translators: 1: field label, 2: what is wrong with it. */
            $lines[] = "" !== $label ? sprintf( __( '%1$s: %2$s', "trinity-forms" ), $label, $message ) : $message;
        }
        $lines   = array_values( array_unique( $lines ) );
        $lines[] = __( "Your browser's Back button brings back what you typed.", "trinity-forms" );
        return $lines;
    }

    /**
     * A body over post_max_size reaches us empty: PHP throws away every field and
     * file, but the nonce header survives. Without this the visitor who attached a
     * big phone photo was told "This field is required." for the fields they had
     * filled in, and the file was never mentioned.
     */
    private function body_too_large( \WP_REST_Request $request, Form $form ): ?\WP_REST_Response {
        $length = (int) $request->get_header( "content_length" );
        $limit  = wp_convert_hr_to_bytes( (string) ini_get( "post_max_size" ) );
        if ( $limit <= 0 || $length <= $limit ) {
            return null;
        }
        $file_fields = array_values( array_filter( $form->fields(), static fn( $f ) => "file" === $f->type ) );
        $max         = $file_fields ? Fields\Field_File::max_bytes( $file_fields[0]->config ) : wp_max_upload_size();
        /* translators: %s: the largest file size accepted, e.g. "2 MB". */
        $message = sprintf( __( "The file is too big to send. Files can be up to %s.", "trinity-forms" ), size_format( $max ) );
        $errors  = [ [ "code" => 413, "reason" => "too_large", "message" => $message ] ];
        if ( 1 === count( $file_fields ) ) {
            // Painted next to the file box (and the stepper jumps to its step); the
            // heading then reads like any other upload problem.
            $errors[0]["message"] = __( "Upload failed.", "trinity-forms" );
            $errors[]             = [ "field" => $file_fields[0]->id, "message" => $message ];
        }
        return new \WP_REST_Response( [ "success" => false, "data" => null, "errors" => $errors, "meta" => [ "status" => 413 ] ], 413 );
    }

    /** @return array{per_ip:int,per_form:int,window:int} */
    private function submit_limits(): array {
        // 10 submissions a minute per visitor IP, 50 a minute per form. The window
        // is fixed: it starts with the first counted submit and does not restart on
        // each later one, which used to lock a shared office IP out indefinitely.
        $defaults = [
            "per_ip"         => 10,
            "per_form"       => 50,
            "window_seconds" => 60,
        ];
        $limits = (array) apply_filters( "trinity_forms_submit_rate_limit", $defaults );
        $limits = array_merge( $defaults, $limits );
        return [
            "per_ip"   => (int) $limits["per_ip"],
            "per_form" => (int) $limits["per_form"],
            "window"   => max( 1, (int) $limits["window_seconds"] ),
        ];
    }

    /** @return true|\WP_REST_Response */
    private function check_submit_rate_limit( int $form_id ) {
        $limits = $this->submit_limits();

        if ( $limits["per_ip"] > 0 ) {
            $wait = self::rate_hit( "trinity_forms_rate_submit_" . sha1( (string) Submission_Pipeline::client_ip() ), $limits["per_ip"], $limits["window"] );
            if ( $wait > 0 ) {
                return $this->too_many_requests( $wait );
            }
        }

        // Only real entries fill the form-wide bucket (counted after the pipeline).
        // When spam counted too, a wave of 50 bot posts from 50 addresses refused
        // the next real visitor, and told them it was their connection.
        if ( $limits["per_form"] > 0 ) {
            $wait = self::rate_peek( "trinity_forms_rate_submit_form_" . $form_id, $limits["per_form"] );
            if ( $wait > 0 ) {
                return $this->too_many_requests( $wait, true );
            }
        }

        return true;
    }

    /** Seconds until a full window resets, or 0 when there is room. Counts nothing. */
    public static function rate_peek( string $key, int $limit ): int {
        $bucket = get_transient( $key );
        $now    = time();
        if ( ! is_array( $bucket ) || (int) ( $bucket["reset"] ?? 0 ) <= $now || (int) ( $bucket["count"] ?? 0 ) < $limit ) {
            return 0;
        }
        return max( 1, (int) $bucket["reset"] - $now );
    }

    /**
     * Count one hit in a fixed window. Returns 0 when allowed, otherwise the
     * seconds until the window resets.
     */
    public static function rate_hit( string $key, int $limit, int $window ): int {
        $now    = time();
        $bucket = get_transient( $key );
        if ( ! is_array( $bucket ) || (int) ( $bucket["reset"] ?? 0 ) <= $now ) {
            $bucket = [ "count" => 0, "reset" => $now + $window ];
        }
        $left = max( 1, (int) $bucket["reset"] - $now );
        if ( (int) $bucket["count"] >= $limit ) {
            return $left;
        }
        $bucket["count"] = (int) $bucket["count"] + 1;
        // Keep the original reset time: expire when the window ends, not $window from now.
        set_transient( $key, $bucket, $left );
        return 0;
    }

    private function too_many_requests( int $wait = 60, bool $whole_form = false ): \WP_REST_Response {
        $wait    = max( 1, $wait );
        $message = $whole_form
            /* translators: %d: seconds to wait. */
            ? sprintf( _n( "This form is getting a lot of submissions right now. Please try again in %d second.", "This form is getting a lot of submissions right now. Please try again in %d seconds.", $wait, "trinity-forms" ), $wait )
            : ( $wait > 90
            /* translators: %d: minutes to wait. */
            ? sprintf( _n( "Too many submissions from your connection. Please try again in %d minute.", "Too many submissions from your connection. Please try again in %d minutes.", (int) ceil( $wait / 60 ), "trinity-forms" ), (int) ceil( $wait / 60 ) )
            /* translators: %d: seconds to wait. */
            : sprintf( _n( "Too many submissions from your connection. Please try again in %d second.", "Too many submissions from your connection. Please try again in %d seconds.", $wait, "trinity-forms" ), $wait ) );
        $response = new \WP_REST_Response( [
            "success" => false,
            "data"    => null,
            "errors"  => [ [ "code" => 429, "reason" => "rate_limited", "message" => $message ] ],
            "meta"    => [ "status" => 429, "retry_after" => $wait ],
        ], 429 );
        $response->header( "Retry-After", (string) $wait );
        return $response;
    }

    /* ---------- Forms ---------- */

    public function list_forms() {
        $posts = get_posts( [
            "post_type"      => "trinity_forms_form",
            "post_status"    => [ "publish", "draft" ],
            "numberposts"    => 100,
        ] );
        $data = array_map( static fn( $p ) => [
            "id"     => (int) $p->ID,
            "title"  => $p->post_title,
            "status" => $p->post_status,
        ], $posts );
        return $this->envelope( $data );
    }

    public function get_form( \WP_REST_Request $request ) {
        $form = Form_Manager::get( (int) $request["id"] );
        if ( ! $form ) {
            return new \WP_REST_Response( $this->envelope( null, [ [ "code" => 404, "message" => "Not found" ] ] ), 404 );
        }
        return $this->envelope( [
            "id"     => $form->id,
            "title"  => $form->title,
            "schema" => $form->schema,
        ] );
    }

    public function create_form( \WP_REST_Request $request ) {
        $title  = sanitize_text_field( (string) $request->get_param( "title" ) ) ?: __( "Untitled form", "trinity-forms" );
        $schema = (array) ( $request->get_param( "schema" ) ?? [] );
        $id     = Form_Manager::create( $title, $schema );
        return $id ? $this->envelope( [ "id" => $id ] ) : new \WP_REST_Response( $this->envelope( null, [ [ "message" => "Create failed" ] ] ), 500 );
    }

    public function update_form( \WP_REST_Request $request ) {
        $id     = (int) $request["id"];
        $schema = (array) ( $request->get_param( "schema" ) ?? [] );
        $title  = $request->get_param( "title" );
        $post   = get_post( $id );

        // save_schema() rewrites post_content wholesale. That is lossless for a form made
        // only of field blocks (which is what save_schema() itself writes), but would drop
        // anything else a person added in the block editor: columns, prose, images.
        // A schema that can't be written as blocks would also turn the form back into
        // JSON, which the editors can't open.
        if ( $schema && $post && Form::looks_like_blocks( (string) $post->post_content )
            && ( ! Form::is_field_markup_only( (string) $post->post_content ) || null === Form::blocks_from_schema( $schema ) ) ) {
            return new \WP_REST_Response( $this->envelope( null, [ [
                "code"    => 409,
                "message" => "This form is block-built; edit it in the form editor or the visual builder.",
            ] ] ), 409 );
        }

        if ( $title !== null ) {
            wp_update_post( [ "ID" => $id, "post_title" => sanitize_text_field( (string) $title ) ] );
        }
        // An absent schema means a title-only update; writing the empty array through
        // would blank the form's fields.
        if ( ! $schema ) {
            return $title !== null
                ? $this->envelope( [ "id" => $id ] )
                : new \WP_REST_Response( $this->envelope( null, [ [ "code" => 400, "message" => "Nothing to update" ] ] ), 400 );
        }
        $ok = Form_Manager::save_schema( $id, $schema );
        return $ok ? $this->envelope( [ "id" => $id ] ) : new \WP_REST_Response( $this->envelope( null, [ [ "message" => "Update failed" ] ] ), 500 );
    }

    public function delete_form( \WP_REST_Request $request ) {
        $id = (int) $request["id"];
        wp_trash_post( $id );
        return $this->envelope( [ "id" => $id ] );
    }

    /* ---------- Submissions ---------- */

    public function list_submissions( \WP_REST_Request $request ) {
        global $wpdb;
        $form_id  = (int) $request->get_param( "form_id" );
        $status   = sanitize_text_field( (string) $request->get_param( "status" ) );
        $page     = max( 1, (int) $request->get_param( "page" ) ?: 1 );
        $per_page = min( 100, max( 1, (int) $request->get_param( "per_page" ) ?: 20 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = "1=1";
        $params = [];
        if ( $form_id ) { $where .= " AND form_id = %d"; $params[] = $form_id; }
        if ( $status )  { $where .= " AND status = %s";  $params[] = $status; }

        // $where contains only "1=1" plus literal column-name fragments with %d/%s placeholders; the table name and user data are bound via prepare().
        $table = $wpdb->prefix . "trinity_forms_entries";
        $base  = "FROM %i WHERE {$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base}", ...array_merge( [ $table ], $params ) ) );

        $sql = "SELECT id, form_id, status, submitted_at, payment_status FROM %i WHERE {$where} ORDER BY submitted_at DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( [ $table ], $params, [ $per_page, $offset ] ) ) );
        foreach ( (array) $rows as $row ) {
            $row->submitted_at_gmt = (string) $row->submitted_at;
            $row->submitted_at     = Dates::to_site( $row->submitted_at );
        }

        return $this->envelope( $rows, [], [ "page" => $page, "per_page" => $per_page, "total" => $total ] );
    }

    public function get_submission( \WP_REST_Request $request ) {
        global $wpdb;
        $id    = (int) $request["id"];
        $entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d", $id ), ARRAY_A );
        if ( ! $entry ) {
            return new \WP_REST_Response( $this->envelope( null, [ [ "code" => 404, "message" => "Not found" ] ] ), 404 );
        }
        $entry["ip_address"] = $entry["ip_address"] ? inet_ntop( $entry["ip_address"] ) : null;
        $entry = Dates::with_site_times( $entry );
        $entry["fields"] = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, field_label, field_type, value FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d",
            $id
        ), ARRAY_A );
        return $this->envelope( $entry );
    }

    public function delete_submission( \WP_REST_Request $request ) {
        global $wpdb;
        $id = (int) $request["id"];
        $wpdb->delete( $wpdb->prefix . "trinity_forms_entries", [ "id" => $id ], [ "%d" ] );
        $wpdb->delete( $wpdb->prefix . "trinity_forms_entry_meta", [ "entry_id" => $id ], [ "%d" ] );
        do_action( "trinity_forms_entry_deleted", $id );
        return $this->envelope( [ "id" => $id ] );
    }

    public function export_submissions( \WP_REST_Request $request ) {
        // Export what the screen is showing. An export that silently ignores the
        // filters you just set is the fastest way to make people stop trusting it.
        $args = [
            "form_id"        => (int) $request->get_param( "form_id" ),
            "view"           => (string) ( $request->get_param( "view" ) ?: "all" ),
            "search"         => (string) $request->get_param( "s" ),
            "date_from"      => (string) $request->get_param( "from" ),
            "date_to"        => (string) $request->get_param( "to" ),
            "payment_status" => (string) $request->get_param( "payment_status" ),
        ];

        // Building and streaming both live on Entries so the "export selected" bulk
        // action on the entries screen produces a byte-identical file.
        $ids = \TrinityForms\Entries::ids( $args );
        \TrinityForms\Entries::send_csv( \TrinityForms\Entries::to_csv( $ids ) );
    }


    private function envelope( $data, array $errors = [], array $meta = [] ): array {
        return [
            "success" => empty( $errors ),
            "data"    => $data,
            "errors"  => $errors,
            "meta"    => $meta,
        ];
    }
}
