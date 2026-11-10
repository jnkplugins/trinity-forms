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

        // type="text" + inputmode="url", not type="url": the browser's URL check
        // refused "ejemplo.es" (which sanitize() turns into https://ejemplo.es)
        // before it reached us. The phone keyboard is the same; the pattern only
        // asks for something with a dot in it, and the server does the real check.
        $control = '<input type="text" inputmode="url" id="' . $id . '" name="' . $name . '" value="' . $val . '"'
            . ' placeholder="' . $placeholder . '" pattern="\S*\.\S*" spellcheck="false" autocapitalize="none"'
            . $required . $this->common_attrs( $config ) . " />";

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        $raw = trim( self::text( $raw ) );
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

    /**
     * The URL as FILTER_VALIDATE_URL can read it.
     *
     * The filter only knows ASCII, so https://ejemplo.es/ñ and an Arabic Wikipedia
     * page were refused while their percent-encoded twins passed. The host goes
     * through IDNA (Punycode) and anything else non-ASCII is percent-encoded, for
     * the check only: the address is stored as the visitor typed it.
     */
    public static function ascii_url( string $url ): string {
        if ( ! preg_match( '/[^\x00-\x7F]/', $url ) ) {
            return $url;
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( "" !== $host && preg_match( '/[^\x00-\x7F]/', $host ) ) {
            $ascii = function_exists( "idn_to_ascii" ) ? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;
            if ( ! is_string( $ascii ) || "" === $ascii ) {
                // No intl: any well-formed label will do for a shape check.
                $ascii = (string) preg_replace( '/[^\x00-\x7F]+/', "xn--a", $host );
            }
            $pos = strpos( $url, $host );
            if ( false !== $pos ) {
                $url = substr_replace( $url, $ascii, $pos, strlen( $host ) );
            }
        }
        return (string) preg_replace_callback( '/[^\x00-\x7F]+/', static fn( $m ) => rawurlencode( $m[0] ), $url );
    }

    public function validate( $value, array $config ): array {
        $value = (string) $value;
        if ( "" === $value ) {
            return [];
        }
        // FILTER_VALIDATE_URL alone is not enough: "mailto:a@b.com" is a perfectly
        // valid URL and a perfectly wrong answer for a field labelled Website.
        $scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
        if ( ! filter_var( self::ascii_url( $value ), FILTER_VALIDATE_URL ) || ! in_array( $scheme, [ "http", "https" ], true ) ) {
            return [ __( "Please enter a valid web address.", "trinity-forms" ) ];
        }
        return [];
    }
}
