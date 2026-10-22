<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Hidden extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id   = esc_attr( $config["id"] ?? "" );
        $name = esc_attr( $this->attr_name( $config ) );
        $val  = esc_attr( (string) ( $value ?? $config["default"] ?? "" ) );
        return '<input type="hidden" id="' . $id . '" name="' . $name . '" value="' . $val . '" />';
    }

    public function sanitize( $raw, array $config ) {
        return sanitize_text_field( (string) $raw );
    }

    public function validate( $value, array $config ): array {
        return [];
    }
}
