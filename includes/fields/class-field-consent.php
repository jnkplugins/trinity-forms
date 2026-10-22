<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_Consent extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id      = esc_attr( $config["id"] ?? "" );
        $name    = esc_attr( $this->attr_name( $config ) );
        $text    = wp_kses_post( $config["consent_text"] ?? $config["label"] ?? __( "I agree.", "trinity-forms" ) );
        $checked = ! empty( $value ) ? " checked" : "";
        $req     = ! empty( $config["required"] ) ? " required" : "";
        // The same required marker as every other field; the box used to be
        // required with nothing on screen saying so.
        $control = '<label class="trinity-forms-consent"><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $checked . $req . ' /> ' . $text . self::required_marker( $config, ! empty( $config["required"] ) ) . '</label>';
        return '<div class="trinity-forms-field trinity-forms-field-consent">' . $control . '</div>';
    }

    public function sanitize( $raw, array $config ) {
        return ! empty( $raw ) ? 1 : 0;
    }

    public function validate( $value, array $config ): array {
        return [];
    }

    public function to_csv_value( $stored ): string {
        return ! empty( $stored ) ? "yes" : "no";
    }
}
