<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Textarea extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $rows     = (int) ( $config["rows"] ?? 4 );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $val      = esc_textarea( (string) ( $value ?? "" ) );
        $control  = '<textarea id="' . $id . '" name="' . $name . '" rows="' . $rows . '"' . $required . '>' . $val . '</textarea>';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        return sanitize_textarea_field( (string) $raw );
    }

    public function validate( $value, array $config ): array {
        return $this->length_errors( $value, $config );
    }
}
