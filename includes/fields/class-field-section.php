<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Section extends Field_Base {

    public function render( array $config, $value = null ): string {
        $heading = esc_html( $config["label"] ?? "" );
        $desc    = wp_kses_post( $config["description"] ?? "" );
        return '<div class="trinity-forms-field trinity-forms-field-section">'
            . ( $heading !== "" ? "<h3>{$heading}</h3>" : "" )
            . ( $desc    !== "" ? "<p>{$desc}</p>"     : "" )
            . '</div>';
    }

    public function sanitize( $raw, array $config ) {
        return null;
    }

    public function validate( $value, array $config ): array {
        return [];
    }
}
