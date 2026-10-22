<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * A delivery charge — either a flat amount or a choice of methods.
 */
class Field_Shipping extends Field_Pricing_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $current  = (string) ( $value ?? "" );
        $options  = (array) ( $config["options"] ?? [] );

        if ( ! $options ) {
            $price    = $this->price_of( $config );
            $control  = '<input type="hidden" id="' . $id . '" name="' . $name . '" value="flat" />';
            $control .= '<p class="trinity-forms-price" data-price="' . esc_attr( (string) $price ) . '">'
                . esc_html( $this->format_price( $price ) ) . "</p>";
            return $this->wrap( $config, $control );
        }

        $items = "";
        foreach ( $options as $i => $opt ) {
            $key      = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $label    = (string) ( $opt["label"] ?? $key );
            $price    = $this->price_of( (array) $opt );
            $input_id = $id . "_" . (int) $i;

            $items .= '<label class="trinity-forms-radio" for="' . esc_attr( $input_id ) . '">'
                . '<input type="radio" id="' . esc_attr( $input_id ) . '" name="' . $name . '"'
                . ' value="' . esc_attr( $key ) . '"' . checked( $current, $key, false )
                . ' data-price="' . esc_attr( (string) $price ) . '"' . $required . " />"
                . "<span>" . esc_html( $label . " — " . $this->format_price( $price ) ) . "</span></label>";
        }

        return $this->wrap( $config, '<div class="trinity-forms-radio-group" role="radiogroup">' . $items . "</div>" );
    }

    /**
     * @param array<string,mixed> $config
     * @param mixed               $value
     */
    public static function resolve_price( array $config, $value ): float {
        $self    = new self();
        $options = (array) ( $config["options"] ?? [] );

        if ( ! $options ) {
            return $self->price_of( $config );
        }

        $chosen = (string) $value;
        foreach ( $options as $opt ) {
            if ( (string) ( $opt["value"] ?? $opt["label"] ?? "" ) === $chosen ) {
                return $self->price_of( (array) $opt );
            }
        }
        return 0.0;
    }
}
