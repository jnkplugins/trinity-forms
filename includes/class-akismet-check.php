<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Akismet spam filtering (free). When the site has an Akismet API key configured,
 * each submission's content is checked against Akismet and flagged submissions are
 * rejected — the same content-based protection WordPress comments get.
 *
 * Wired onto the trinity_forms_is_spam extension point. If Akismet isn't set up it
 * simply never vetoes anything. The decision is routed through the
 * trinity_forms_akismet_result filter so it can be stubbed in tests and overridden
 * by integrators.
 */
class Akismet_Check {

    public static function register(): void {
        add_filter( "trinity_forms_is_spam", [ __CLASS__, "check" ], 10, 3 );
    }

    /**
     * @param bool  $spam    running spam verdict
     * @param Form  $form    the form
     * @param array $payload submitted values
     */
    public static function check( $spam, $form, $payload ): bool {
        if ( $spam ) {
            return true; // already flagged by an earlier check
        }
        $params = self::build_params( $form, $payload );

        // Testable / overridable decision. A boolean short-circuits the HTTP call.
        $override = apply_filters( "trinity_forms_akismet_result", null, $params, $form );
        if ( is_bool( $override ) ) {
            return $override;
        }

        if ( ! self::available() ) {
            return false;
        }
        $spam = self::query( $params );
        if ( $spam ) {
            Anti_Spam::flag( __( "Akismet flagged it.", "trinity-forms" ) );
        }
        return $spam;
    }

    public static function available(): bool {
        return self::api_key() !== "";
    }

    private static function api_key(): string {
        if ( class_exists( "Akismet" ) && method_exists( "Akismet", "get_api_key" ) ) {
            $k = (string) \Akismet::get_api_key();
            if ( $k !== "" ) {
                return $k;
            }
        }
        return (string) get_option( "wordpress_api_key", "" );
    }

    /** Build Akismet comment-check parameters from the submission. */
    private static function build_params( $form, array $payload ): array {
        $author = "";
        $email  = "";
        $content = [];
        foreach ( ( $form instanceof Form ) ? $form->fields() : [] as $field ) {
            $val = self::flatten( $payload[ $field->id ] ?? "" );
            if ( $val === "" ) {
                continue;
            }
            if ( $email === "" && $field->type === "email" ) {
                $email = $val;
            } elseif ( $author === "" && $field->type === "name" ) {
                $author = $val;
            }
            $content[] = $val;
        }

        return [
            "blog"                 => home_url(),
            "user_ip"              => (string) Submission_Pipeline::client_ip(),
            "user_agent"           => isset( $_SERVER["HTTP_USER_AGENT"] ) ? sanitize_text_field( wp_unslash( $_SERVER["HTTP_USER_AGENT"] ) ) : "",
            "referrer"             => isset( $_SERVER["HTTP_REFERER"] ) ? sanitize_text_field( wp_unslash( $_SERVER["HTTP_REFERER"] ) ) : "",
            "comment_type"         => "contact-form",
            "comment_author"       => $author,
            "comment_author_email" => $email,
            "comment_content"      => implode( "\n", $content ),
        ];
    }

    /**
     * A submitted value as one line of text. Repeater rows arrive nested
     * ([[item => …]]), and strval() on an inner array raised "Array to string
     * conversion" on every such submission; only scalars are kept, at any depth.
     *
     * @param mixed $val
     */
    private static function flatten( $val ): string {
        if ( ! is_array( $val ) ) {
            return is_scalar( $val ) ? (string) $val : "";
        }
        $parts = [];
        array_walk_recursive( $val, static function ( $v ) use ( &$parts ) {
            if ( is_scalar( $v ) && "" !== (string) $v ) {
                $parts[] = (string) $v;
            }
        } );
        return implode( ", ", $parts );
    }

    /** Query Akismet's comment-check endpoint; true means spam. */
    private static function query( array $params ): bool {
        $key = self::api_key();
        if ( $key === "" ) {
            return false;
        }
        $resp = wp_remote_post( "https://" . $key . ".rest.akismet.com/1.1/comment-check", [
            "timeout" => 8,
            "body"    => $params,
            "headers" => [ "User-Agent" => "Trinity Forms/" . TRINITY_FORMS_VERSION ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return false; // fail open — never block on an Akismet outage
        }
        return trim( (string) wp_remote_retrieve_body( $resp ) ) === "true";
    }
}
