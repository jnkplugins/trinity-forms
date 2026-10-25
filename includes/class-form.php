<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Lightweight value object representing a form CPT row + its parsed schema.
 *
 * Field definitions live in post_content (Gutenberg trinity-forms/field-* blocks
 * or, for programmatic/REST-created forms, raw JSON). Behavior — settings,
 * notifications, payment, integrations — lives in post_meta
 * `_trinity_forms_settings`. __construct merges both sources into a unified schema.
 */
class Form {

    public const SETTINGS_META = "_trinity_forms_settings";

    /** Schema sections that a block-built form keeps in SETTINGS_META. */
    public const SECTIONS = [ "settings", "notifications", "payment", "integrations" ];

    /**
     * Schema key => block attribute. The inverse of the mapping in schema_from_blocks();
     * a key missing here cannot be written as block markup.
     */
    private const ATTR_MAP = [
        "label" => "label", "required" => "required", "placeholder" => "placeholder", "help" => "help",
        "min_length" => "minLength", "max_length" => "maxLength", "pattern" => "pattern",
        "pattern_message" => "patternMessage", "rows" => "rows", "min" => "min", "max" => "max",
        "step" => "step", "accept" => "accept", "multiple" => "multiple", "intl" => "intl",
        "correct_answer" => "correctAnswer", "points" => "points", "formula" => "formula",
        "prefix" => "prefix", "suffix" => "suffix", "decimals" => "decimals", "max_size" => "maxSize",
        "default" => "default", "html" => "html", "description" => "description",
        "consent_text" => "consentText", "next_text" => "nextText", "prev_text" => "prevText",
        "confirm" => "confirm", "max_choices" => "maxChoices", "columns" => "columns",
        "max_rows" => "maxRows", "product_type" => "productType", "price" => "price",
        "product_field" => "productField", "post_target" => "postTarget",
        "chained_data" => "chainedData", "choice_style" => "choiceStyle",
        "default_value" => "defaultValue", "admin_label" => "adminLabel", "css_class" => "cssClass",
        "field_width" => "fieldWidth", "visibility" => "visibility", "allow_prefill" => "allowPrefill",
        "prefill_param" => "prefillParam", "prevent_duplicates" => "preventDuplicates",
        "validation_message" => "validationMessage", "autocomplete" => "autocomplete",
        "description_placement" => "descriptionPlacement", "add_button" => "addButton",
        "amount_mode" => "amountMode", "amount" => "amount", "amount_field" => "amountField",
        "currency" => "currency", "options" => "options", "conditional" => "conditional",
    ];

    public int    $id;
    public string $title;
    public array  $schema;

    /** @var Field[] */
    private array $fields_cache;

    public function __construct( \WP_Post $post ) {
        $this->id    = (int) $post->ID;
        $this->title = $post->post_title;

        $content = (string) $post->post_content;
        $base    = self::looks_like_blocks( $content )
            ? self::schema_from_blocks( $content )
            : ( is_array( json_decode( $content, true ) ) ? json_decode( $content, true ) : [] );

        $meta = get_post_meta( $this->id, self::SETTINGS_META, true );
        if ( is_array( $meta ) ) {
            foreach ( [ "settings", "notifications", "payment", "integrations" ] as $section ) {
                if ( isset( $meta[ $section ] ) ) {
                    $base[ $section ] = $meta[ $section ];
                }
            }
            // Form settings belong under "settings". One written at the top level
            // (code that sets the meta by hand) used to be ignored without a word:
            // a top-level confirmation_message left visitors seeing "Thank you!".
            // Use it, unless "settings" holds a value of its own (not just the default
            // a new form starts with), and say so to developers.
            $stray = array_diff_key( $meta, array_flip( array_merge( self::SECTIONS, [ "version", "steps", "fields" ] ) ) );
            if ( $stray ) {
                $settings = isset( $base["settings"] ) && is_array( $base["settings"] ) ? $base["settings"] : [];
                $defaults = Form_Manager::default_schema()["settings"];
                foreach ( $stray as $key => $value ) {
                    if ( ! array_key_exists( $key, $settings ) || ( array_key_exists( $key, $defaults ) && $settings[ $key ] === $defaults[ $key ] ) ) {
                        $settings[ $key ] = $value;
                    }
                }
                $base["settings"] = $settings;
                if ( function_exists( "_doing_it_wrong" ) ) {
                    _doing_it_wrong(
                        __METHOD__,
                        sprintf(
                            /* translators: 1: list of meta keys, 2: the meta key name. */
                            esc_html__( 'Form settings %1$s are at the top level of %2$s; put them under "settings".', "trinity-forms" ),
                            esc_html( implode( ", ", array_keys( $stray ) ) ),
                            esc_html( self::SETTINGS_META )
                        ),
                        "2.14.0"
                    );
                }
            }
        }

        $this->schema = $base;
    }

    public function settings(): array {
        return isset( $this->schema["settings"] ) && is_array( $this->schema["settings"] )
            ? $this->schema["settings"]
            : [];
    }

    public function get_setting( string $key, $default = null ) {
        return $this->settings()[ $key ] ?? $default;
    }

    public function steps(): array {
        return isset( $this->schema["steps"] ) && is_array( $this->schema["steps"] )
            ? $this->schema["steps"]
            : [];
    }

    /** @return Field[] */
    public function fields(): array {
        if ( isset( $this->fields_cache ) ) {
            return $this->fields_cache;
        }
        $list = [];
        foreach ( $this->steps() as $step ) {
            foreach ( $step["fields"] ?? [] as $config ) {
                if ( ! isset( $config["id"], $config["type"] ) ) {
                    continue;
                }
                // No render-time premium filtering: premium field types are only
                // registered (in pro/, license-gated) when Pro is active, so in the
                // free build they have no handler and Field::render() is a harmless
                // no-op. Nothing to drop here.
                $list[] = new Field( $config );
            }
        }
        return $this->fields_cache = $list;
    }

    public function field( string $id ): ?Field {
        foreach ( $this->fields() as $f ) {
            if ( $f->id === $id ) {
                return $f;
            }
        }
        return null;
    }

    public function notifications(): array {
        return isset( $this->schema["notifications"] ) && is_array( $this->schema["notifications"] )
            ? $this->schema["notifications"]
            : [];
    }

    public function integrations(): array {
        return isset( $this->schema["integrations"] ) && is_array( $this->schema["integrations"] )
            ? $this->schema["integrations"]
            : [];
    }

    public function payment_config(): array {
        return isset( $this->schema["payment"] ) && is_array( $this->schema["payment"] )
            ? $this->schema["payment"]
            : [];
    }

    public function payment_enabled(): bool {
        return ! empty( $this->payment_config()["enabled"] );
    }

    public function payment_provider(): string {
        return (string) ( $this->payment_config()["provider"] ?? "stripe" );
    }

    public function payment_currency(): string {
        return strtoupper( (string) ( $this->payment_config()["currency"] ?? "USD" ) );
    }

    public function retention_days(): int {
        $opt     = get_option( "trinity_forms_settings", [] );
        $default = isset( $opt["default_retention_days"] ) ? (int) $opt["default_retention_days"] : 0;
        return (int) $this->get_setting( "retention_days", $default );
    }

    public static function looks_like_blocks( string $content ): bool {
        // Any trinity-forms/field-* block marker means this is a block-built form.
        return $content !== "" && strpos( $content, "<!-- wp:trinity-forms/field-" ) !== false;
    }

    /** @return array{version:string, steps:array} */
    private static function schema_from_blocks( string $content ): array {
        $blocks    = parse_blocks( $content );
        $registry  = class_exists( "WP_Block_Type_Registry" ) ? \WP_Block_Type_Registry::get_instance() : null;
        $fields    = [];
        foreach ( self::flatten_blocks( $blocks ) as $b ) {
            $name = (string) ( $b["blockName"] ?? "" );
            if ( strpos( $name, "trinity-forms/field-" ) !== 0 ) {
                continue;
            }
            $type  = substr( $name, strlen( "trinity-forms/field-" ) );
            $attrs = is_array( $b["attrs"] ?? null ) ? $b["attrs"] : [];

            // Gutenberg only serializes attrs that differ from defaults; merge the
            // block-type defaults underneath so we don't lose label/placeholder/etc.
            if ( $registry ) {
                $type_obj = $registry->get_registered( $name );
                if ( $type_obj && is_array( $type_obj->attributes ) ) {
                    $defaults = [];
                    foreach ( $type_obj->attributes as $attr_key => $attr_def ) {
                        if ( array_key_exists( "default", (array) $attr_def ) ) {
                            $defaults[ $attr_key ] = $attr_def["default"];
                        }
                    }
                    $attrs = array_merge( $defaults, $attrs );
                }
            }

            $id = (string) ( $attrs["fieldId"] ?? "" );
            if ( $id === "" ) { continue; }

            // Block names are hyphenated (page-break, chained-select, rich-text) but
            // type slugs use underscores.
            $schema_type = str_replace( "-", "_", $type );

            $field = [
                "id"          => $id,
                "type"        => $schema_type,
                "label"       => (string) ( $attrs["label"] ?? "" ),
                "required"    => ! empty( $attrs["required"] ),
                "placeholder" => (string) ( $attrs["placeholder"] ?? "" ),
                "help"        => (string) ( $attrs["help"] ?? "" ),
            ];
            // Map Gutenberg-style camelCase attrs onto the schema's snake_case keys.
            if ( isset( $attrs["minLength"] ) )   { $field["min_length"]   = (int) $attrs["minLength"]; }
            if ( isset( $attrs["maxLength"] ) )   { $field["max_length"]   = (int) $attrs["maxLength"]; }
            if ( isset( $attrs["pattern"] ) )     { $field["pattern"]      = (string) $attrs["pattern"]; }
            if ( isset( $attrs["patternMessage"] ) ) { $field["pattern_message"] = (string) $attrs["patternMessage"]; }
            if ( isset( $attrs["rows"] ) )        { $field["rows"]         = (int) $attrs["rows"]; }
            if ( isset( $attrs["min"] ) )         { $field["min"]          = $attrs["min"]; }
            if ( isset( $attrs["max"] ) )         { $field["max"]          = $attrs["max"]; }
            if ( isset( $attrs["step"] ) )        { $field["step"]         = $attrs["step"]; }
            if ( isset( $attrs["accept"] ) )      { $field["accept"]       = (string) $attrs["accept"]; }
            if ( isset( $attrs["multiple"] ) )    { $field["multiple"]     = (bool) $attrs["multiple"]; }
            if ( isset( $attrs["intl"] ) )        { $field["intl"]         = (bool) $attrs["intl"]; }
            if ( isset( $attrs["correctAnswer"] ) && $attrs["correctAnswer"] !== "" ) { $field["correct_answer"] = (string) $attrs["correctAnswer"]; }
            if ( isset( $attrs["points"] ) )      { $field["points"]       = (float) $attrs["points"]; }
            if ( isset( $attrs["formula"] ) )     { $field["formula"]      = (string) $attrs["formula"]; }
            if ( isset( $attrs["prefix"] ) )      { $field["prefix"]       = (string) $attrs["prefix"]; }
            if ( isset( $attrs["suffix"] ) )      { $field["suffix"]       = (string) $attrs["suffix"]; }
            if ( isset( $attrs["decimals"] ) )    { $field["decimals"]     = (int) $attrs["decimals"]; }
            if ( isset( $attrs["maxSize"] ) )     { $field["max_size"]     = (int) $attrs["maxSize"]; }
            if ( isset( $attrs["default"] ) )     { $field["default"]      = (string) $attrs["default"]; }
            if ( isset( $attrs["html"] ) )        { $field["html"]         = (string) $attrs["html"]; }
            if ( isset( $attrs["description"] ) ) { $field["description"]  = (string) $attrs["description"]; }
            if ( isset( $attrs["consentText"] ) ) { $field["consent_text"] = (string) $attrs["consentText"]; }
            if ( isset( $attrs["nextText"] ) )    { $field["next_text"]    = (string) $attrs["nextText"]; }
            if ( isset( $attrs["prevText"] ) )    { $field["prev_text"]    = (string) $attrs["prevText"]; }

            // Phase-4 field types.
            if ( isset( $attrs["confirm"] ) )      { $field["confirm"]       = (bool) $attrs["confirm"]; }
            if ( isset( $attrs["maxChoices"] ) )   { $field["max_choices"]   = (int) $attrs["maxChoices"]; }
            if ( isset( $attrs["columns"] ) )      { $field["columns"]       = (array) $attrs["columns"]; }
            if ( isset( $attrs["maxRows"] ) )      { $field["max_rows"]      = (int) $attrs["maxRows"]; }
            if ( isset( $attrs["productType"] ) )  { $field["product_type"]  = (string) $attrs["productType"]; }
            if ( isset( $attrs["price"] ) )        { $field["price"]         = (string) $attrs["price"]; }
            if ( isset( $attrs["productField"] ) ) { $field["product_field"] = (string) $attrs["productField"]; }
            if ( isset( $attrs["postTarget"] ) )   { $field["post_target"]   = (string) $attrs["postTarget"]; }
            if ( isset( $attrs["chainedData"] ) )  { $field["chained_data"]  = (string) $attrs["chainedData"]; }
            if ( isset( $attrs["choiceStyle"] ) )  { $field["choice_style"]  = (string) $attrs["choiceStyle"]; }

            // Settings shared by every field type (Field_Registry::COMMON_ATTRIBUTES),
            // injected into all field blocks rather than declared twenty times.
            if ( isset( $attrs["defaultValue"] ) )         { $field["default_value"]         = (string) $attrs["defaultValue"]; }
            if ( isset( $attrs["adminLabel"] ) )           { $field["admin_label"]           = (string) $attrs["adminLabel"]; }
            if ( isset( $attrs["cssClass"] ) )             { $field["css_class"]             = (string) $attrs["cssClass"]; }
            if ( isset( $attrs["fieldWidth"] ) )           { $field["field_width"]           = (string) $attrs["fieldWidth"]; }
            if ( isset( $attrs["visibility"] ) )           { $field["visibility"]            = (string) $attrs["visibility"]; }
            if ( isset( $attrs["allowPrefill"] ) )         { $field["allow_prefill"]         = (bool) $attrs["allowPrefill"]; }
            if ( isset( $attrs["prefillParam"] ) )         { $field["prefill_param"]         = (string) $attrs["prefillParam"]; }
            if ( isset( $attrs["preventDuplicates"] ) )    { $field["prevent_duplicates"]    = (bool) $attrs["preventDuplicates"]; }
            if ( isset( $attrs["validationMessage"] ) )    { $field["validation_message"]    = (string) $attrs["validationMessage"]; }
            if ( isset( $attrs["autocomplete"] ) )         { $field["autocomplete"]          = (string) $attrs["autocomplete"]; }
            if ( isset( $attrs["descriptionPlacement"] ) ) { $field["description_placement"] = (string) $attrs["descriptionPlacement"]; }
            if ( isset( $attrs["addButton"] ) )   { $field["add_button"]   = (string) $attrs["addButton"]; }
            if ( isset( $attrs["amountMode"] ) )  { $field["amount_mode"]  = (string) $attrs["amountMode"]; }
            if ( isset( $attrs["amount"] ) )      { $field["amount"]       = (float)  $attrs["amount"]; }
            if ( isset( $attrs["amountField"] ) ) { $field["amount_field"] = (string) $attrs["amountField"]; }
            if ( isset( $attrs["currency"] ) )    { $field["currency"]     = (string) $attrs["currency"]; }
            // Every choice-type block (image choice, multiple choice, multi select, option,
            // product), not only select/radio/checkbox: the others rendered an empty
            // group with nothing to pick.
            if ( is_array( $attrs["options"] ?? null ) ) {
                $field["options"] = $attrs["options"];
            }
            // Conditional-logic rules may arrive as an array attr or a JSON string.
            if ( isset( $attrs["conditional"] ) ) {
                $cond = is_string( $attrs["conditional"] ) ? json_decode( $attrs["conditional"], true ) : $attrs["conditional"];
                if ( is_array( $cond ) && ! empty( $cond["conditions"] ) ) {
                    $field["conditional"] = $cond;
                }
            }
            if ( $schema_type === "repeater" && isset( $attrs["subFieldsJson"] ) ) {
                $decoded = json_decode( (string) $attrs["subFieldsJson"], true );
                if ( is_array( $decoded ) ) {
                    $field["sub_fields"] = $decoded;
                }
            }
            $fields[] = $field;
        }
        return [
            "version" => "1.0",
            "steps"   => [ [ "id" => "step_1", "title" => "", "fields" => $fields ] ],
        ];
    }

    /** True when the content is nothing but top-level field blocks (and whitespace). */
    public static function is_field_markup_only( string $content ): bool {
        foreach ( parse_blocks( $content ) as $block ) {
            $name = (string) ( $block["blockName"] ?? "" );
            if ( "" === $name ) {
                if ( "" !== trim( (string) ( $block["innerHTML"] ?? "" ) ) ) {
                    return false;
                }
                continue;
            }
            if ( strpos( $name, "trinity-forms/field-" ) !== 0 || ! empty( $block["innerBlocks"] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Block markup for a JSON schema: the inverse of schema_from_blocks().
     *
     * Both editors read only block markup, so a form stored as JSON (import, the
     * Gravity Forms importer, REST) opened as an empty canvas, and saving it there
     * replaced the live form with nothing.
     *
     * Returns null instead of guessing when the schema holds something block markup
     * cannot carry (a step title, an unknown top-level key, a field setting with no
     * block attribute). The markup is parsed back and compared field by field before
     * it is returned, so a non-null answer is known to lose nothing.
     *
     * The sections (settings, notifications…) are not part of the markup; callers
     * move them to SETTINGS_META.
     */
    public static function blocks_from_schema( array $schema ): ?string {
        if ( array_diff( array_keys( $schema ), array_merge( [ "version", "steps" ], self::SECTIONS ) ) ) {
            return null;
        }

        $fields = [];
        $ids    = [];
        foreach ( array_values( (array) ( $schema["steps"] ?? [] ) ) as $i => $step ) {
            if ( ! is_array( $step ) || "" !== (string) ( $step["title"] ?? "" ) ) {
                return null;
            }
            // Block-built forms are one flat list where a page break starts the next step.
            if ( $i > 0 ) {
                $fields[] = [ "id" => "step_break_" . $i, "type" => "page_break" ];
            }
            foreach ( (array) ( $step["fields"] ?? [] ) as $field ) {
                if ( ! is_array( $field ) ) {
                    return null;
                }
                $fields[] = $field;
            }
        }

        $out = [];
        foreach ( $fields as $field ) {
            $id = (string) ( $field["id"] ?? "" );
            if ( isset( $ids[ $id ] ) ) {
                return null;
            }
            $ids[ $id ] = true;
            $block      = self::block_for_field( $field );
            if ( null === $block ) {
                return null;
            }
            $out[] = $block;
        }
        if ( ! $out ) {
            return "";
        }

        $markup = implode( "\n\n", $out ) . "\n";
        $parsed = self::schema_from_blocks( $markup )["steps"][0]["fields"];
        return self::same_fields( $fields, $parsed ) ? $markup : null;
    }

    private static function block_for_field( array $field ): ?string {
        $id   = (string) ( $field["id"] ?? "" );
        $type = (string) ( $field["type"] ?? "" );
        if ( "" === $id || "" === $type ) {
            return null;
        }

        $attrs = [ "fieldId" => $id ];
        foreach ( $field as $key => $value ) {
            if ( "id" === $key || "type" === $key ) {
                continue;
            }
            if ( "sub_fields" === $key ) {
                $attrs["subFieldsJson"] = (string) wp_json_encode( $value );
                continue;
            }
            if ( ! isset( self::ATTR_MAP[ $key ] ) ) {
                if ( self::is_blank( $value ) ) {
                    continue;
                }
                return null;
            }
            $attrs[ self::ATTR_MAP[ $key ] ] = $value;
        }

        // Leave out what equals the block's registered default, as Gutenberg does;
        // schema_from_blocks() merges the defaults back in.
        $name     = "trinity-forms/field-" . str_replace( "_", "-", $type );
        $registry = class_exists( "WP_Block_Type_Registry" ) ? \WP_Block_Type_Registry::get_instance() : null;
        $type_obj = $registry ? $registry->get_registered( $name ) : null;
        if ( $type_obj && is_array( $type_obj->attributes ) ) {
            foreach ( $type_obj->attributes as $attr_key => $attr_def ) {
                if ( "fieldId" !== $attr_key && array_key_exists( $attr_key, $attrs )
                    && array_key_exists( "default", (array) $attr_def ) && $attrs[ $attr_key ] === $attr_def["default"] ) {
                    unset( $attrs[ $attr_key ] );
                }
            }
        }

        return "<!-- wp:" . $name . " " . serialize_block_attributes( $attrs ) . " /-->";
    }

    /** Every setting the original fields had must come back with the same value. */
    private static function same_fields( array $original, array $parsed ): bool {
        if ( count( $original ) !== count( $parsed ) ) {
            return false;
        }
        foreach ( $original as $i => $field ) {
            $back = $parsed[ $i ];
            if ( (string) $field["id"] !== $back["id"] || (string) $field["type"] !== $back["type"] ) {
                return false;
            }
            foreach ( $field as $key => $value ) {
                if ( "id" === $key || "type" === $key ) {
                    continue;
                }
                if ( self::comparable( $value ) !== self::comparable( $back[ $key ] ?? null ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param mixed $value */
    private static function comparable( $value ): string {
        if ( self::is_blank( $value ) ) {
            return "";
        }
        if ( is_array( $value ) ) {
            return (string) wp_json_encode( $value );
        }
        if ( is_bool( $value ) ) {
            return $value ? "1" : "";
        }
        return (string) $value;
    }

    /** @param mixed $value */
    private static function is_blank( $value ): bool {
        return null === $value || "" === $value || false === $value || [] === $value;
    }

    private static function flatten_blocks( array $blocks ): array {
        $out = [];
        foreach ( $blocks as $b ) {
            $out[] = $b;
            if ( ! empty( $b["innerBlocks"] ) ) {
                $out = array_merge( $out, self::flatten_blocks( $b["innerBlocks"] ) );
            }
        }
        return $out;
    }
}
