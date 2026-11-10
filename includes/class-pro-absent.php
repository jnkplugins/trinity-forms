<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * What a site sees after going from Pro back to the free version.
 *
 * Entries, forms and settings are kept, but the Pro code is gone. Before this,
 * visitors read the raw "[trinity_forms_view id=54]" text, a booking form quietly
 * lost its date and time, and the Visual Builder showed cards named
 * "trinity-forms/field-signature" whose link opened a block editor full of
 * "unsupported block" boxes. Now visitors get nothing where a Pro-only shortcode
 * was, and the free fields of a form keep working; people who manage forms see a
 * short note naming what is off.
 *
 * Only active when the Pro code is not installed (the free build). A Pro install
 * whose licence lapsed keeps running these features (see License).
 */
class Pro_Absent {

    /** Shortcodes only Pro can draw. */
    private const SHORTCODES = [ "trinity_forms_view" ];

    /** True when the Pro code is not installed (filterable for tests). */
    public static function active(): bool {
        return (bool) apply_filters( "trinity_forms_pro_absent", ! License::pro_installed() );
    }

    public static function bootstrap(): void {
        if ( ! self::active() ) {
            return;
        }
        // Late, so a real handler registered by anything else wins.
        add_action( "init", [ self::class, "register_shortcodes" ], 99 );
        add_filter( "trinity_forms_builder_config", [ self::class, "builder_config" ], 10, 2 );
    }

    /**
     * Pro field types by block slug (hyphenated), with the names people know.
     *
     * @return array<string,string>
     */
    public static function field_names(): array {
        return [
            "signature"      => __( "Signature", "trinity-forms" ),
            "payment"        => __( "Payment summary", "trinity-forms" ),
            "repeater"       => __( "Repeater", "trinity-forms" ),
            "calculation"    => __( "Calculation", "trinity-forms" ),
            "post-field"     => __( "Post Field", "trinity-forms" ),
            "chained-select" => __( "Chained Selects", "trinity-forms" ),
            "rich-text"      => __( "Rich Text", "trinity-forms" ),
            "likert"         => __( "Likert / Matrix", "trinity-forms" ),
            "nps"            => __( "Net Promoter Score", "trinity-forms" ),
            "ranking"        => __( "Ranking", "trinity-forms" ),
            "booking"        => __( "Booking", "trinity-forms" ),
            "lookup"         => __( "Lookup", "trinity-forms" ),
        ];
    }

    /** "Signature (Pro)" for a Pro field type (schema or block slug), "" otherwise. */
    public static function pro_label( string $type ): string {
        $names = self::field_names();
        $slug  = str_replace( "_", "-", $type );
        if ( ! isset( $names[ $slug ] ) ) {
            return "";
        }
        /* translators: %s: name of a Pro field, e.g. Signature. */
        return sprintf( __( "%s (Pro)", "trinity-forms" ), $names[ $slug ] );
    }

    /**
     * What a form uses that cannot run without Pro, as readable names.
     *
     * @return array<int,string>
     */
    public static function features_off( Form $form ): array {
        if ( ! self::active() ) {
            return [];
        }
        $off   = [];
        $names = self::field_names();
        foreach ( $form->fields() as $field ) {
            $slug = str_replace( "_", "-", $field->type );
            if ( isset( $names[ $slug ] ) && ! $field->handler() ) {
                $off[] = $names[ $slug ];
            }
        }
        $flags = [
            "allow_save_progress" => __( "Save and continue later", "trinity-forms" ),
            "conversational"      => __( "One question at a time (chat style)", "trinity-forms" ),
            "landing_page"        => __( "Landing page", "trinity-forms" ),
            "require_approval"    => __( "Approval of entries", "trinity-forms" ),
            "capture_partials"    => __( "Saving unfinished entries", "trinity-forms" ),
            "popup_sitewide"      => __( "Popup on every page", "trinity-forms" ),
        ];
        foreach ( $flags as $key => $label ) {
            if ( ! empty( $form->get_setting( $key ) ) ) {
                $off[] = $label;
            }
        }
        $groups = [
            "user_registration" => __( "User registration", "trinity-forms" ),
            "post_creation"     => __( "Creating posts from entries", "trinity-forms" ),
        ];
        foreach ( $groups as $key => $label ) {
            $cfg = $form->get_setting( $key );
            if ( is_array( $cfg ) && ! empty( $cfg["enabled"] ) ) {
                $off[] = $label;
            }
        }
        if ( $form->payment_enabled() ) {
            $off[] = __( "Payments", "trinity-forms" );
        }
        foreach ( $form->integrations() as $cfg ) {
            if ( is_array( $cfg ) && ! empty( $cfg["enabled"] ) ) {
                $off[] = __( "Integrations", "trinity-forms" );
                break;
            }
        }
        return array_values( array_unique( $off ) );
    }

    /** The note above a form for people who manage forms, or "". */
    public static function form_notice( Form $form ): string {
        if ( is_admin() || ! current_user_can( Admin::CAP ) ) {
            return "";
        }
        $off = self::features_off( $form );
        if ( ! $off ) {
            return "";
        }
        return '<p class="trinity-forms-pro-off-note" style="border:1px dashed #996800;padding:.75em 1em;color:#6e4b00;">'
            . esc_html( sprintf(
                /* translators: %s: comma-separated list, e.g. "Booking, User registration". */
                __( "Only people who manage forms see this note. This form uses Pro features that are off in the free version: %s. Visitors get the form without them.", "trinity-forms" ),
                implode( ", ", $off )
            ) )
            . "</p>";
    }

    public static function register_shortcodes(): void {
        foreach ( self::SHORTCODES as $tag ) {
            if ( ! shortcode_exists( $tag ) ) {
                add_shortcode( $tag, [ self::class, "shortcode" ] );
            }
        }
    }

    /** A Pro-only shortcode: nothing for visitors, a short note for managers. */
    public static function shortcode( $atts = [], $content = "", $tag = "" ): string {
        if ( ! current_user_can( Admin::CAP ) ) {
            return "";
        }
        return '<p class="trinity-forms-pro-note"><em>'
            . esc_html__( "Only people who manage forms see this note: this View needs Trinity Forms Pro, so visitors see nothing here.", "trinity-forms" )
            . "</em></p>";
    }

    /**
     * Readable names for Pro blocks in the Visual Builder, and the list of what is
     * off for the form being edited.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function builder_config( array $config, int $form_id ): array {
        $labels = [];
        foreach ( array_keys( self::field_names() ) as $slug ) {
            $labels[ "trinity-forms/field-" . $slug ] = self::pro_label( $slug );
        }
        $config["proBlocks"] = $labels;
        $form                = $form_id ? Form_Manager::get( $form_id ) : null;
        $off                 = $form ? self::features_off( $form ) : [];
        $config["proOff"]    = $off
            ? sprintf(
                /* translators: %s: comma-separated list, e.g. "Booking, User registration". */
                __( "This form uses Pro features that are off in the free version: %s. Visitors get the form without them, and they are kept for when Pro is back.", "trinity-forms" ),
                implode( ", ", $off )
            )
            : "";
        return $config;
    }
}
