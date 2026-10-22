<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * A multi-select dropdown.
 *
 * The compact answer to "pick several from a long list" — checkboxes are better
 * for five options, worse for fifty.
 */
class Field_Multiselect extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        // The [] suffix is what makes PHP collect several values under one key.
        $name     = esc_attr( $this->attr_name( $config ) ) . "[]";
        $required = ! empty( $config["required"] ) ? " required" : "";
        $size     = max( 3, min( 12, count( (array) ( $config["options"] ?? [] ) ) ) );

        $current = array_map( "strval", (array) ( $value ?? [] ) );
        $options = "";
        foreach ( ( $config["options"] ?? [] ) as $opt ) {
            $opt_value = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $opt_label = (string) ( $opt["label"] ?? $opt_value );
            $sel       = in_array( $opt_value, $current, true ) ? " selected" : "";
            $options  .= '<option value="' . esc_attr( $opt_value ) . '"' . $sel . ">" . esc_html( $opt_label ) . "</option>";
        }

        $control = '<select multiple id="' . $id . '" name="' . $name . '" size="' . (int) $size . '"'
            . $required . $this->common_attrs( $config ) . ">" . $options . "</select>";

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        if ( ! is_array( $raw ) ) {
            $raw = ( null === $raw || "" === $raw ) ? [] : [ $raw ];
        }
        return array_values( array_filter( array_map(
            static fn( $v ) => sanitize_text_field( (string) $v ),
            $raw
        ), static fn( $v ) => "" !== $v ) );
    }

    public function validate( $value, array $config ): array {
        $value = (array) $value;
        if ( ! $value || empty( $config["options"] ) ) {
            return [];
        }

        $allowed = array_map(
            static fn( $o ) => (string) ( $o["value"] ?? $o["label"] ?? "" ),
            $config["options"]
        );
        foreach ( $value as $v ) {
            if ( ! in_array( (string) $v, $allowed, true ) ) {
                return [ __( "Invalid selection.", "trinity-forms" ) ];
            }
        }

        $max = isset( $config["max_choices"] ) ? (int) $config["max_choices"] : 0;
        if ( $max > 0 && count( $value ) > $max ) {
            return [ sprintf(
                /* translators: %d: maximum number of choices. */
                __( "Please choose no more than %d options.", "trinity-forms" ),
                $max
            ) ];
        }

        return [];
    }
}
