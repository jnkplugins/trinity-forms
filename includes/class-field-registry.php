<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Maps field type slugs (e.g. "text", "email") to their handler instances.
 *
 * Bootstrapped from Plugin::boot() and filterable via `trinity_forms_field_types`
 * so add-ons can register custom field types without touching core.
 */
class Field_Registry {

    /** @var array<string, Fields\Field_Base>|null */
    private static ?array $types = null;

    /**
     * Settings every field type gets, whatever it is.
     *
     * Injected into each trinity-forms/field-* block at registration rather than
     * copied into twenty-odd block.json files — one list, and both editors see it
     * (the visual builder reads the registered block type's attributes, and the
     * Gutenberg editor is handed the same server-side metadata).
     *
     * Names are camelCase because that is the block-attribute convention; Form
     * maps them onto the schema's snake_case keys.
     *
     * @var array<string,array<string,mixed>>
     */
    public const COMMON_ATTRIBUTES = [
        "defaultValue"         => [ "type" => "string",  "default" => "" ],
        "adminLabel"           => [ "type" => "string",  "default" => "" ],
        "cssClass"             => [ "type" => "string",  "default" => "" ],
        "fieldWidth"           => [ "type" => "string",  "default" => "" ],
        "visibility"           => [ "type" => "string",  "default" => "everyone" ],
        "allowPrefill"         => [ "type" => "boolean", "default" => false ],
        "prefillParam"         => [ "type" => "string",  "default" => "" ],
        "preventDuplicates"    => [ "type" => "boolean", "default" => false ],
        "validationMessage"    => [ "type" => "string",  "default" => "" ],
        "autocomplete"         => [ "type" => "string",  "default" => "" ],
        // Empty = follow the form's "description placement" setting. A "below" default
        // was merged into every block-built field, so the form-wide setting never applied.
        "descriptionPlacement" => [ "type" => "string",  "default" => "" ],
    ];

    /**
     * Add the shared settings to every field block as it registers.
     *
     * Hooked on register_block_type_args from Plugin::boot() — not from bootstrap()
     * below, which is also called lazily by get() and would re-add the filter.
     *
     * A block's own declaration wins, so a field that already defines one of these
     * keeps its version.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function add_common_attributes( array $args, string $name ): array {
        if ( 0 !== strpos( $name, "trinity-forms/field-" ) ) {
            return $args;
        }
        $args["attributes"] = array_merge( self::COMMON_ATTRIBUTES, (array) ( $args["attributes"] ?? [] ) );
        return $args;
    }

    public static function bootstrap(): void {
        // Free-tier field types only. Premium types (signature, payment,
        // repeater and friends) live in pro/ and are added (license-gated) via the
        // trinity_forms_field_types filter from pro/bootstrap.php. The free build never
        // registers them, so they have no handler and simply don't render.
        $defaults = [
            "text"        => new Fields\Field_Text(),
            "textarea"    => new Fields\Field_Textarea(),
            "email"       => new Fields\Field_Email(),
            "number"      => new Fields\Field_Number(),
            "range"       => new Fields\Field_Range(),
            "name"        => new Fields\Field_Name(),
            "phone"       => new Fields\Field_Phone(),
            "address"     => new Fields\Field_Address(),
            "country"     => new Fields\Field_Country(),
            "select"      => new Fields\Field_Select(),
            "radio"       => new Fields\Field_Radio(),
            "checkbox"    => new Fields\Field_Checkbox(),
            "date"        => new Fields\Field_Date(),
            "time"        => new Fields\Field_Time(),
            "hidden"      => new Fields\Field_Hidden(),
            "html"        => new Fields\Field_Html(),
            "page_break"  => new Fields\Field_Page_Break(),
            "section"     => new Fields\Field_Section(),
            "rating"      => new Fields\Field_Rating(),
            "consent"     => new Fields\Field_Consent(),
            "website"     => new Fields\Field_Website(),
            "password"    => new Fields\Field_Password(),
            "multiselect" => new Fields\Field_Multiselect(),
            "image_choice"=> new Fields\Field_Image_Choice(),
            "multiple_choice" => new Fields\Field_Multiple_Choice(),
            "list"        => new Fields\Field_List(),
            // File upload is free since 2.9.0: Contact Form 7 gives it away, so
            // missing it read as a gap rather than a Pro perk.
            "file"        => new Fields\Field_File(),
            // Pricing fields are free: a form may price an order and show a total.
            // Taking the money (Stripe/PayPal/Mollie) is what stays premium.
            "product"     => new Fields\Field_Product(),
            "quantity"    => new Fields\Field_Quantity(),
            "option"      => new Fields\Field_Option(),
            "shipping"    => new Fields\Field_Shipping(),
            "total"       => new Fields\Field_Total(),
        ];
        $types = [];
        foreach ( (array) apply_filters( "trinity_forms_field_types", $defaults ) as $slug => $type ) {
            if ( $type instanceof Fields\Field_Base ) {
                $types[ $slug ] = $type;
            }
        }
        self::$types = $types;
    }

    public static function get( string $slug ): ?Fields\Field_Base {
        if ( self::$types === null ) {
            self::bootstrap();
        }
        $type = self::$types[ $slug ] ?? null;
        return $type instanceof Fields\Field_Base ? $type : null;
    }

    /** @return array<string, Fields\Field_Base> */
    public static function all(): array {
        if ( self::$types === null ) {
            self::bootstrap();
        }
        return self::$types ?? [];
    }
}
