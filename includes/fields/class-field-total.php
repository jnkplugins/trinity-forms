<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * The order total.
 *
 * Renders read-only and posts nothing worth trusting — Submission_Pipeline
 * overwrites whatever arrives with Pricing::calculate(). The visible input exists
 * so the visitor can see what they are agreeing to; the number that gets stored is
 * the one the server worked out.
 */
class Field_Total extends Field_Pricing_Base {

    public function render( array $config, $value = null ): string {
        $id   = esc_attr( $config["id"] ?? "" );
        $name = esc_attr( $this->attr_name( $config ) );

        // readonly rather than disabled: disabled inputs are not submitted at all,
        // and the running total is updated by the front-end script.
        $control = '<div class="trinity-forms-total">'
            . '<output class="trinity-forms-total-display" for="' . $id . '">'
            . esc_html( $this->format_price( 0.0 ) ) . "</output>"
            . '<input type="hidden" id="' . $id . '" name="' . $name . '" value="0"'
            . ' data-total="1" data-currency="' . esc_attr( $this->currency_symbol() ) . '" />'
            . "</div>";

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        // Kept numeric for tidiness only; the pipeline replaces it regardless.
        return (string) (float) preg_replace( '/[^0-9.\-]/', "", (string) $raw );
    }
}
