<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Checkbox extends Field_Base {

    public function render( array $config, $value = null ): string {
        $name    = esc_attr( $this->attr_name( $config ) ) . "[]";
        $current = is_array( $value )
            ? array_map( "strval", $value )
            : ( $value !== null && $value !== "" ? [ (string) $value ] : [] );
        $items   = "";
        foreach ( ( $config["options"] ?? [] ) as $i => $opt ) {
            $opt_value = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $opt_label = (string) ( $opt["label"] ?? $opt_value );
            $oid       = esc_attr( ( $config["id"] ?? "" ) . "_" . $i );
            $checked   = in_array( $opt_value, $current, true ) ? " checked" : "";
            // An option marked disabled (e.g. sold out) is shown but cannot be picked;
            // "note" is extra text after the label, such as "3 left".
            $off       = ! empty( $opt["disabled"] );
            $note      = (string) ( $opt["note"] ?? "" );
            $items    .= '<label class="trinity-forms-checkbox' . ( $off ? " is-disabled" : "" ) . '"><input type="checkbox" id="' . $oid . '" name="' . $name . '" value="' . esc_attr( $opt_value ) . '"' . ( $off ? "" : $checked ) . ( $off ? " disabled" : "" ) . ' /> ' . esc_html( $opt_label )
                . ( "" !== $note ? ' <span class="trinity-forms-option-note">' . esc_html( $note ) . "</span>" : "" ) . '</label>';
        }
        return $this->wrap( $config, '<div class="trinity-forms-checkbox-group">' . $items . '</div>' );
    }

    public function sanitize( $raw, array $config ) {
        if ( ! is_array( $raw ) ) {
            $raw = $raw === null || $raw === "" ? [] : [ $raw ];
        }
        return array_values( array_map( static fn( $v ) => sanitize_text_field( (string) $v ), $raw ) );
    }

    public function validate( $value, array $config ): array {
        if ( ! is_array( $value ) || empty( $config["options"] ) ) {
            return [];
        }
        $allowed = array_map( static fn( $o ) => (string) ( $o["value"] ?? $o["label"] ?? "" ), $config["options"] );
        foreach ( $value as $v ) {
            if ( ! in_array( (string) $v, $allowed, true ) ) {
                return [ __( "Invalid selection.", "trinity-forms" ) ];
            }
        }
        return [];
    }
}
