<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Field blocks declare their starting text (label, placeholder, choices, consent
 * sentence) as attribute defaults in block.json, and block.json defaults are never
 * translated. On a Spanish site every new Email field said "Email" with
 * "you@example.com", a new Dropdown said "Choose one", "— Select —", "Option A",
 * and those English words reached visitors whenever the owner kept the default.
 *
 * Every editor (visual builder, block editor) and the front end read these
 * defaults from the server-side block registry, so translating them once, as the
 * metadata is registered, puts the site's language in all three. The strings are
 * spelled out here so the .pot picks them up.
 */
class Block_Defaults {

    public static function register(): void {
        add_filter( "block_type_metadata", [ self::class, "translate_metadata" ] );
    }

    /** The English defaults used in block.json, in the site's language. */
    public static function strings(): array {
        return [
            "Address"               => __( "Address", "trinity-forms" ),
            "Choose any"            => __( "Choose any", "trinity-forms" ),
            "Choose one"            => __( "Choose one", "trinity-forms" ),
            "Option A"              => __( "Option A", "trinity-forms" ),
            "Option B"              => __( "Option B", "trinity-forms" ),
            "Option one"            => __( "Option one", "trinity-forms" ),
            "Option two"            => __( "Option two", "trinity-forms" ),
            "Consent"               => __( "Consent", "trinity-forms" ),
            "I agree to the terms." => __( "I agree to the terms.", "trinity-forms" ),
            "Country"               => __( "Country", "trinity-forms" ),
            "Date"                  => __( "Date", "trinity-forms" ),
            "Email"                 => __( "Email", "trinity-forms" ),
            /* translators: example address shown inside an empty email field. */
            "you@example.com"       => __( "you@example.com", "trinity-forms" ),
            "File"                  => __( "File", "trinity-forms" ),
            "Hidden"                => __( "Hidden", "trinity-forms" ),
            "Image Choice"          => __( "Image Choice", "trinity-forms" ),
            "List"                  => __( "List", "trinity-forms" ),
            "Item"                  => __( "Item", "trinity-forms" ),
            "Multiple Choice"       => __( "Multiple Choice", "trinity-forms" ),
            "Multi Select"          => __( "Multi Select", "trinity-forms" ),
            "Name"                  => __( "Name", "trinity-forms" ),
            "Number"                => __( "Number", "trinity-forms" ),
            "Option"                => __( "Option", "trinity-forms" ),
            "Next"                  => __( "Next", "trinity-forms" ),
            "Back"                  => __( "Back", "trinity-forms" ),
            "Password"              => __( "Password", "trinity-forms" ),
            "Phone"                 => __( "Phone", "trinity-forms" ),
            "Product"               => __( "Product", "trinity-forms" ),
            "Quantity"              => __( "Quantity", "trinity-forms" ),
            "Range"                 => __( "Range", "trinity-forms" ),
            "Rating"                => __( "Rating", "trinity-forms" ),
            "Section"               => __( "Section", "trinity-forms" ),
            "— Select —"            => __( "— Select —", "trinity-forms" ),
            "Shipping"              => __( "Shipping", "trinity-forms" ),
            "Message"               => __( "Message", "trinity-forms" ),
            "Text"                  => __( "Text", "trinity-forms" ),
            "Time"                  => __( "Time", "trinity-forms" ),
            "Total"                 => __( "Total", "trinity-forms" ),
            "Website"               => __( "Website", "trinity-forms" ),
            "Location"              => __( "Location", "trinity-forms" ),
            "Post title"            => __( "Post title", "trinity-forms" ),
            "Items"                 => __( "Items", "trinity-forms" ),
            "Add item"              => __( "Add item", "trinity-forms" ),
            "Signature"             => __( "Signature", "trinity-forms" ),
        ];
    }

    /** Attributes whose default is words a visitor reads. */
    private const TEXT_ATTRS = [ "label", "placeholder", "consentText", "nextText", "prevText", "addButton" ];

    /** List attributes whose items carry a visible "label". */
    private const LIST_ATTRS = [ "options", "columns" ];

    public static function translate_metadata( $metadata ) {
        if ( ! is_array( $metadata ) || 0 !== strpos( (string) ( $metadata["name"] ?? "" ), "trinity-forms/field-" )
            || empty( $metadata["attributes"] ) || ! is_array( $metadata["attributes"] ) ) {
            return $metadata;
        }
        $map = self::strings();
        foreach ( $metadata["attributes"] as $key => $def ) {
            if ( ! is_array( $def ) || ! array_key_exists( "default", $def ) ) {
                continue;
            }
            if ( in_array( $key, self::TEXT_ATTRS, true ) && is_string( $def["default"] ) && isset( $map[ $def["default"] ] ) ) {
                $metadata["attributes"][ $key ]["default"] = $map[ $def["default"] ];
            } elseif ( in_array( $key, self::LIST_ATTRS, true ) && is_array( $def["default"] ) ) {
                foreach ( $def["default"] as $i => $item ) {
                    if ( is_array( $item ) && isset( $item["label"] ) && is_string( $item["label"] ) && isset( $map[ $item["label"] ] ) ) {
                        $metadata["attributes"][ $key ]["default"][ $i ]["label"] = $map[ $item["label"] ];
                    }
                }
            }
        }
        return $metadata;
    }
}
