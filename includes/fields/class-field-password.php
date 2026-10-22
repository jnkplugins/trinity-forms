<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * A password field, with an optional confirmation input.
 *
 * Deliberately never stored as an entry value: Submission_Pipeline drops it, and
 * the only consumer is the premium User Registration feature, which reads it from
 * the payload before storage. A form plugin that quietly kept plaintext passwords
 * in its own table would be a liability, not a feature.
 */
class Field_Password extends Field_Base {

    /** Password values are excluded from entry storage by the pipeline. */
    public const NEVER_STORED = true;

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $min      = isset( $config["min_length"] ) && "" !== $config["min_length"]
            ? ' minlength="' . (int) $config["min_length"] . '"'
            : "";

        // Never re-rendered with a value, even on a validation failure: putting a
        // password back into the HTML is how it ends up in a proxy log or a cache.
        $control = '<input type="password" id="' . $id . '" name="' . $name . '"'
            . ' autocomplete="new-password"' . $required . $min . " />";

        if ( ! empty( $config["confirm"] ) ) {
            $control .= '<input type="password" id="' . $id . '_confirm" name="' . $name . '_confirm"'
                . ' autocomplete="new-password" placeholder="'
                . esc_attr__( "Confirm password", "trinity-forms" ) . '"' . $required . " />";
        }

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        // No sanitizing beyond a string cast: stripping characters would silently
        // change somebody's password into one they cannot type again.
        return is_scalar( $raw ) ? (string) $raw : "";
    }

    public function validate( $value, array $config ): array {
        $value  = (string) $value;
        $errors = [];

        if ( "" === $value ) {
            return $errors;
        }

        $min = isset( $config["min_length"] ) && "" !== $config["min_length"] ? (int) $config["min_length"] : 0;
        if ( $min > 0 && strlen( $value ) < $min ) {
            $errors[] = sprintf(
                /* translators: %d: minimum number of characters. */
                __( "Password must be at least %d characters.", "trinity-forms" ),
                $min
            );
        }

        if ( ! empty( $config["confirm"] ) ) {
            $field_id = (string) ( $config["id"] ?? "" );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read only to compare against the already-sanitized value; the submission nonce is checked by the caller.
            $confirm = (string) ( wp_unslash( $_POST["trinity_forms_fields"][ $field_id . "_confirm" ] ?? "" ) );
            if ( ! hash_equals( $value, $confirm ) ) {
                $errors[] = __( "The two passwords do not match.", "trinity-forms" );
            }
        }

        return $errors;
    }

    /** Nothing to put in a CSV — see the class docblock. */
    public function to_csv_value( $stored ): string {
        return "";
    }
}
