<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * Phone field — a single tel input with optional international-format validation.
 * When "intl" is on, values must look like an international number (optional
 * leading +, 7–15 digits, spaces/dashes/parens allowed); otherwise any non-empty
 * phone-ish string is accepted.
 */
class Field_Phone extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $ph       = isset( $config["placeholder"] ) ? ' placeholder="' . esc_attr( $config["placeholder"] ) . '"' : "";
        $val      = esc_attr( (string) ( is_array( $value ) ? "" : ( $value ?? "" ) ) );
        $control  = '<input type="tel" id="' . $id . '" name="' . $name . '" value="' . $val . '"' . $ph . $required . ' autocomplete="tel" inputmode="tel" />';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        if ( is_array( $raw ) ) {
            return "";
        }
        // Keep digits and common phone punctuation; collapse whitespace.
        $clean = preg_replace( "/[^0-9+()\\-. ]/", "", (string) $raw );
        return trim( preg_replace( "/\\s+/", " ", (string) $clean ) );
    }

    public function validate( $value, array $config ): array {
        if ( $value === "" || $value === null ) {
            return [];
        }
        $digits = preg_replace( "/\\D/", "", (string) $value );
        if ( ! empty( $config["intl"] ) ) {
            // International: 7–15 digits (E.164 caps at 15), optional leading +.
            if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
                return [ __( "Enter a valid international phone number.", "trinity-forms" ) ];
            }
        } elseif ( strlen( $digits ) < 7 ) {
            return [ __( "Enter a valid phone number.", "trinity-forms" ) ];
        }
        return [];
    }
}
