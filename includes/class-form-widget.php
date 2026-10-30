<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * "Trinity Forms" classic widget: a chosen form in a sidebar, in SiteOrigin Page
 * Builder's widget picker, or as a Legacy Widget block in a block widget area.
 */
class Form_Widget extends \WP_Widget {

    public function __construct() {
        parent::__construct(
            "trinity_forms_widget",
            __( "Trinity Forms", "trinity-forms" ),
            [
                "description"                 => __( "Show one of your forms.", "trinity-forms" ),
                "classname"                   => "widget_trinity_forms",
                "customize_selective_refresh" => true,
                "show_instance_in_rest"       => true,
            ]
        );
    }

    /** The registered (non-namespaced) class: see class-trinity-forms-widget.php. */
    public const CLASS_NAME = "Trinity_Forms_Widget";

    public static function register(): void {
        require_once __DIR__ . "/class-trinity-forms-widget.php";
        add_action( "widgets_init", static function (): void {
            register_widget( self::CLASS_NAME );
        } );
        // Page Builder layouts saved with the old namespaced name open (and render)
        // as the plain one. Sidebars and Legacy Widget blocks key on the id_base,
        // which did not change.
        add_filter( "siteorigin_panels_widget_class", [ self::class, "siteorigin_class" ], 20 );
        add_filter( "siteorigin_panels_data", [ self::class, "siteorigin_data" ], 5 );
    }

    /**
     * @param mixed $class
     * @return mixed
     */
    public static function siteorigin_class( $class ) {
        if ( is_string( $class ) && strpos( $class, "Form_Widget" ) !== false
            && ltrim( (string) preg_replace( '/\\\\+/', "\\", $class ), "\\" ) === self::class ) {
            return self::CLASS_NAME;
        }
        return $class;
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    public static function siteorigin_data( $data ) {
        if ( ! is_array( $data ) || empty( $data["widgets"] ) || ! is_array( $data["widgets"] ) ) {
            return $data;
        }
        foreach ( $data["widgets"] as $i => $widget ) {
            foreach ( [ "panels_info", "info" ] as $key ) {
                if ( is_array( $widget ) && isset( $widget[ $key ]["class"] ) ) {
                    $data["widgets"][ $i ][ $key ]["class"] = self::siteorigin_class( $widget[ $key ]["class"] );
                }
            }
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed> $instance
     */
    public function widget( $args, $instance ): void {
        $form = Form_Manager::get_public( (int) ( $instance["form_id"] ?? 0 ) );
        if ( ! $form ) {
            $id = (int) ( $instance["form_id"] ?? 0 );
            if ( $id > 0 ) {
                echo Form_Renderer::missing_notice( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside, admins only.
            }
            return;
        }
        $title = apply_filters( "widget_title", (string) ( $instance["title"] ?? "" ), $instance, $this->id_base );
        echo $args["before_widget"] ?? ""; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
        if ( "" !== $title ) {
            echo ( $args["before_title"] ?? "" ) . esc_html( $title ) . ( $args["after_title"] ?? "" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
        }
        echo Form_Renderer::render( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes.
        echo $args["after_widget"] ?? ""; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
    }

    /** @param array<string,mixed> $instance */
    public function form( $instance ): string {
        $forms   = Classic_Editor::forms();
        $current = (int) ( $instance["form_id"] ?? 0 );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( "title" ) ); ?>"><?php esc_html_e( "Title (optional)", "trinity-forms" ); ?></label>
            <input class="widefat" type="text" id="<?php echo esc_attr( $this->get_field_id( "title" ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( "title" ) ); ?>" value="<?php echo esc_attr( (string) ( $instance["title"] ?? "" ) ); ?>" />
        </p>
        <?php if ( $forms ) : ?>
            <p>
                <label for="<?php echo esc_attr( $this->get_field_id( "form_id" ) ); ?>"><?php esc_html_e( "Form", "trinity-forms" ); ?></label>
                <select class="widefat" id="<?php echo esc_attr( $this->get_field_id( "form_id" ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( "form_id" ) ); ?>">
                    <option value="0"><?php esc_html_e( "Choose a form", "trinity-forms" ); ?></option>
                    <?php foreach ( $forms as $id => $title ) : ?>
                        <option value="<?php echo (int) $id; ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $title ); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
        <?php else : ?>
            <p>
                <?php esc_html_e( "You have no published forms yet.", "trinity-forms" ); ?>
                <a href="<?php echo esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=" . Templates::PAGE ) ); ?>"><?php esc_html_e( "Create a form", "trinity-forms" ); ?></a>
            </p>
        <?php endif; ?>
        <?php
        return "";
    }

    /**
     * @param array<string,mixed> $new_instance
     * @param array<string,mixed> $old_instance
     * @return array<string,mixed>
     */
    public function update( $new_instance, $old_instance ): array {
        return [
            "title"   => sanitize_text_field( (string) ( $new_instance["title"] ?? "" ) ),
            "form_id" => absint( $new_instance["form_id"] ?? 0 ),
        ];
    }
}
