<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Shortcode {

    public static function register(): void {
        add_shortcode( "trinity-forms", [ self::class, "render_form" ] );

        // Free: a logged-in user's own submissions (strictly self-scoped, read-only).
        add_shortcode( "trinity_forms_my_submissions", [ My_Submissions::class, "render" ] );

        // The frontend [trinity_forms_submissions] table is premium; pro/ registers it
        // on this action. Nothing listens in the free build.
        do_action( "trinity_forms_register_shortcodes" );

        // Free-build stub so the raw [trinity_forms_submissions] text never prints on the
        // page. Pro replaces this with the real table by registering after this runs.
        if ( ! shortcode_exists( "trinity_forms_submissions" ) ) {
            add_shortcode( "trinity_forms_submissions", [ self::class, "render_submissions_stub" ] );
        }
    }

    /** No-op free stub: empty for visitors; a quiet Pro note for admins only. */
    public static function render_submissions_stub( $atts ): string {
        if ( current_user_can( "manage_options" ) ) {
            return '<p class="trinity-forms-pro-note"><em>'
                . esc_html__( "The submissions table is available in Trinity Forms Pro.", "trinity-forms" )
                . '</em></p>';
        }
        return "";
    }

    public static function render_form( $atts ): string {
        $atts = shortcode_atts( [ "id" => 0 ], (array) $atts, "trinity-forms" );
        $form = Form_Manager::get_public( (int) $atts["id"] );
        if ( ! $form ) {
            return (int) $atts["id"] > 0 ? Form_Renderer::missing_notice( (int) $atts["id"] ) : "";
        }
        return Form_Renderer::render( $form );
    }
}
