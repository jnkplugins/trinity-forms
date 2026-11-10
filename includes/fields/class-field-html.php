<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_HTML extends Field_Base {

    /** The placeholder older versions stored as the field's starting content. */
    public const OLD_DEFAULT = "<p>Custom HTML</p>";

    public function render( array $config, $value = null ): string {
        $html = trim( (string) ( $config["html"] ?? "" ) );
        // An HTML field nobody filled in printed "Custom HTML" to visitors. Empty,
        // or still the old English placeholder, it prints nothing.
        if ( "" === $html || self::OLD_DEFAULT === $html ) {
            return "";
        }
        return '<div class="trinity-forms-field trinity-forms-field-html">' . wp_kses_post( $html ) . '</div>';
    }

    public function sanitize( $raw, array $config ) {
        return null;
    }

    public function validate( $value, array $config ): array {
        return [];
    }
}
