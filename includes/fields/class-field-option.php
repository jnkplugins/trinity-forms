<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * An add-on that adjusts the price of a product — gift wrap, express delivery,
 * a larger size. Same storage shape as a choice field; the price lives in config.
 */
class Field_Option extends Field_Pricing_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $multiple = ! empty( $config["multiple"] );
        $name     = esc_attr( $this->attr_name( $config ) ) . ( $multiple ? "[]" : "" );
        $required = ! empty( $config["required"] ) && ! $multiple ? " required" : "";
        $current  = $multiple
            ? array_map( "strval", (array) ( $value ?? [] ) )
            : [ (string) ( $value ?? "" ) ];

        $items = "";
        foreach ( (array) ( $config["options"] ?? [] ) as $i => $opt ) {
            $key      = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $label    = (string) ( $opt["label"] ?? $key );
            $price    = $this->price_of( (array) $opt );
            $input_id = $id . "_" . (int) $i;
            $checked  = in_array( $key, $current, true ) ? " checked" : "";
            $shown    = $price > 0 ? $label . " (+" . $this->format_price( $price ) . ")" : $label;

            $items .= '<label class="trinity-forms-' . ( $multiple ? "checkbox" : "radio" ) . '" for="' . esc_attr( $input_id ) . '">'
                . '<input type="' . ( $multiple ? "checkbox" : "radio" ) . '" id="' . esc_attr( $input_id ) . '"'
                . ' name="' . $name . '" value="' . esc_attr( $key ) . '"' . $checked
                . ' data-price="' . esc_attr( (string) $price ) . '"'
                . ' data-product="' . esc_attr( (string) ( $config["product_field"] ?? "" ) ) . '"' . $required . " />"
                . "<span>" . esc_html( $shown ) . "</span></label>";
        }

        $group   = $multiple ? "trinity-forms-checkbox-group" : "trinity-forms-radio-group";
        $control = '<div class="' . $group . '">' . $items . "</div>";

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

    /**
     * Total price of the chosen options.
     *
     * @param array<string,mixed> $config
     * @param mixed               $value
     */
    public static function resolve_price( array $config, $value ): float {
        $self     = new self();
        $selected = array_map( "strval", (array) $value );
        $total    = 0.0;

        foreach ( (array) ( $config["options"] ?? [] ) as $opt ) {
            $key = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            if ( in_array( $key, $selected, true ) ) {
                $total += $self->price_of( (array) $opt );
            }
        }
        return $total;
    }
}
