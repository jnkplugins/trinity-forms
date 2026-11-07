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

    /**
     * The form's full settings box (every tab: notifications, payments, save and
     * continue, popup, workflow...), shown inside the builder's "All settings"
     * window. Before 2.26.0 these lived only under the block editor, so someone who
     * built forms in the Visual Builder never found the Pro settings at all.
     */
    public const SETTINGS_PAGE = "trinity-forms-form-settings";

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
        if ( "canvas" !== self::site_default_editor() || ! current_user_can( Admin::CAP ) ) {
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
        if ( ! empty( $_GET["form"] ) || ! current_user_can( Admin::CAP ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen routing only.
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
     * load-post.php. `tf_editor` is the per-tab escape hatch: Gutenberg
     * history.replaceState()s it away after a save, and form-editor.js puts it
     * back, so a reload of that tab stays in the block editor. The per-form
     * setting is still what decides for a new visit.
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
            Admin::CAP,
            self::PAGE,
            [ __CLASS__, "render" ]
        );
        if ( $hook ) {
            add_action( "load-" . $hook, [ __CLASS__, "maybe_start_blank" ] );
        }

        $settings_hook = add_submenu_page(
            "edit.php?post_type=trinity_forms_form",
            __( "Form settings", "trinity-forms" ),
            __( "Form settings", "trinity-forms" ),
            Admin::CAP,
            self::SETTINGS_PAGE,
            [ __CLASS__, "render_settings" ]
        );
        // Reached from the builder only: registered (so it may be opened) but not
        // listed in the menu.
        remove_submenu_page( "edit.php?post_type=trinity_forms_form", self::SETTINGS_PAGE );
        if ( $settings_hook ) {
            add_action( "load-" . $settings_hook, [ __CLASS__, "load_settings" ] );
        }
    }

    public static function settings_url( int $form_id ): string {
        // Under the forms menu's own address: a page left out of the menu is only
        // found by WordPress through its parent (admin.php?page= answered 403).
        return admin_url( "edit.php?post_type=trinity_forms_form&page=" . self::SETTINGS_PAGE . "&form=" . $form_id );
    }

    /** The form the settings window is for, when this user may edit it. */
    private static function settings_post(): ?\WP_Post {
        $id   = isset( $_GET["form"] ) ? absint( $_GET["form"] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen selector; the save is nonce-checked by Form_Editor::save().
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || "trinity_forms_form" !== $post->post_type || ! current_user_can( "edit_post", $post->ID ) ) {
            return null;
        }
        return $post;
    }

    /** Save (through the same code as the block editor's Update), then show the box. */
    public static function load_settings(): void {
        $post = self::settings_post();
        if ( ! $post ) {
            wp_die( esc_html__( "Sorry, you are not allowed to edit this form.", "trinity-forms" ), "", [ "response" => 403 ] );
        }
        if ( "POST" === ( $_SERVER["REQUEST_METHOD"] ?? "" ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
            $nonce = isset( $_POST[ Form_Editor::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ Form_Editor::NONCE ] ) ) : "";
            if ( ! wp_verify_nonce( $nonce, Form_Editor::NONCE ) ) {
                wp_die( esc_html__( "This page was open too long. Reload it and save again.", "trinity-forms" ), "", [ "response" => 403, "back_link" => true ] );
            }
            // Form_Editor::save() and everything else that listens for a form save
            // (page-cache purge, revisions) runs as it does after the block editor.
            do_action( "save_post_trinity_forms_form", (int) $post->ID, $post, true );
            wp_safe_redirect( add_query_arg( "saved", "1", self::settings_url( (int) $post->ID ) ) );
            exit;
        }
        add_action( "admin_enqueue_scripts", static function () use ( $post ) {
            Form_Editor::enqueue_for( (int) $post->ID );
        } );
        add_filter( "admin_body_class", static fn( $c ) => $c . " tf-settings-embed" );
        // A page left out of the menu has no title of its own (PHP 8.1 warns).
        $GLOBALS["title"] = __( "Form settings", "trinity-forms" ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        // The window has no admin menu, bar or footer of its own: the builder around
        // it is the page. Links leave the window (target _top); the form stays in it.
        add_action( "admin_head", static function () {
            ?>
            <base target="_top" />
            <style>
            .tf-settings-embed #adminmenumain, .tf-settings-embed #wpadminbar, .tf-settings-embed #wpfooter, .tf-settings-embed #wpbody-content > .notice, .tf-settings-embed .tf-settings-wrap > .notice:not(.tf-settings-notice), .tf-settings-embed #screen-meta-links { display: none !important; }
            html.wp-toolbar { padding-top: 0 !important; }
            .tf-settings-embed #wpcontent, .tf-settings-embed #wpbody-content { margin-left: 0 !important; padding: 0 0 72px !important; }
            .tf-settings-embed .tf-settings-wrap { margin: 0; padding: 12px 16px; }
            .tf-settings-embed .tf-settings-save { position: fixed; bottom: 0; left: 0; right: 0; padding: 12px 16px; background: #fff; border-top: 1px solid #dcdcde; z-index: 10; }
            </style>
            <?php
        } );
    }

    public static function render_settings(): void {
        $post = self::settings_post();
        if ( ! $post ) {
            return;
        }
        $GLOBALS["post"] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the settings box reads the current post, as on post.php.
        setup_postdata( $post );
        ?>
        <div class="wrap tf-settings-wrap">
            <h1 class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: form title */ __( "Settings for %s", "trinity-forms" ), get_the_title( $post ) ) ); ?></h1>
            <?php if ( isset( $_GET["saved"] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only. ?>
                <div class="notice notice-success inline tf-settings-notice"><p><?php esc_html_e( "Settings saved.", "trinity-forms" ); ?></p></div>
                <script>try { window.parent.postMessage( { trinityFormsSettingsSaved: <?php echo (int) $post->ID; ?> }, window.location.origin ); } catch ( e ) {}</script>
            <?php endif; ?>
            <form method="post" target="_self" action="<?php echo esc_url( self::settings_url( (int) $post->ID ) ); ?>">
                <div id="trinity-forms-form-settings" class="postbox"><div class="inside">
                    <?php Form_Editor::render_meta_box( $post ); ?>
                </div></div>
                <p class="tf-settings-save"><button type="submit" class="button button-primary"><?php esc_html_e( "Save settings", "trinity-forms" ); ?></button></p>
            </form>
        </div>
        <?php
        wp_reset_postdata();
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
            // The build also writes a mirrored style-index-rtl.css; WordPress swaps
            // it in for Arabic, Hebrew and other right-to-left sites.
            if ( is_file( TRINITY_FORMS_PATH . "builder/build/style-index-rtl.css" ) ) {
                wp_style_add_data( "trinity-forms-builder", "rtl", "replace" );
            }
        }

        $form_id = isset( $_GET["form"] ) ? absint( $_GET["form"] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector.
        // The client fetches the form through Builder_REST rather than receiving a
        // pre-flattened copy: a localized field list could only ever be a lossy
        // summary, and saving from a lossy model is how a builder eats a form.
        wp_localize_script( "trinity-forms-builder", "TrinityFormsBuilder", (array) apply_filters( "trinity_forms_builder_config", [
            "formId"         => $form_id,
            "nonce"          => wp_create_nonce( "wp_rest" ),
            "blockEditorUrl" => $form_id ? self::block_editor_url( $form_id ) : "",
            // Every setting the form has, in a window inside the builder.
            "allSettingsUrl" => $form_id ? self::settings_url( $form_id ) : "",
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
