<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * Choices shown as pictures rather than radio dots.
 *
 * The underlying control is still a real radio or checkbox input — the image is a
 * label for it. That keeps keyboard navigation, screen readers and browser
 * validation working, which a div-and-JavaScript version quietly loses.
 */
class Field_Image_Choice extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $multiple = ! empty( $config["multiple"] );
        $type     = $multiple ? "checkbox" : "radio";
        $name     = esc_attr( $this->attr_name( $config ) ) . ( $multiple ? "[]" : "" );
        $required = ! empty( $config["required"] ) && ! $multiple ? " required" : "";

        $current = $multiple
            ? array_map( "strval", (array) ( $value ?? [] ) )
            : [ (string) ( $value ?? "" ) ];

        $items = "";
        foreach ( (array) ( $config["options"] ?? [] ) as $i => $opt ) {
            $opt_value = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $opt_label = (string) ( $opt["label"] ?? $opt_value );
            $opt_image = (string) ( $opt["image"] ?? "" );
            $input_id  = $id . "_" . (int) $i;
            $checked   = in_array( $opt_value, $current, true ) ? " checked" : "";

            $items .= '<label class="trinity-forms-image-choice" for="' . esc_attr( $input_id ) . '">';
            $items .= '<input type="' . $type . '" id="' . esc_attr( $input_id ) . '" name="' . $name . '"'
                . ' value="' . esc_attr( $opt_value ) . '"' . $checked . $required . " />";
            if ( "" !== $opt_image ) {
                // Decorative: the visible text label right below carries the meaning,
                // so an alt repeating it would just be read out twice.
                $items .= '<img src="' . esc_url( $opt_image ) . '" alt="" loading="lazy" />';
            }
            $items .= '<span class="trinity-forms-image-choice-label">' . esc_html( $opt_label ) . "</span>";
            $items .= "</label>";
        }

        $control = '<div class="trinity-forms-image-choices" role="group">' . $items . "</div>";
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        if ( ! empty( $config["multiple"] ) ) {
            $raw = is_array( $raw ) ? $raw : ( ( null === $raw || "" === $raw ) ? [] : [ $raw ] );
            return array_values( array_filter( array_map(
                static fn( $v ) => sanitize_text_field( (string) $v ),
                $raw
            ), static fn( $v ) => "" !== $v ) );
        }
        return sanitize_text_field( (string) ( is_array( $raw ) ? reset( $raw ) : $raw ) );
    }

    public function validate( $value, array $config ): array {
        if ( empty( $config["options"] ) ) {
            return [];
        }
        $allowed  = array_map(
            static fn( $o ) => (string) ( $o["value"] ?? $o["label"] ?? "" ),
            $config["options"]
        );
        $selected = array_filter( array_map( "strval", (array) $value ), static fn( $v ) => "" !== $v );

        foreach ( $selected as $v ) {
            if ( ! in_array( $v, $allowed, true ) ) {
                return [ __( "Invalid selection.", "trinity-forms" ) ];
            }
        }
        return [];
    }
}
