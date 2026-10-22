<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * A URL field.
 *
 * People type "example.com" far more often than "https://example.com", so a bare
 * host gets a scheme rather than a validation error — the alternative is a form
 * that rejects the thing everybody types.
 */
class Field_Website extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id          = esc_attr( $config["id"] ?? "" );
        $name        = esc_attr( $this->attr_name( $config ) );
        $placeholder = esc_attr( $config["placeholder"] ?? "https://example.com" );
        $required    = ! empty( $config["required"] ) ? " required" : "";
        $val         = esc_attr( (string) ( $value ?? "" ) );

        $control = '<input type="url" id="' . $id . '" name="' . $name . '" value="' . $val . '"'
            . ' placeholder="' . $placeholder . '"' . $required . $this->common_attrs( $config ) . " />";

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        $raw = trim( (string) $raw );
        if ( "" === $raw ) {
            return "";
        }

        // Match ANY scheme, not just "scheme://". Testing for the slashes let
        // "javascript:alert(1)" through as scheme-less and turned it into
        // "https://javascript:alert(1)" — harmless as a link, but nonsense to store.
        if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $raw, $m ) ) {
            if ( ! in_array( strtolower( $m[1] ), [ "http", "https" ], true ) ) {
                // Returned unchanged so validate() can say "that isn't a web
                // address" — blanking it here would look like the form ate the input.
                return $raw;
            }
        } else {
            // esc_url_raw would add "http://" itself; doing it here keeps the choice
            // of scheme ours.
            $raw = "https://" . $raw;
        }

        return esc_url_raw( $raw, [ "http", "https" ] );
    }

    public function validate( $value, array $config ): array {
        $value = (string) $value;
        if ( "" === $value ) {
            return [];
        }
        // FILTER_VALIDATE_URL alone is not enough: "mailto:a@b.com" is a perfectly
        // valid URL and a perfectly wrong answer for a field labelled Website.
        $scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
        if ( ! filter_var( $value, FILTER_VALIDATE_URL ) || ! in_array( $scheme, [ "http", "https" ], true ) ) {
            return [ __( "Please enter a valid web address.", "trinity-forms" ) ];
        }
        return [];
    }
}
