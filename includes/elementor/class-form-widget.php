<?php
namespace TrinityForms\Elementor;

use TrinityForms\Classic_Editor;
use TrinityForms\Form_Manager;
use TrinityForms\Form_Renderer;
use TrinityForms\Templates;

defined( "ABSPATH" ) || exit;

/**
 * Elementor widget: pick a form, see it in the editor, visitors get the real form.
 * Loaded only from TrinityForms\Elementor::register_widget(), when Elementor is active.
 */
class Form_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return "trinity-forms";
    }

    public function get_title() {
        return __( "Trinity Forms", "trinity-forms" );
    }

    public function get_icon() {
        return "eicon-form-horizontal";
    }

    public function get_categories() {
        return [ "general" ];
    }

    /** What the panel's search matches besides the title. */
    public function get_keywords() {
        return [ "trinity", "form", "forms", "contact", "contact form", "enquiry", "inquiry", _x( "form", "Elementor panel search word", "trinity-forms" ), _x( "contact", "Elementor panel search word", "trinity-forms" ) ];
    }

    public function get_script_depends() {
        return [ "trinity-forms" ];
    }

    public function get_style_depends() {
        return [ "trinity-forms" ];
    }

    /**
     * The form carries a fresh nonce and time token on every page view, so
     * Elementor must never keep this widget's output in its element cache.
     */
    protected function is_dynamic_content(): bool {
        return true;
    }

    protected function register_controls() {
        $this->start_controls_section( "trinity_forms_section", [ "label" => __( "Form", "trinity-forms" ) ] );

        $forms = Classic_Editor::forms();
        if ( $forms ) {
            $this->add_control( "form_id", [
                "label"   => __( "Form", "trinity-forms" ),
                "type"    => \Elementor\Controls_Manager::SELECT,
                // Groups, not one options list: the panel draws an object's numeric
                // keys first, which put "Choose a form" below every form.
                "groups"  => [
                    [ "label" => "", "options" => [ "" => __( "Choose a form", "trinity-forms" ) ] ],
                    [ "label" => __( "Published forms", "trinity-forms" ), "options" => $forms ],
                ],
                "default" => "",
            ] );
        }

        $links = $forms
            ? sprintf(
                '<a href="%1$s" target="_blank">%2$s</a> · <a href="%3$s" target="_blank">%4$s</a>',
                esc_url( admin_url( "edit.php?post_type=trinity_forms_form" ) ),
                esc_html__( "Edit your forms", "trinity-forms" ),
                esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=" . Templates::PAGE ) ),
                esc_html__( "Create a form", "trinity-forms" )
            )
            : esc_html__( "You have no published forms yet.", "trinity-forms" ) . ' <a href="' . esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=" . Templates::PAGE ) ) . '" target="_blank">' . esc_html__( "Create a form", "trinity-forms" ) . "</a>";
        $this->add_control( "trinity_forms_links", [
            "type"            => \Elementor\Controls_Manager::RAW_HTML,
            "raw"             => $links,
            "content_classes" => "elementor-descriptor",
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $id   = (int) ( $this->get_settings_for_display( "form_id" ) ?? 0 );
        $form = $id > 0 ? Form_Manager::get_public( $id ) : null;
        if ( ! $form ) {
            if ( $id > 0 ) {
                echo Form_Renderer::missing_notice( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside, editors only.
            } elseif ( current_user_can( "edit_posts" ) ) {
                // Only in the editor (or for someone who can edit): visitors see nothing.
                echo '<p class="trinity-forms-elementor-empty" style="border:1px dashed #a0a5aa;padding:1em;text-align:center;">'
                    . esc_html__( "Choose a form in the Trinity Forms panel.", "trinity-forms" ) . "</p>";
            }
            return;
        }
        echo Form_Renderer::render( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes.
    }
}
