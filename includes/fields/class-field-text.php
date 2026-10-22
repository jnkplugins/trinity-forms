<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Text extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id          = esc_attr( $config["id"] ?? "" );
        $name        = esc_attr( $this->attr_name( $config ) );
        $placeholder = esc_attr( $config["placeholder"] ?? "" );
        $required    = ! empty( $config["required"] ) ? " required" : "";
        $val         = esc_attr( (string) ( $value ?? "" ) );
        $control     = '<input type="text" id="' . $id . '" name="' . $name . '" value="' . $val . '" placeholder="' . $placeholder . '"' . $required . ' />';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        return sanitize_text_field( (string) $raw );
    }

    public function validate( $value, array $config ): array {
        return $this->length_errors( $value, $config );
    }
}
