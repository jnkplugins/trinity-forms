<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Number extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $min      = isset( $config["min"] )  ? ' min="'  . esc_attr( $config["min"] )  . '"' : "";
        $max      = isset( $config["max"] )  ? ' max="'  . esc_attr( $config["max"] )  . '"' : "";
        $step     = isset( $config["step"] ) ? ' step="' . esc_attr( $config["step"] ) . '"' : "";
        $val      = esc_attr( (string) ( $value ?? "" ) );
        $control  = '<input type="number" id="' . $id . '" name="' . $name . '" value="' . $val . '"' . $min . $max . $step . $required . ' />';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        if ( $raw === null || $raw === "" ) {
            return "";
        }
        $clean = preg_replace( "/[^0-9.\-]/", "", self::ascii_digits( (string) $raw ) );
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
        if ( isset( $config["min"] ) && $num < (float) $config["min"] ) {
            /* translators: %s: minimum allowed value. */
            $errors[] = sprintf( __( "Must be at least %s.", "trinity-forms" ), $config["min"] );
        }
        if ( isset( $config["max"] ) && $num > (float) $config["max"] ) {
            /* translators: %s: maximum allowed value. */
            $errors[] = sprintf( __( "Must be no more than %s.", "trinity-forms" ), $config["max"] );
        }
        return $errors;
    }
}
