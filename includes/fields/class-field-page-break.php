<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Page_Break extends Field_Base {

    public function render( array $config, $value = null ): string {
        return "";
    }

    public function sanitize( $raw, array $config ) {
        return null;
    }

    public function validate( $value, array $config ): array {
        return [];
    }
}
