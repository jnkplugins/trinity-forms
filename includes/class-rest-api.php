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
        // CRIT-01: verify the wp_rest nonce embedded by Form_Renderer.
        $nonce = (string) ( $request->get_param( "_wpnonce" ) ?: $request->get_header( "x_wp_nonce" ) );
        if ( ! wp_verify_nonce( $nonce, "wp_rest" ) ) {
            return new \WP_REST_Response( $this->envelope( null, [ [ "code" => 403, "message" => __( "Invalid or missing nonce.", "trinity-forms" ) ] ] ), 403 );
        }

        $form_id = (int) $request["form_id"];

        // Reject unknown form IDs before rate-limiting, so unauthenticated requests
        // can't create arbitrary per-form transients (storage bloat).
        // Drafts are not public: the same rule the shortcode and block apply.
        if ( ! Form_Manager::get_public( $form_id ) ) {
            return new \WP_REST_Response( $this->envelope( null, [ [ "code" => 404, "message" => __( "Form not found.", "trinity-forms" ) ] ] ), 404 );
        }

        // CRIT-02: rate-limit public submissions.
        $rate = $this->check_submit_rate_limit( $form_id );
        if ( $rate !== true ) {
            return $rate;
        }

        $payload = (array) $request->get_param( "trinity_forms_fields" );

        // Honeypot + time-trap fields are at the top level.
        $payload[ Anti_Spam::HONEYPOT_FIELD ] = (string) $request->get_param( Anti_Spam::HONEYPOT_FIELD );
        $payload[ Anti_Spam::TIMETRAP_FIELD ] = (string) $request->get_param( Anti_Spam::TIMETRAP_FIELD );

        // The page the form was on. The browser sends JSON, so this never reached
        // $_POST and every entry's "Submitted from" stayed empty.
        $source_url = (string) $request->get_param( "_trinity_forms_source_url" );
        if ( "" !== $source_url ) {
            $payload["_trinity_forms_source_url"] = $source_url;
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

        $result = ( new Submission_Pipeline() )->handle( $form_id, $payload );
        $status = $result["meta"]["status"] ?? ( $result["success"] ? 200 : 400 );
        return new \WP_REST_Response( $result, (int) $status );
    }

    /** @return true|\WP_REST_Response */
    private function check_submit_rate_limit( int $form_id ) {
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

        $window   = max( 1, (int) $limits["window_seconds"] );
        $per_ip   = (int) $limits["per_ip"];
        $per_form = (int) $limits["per_form"];

        if ( $per_ip > 0 ) {
            $wait = self::rate_hit( "trinity_forms_rate_submit_" . sha1( (string) Submission_Pipeline::client_ip() ), $per_ip, $window );
            if ( $wait > 0 ) {
                return $this->too_many_requests( $wait );
            }
        }

        if ( $per_form > 0 ) {
            $wait = self::rate_hit( "trinity_forms_rate_submit_form_" . $form_id, $per_form, $window );
            if ( $wait > 0 ) {
                return $this->too_many_requests( $wait );
            }
        }

        return true;
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

    private function too_many_requests( int $wait = 60 ): \WP_REST_Response {
        $wait    = max( 1, $wait );
        $message = $wait > 90
            /* translators: %d: minutes to wait. */
            ? sprintf( _n( "Too many submissions from your connection. Please try again in %d minute.", "Too many submissions from your connection. Please try again in %d minutes.", (int) ceil( $wait / 60 ), "trinity-forms" ), (int) ceil( $wait / 60 ) )
            /* translators: %d: seconds to wait. */
            : sprintf( _n( "Too many submissions from your connection. Please try again in %d second.", "Too many submissions from your connection. Please try again in %d seconds.", $wait, "trinity-forms" ), $wait );
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
