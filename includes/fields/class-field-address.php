<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * Address field — street, line 2, city, state/region, postal code and country
 * sub-inputs under one field id. Stored as a single readable comma-joined string
 * so entry detail, CSV export and merge tags render it cleanly.
 */
class Field_Address extends Field_Base {

    /** Ordered sub-parts: key => [ label, autocomplete token ]. */
    private function parts(): array {
        return [
            "line1"   => [ __( "Street address", "trinity-forms" ),  "address-line1" ],
            "line2"   => [ __( "Address line 2", "trinity-forms" ),  "address-line2" ],
            "city"    => [ __( "City", "trinity-forms" ),            "address-level2" ],
            "state"   => [ __( "State / Region", "trinity-forms" ),  "address-level1" ],
            "postal"  => [ __( "Postal code", "trinity-forms" ),     "postal-code" ],
            "country" => [ __( "Country", "trinity-forms" ),         "country-name" ],
        ];
    }

    public function render( array $config, $value = null ): string {
        $id   = esc_attr( $config["id"] ?? "" );
        $base = esc_attr( $this->attr_name( $config ) );
        $req  = ! empty( $config["required"] ) ? " required" : "";
        $vals = is_array( $value ) ? $value : [];

        $rows = "";
        foreach ( $this->parts() as $key => [ $label, $ac ] ) {
            // Only line1, city and country carry the "required" flag when the field is required.
            $r  = ( $req && in_array( $key, [ "line1", "city", "country" ], true ) ) ? " required" : "";
            $v  = esc_attr( (string) ( $vals[ $key ] ?? "" ) );
            $rows .= '<span class="trinity-forms-subfield trinity-forms-address-' . esc_attr( $key ) . '">'
                . '<input type="text" id="' . $id . '_' . esc_attr( $key ) . '" name="' . $base . '[' . esc_attr( $key ) . ']" value="' . $v . '"'
                . ' placeholder="' . esc_attr( $label ) . '" autocomplete="' . esc_attr( $ac ) . '"' . $r . ' />'
                . '</span>';
        }
        return $this->wrap( $config, '<span class="trinity-forms-address">' . $rows . '</span>' );
    }

    public function sanitize( $raw, array $config ) {
        // First call: array of sub-values. Second (idempotent) call: the joined string.
        if ( ! is_array( $raw ) ) {
            return sanitize_text_field( (string) ( $raw ?? "" ) );
        }
        $clean = [];
        foreach ( array_keys( $this->parts() ) as $key ) {
            $part = sanitize_text_field( (string) ( $raw[ $key ] ?? "" ) );
            if ( $part !== "" ) {
                $clean[] = $part;
            }
        }
        return implode( ", ", $clean );
    }

    public function validate( $value, array $config ): array {
        // "Required" is enforced by the Field wrapper (is_empty on the joined string).
        return [];
    }
}
