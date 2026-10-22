<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Visual (drag-and-drop) form builder: the admin screen, the per-form "which editor"
 * choice, and the routing between the two editors.
 *
 * On by default as of 2.1: the drag-and-drop canvas is what people expect from a form
 * builder, so new forms open in it (Forms → Settings → "Open forms in" → Visual builder).
 * Upgrading sites keep their editing experience: Activator::grandfather_existing_forms()
 * stamps every form that existed before the upgrade with an explicit "block" choice, and
 * an explicit per-form choice always wins in editor_for(). So "on by default" applies to
 * forms created from here on, never retroactively. Force it either way with the
 * TRINITY_FORMS_BUILDER constant, or override the resolved value with the
 * `trinity_forms_enable_builder` filter. The block editor always stays available
 * (per-form editor choice + the "Open in block editor" link).
 *
 * The React app lives in builder/src (built to builder/build via
 * `npm run build:builder`) and reads/writes the form through Builder_REST, which
 * round-trips the same block markup the block editor writes. Neither editor owns the
 * form: the choice here is UI routing, not a fork in the data.
 */
class Form_Builder {

    private const PAGE = "trinity-forms-builder";

    public static function enabled(): bool {
        if ( defined( "TRINITY_FORMS_BUILDER" ) ) {
            return (bool) TRINITY_FORMS_BUILDER;
        }
        // Available whichever editor is the site default. The default only decides
        // where "Add Form" and a form's Edit link go (site_default_editor() and
        // editor_for()); tying the whole screen to it left the "Visual Builder" menu
        // item saying "Sorry, you are not allowed to access this page".
        return (bool) apply_filters( "trinity_forms_enable_builder", true );
    }

    public static function register(): void {
        if ( ! self::enabled() ) {
            return;
        }
        add_action( "admin_menu", [ __CLASS__, "add_page" ] );
        add_action( "admin_enqueue_scripts", [ __CLASS__, "enqueue" ] );

        // Per-form "which editor" choice, stored alongside every other form setting.
        add_action( "trinity_forms_form_editor_general_rows", [ __CLASS__, "render_setting_row" ], 10, 2 );
        // Priority is irrelevant here, but the FILTER is not optional: Form_Editor's
        // sanitize_settings() merges a fixed key list, so a setting added only to the
        // view would silently never persist.
        add_filter( "trinity_forms_save_form_meta", [ __CLASS__, "save_meta" ], 20, 3 );

        add_action( "load-post.php", [ __CLASS__, "maybe_redirect_to_canvas" ] );
        add_action( "load-post-new.php", [ __CLASS__, "maybe_start_new_in_canvas" ] );
    }

    /**
     * "Add Form" while the site opens forms in the visual builder: start the new form
     * there, not in the block editor. Same for the builder's own menu item, which
     * used to open on form 0 and answer "Sorry, you are not allowed to do that."
     *
     * A draft is created first because the builder edits an existing post through
     * Builder_REST; the block editor makes its auto-draft the same way.
     */
    public static function maybe_start_new_in_canvas(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen routing only; creating an empty draft is what post-new.php itself does.
        if ( "trinity_forms_form" !== ( isset( $_GET["post_type"] ) ? sanitize_key( wp_unslash( $_GET["post_type"] ) ) : "" ) ) {
            return;
        }
        if ( isset( $_GET["tf_editor"] ) && "block" === sanitize_key( wp_unslash( $_GET["tf_editor"] ) ) ) {
            return;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( "canvas" !== self::site_default_editor() || ! current_user_can( "edit_posts" ) ) {
            return;
        }
        $id = self::create_draft();
        if ( $id ) {
            wp_safe_redirect( self::builder_url( $id ) );
            exit;
        }
    }

    /** The builder screen opened with no form: make one and show it. */
    public static function maybe_start_blank(): void {
        if ( ! empty( $_GET["form"] ) || ! current_user_can( "edit_posts" ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen routing only.
            return;
        }
        $id = self::create_draft();
        if ( $id ) {
            wp_safe_redirect( self::builder_url( $id ) );
            exit;
        }
    }

    private static function site_default_editor(): string {
        $settings = get_option( "trinity_forms_settings", [] );
        return "block" === ( is_array( $settings ) ? ( $settings["default_editor"] ?? "canvas" ) : "canvas" ) ? "block" : "canvas";
    }

    private static function create_draft(): int {
        $id = wp_insert_post( [
            "post_type"    => "trinity_forms_form",
            // An auto-draft, like the one post-new.php makes: WordPress deletes it after
            // a week if nobody saves it, and it stays out of the forms list and every
            // form picker. Every visit to Add Form used to leave a permanent
            // "Untitled form" draft behind. The first save in the builder promotes it.
            "post_status"  => "auto-draft",
            "post_title"   => __( "Untitled form", "trinity-forms" ),
            "post_content" => "",
        ], true );
        if ( is_wp_error( $id ) || ! $id ) {
            return 0;
        }
        do_action( "trinity_forms_form_created", (int) $id );
        return (int) $id;
    }

    /* ------------------------------------------------------------ which editor */

    /**
     * Which editor a form opens in: its own choice, else the site default.
     *
     * The site default is "canvas" — drag-and-drop is what people expect from a form
     * builder, and it is the first thing they should meet.
     */
    public static function editor_for( ?Form $form ): string {
        $per_form = $form instanceof Form ? (string) $form->get_setting( "editor", "" ) : "";
        if ( in_array( $per_form, [ "canvas", "block" ], true ) ) {
            return $per_form;
        }

        $default = (string) ( get_option( "trinity_forms_settings", [] )["default_editor"] ?? "canvas" );
        $default = in_array( $default, [ "canvas", "block" ], true ) ? $default : "canvas";

        // Safety valve, reachable only when the form made no explicit choice (an
        // explicit one already returned above). A form holding blocks the canvas can't
        // model (columns, prose, a premium field this site can't register) would open
        // as a wall of locked cards, so send it to the editor that CAN edit it.
        //
        // No `$default === "canvas"` guard: where the default is already "block" this
        // returns "block" either way, so the condition would be unreachable logic.
        if ( $form instanceof Form && self::has_opaque_blocks( $form ) ) {
            return "block";
        }
        return $default;
    }

    /** True when the form contains anything the canvas can only show as a locked card. */
    private static function has_opaque_blocks( Form $form ): bool {
        $post = get_post( $form->id );
        if ( ! $post ) {
            return false;
        }
        foreach ( Builder_REST::blocks_for( (string) $post->post_content ) as $block ) {
            if ( ( $block["kind"] ?? "" ) === "opaque" ) {
                return true;
            }
        }
        return false;
    }

    public static function render_setting_row( $form, $settings ): void {
        $choice = (string) ( ( (array) $settings )["editor"] ?? "" );
        $url    = $form instanceof Form ? self::builder_url( $form->id ) : "";
        ?>
        <tr>
            <th scope="row"><?php esc_html_e( "Edit this form with", "trinity-forms" ); ?></th>
            <td>
                <select name="trinity-forms[settings][editor]">
                    <option value=""       <?php selected( $choice, "" ); ?>><?php esc_html_e( "Site default", "trinity-forms" ); ?></option>
                    <option value="canvas" <?php selected( $choice, "canvas" ); ?>><?php esc_html_e( "Visual builder (drag and drop)", "trinity-forms" ); ?></option>
                    <option value="block"  <?php selected( $choice, "block" ); ?>><?php esc_html_e( "Block editor", "trinity-forms" ); ?></option>
                </select>
                <p class="description">
                    <?php esc_html_e( "Both editors read and write the same form — switch whenever you like.", "trinity-forms" ); ?>
                    <?php if ( $url ) : ?>
                        <a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( "Open in the visual builder", "trinity-forms" ); ?></a>
                    <?php endif; ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array $meta     the meta being saved
     * @param array $raw      sanitized raw POST (trinity-forms[...])
     * @param array $existing previously-stored meta
     */
    public static function save_meta( array $meta, array $raw, array $existing ): array {
        if ( ! isset( $meta["settings"] ) || ! is_array( $meta["settings"] ) ) {
            $meta["settings"] = [];
        }
        $choice = (string) ( $raw["settings"]["editor"] ?? "" );
        $meta["settings"]["editor"] = in_array( $choice, [ "canvas", "block" ], true ) ? $choice : "";
        return $meta;
    }

    /* ---------------------------------------------------------------- routing */

    /**
     * Send a canvas-mode form to the builder when it is opened for editing.
     *
     * No redirect loop is possible: the builder lives on admin.php, which never fires
     * load-post.php. `tf_editor` is the per-page-load escape hatch — Gutenberg
     * history.replaceState()s it away after a save, which is correct: the per-form
     * setting is the sticky control, not the URL.
     */
    public static function maybe_redirect_to_canvas(): void {
        // Route page views only. The block editor saves its metaboxes with a POST
        // back to post.php (meta-box-loader=1), and Gutenberg has already
        // replaceState()d `tf_editor` out of the URL by then — so redirecting a POST
        // here threw that save away before it ran, silently losing notifications and
        // every other per-form setting edited in the block editor.
        if ( ( $_SERVER["REQUEST_METHOD"] ?? "GET" ) !== "GET" ) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
        $id = isset( $_GET["post"] ) ? absint( $_GET["post"] ) : 0;
        // "message" is WordPress' return trip after a save (the block editor's
        // meta-box POST follows it in the background). Routing that to the builder
        // loaded the whole builder unseen and used up the save's admin notices.
        if ( ! $id || ( $_GET["action"] ?? "" ) !== "edit" || isset( $_GET["tf_editor"] ) || isset( $_GET["message"] ) ) {
            return;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== "trinity_forms_form" || ! current_user_can( "edit_post", $id ) ) {
            return;
        }
        if ( self::editor_for( Form_Manager::get( $id ) ) !== "canvas" ) {
            return;
        }
        wp_safe_redirect( self::builder_url( $id ) );
        exit;
    }

    public static function builder_url( int $form_id ): string {
        return admin_url( "admin.php?page=" . self::PAGE . "&form=" . $form_id );
    }

    /** The block editor, bypassing the canvas redirect for this page load. */
    public static function block_editor_url( int $form_id ): string {
        return admin_url( "post.php?post=" . $form_id . "&action=edit&tf_editor=block" );
    }

    public static function add_page(): void {
        $hook = add_submenu_page(
            "edit.php?post_type=trinity_forms_form",
            __( "Visual Builder", "trinity-forms" ),
            __( "Visual Builder", "trinity-forms" ),
            "edit_posts",
            self::PAGE,
            [ __CLASS__, "render" ]
        );
        if ( $hook ) {
            add_action( "load-" . $hook, [ __CLASS__, "maybe_start_blank" ] );
        }
    }

    public static function render(): void {
        // The same branded top bar as every other Trinity screen; the builder draws
        // its own title bar, so there is no page header.
        Admin_UI::open( __( "Visual Builder", "trinity-forms" ), "", "", [ "head" => false, "class" => "tf-builder-page" ] );
        echo '<h1 class="screen-reader-text">' . esc_html__( "Visual Builder", "trinity-forms" ) . '</h1><div id="trinity-forms-builder-root"></div>';
        Admin_UI::close();
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, self::PAGE ) === false ) {
            return;
        }
        $build = TRINITY_FORMS_PATH . "builder/build/index.js";
        $asset = TRINITY_FORMS_PATH . "builder/build/index.asset.php";
        if ( ! is_file( $build ) || ! is_file( $asset ) ) {
            add_action( "admin_notices", static function () {
                echo '<div class="notice notice-warning"><p>'
                    . esc_html__( "Trinity Forms Visual Builder: run `npm run build:builder` to compile the builder assets.", "trinity-forms" )
                    . '</p></div>';
            } );
            return;
        }
        $meta = require $asset;
        wp_enqueue_script( "trinity-forms-builder", TRINITY_FORMS_URL . "builder/build/index.js", $meta["dependencies"], $meta["version"], true );
        Plugin::script_translations( "trinity-forms-builder" );
        wp_enqueue_style( "wp-components" );
        // wp-scripts emits the imported stylesheet as style-index.css.
        if ( is_file( TRINITY_FORMS_PATH . "builder/build/style-index.css" ) ) {
            wp_enqueue_style( "trinity-forms-builder", TRINITY_FORMS_URL . "builder/build/style-index.css", [ "wp-components" ], $meta["version"] );
        }

        $form_id = isset( $_GET["form"] ) ? absint( $_GET["form"] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector.
        // The client fetches the form through Builder_REST rather than receiving a
        // pre-flattened copy: a localized field list could only ever be a lossy
        // summary, and saving from a lossy model is how a builder eats a form.
        wp_localize_script( "trinity-forms-builder", "TrinityFormsBuilder", (array) apply_filters( "trinity_forms_builder_config", [
            "formId"         => $form_id,
            "nonce"          => wp_create_nonce( "wp_rest" ),
            "blockEditorUrl" => $form_id ? self::block_editor_url( $form_id ) : "",
            // The starter library already creates a form and redirects to post.php,
            // which this class routes straight here — so the builder needs no gallery
            // of its own, just a way in from an empty canvas.
            "templatesUrl"   => admin_url( "edit.php?post_type=trinity_forms_form&page=" . Templates::PAGE ),
            "settingsUrl"    => admin_url( "edit.php?post_type=trinity_forms_form&page=" . Admin::SETTINGS ),
            // Extensions (Pro's AI assistant) add their own entry points here.
            "aiUrl"          => "",
        ], $form_id ) );
    }
}
