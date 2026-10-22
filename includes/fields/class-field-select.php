<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Select extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $current  = (string) ( $value ?? "" );
        $options  = "";
        if ( ! empty( $config["placeholder"] ) ) {
            $options .= '<option value="">' . esc_html( $config["placeholder"] ) . '</option>';
        }
        foreach ( ( $config["options"] ?? [] ) as $opt ) {
            $opt_value = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $opt_label = (string) ( $opt["label"] ?? $opt_value );
            $sel       = selected( $current, $opt_value, false );
            $off       = ! empty( $opt["disabled"] );
            $note      = (string) ( $opt["note"] ?? "" );
            $options  .= '<option value="' . esc_attr( $opt_value ) . '"' . ( $off ? " disabled" : $sel ) . '>' . esc_html( $opt_label . ( "" !== $note ? " (" . $note . ")" : "" ) ) . '</option>';
        }
        $control = '<select id="' . $id . '" name="' . $name . '"' . $required . '>' . $options . '</select>';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        return sanitize_text_field( (string) $raw );
    }

    public function validate( $value, array $config ): array {
        if ( $value === "" || $value === null || empty( $config["options"] ) ) {
            return [];
        }
        $allowed = array_map( static fn( $o ) => (string) ( $o["value"] ?? $o["label"] ?? "" ), $config["options"] );
        if ( ! in_array( (string) $value, $allowed, true ) ) {
            return [ __( "Invalid selection.", "trinity-forms" ) ];
        }
        return [];
    }
}
