<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Email extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id          = esc_attr( $config["id"] ?? "" );
        $name        = esc_attr( $this->attr_name( $config ) );
        $placeholder = esc_attr( $config["placeholder"] ?? "" );
        $required    = ! empty( $config["required"] ) ? " required" : "";
        $val         = esc_attr( (string) ( $value ?? "" ) );
        $control     = '<input type="email" id="' . $id . '" name="' . $name . '" value="' . $val . '" placeholder="' . $placeholder . '"' . $required . ' />';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        $raw = trim( (string) $raw );
        if ( "" === $raw ) {
            return "";
        }
        // Only normalize values that sanitize_email() leaves unchanged (i.e. already
        // clean addresses). For anything it would blank or rewrite (e.g. "notanemail"
        // -> "", or "a b@c.com" -> "ab@c.com"), keep the raw input so validate() can
        // surface a "valid email" error instead of silently dropping/mutating it.
        $sanitized = sanitize_email( $raw );
        return ( "" !== $sanitized && $sanitized === $raw ) ? $sanitized : $raw;
    }

    public function validate( $value, array $config ): array {
        $errors = [];
        if ( is_string( $value ) && $value !== "" && ! is_email( $value ) ) {
            $errors[] = __( "Please enter a valid email address.", "trinity-forms" );
        }
        return $errors;
    }
}
