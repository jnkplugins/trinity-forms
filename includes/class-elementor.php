<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * The "Trinity Forms" widget in Elementor's panel.
 *
 * A shop built with Elementor had to find the shortcode and paste it into a
 * Shortcode widget; searching the panel for "trinity", "form" or "contact" found
 * nothing of ours (persona run shop-elementor, 29 Oct 2026). Registered only when
 * Elementor is active: the widget class extends Elementor's own base class, so it
 * is loaded inside Elementor's register hook and nowhere else.
 */
final class Elementor {

    public static function register(): void {
        add_action( "elementor/widgets/register", [ self::class, "register_widget" ] );
        // Elementor also lists every classic WordPress widget, so the panel showed
        // "Trinity Forms" twice. Keep the Elementor one.
        add_filter( "elementor/widgets/black_list", [ self::class, "hide_classic_widget" ] );
    }

    /** @param mixed $list */
    public static function hide_classic_widget( $list ): array {
        $list   = is_array( $list ) ? $list : [];
        $list[] = Form_Widget::CLASS_NAME;
        return $list;
    }

    /** @param object $widgets_manager Elementor's \Elementor\Widgets_Manager. */
    public static function register_widget( $widgets_manager ): void {
        if ( ! class_exists( "\\Elementor\\Widget_Base" ) || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, "register" ) ) {
            return;
        }
        require_once TRINITY_FORMS_PATH . "includes/elementor/class-form-widget.php";
        $widgets_manager->register( new Elementor\Form_Widget() );
    }
}
