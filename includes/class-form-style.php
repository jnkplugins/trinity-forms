<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * How a form looks: a style preset and an accent colour, written as CSS custom
 * properties on the form element. public.css reads every one of them with the
 * old look as the fallback, so a form with no style settings renders exactly as
 * it did before.
 *
 * Free: the default look plus two presets and the accent colour. Pro adds more
 * presets and finer controls through the trinity_forms_style_presets and
 * trinity_forms_form_style_vars filters.
 */
class Form_Style {

    /** The accent colour the stylesheet uses when none is chosen. */
    public const DEFAULT_ACCENT = "#4f46e5";

    /**
     * Free presets: key => CSS variables.
     *
     * @return array<string,array<string,string>>
     */
    public static function free_presets(): array {
        return [
            "default" => [],
            "rounded" => [
                "--trinity-forms-radius"        => "14px",
                "--trinity-forms-button-radius" => "999px",
            ],
            "sharp"   => [
                "--trinity-forms-radius"        => "0",
                "--trinity-forms-button-radius" => "0",
                "--trinity-forms-border"        => "#98a2b3",
            ],
            // Free since 2.11.0 (was Pro): on a dark theme the default white
            // fields are the odd one out, and that is not a paid problem.
            "dark"    => [
                "--trinity-forms-text"     => "#f2f4f7",
                "--trinity-forms-label"    => "#f2f4f7",
                "--trinity-forms-field-bg" => "#1d2939",
                "--trinity-forms-border"   => "#344054",
                "--trinity-forms-accent"   => "#818cf8",
            ],
            // The block theme's own palette, where it has one: theme.json colours
            // reach the page as --wp--preset--color--*. Each falls back to the
            // plugin's colour, so a theme without that slot looks like Default.
            "theme"   => [
                "--trinity-forms-accent"      => "var(--wp--preset--color--primary, var(--wp--preset--color--accent-1, #4f46e5))",
                "--trinity-forms-field-bg"    => "var(--wp--preset--color--base, #fff)",
                "--trinity-forms-field-color" => "var(--wp--preset--color--contrast, #101828)",
                "--trinity-forms-border"      => "var(--wp--preset--color--contrast-3, #d0d5dd)",
            ],
        ];
    }

    /**
     * Every preset's variables, Pro ones included.
     *
     * @return array<string,array<string,string>>
     */
    public static function presets(): array {
        return (array) apply_filters( "trinity_forms_style_preset_vars", self::free_presets() );
    }

    /** Preset choices for the Layout tab: key => label. Pro adds its own. */
    public static function preset_labels(): array {
        return (array) apply_filters( "trinity_forms_style_presets", [
            "default" => __( "Default", "trinity-forms" ),
            "rounded" => __( "Rounded", "trinity-forms" ),
            "sharp"   => __( "Sharp", "trinity-forms" ),
            "dark"    => __( "Dark", "trinity-forms" ),
            "theme"   => __( "Theme colours", "trinity-forms" ),
        ] );
    }

    public static function preset( Form $form ): string {
        $p = (string) $form->get_setting( "style_preset", "default" );
        return isset( self::preset_labels()[ $p ] ) ? $p : "default";
    }

    /** The accent colour, or "" for the stylesheet default. */
    public static function accent( Form $form ): string {
        $c = (string) sanitize_hex_color( (string) $form->get_setting( "style_accent", "" ) );
        return strtolower( $c ) === self::DEFAULT_ACCENT ? "" : $c;
    }

    /**
     * The CSS variables for one form.
     *
     * @return array<string,string>
     */
    public static function vars( Form $form ): array {
        $preset = self::preset( $form );
        $vars   = (array) ( self::presets()[ $preset ] ?? [] );
        $accent = self::accent( $form );
        if ( "" !== $accent ) {
            $vars["--trinity-forms-accent"] = $accent;
        }
        $vars = (array) apply_filters( "trinity_forms_form_style_vars", $vars, $form, $preset );

        // Only custom properties with plain values reach the style attribute.
        $out = [];
        foreach ( $vars as $name => $value ) {
            $name  = (string) $name;
            $value = trim( (string) $value );
            if ( preg_match( '/^--trinity-forms-[a-z0-9-]+$/', $name ) && "" !== $value && preg_match( '/^[#a-zA-Z0-9.,%() -]+$/', $value ) ) {
                $out[ $name ] = $value;
            }
        }
        return $out;
    }

    /** The style attribute value ("" when the form uses the plain default). */
    public static function inline( Form $form ): string {
        $parts = [];
        foreach ( self::vars( $form ) as $name => $value ) {
            $parts[] = $name . ":" . $value;
        }
        return implode( ";", $parts );
    }

    /** A class naming the preset, for presets that need more than variables. */
    public static function css_class( Form $form ): string {
        $preset = self::preset( $form );
        return "default" === $preset ? "" : "trinity-forms-style-" . sanitize_html_class( $preset );
    }

    /**
     * Sanitize the style keys of a form's settings (Layout tab).
     *
     * @param array<string,mixed> $raw
     * @return array<string,string>
     */
    public static function sanitize( array $raw ): array {
        $preset = (string) ( $raw["style_preset"] ?? "default" );
        $accent = (string) sanitize_hex_color( (string) ( $raw["style_accent"] ?? "" ) );
        return [
            "style_preset" => isset( self::preset_labels()[ $preset ] ) ? $preset : "default",
            "style_accent" => strtolower( $accent ) === self::DEFAULT_ACCENT ? "" : $accent,
        ];
    }
}
