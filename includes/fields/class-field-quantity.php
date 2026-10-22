<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * How many of a product. Multiplies the product field it is bound to.
 */
class Field_Quantity extends Field_Pricing_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $min      = isset( $config["min"] ) ? (int) $config["min"] : 0;
        $max      = isset( $config["max"] ) && "" !== $config["max"] ? (int) $config["max"] : 0;
        $val      = "" === (string) ( $value ?? "" ) ? (string) $min : (string) (int) $value;

        $control = '<input type="number" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $val ) . '"'
            . ' min="' . (int) $min . '"' . ( $max > 0 ? ' max="' . (int) $max . '"' : "" )
            . ' step="1" data-product="' . esc_attr( (string) ( $config["product_field"] ?? "" ) ) . '"'
            . $required . " />";

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        return "" === (string) $raw ? "" : (string) max( 0, (int) $raw );
    }

    public function validate( $value, array $config ): array {
        if ( "" === (string) $value ) {
            return [];
        }
        $qty = (int) $value;
        $min = isset( $config["min"] ) ? (int) $config["min"] : 0;
        $max = isset( $config["max"] ) && "" !== $config["max"] ? (int) $config["max"] : 0;

        if ( $qty < $min ) {
            /* translators: %d: minimum quantity. */
            return [ sprintf( __( "Please enter a quantity of at least %d.", "trinity-forms" ), $min ) ];
        }
        if ( $max > 0 && $qty > $max ) {
            /* translators: %d: maximum quantity. */
            return [ sprintf( __( "Please enter a quantity of %d or fewer.", "trinity-forms" ), $max ) ];
        }
        return [];
    }
}
