<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Radio extends Field_Base {

    public function render( array $config, $value = null ): string {
        $name    = esc_attr( $this->attr_name( $config ) );
        $current = (string) ( $value ?? "" );
        $items   = "";
        foreach ( ( $config["options"] ?? [] ) as $i => $opt ) {
            $opt_value = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $opt_label = (string) ( $opt["label"] ?? $opt_value );
            $oid       = esc_attr( ( $config["id"] ?? "" ) . "_" . $i );
            $checked   = checked( $current, $opt_value, false );
            // An option marked disabled (e.g. sold out) is shown but cannot be picked;
            // "note" is extra text after the label, such as "3 left".
            $off       = ! empty( $opt["disabled"] );
            $note      = (string) ( $opt["note"] ?? "" );
            $items    .= '<label class="trinity-forms-radio' . ( $off ? " is-disabled" : "" ) . '"><input type="radio" id="' . $oid . '" name="' . $name . '" value="' . esc_attr( $opt_value ) . '"' . ( $off ? "" : $checked ) . ( $off ? " disabled" : "" ) . ' /> ' . esc_html( $opt_label )
                . ( "" !== $note ? ' <span class="trinity-forms-option-note">' . esc_html( $note ) . "</span>" : "" ) . '</label>';
        }
        return $this->wrap( $config, '<div class="trinity-forms-radio-group">' . $items . '</div>' );
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
