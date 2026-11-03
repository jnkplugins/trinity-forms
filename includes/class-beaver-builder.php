<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * "Trinity Forms" module in Beaver Builder's panel (TF-25). Works with the free
 * Beaver Builder Lite from wordpress.org and the paid versions.
 *
 * Like the Elementor widget: pick a form, see the real form in the editor,
 * visitors get the real form. The module class extends Beaver Builder's own
 * FLBuilderModule, so it is only loaded when Beaver Builder is active.
 */
final class Beaver_Builder {

    public static function register(): void {
        add_action( "init", [ self::class, "register_module" ], 20 );
    }

    public static function register_module(): void {
        if ( ! class_exists( "FLBuilder" ) || ! class_exists( "FLBuilderModule" ) || ! method_exists( "FLBuilder", "register_module" ) ) {
            return;
        }
        // Beaver Builder names a module after its class file: "trinity-forms".
        require_once TRINITY_FORMS_PATH . "includes/beaver-builder/trinity-forms.php";
        \FLBuilder::register_module( Beaver_Builder\Form_Module::class, self::settings_form() );
    }

    /** @return array<string,mixed> Beaver Builder's settings-form array. */
    public static function settings_form(): array {
        $forms   = Classic_Editor::forms();
        $create  = admin_url( "edit.php?post_type=trinity_forms_form&page=" . Templates::PAGE );
        $fields  = [];
        if ( $forms ) {
            // A group, not one flat list: the panel draws numeric keys first, which
            // put "Choose a form" below every form (as in the Elementor widget).
            $fields["form_id"] = [
                "type"    => "select",
                "label"   => __( "Form", "trinity-forms" ),
                "default" => "",
                "options" => [
                    ""      => __( "Choose a form", "trinity-forms" ),
                    "forms" => [
                        "label"   => __( "Published forms", "trinity-forms" ),
                        "options" => $forms,
                    ],
                ],
            ];
        }
        $fields["trinity_forms_links"] = [
            "type"    => "raw",
            "content" => $forms
                ? '<p><a href="' . esc_url( admin_url( "edit.php?post_type=trinity_forms_form" ) ) . '" target="_blank">' . esc_html__( "Edit your forms", "trinity-forms" ) . '</a> · <a href="' . esc_url( $create ) . '" target="_blank">' . esc_html__( "Create a form", "trinity-forms" ) . "</a></p>"
                : "<p>" . esc_html__( "You have no published forms yet.", "trinity-forms" ) . ' <a href="' . esc_url( $create ) . '" target="_blank">' . esc_html__( "Create a form", "trinity-forms" ) . "</a></p>",
        ];
        return [
            "general" => [
                "title"    => __( "Form", "trinity-forms" ),
                "sections" => [
                    "general" => [
                        "title"  => "",
                        "fields" => $fields,
                    ],
                ],
            ],
        ];
    }

    /** The module's HTML; also what Beaver Builder shows in its editor. */
    public static function render( $settings ): string {
        $id   = (int) ( is_object( $settings ) ? ( $settings->form_id ?? 0 ) : ( (array) $settings )["form_id"] ?? 0 );
        $form = $id > 0 ? Form_Manager::get_public( $id ) : null;
        if ( $form ) {
            return Form_Renderer::render( $form );
        }
        if ( $id > 0 ) {
            return Form_Renderer::missing_notice( $id );
        }
        // Only for people who can edit: visitors see nothing.
        return current_user_can( "edit_posts" )
            ? '<p class="trinity-forms-builder-empty" style="border:1px dashed #a0a5aa;padding:1em;text-align:center;">' . esc_html__( "Choose a form in the module settings.", "trinity-forms" ) . "</p>"
            : "";
    }
}
