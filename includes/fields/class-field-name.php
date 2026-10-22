<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * Name field — first + last sub-inputs under one field id. Stored as a single
 * readable string ("First Last") so entry detail, CSV export and merge tags all
 * render it cleanly (the array shape is only used transiently on input).
 */
class Field_Name extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id    = esc_attr( $config["id"] ?? "" );
        $base  = esc_attr( $this->attr_name( $config ) );
        $req   = ! empty( $config["required"] ) ? " required" : "";
        // On error re-render the value may be an array (sub-values) or the joined string.
        $first = "";
        $last  = "";
        if ( is_array( $value ) ) {
            $first = (string) ( $value["first"] ?? "" );
            $last  = (string) ( $value["last"]  ?? "" );
        } elseif ( is_string( $value ) && $value !== "" ) {
            $parts = explode( " ", $value, 2 );
            $first = $parts[0] ?? "";
            $last  = $parts[1] ?? "";
        }
        // Real <label>s rather than placeholders alone: a placeholder is not an
        // accessible name, and it disappears the moment somebody starts typing.
        // sub_label() honours the form's sub-label placement, including "hidden",
        // which hides them visually but keeps them for screen readers.
        $above = "above" === (string) ( $config["sub_label_placement"] ?? "below" );

        $first_label = $this->sub_label( $config, $id . "_first", __( "First", "trinity-forms" ) );
        $last_label  = $this->sub_label( $config, $id . "_last", __( "Last", "trinity-forms" ) );

        // The placeholder only when the sub-labels are hidden. With a visible
        // sub-label it said "First" / "Last" twice, once in the box and once under it.
        $hidden      = "hidden" === (string) ( $config["sub_label_placement"] ?? "below" );
        $first_ph    = $hidden ? ' placeholder="' . esc_attr__( "First", "trinity-forms" ) . '"' : "";
        $last_ph     = $hidden ? ' placeholder="' . esc_attr__( "Last", "trinity-forms" ) . '"' : "";
        $first_input = '<input type="text" id="' . $id . '_first" name="' . $base . '[first]" value="' . esc_attr( $first )
            . '"' . $first_ph . $req . " />";
        $last_input = '<input type="text" id="' . $id . '_last" name="' . $base . '[last]" value="' . esc_attr( $last )
            . '"' . $last_ph . $req . " />";

        $control = '<span class="trinity-forms-name">'
            . '<span class="trinity-forms-subfield">'
                . ( $above ? $first_label . $first_input : $first_input . $first_label )
            . "</span>"
            . '<span class="trinity-forms-subfield">'
                . ( $above ? $last_label . $last_input : $last_input . $last_label )
            . "</span>"
            . "</span>";

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        // First call: array of sub-values. Second (idempotent) call: the joined string.
        if ( is_array( $raw ) ) {
            $first = sanitize_text_field( (string) ( $raw["first"] ?? "" ) );
            $last  = sanitize_text_field( (string) ( $raw["last"]  ?? "" ) );
            return trim( $first . " " . $last );
        }
        return sanitize_text_field( (string) ( $raw ?? "" ) );
    }

    public function validate( $value, array $config ): array {
        // "Required" is enforced by the Field wrapper (is_empty on the joined string).
        return [];
    }
}
