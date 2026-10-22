<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

abstract class Field_Base {

    abstract public function render( array $config, $value = null ): string;
    abstract public function sanitize( $raw, array $config );
    abstract public function validate( $value, array $config ): array;

    public function to_csv_value( $stored ): string {
        return is_array( $stored ) ? implode( ", ", $stored ) : (string) $stored;
    }

    public function block_attributes(): array {
        return [];
    }

    /**
     * Standard label + control + help wrapper used by most field types.
     *
     * Every per-field presentation setting is applied here rather than in each of
     * the twenty-odd field classes — a css class, a column width or a description
     * position should not need twenty edits to add.
     */
    protected function wrap( array $config, string $control, ?string $help_override = null ): string {
        $id       = (string) ( $config["id"] ?? "" );
        $label    = (string) ( $config["label"] ?? "" );
        $help     = $help_override ?? (string) ( $config["help"] ?? "" );
        $required = ! empty( $config["required"] );

        $classes = [
            "trinity-forms-field",
            "trinity-forms-field-" . ( $config["type"] ?? "text" ),
        ];

        // Width is a layout choice expressed as a class so the stylesheet owns the
        // actual grid maths; anything unrecognised falls back to full width.
        $width = (string) ( $config["field_width"] ?? "" );
        if ( in_array( $width, [ "50", "33", "25" ], true ) ) {
            $classes[] = "trinity-forms-w-" . $width;
        }

        if ( ! empty( $config["css_class"] ) ) {
            // Author-supplied, so split and sanitize rather than trusting the string.
            foreach ( preg_split( '/\s+/', (string) $config["css_class"] ) as $custom ) {
                $custom = sanitize_html_class( $custom );
                if ( "" !== $custom ) {
                    $classes[] = $custom;
                }
            }
        }

        $help_html = "" !== $help
            ? '<small class="trinity-forms-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . "</small>"
            : "";
        $help_above = "above" === (string) ( $config["description_placement"] ?? "below" );

        // A grouped control (name parts, checkbox/radio groups, rating) has no single
        // element with this id, and <label for> pointing at nothing is invalid: the
        // label then names the group instead.
        $targets_control = "" !== $id && (bool) preg_match( '/\sid="' . preg_quote( esc_attr( $id ), "/" ) . '"/', $control );
        $group_attrs     = ( "" !== $label && ! $targets_control )
            ? ' role="group" aria-labelledby="' . esc_attr( $id ) . '-label"'
            : "";

        $out = '<div class="' . esc_attr( implode( " ", $classes ) ) . '" data-field-id="' . esc_attr( $id ) . '"' . $group_attrs . '>';
        if ( "" !== $label ) {
            $out .= $targets_control
                ? '<label for="' . esc_attr( $id ) . '">'
                : '<label id="' . esc_attr( $id ) . '-label">';
            $out .= esc_html( $label ) . self::required_marker( $config, $required );
            $out .= "</label>";
        }
        if ( $help_above ) {
            $out .= $help_html;
        }
        $out .= $control;
        if ( ! $help_above ) {
            $out .= $help_html;
        }
        $out .= "</div>";
        return $out;
    }

    /**
     * How a required field is marked.
     *
     * The asterisk is a convention, not a rule: some forms mark the optional ones
     * instead, and on a form where nearly everything is required a column of
     * asterisks is noise. The marker is aria-hidden because the input already
     * carries `required`, and announcing "star" after every label is worse than
     * saying nothing.
     *
     * @param array<string,mixed> $config
     */
    protected static function required_marker( array $config, bool $required ): string {
        $style = (string) ( $config["required_indicator"] ?? "asterisk" );

        if ( "none" === $style ) {
            return "";
        }

        if ( "optional" === $style ) {
            // Inverted: mark what may be skipped rather than what may not.
            return $required
                ? ""
                : ' <span class="trinity-forms-optional">(' . esc_html__( "optional", "trinity-forms" ) . ")</span>";
        }

        if ( ! $required ) {
            return "";
        }

        if ( "text" === $style ) {
            return ' <span class="trinity-forms-required">(' . esc_html__( "required", "trinity-forms" ) . ")</span>";
        }

        return ' <span class="trinity-forms-required" aria-hidden="true">*</span>';
    }

    /**
     * The sub-label for one input of a composite field (name, address).
     *
     * Rendered as a real <label> tied to its input, and hidden visually rather than
     * removed when the form is set to "no sub-labels" — a first-name box with no
     * accessible name is a worse form, not a tidier one.
     *
     * @param array<string,mixed> $config
     */
    protected function sub_label( array $config, string $for, string $text ): string {
        $placement = (string) ( $config["sub_label_placement"] ?? "below" );
        $class     = "hidden" === $placement
            ? "screen-reader-text"
            : "trinity-forms-sub-label trinity-forms-sub-label-" . ( "above" === $placement ? "above" : "below" );

        return '<label class="' . esc_attr( $class ) . '" for="' . esc_attr( $for ) . '">' . esc_html( $text ) . "</label>";
    }

    /**
     * Extra input attributes shared by every field type.
     *
     * Returns a pre-escaped attribute fragment (with a leading space) or "".
     */
    protected function common_attrs( array $config ): string {
        $out = "";

        if ( ! empty( $config["autocomplete"] ) ) {
            $out .= ' autocomplete="' . esc_attr( (string) $config["autocomplete"] ) . '"';
        }
        // Point the input at its own help text so screen readers announce it, rather
        // than leaving the description as unattached prose next to the box.
        if ( ! empty( $config["help"] ) ) {
            $out .= ' aria-describedby="' . esc_attr( (string) ( $config["id"] ?? "" ) ) . '-help"';
        }
        return $out;
    }

    /**
     * Whether this field should be rendered for the current visitor.
     *
     * Distinct from conditional logic, which is about answers. This is about who is
     * looking: "logged_in" hides a field from anonymous visitors, "admin" keeps it
     * for staff only, and "hidden" renders it but never shows it (a carrier for a
     * dynamically-populated value).
     */
    public static function is_visible_to_user( array $config ): bool {
        switch ( (string) ( $config["visibility"] ?? "everyone" ) ) {
            case "logged_in":
                return is_user_logged_in();
            case "admin":
                return current_user_can( "manage_options" );
            default:
                return true;
        }
    }

    /** The HTML name attribute. Top-level fields nest under trinity_forms_fields[id]. */
    protected function attr_name( array $config ): string {
        return "trinity_forms_fields[" . ( $config["id"] ?? "" ) . "]";
    }

    /** Common min/max length and regex validation used by text-like fields. */
    protected function length_errors( $value, array $config ): array {
        $errors = [];
        $value  = (string) $value;
        $len    = function_exists( "mb_strlen" ) ? mb_strlen( $value ) : strlen( $value );

        if ( isset( $config["min_length"] ) && $config["min_length"] !== "" && $len > 0 && $len < (int) $config["min_length"] ) {
            $errors[] = sprintf(
                /* translators: %d: minimum number of characters. */
                __( "Must be at least %d characters.", "trinity-forms" ),
                (int) $config["min_length"]
            );
        }
        if ( isset( $config["max_length"] ) && $config["max_length"] !== "" && $len > (int) $config["max_length"] ) {
            $errors[] = sprintf(
                /* translators: %d: maximum number of characters. */
                __( "Must be %d characters or fewer.", "trinity-forms" ),
                (int) $config["max_length"]
            );
        }
        if ( ! empty( $config["pattern"] ) && $value !== "" ) {
            $pattern = "/" . str_replace( "/", "\\/", $config["pattern"] ) . "/u";
            $match   = @preg_match( $pattern, $value );
            if ( $match === 0 ) {
                $errors[] = (string) ( $config["pattern_message"] ?? __( "Invalid format.", "trinity-forms" ) );
            }
        }
        return $errors;
    }
}
