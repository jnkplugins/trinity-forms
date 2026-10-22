<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Schema-side wrapper around a single field config.
 *
 * Holds the raw config plus the registered Field_Base handler that knows how
 * to render/sanitize/validate. The pipeline talks to this object; the handler
 * is the strategy.
 */
class Field {

    public string $id;
    public string $type;
    public string $label;
    public bool   $required;
    public array  $config;

    public function __construct( array $config ) {
        $this->config   = $config;
        $this->id       = (string) ( $config["id"]    ?? "" );
        $this->type     = (string) ( $config["type"]  ?? "text" );
        $this->label    = (string) ( $config["label"] ?? "" );
        $this->required = ! empty( $config["required"] );
    }

    public function handler(): ?Fields\Field_Base {
        return Field_Registry::get( $this->type );
    }

    public function sanitize( $raw ) {
        $h = $this->handler();
        return $h ? $h->sanitize( $raw, $this->config ) : self::sanitize_unknown( $raw );
    }

    /**
     * A field type with no handler (an add-on that was switched off, a Pro field on
     * a site that never had Pro) is stored as plain text. It used to be stored
     * exactly as posted, markup and all.
     *
     * @param mixed $raw
     * @return mixed
     */
    public static function sanitize_unknown( $raw ) {
        if ( null === $raw ) {
            return null;
        }
        if ( is_array( $raw ) ) {
            return map_deep( $raw, static fn( $v ) => is_scalar( $v ) ? sanitize_text_field( (string) $v ) : "" );
        }
        return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : "";
    }

    public function validate( $value ): array {
        $h = $this->handler();
        if ( ! $h ) {
            return [];
        }
        $errors = $h->validate( $value, $this->config );
        if ( $this->required && $this->is_empty( $value ) ) {
            $errors[] = __( "This field is required.", "trinity-forms" );
        }

        // A custom message replaces the lot rather than being appended: the reason
        // to write your own is that the built-in wording was wrong for this field.
        $custom = trim( (string) ( $this->config["validation_message"] ?? "" ) );
        if ( $errors && "" !== $custom ) {
            return [ $custom ];
        }

        return $errors;
    }

    public function render( $value = null ): string {
        $h = $this->handler();
        if ( ! $h ) {
            return "";
        }

        // A configured default is the starting value only when nothing else supplied
        // one. Saved progress and prefill both resolve before this and pass a value
        // in, so they win — the default is the floor, not an override.
        if ( null === $value || "" === $value ) {
            $default = (string) ( $this->config["default_value"] ?? "" );
            if ( "" !== $default ) {
                $value = $default;
            }
        }

        // Lets an extension adjust what is drawn without touching the stored
        // field, e.g. Pro choice limits marking a full option as sold out.
        $config = (array) apply_filters( "trinity_forms_field_render_config", $this->config, $this, Form_Renderer::$rendering );

        return $h->render( $config, $value );
    }

    /**
     * The label to use in entries and exports.
     *
     * An admin label exists for the case where the public label is a whole sentence
     * ("How did you hear about us?") and the entries table needs "Source".
     */
    public function display_label(): string {
        $admin = trim( (string) ( $this->config["admin_label"] ?? "" ) );
        return "" !== $admin ? $admin : $this->label;
    }

    private function is_empty( $value ): bool {
        if ( is_array( $value ) ) {
            return count( array_filter( $value, static fn( $v ) => $v !== "" && $v !== null ) ) === 0;
        }
        return $value === null || $value === "" || $value === false;
    }
}
