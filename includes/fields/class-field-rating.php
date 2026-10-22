<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Rating extends Field_Base {

    public function render( array $config, $value = null ): string {
        $name    = esc_attr( $this->attr_name( $config ) );
        $current = (int) ( $value ?? 0 );
        $max     = (int) ( $config["max"] ?? 5 );
        $items   = "";
        // Stars, not bare numbered radios. The radios stay (keyboard and screen
        // readers use them as normal) but are hidden visually; each label shows a
        // star and says "3 of 5" to assistive tech. public.css fills the stars up to
        // the chosen one, and previews on hover.
        for ( $i = 1; $i <= $max; $i++ ) {
            $checked = checked( $current, $i, false );
            $items  .= '<label class="trinity-forms-star">'
                . '<input type="radio" name="' . $name . '" value="' . $i . '"' . $checked . ' />'
                . '<span class="trinity-forms-star-icon" aria-hidden="true">&#9733;</span>'
                . '<span class="trinity-forms-sr">' . esc_html( sprintf(
                    /* translators: 1: rating chosen, 2: highest rating. */
                    __( '%1$d of %2$d', 'trinity-forms' ),
                    $i,
                    $max
                ) ) . '</span>'
                . '</label>';
        }
        return $this->wrap( $config, '<div class="trinity-forms-rating" role="radiogroup">' . $items . '</div>' );
    }

    public function sanitize( $raw, array $config ) {
        if ( $raw === "" || $raw === null ) {
            return "";
        }
        return (int) $raw;
    }

    public function validate( $value, array $config ): array {
        if ( $value === "" || $value === null ) {
            return [];
        }
        $max = (int) ( $config["max"] ?? 5 );
        if ( ! is_numeric( $value ) || (int) $value < 1 || (int) $value > $max ) {
            return [ __( "Invalid rating.", "trinity-forms" ) ];
        }
        return [];
    }
}
