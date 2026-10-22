<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Dynamic population — filling a field's initial value from the request context.
 *
 * This is the feature agencies reach for constantly: put ?plan=pro on a link in a
 * campaign email and the form already knows which plan; show a logged-in visitor
 * their own name and address without asking again.
 *
 * Opt-in per field (`allow_prefill` + `prefill_param`), because a form whose every
 * field can be set from the URL is a form whose data you cannot trust. Hidden
 * fields are the exception: carrying a value is the only thing they are for.
 *
 * Nothing here is trusted at submission time. Prefill sets what the *input shows*;
 * the value still arrives as an ordinary POST and goes through the same
 * sanitize()/validate() pair as anything a visitor typed. Treating a prefilled
 * value as pre-validated is how "?amount=0" becomes a free order.
 */
class Prefill {

    /**
     * Resolve the starting value for one field, or null to leave it alone.
     *
     * Sources are tried in order and the first hit wins:
     *   1. the query string
     *   2. the logged-in user (a small whitelist of profile fields, plus user meta)
     *   3. the post the form is embedded in
     *   4. a cookie of the same name
     *   5. the trinity_forms_field_value_{param} filter
     *
     * @param array<string,mixed> $config
     * @return mixed|null
     */
    public static function resolve( array $config ) {
        $param = self::param_for( $config );
        if ( "" === $param ) {
            return null;
        }

        $value = null;

        // 1. Query string.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-driven default value for a form input, not a state change; the submitted value is sanitized and validated independently.
        if ( isset( $_GET[ $param ] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $raw   = wp_unslash( $_GET[ $param ] );
            $value = is_array( $raw )
                ? array_map( "sanitize_text_field", $raw )
                : sanitize_text_field( (string) $raw );
        }

        // 2. The current user.
        if ( null === $value && is_user_logged_in() ) {
            $value = self::from_user( $param );
        }

        // 3. The post the form sits on.
        if ( null === $value ) {
            $post = get_post();
            if ( $post ) {
                $value = self::from_post( $param, $post );
            }
        }

        // 4. Cookie.
        if ( null === $value && isset( $_COOKIE[ $param ] ) ) {
            $value = sanitize_text_field( wp_unslash( (string) $_COOKIE[ $param ] ) );
        }

        /**
         * Last word on a prefilled value — the analogue of Gravity Forms'
         * gform_field_value_{param}. Returning null leaves the field empty.
         *
         * @param mixed               $value  Resolved value, or null.
         * @param array<string,mixed> $config The field config.
         */
        $value = apply_filters( "trinity_forms_field_value_" . $param, $value, $config );
        // Deliberately NOT "trinity_forms_field_value" — the pipeline already owns
        // that name with a different signature ($clean, Field, $payload), and a
        // callback written for one would fire for the other with the wrong args.
        $value = apply_filters( "trinity_forms_prefill_value", $value, $param, $config );

        return ( null === $value || "" === $value ) ? null : $value;
    }

    /**
     * The parameter name a field listens on, or "" when it listens for nothing.
     *
     * Hidden fields default to prefilling on their own id: a hidden field exists to
     * carry a value, and making every one of them tick a box first would be busywork.
     *
     * @param array<string,mixed> $config
     */
    public static function param_for( array $config ): string {
        $type = (string) ( $config["type"] ?? "" );
        $id   = (string) ( $config["id"] ?? "" );

        // An admin-only field must never be settable by a visitor's URL.
        if ( "admin" === (string) ( $config["visibility"] ?? "" ) ) {
            return "";
        }

        if ( empty( $config["allow_prefill"] ) && "hidden" !== $type ) {
            return "";
        }

        $param = trim( (string) ( $config["prefill_param"] ?? "" ) );
        return "" !== $param ? sanitize_key( $param ) : sanitize_key( $id );
    }

    /**
     * Values readable from the logged-in user.
     *
     * The named ones are a deliberate whitelist rather than "any WP_User property",
     * so a parameter called `user_pass` can never resolve to anything.
     *
     * @return string|null
     */
    private static function from_user( string $param ): ?string {
        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return null;
        }

        $known = [
            "user_id"      => (string) $user->ID,
            "user_login"   => $user->user_login,
            "user_email"   => $user->user_email,
            "first_name"   => $user->first_name,
            "last_name"    => $user->last_name,
            "display_name" => $user->display_name,
            "user_url"     => $user->user_url,
        ];
        if ( isset( $known[ $param ] ) ) {
            return "" !== $known[ $param ] ? $known[ $param ] : null;
        }

        // Explicit user: prefix for arbitrary meta, so a plain ?first_name= in the
        // query string can't be shadowed by an unrelated meta key.
        if ( 0 === strpos( $param, "user_meta_" ) ) {
            $key   = substr( $param, strlen( "user_meta_" ) );
            $value = get_user_meta( $user->ID, $key, true );
            return is_scalar( $value ) && "" !== $value ? (string) $value : null;
        }

        return null;
    }

    /** @return string|null */
    private static function from_post( string $param, \WP_Post $post ): ?string {
        $known = [
            "post_id"    => (string) $post->ID,
            "post_title" => $post->post_title,
            "post_url"   => (string) get_permalink( $post ),
        ];
        if ( isset( $known[ $param ] ) ) {
            return "" !== $known[ $param ] ? $known[ $param ] : null;
        }

        if ( 0 === strpos( $param, "post_meta_" ) ) {
            $key   = substr( $param, strlen( "post_meta_" ) );
            $value = get_post_meta( $post->ID, $key, true );
            return is_scalar( $value ) && "" !== $value ? (string) $value : null;
        }

        return null;
    }
}
