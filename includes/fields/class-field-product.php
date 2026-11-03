<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * A priced item on the form.
 *
 * Three shapes, all posting the chosen product's *key* rather than its price — the
 * amount is looked up from the stored config at submission, so editing the number
 * in devtools changes nothing.
 */
class Field_Product extends Field_Pricing_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $mode     = (string) ( $config["product_type"] ?? "single" );
        $current  = (string) ( $value ?? "" );

        if ( "single" === $mode ) {
            $price = $this->price_of( $config );
            // A hidden input, because there is nothing to choose — the field exists
            // to put a fixed line item on the order.
            // A "note" (Pro stock: "sold out", "3 left") follows the price; a sold-out
            // product posts nothing, so it adds nothing to the order.
            $note     = (string) ( $config["note"] ?? "" );
            $control  = ! empty( $config["disabled"] ) ? "" : '<input type="hidden" id="' . $id . '" name="' . $name . '" value="single" />';
            $control .= '<p class="trinity-forms-price" data-price="' . esc_attr( (string) ( ! empty( $config["disabled"] ) ? 0 : $price ) ) . '">'
                . "<bdi>" . esc_html( $this->format_price( $price ) ) . "</bdi>"
                . ( "" !== $note ? ' <span class="trinity-forms-option-note">' . esc_html( $note ) . "</span>" : "" ) . "</p>";
            return $this->wrap( $config, $control );
        }

        $options = "";
        $radios  = "";
        foreach ( (array) ( $config["options"] ?? [] ) as $i => $opt ) {
            $key   = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            $label = (string) ( $opt["label"] ?? $key );
            $price = $this->price_of( (array) $opt );
            $shown = $label . " — " . $this->format_price( $price );
            // Shown but not pickable when disabled (Pro stock: sold out); "note" after it.
            $off   = ! empty( $opt["disabled"] );
            $note  = (string) ( $opt["note"] ?? "" );
            if ( "" !== $note ) {
                $shown .= " (" . $note . ")";
            }

            if ( "select" === $mode ) {
                $options .= '<option value="' . esc_attr( $key ) . '"' . ( $off ? " disabled" : selected( $current, $key, false ) )
                    . ' data-price="' . esc_attr( (string) $price ) . '">' . esc_html( $shown ) . "</option>";
            } else {
                $input_id = $id . "_" . (int) $i;
                $radios  .= '<label class="trinity-forms-radio" for="' . esc_attr( $input_id ) . '">'
                    . '<input type="radio" id="' . esc_attr( $input_id ) . '" name="' . $name . '"'
                    . ' value="' . esc_attr( $key ) . '"' . ( $off ? " disabled" : checked( $current, $key, false ) . $required )
                    . ' data-price="' . esc_attr( (string) $price ) . '" />'
                    . "<span>" . esc_html( $shown ) . "</span></label>";
            }
        }

        $control = "select" === $mode
            ? '<select id="' . $id . '" name="' . $name . '"' . $required . ">"
                . '<option value="">' . esc_html__( "— select —", "trinity-forms" ) . "</option>"
                . $options . "</select>"
            : '<div class="trinity-forms-radio-group" role="radiogroup">' . $radios . "</div>";

        return $this->wrap( $config, $control );
    }

    /**
     * The price this field contributes, resolved from configuration.
     *
     * @param array<string,mixed> $config
     * @param mixed               $value
     */
    public static function resolve_price( array $config, $value ): float {
        $self = new self();
        $mode = (string) ( $config["product_type"] ?? "single" );

        if ( "single" === $mode ) {
            return $self->price_of( $config );
        }

        $chosen = (string) $value;
        foreach ( (array) ( $config["options"] ?? [] ) as $opt ) {
            $key = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            if ( $key === $chosen ) {
                return $self->price_of( (array) $opt );
            }
        }
        return 0.0;
    }
}
