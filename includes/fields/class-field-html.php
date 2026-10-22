<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_HTML extends Field_Base {

    public function render( array $config, $value = null ): string {
        return '<div class="trinity-forms-field trinity-forms-field-html">' . wp_kses_post( $config["html"] ?? "" ) . '</div>';
    }

    public function sanitize( $raw, array $config ) {
        return null;
    }

    public function validate( $value, array $config ): array {
        return [];
    }
}
