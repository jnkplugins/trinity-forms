<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

use TrinityForms\Field_Registry;

/**
 * One choice field that can be drawn as radios, checkboxes or a dropdown.
 *
 * The problem it solves is a palette one, not a functional one. "Radio", "Checkbox
 * group", "Select" and "Multi Select" are four entries for what an author thinks of
 * as one decision — ask a question, list some answers — and choosing wrongly means
 * deleting the field and rebuilding it with the same options typed again.
 *
 * Deliberately a *delegate*, not a fifth implementation: it picks the existing
 * handler and hands the work over. A validation fix in Field_Checkbox has to reach
 * this field too, and it does, because this field is Field_Checkbox when it is
 * drawn that way.
 */
class Field_Multiple_Choice extends Field_Base {

    /** Display style => the field type that already does it. */
    private const DELEGATES = [
        "radio"    => "radio",
        "checkbox" => "checkbox",
        "select"   => "select",
        "multi"    => "multiselect",
    ];

    /** Does this style accept more than one answer? */
    public static function is_multiple( array $config ): bool {
        return in_array( self::style( $config ), [ "checkbox", "multi" ], true );
    }

    private static function style( array $config ): string {
        $style = (string) ( $config["choice_style"] ?? "radio" );
        return isset( self::DELEGATES[ $style ] ) ? $style : "radio";
    }

    /**
     * The handler that actually draws and validates this field.
     *
     * Resolved through the registry rather than newed up, so a site that has
     * replaced (say) the select field through trinity_forms_field_types gets its
     * replacement here too.
     */
    private function delegate( array $config ): ?Field_Base {
        return Field_Registry::get( self::DELEGATES[ self::style( $config ) ] );
    }

    /**
     * Hand the delegate a config that looks like its own.
     *
     * The type is rewritten so the wrapper class and any type-specific branch inside
     * the delegate behave exactly as they would on a real field of that type.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function delegate_config( array $config ): array {
        $config["type"] = self::DELEGATES[ self::style( $config ) ];
        return $config;
    }

    public function render( array $config, $value = null ): string {
        $handler = $this->delegate( $config );
        if ( ! $handler ) {
            return "";
        }
        return $handler->render( $this->delegate_config( $config ), $value );
    }

    public function sanitize( $raw, array $config ) {
        $handler = $this->delegate( $config );
        if ( ! $handler ) {
            return self::is_multiple( $config ) ? [] : "";
        }
        return $handler->sanitize( $raw, $this->delegate_config( $config ) );
    }

    public function validate( $value, array $config ): array {
        $handler = $this->delegate( $config );
        if ( ! $handler ) {
            return [];
        }
        return $handler->validate( $value, $this->delegate_config( $config ) );
    }

    public function to_csv_value( $stored ): string {
        return is_array( $stored ) ? implode( ", ", array_map( "strval", $stored ) ) : (string) $stored;
    }
}
