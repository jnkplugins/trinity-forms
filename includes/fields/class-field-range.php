<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * Range slider field. A numeric input rendered as a slider with a live value
 * readout, reusing the number field's min/max/step semantics plus a starting
 * "default" value. The submitted value is stored as a number, like Field_Number.
 */
class Field_Range extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $min      = isset( $config["min"] )  && $config["min"]  !== "" ? (string) $config["min"]  : "0";
        $max      = isset( $config["max"] )  && $config["max"]  !== "" ? (string) $config["max"]  : "100";
        $step     = isset( $config["step"] ) && $config["step"] !== "" ? (string) $config["step"] : "1";
        $default  = isset( $config["default"] ) && $config["default"] !== "" ? (string) $config["default"] : $min;
        $val      = ( $value === null || $value === "" ) ? $default : (string) $value;

        // Native oninput mirrors the slider value into the adjacent <output>; the
        // field still submits correctly with JS disabled (no live readout only).
        $control  = '<span class="trinity-forms-range">'
            . '<input type="range" id="' . $id . '" name="' . $name . '" value="' . esc_attr( $val ) . '"'
            . ' min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" step="' . esc_attr( $step ) . '"' . $required
            . ' oninput="var o=this.nextElementSibling;if(o){o.value=this.value;}" />'
            . '<output class="trinity-forms-range-value" for="' . $id . '">' . esc_html( $val ) . '</output>'
            . '</span>';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        if ( $raw === null || $raw === "" ) {
            return "";
        }
        $clean = preg_replace( "/[^0-9.\-]/", "", (string) $raw );
        if ( $clean === "" ) {
            return "";
        }
        return strpos( $clean, "." ) !== false ? (float) $clean : (int) $clean;
    }

    public function validate( $value, array $config ): array {
        $errors = [];
        if ( $value === "" || $value === null ) {
            return $errors;
        }
        if ( ! is_numeric( $value ) ) {
            return [ __( "Must be a number.", "trinity-forms" ) ];
        }
        $num = (float) $value;
        if ( isset( $config["min"] ) && $config["min"] !== "" && $num < (float) $config["min"] ) {
            /* translators: %s: minimum allowed value. */
            $errors[] = sprintf( __( "Must be at least %s.", "trinity-forms" ), $config["min"] );
        }
        if ( isset( $config["max"] ) && $config["max"] !== "" && $num > (float) $config["max"] ) {
            /* translators: %s: maximum allowed value. */
            $errors[] = sprintf( __( "Must be no more than %s.", "trinity-forms" ), $config["max"] );
        }
        return $errors;
    }
}
