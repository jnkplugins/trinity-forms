<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Time extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $val      = esc_attr( (string) ( $value ?? "" ) );
        $control  = '<input type="time" id="' . $id . '" name="' . $name . '" value="' . $val . '"' . $required . ' />';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        $raw = trim( (string) $raw );
        return preg_match( "/^\d{2}:\d{2}(:\d{2})?$/", $raw ) ? $raw : "";
    }

    public function validate( $value, array $config ): array {
        if ( $value === "" || $value === null ) {
            return [];
        }
        return preg_match( "/^\d{2}:\d{2}(:\d{2})?$/", (string) $value )
            ? []
            : [ __( "Invalid time.", "trinity-forms" ) ];
    }
}
