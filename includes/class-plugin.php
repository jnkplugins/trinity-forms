<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

final class Plugin {
    private static ?self $instance = null;
    public static function instance(): self { return self::$instance ??= new self(); }
    private function __construct() {}

    public function boot(): void {
        // Must be added before any field block registers on init, so every one of
        // them picks up the shared field settings (width, css class, prefill, …).
        add_filter( "register_block_type_args", [ Field_Registry::class, "add_common_attributes" ], 10, 2 );

        Field_Registry::bootstrap();
        Submission_Pipeline::bootstrap();
        CLI::register();
        Scoring::bootstrap();

        // Free trust/parity features: GDPR export-erase, form analytics funnel,
        // and Akismet spam filtering (self-activates when Akismet is configured).
        Privacy::register();
        Analytics::register();
        Activity::register();
        Akismet_Check::register();
        // Email check: keeps the reason a form email failed, warns about recent
        // failures and powers the "Send a test email" buttons.
        Mail_Check::register();

        // Premium module boots here (pro/ only): licensing SDK + client, premium
        // field types, resume manager, integrations dispatch, payment adapters.
        // In the free build nothing listens, so the plugin runs as the free tier.
        do_action( "trinity_forms_boot" );

        // Before anything on init asks for a string: the .mo files ship in languages/.
        add_action( "init",          [ self::class, "load_textdomain" ], 1 );
        add_action( "init",          [ $this, "register_post_type" ] );
        add_action( "init",          [ $this, "register_blocks" ] );
        add_action( "init",          [ $this, "register_shortcodes" ] );
        add_action( "rest_api_init", [ $this, "register_rest_routes" ] );
        add_action( "rest_api_init", [ $this, "register_builder_routes" ] );
        add_action( "enqueue_block_editor_assets", [ $this, "enqueue_conditional_panel" ] );
        add_action( "enqueue_block_editor_assets", [ $this, "hide_field_blocks_outside_forms" ] );

        // Registered outside is_admin(): a form can be created over REST too, and it
        // should email someone just the same.
        add_action( "trinity_forms_form_created",  [ Form_Manager::class, "seed_default_notification" ] );
        // Before the canvas redirect (default priority 10), so whichever editor opens
        // gets block markup instead of a JSON form it would show as empty.
        add_action( "load-post.php",               [ Form_Manager::class, "upgrade_on_edit_screen" ], 5 );

        add_action( "trinity_forms_daily_cron",    [ Cron::class, "enforce_retention_policies" ] );
        add_action( "trinity_forms_daily_cron",    [ Cron::class, "purge_trashed_entries" ] );
        add_action( "trinity_forms_entry_deleted", [ Submission_Pipeline::class, "cleanup_entry_files" ] );
        Private_Files::register();

        // Bulk "resend notifications" queues one action per entry rather than
        // sending inline, so a 200-entry resend doesn't time out the request.
        add_action( "trinity_forms_resend_notification", [ Notification_Engine::class, "dispatch" ] );

        add_filter( "the_content", [ Form_Renderer::class, "maybe_render_resume_notice" ] );

        add_action( "wp_enqueue_scripts", [ Public_Front::class, "enqueue_assets" ] );

        if ( is_admin() ) {
            // Apply pending schema migrations on update (the activation hook does
            // not fire on in-place upgrades, e.g. free → Pro via zip upload).
            add_action( "admin_init", [ Activator::class, "maybe_upgrade" ] );
            Schema_Check::register();
            Admin::bootstrap();
            Onboarding::register();
            Templates::bootstrap();
            Results::bootstrap();
            // Visual builder (Phase 7): dormant unless explicitly enabled.
            Form_Builder::register();
        }
    }

    public function register_post_type(): void {
        register_post_type( "trinity_forms_form", [
            "label"        => __( "Trinity Forms Forms", "trinity-forms" ),
            // The menu has to read "Trinity Forms → All Forms", because that is how
            // the docs, the readme and every support reply describe it. A menu
            // labelled just "Forms" sends people looking for a plugin they have
            // already installed.
            "labels"       => [
                "name"          => __( "Trinity Forms", "trinity-forms" ),
                "singular_name" => __( "Form",          "trinity-forms" ),
                "all_items"     => __( "All Forms",     "trinity-forms" ),
                "add_new_item"  => __( "Add Form",      "trinity-forms" ),
                "edit_item"     => __( "Edit Form",     "trinity-forms" ),
                "not_found"     => __( "No forms yet — start from a template, or add a blank one.", "trinity-forms" ),
            ],
            "public"       => false,
            "show_ui"      => true,
            "show_in_menu" => true,
            "show_in_rest" => true,
            "menu_icon"    => "dashicons-feedback",
            "supports"     => [ "title", "editor", "custom-fields" ],
            "capability_type" => "post",
            "template"     => [
                [ "trinity-forms/field-text",     [ "label" => "Name",  "required" => true ] ],
                [ "trinity-forms/field-email",    [ "label" => "Email", "required" => true ] ],
                [ "trinity-forms/field-textarea", [ "label" => "Message" ] ],
            ],
        ] );
    }

    public function register_blocks(): void {
        add_filter( "block_categories_all", [ $this, "register_block_category" ] );

        // Free-tier block types only. Premium field blocks (signature /
        // payment / repeater) and the frontend submissions-table block live in
        // pro/ and register themselves on the trinity_forms_register_blocks action.
        $blocks = [
            "form-block",
            "field-text",
            "field-email",
            "field-textarea",
            "field-select",
            "field-number",
            "field-range",
            "field-name",
            "field-phone",
            "field-address",
            "field-country",
            "field-radio",
            "field-checkbox",
            "field-date",
            "field-time",
            "field-hidden",
            "field-website",
            "field-password",
            "field-multiselect",
            "field-image-choice",
            "field-multiple-choice",
            "field-list",
            "field-product",
            "field-quantity",
            "field-option",
            "field-shipping",
            "field-total",
            "field-html",
            "field-page-break",
            "field-section",
            "field-rating",
            "field-consent",
            "field-file",
        ];
        foreach ( $blocks as $slug ) {
            self::register_block_dir( TRINITY_FORMS_PATH . "build/{$slug}", TRINITY_FORMS_PATH . "blocks/{$slug}" );
        }

        // Premium blocks self-register here (pro/ only).
        do_action( "trinity_forms_register_blocks" );
    }

    /**
     * Conditional-logic + pattern Inspector controls for every field block.
     *
     * Not a block itself, so it has no block.json for wp-scripts to discover — it is
     * added as an explicit entry in webpack.config.js and enqueued here.
     */
    /**
     * Field blocks only mean something inside a form. On pages, posts, widgets and
     * the site editor they crowded the inserter with generic names ("Text",
     * "Email", "HTML", "List") next to core blocks, and did nothing when added.
     * Only the Form block (and the Pro view blocks) stay available there.
     */
    public function hide_field_blocks_outside_forms(): void {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( $screen && "trinity_forms_form" === $screen->post_type ) {
            return;
        }
        wp_add_inline_script( "wp-blocks", self::field_block_hide_script(), "after" );
    }

    /** The inline script used by hide_field_blocks_outside_forms(); public for tests. */
    public static function field_block_hide_script(): string {
        return 'wp.domReady(function(){var b=wp.blocks;if(!b||!b.getBlockTypes){return;}'
            . 'b.getBlockTypes().forEach(function(t){if(/^trinity-forms\/field-/.test(t.name)){b.unregisterBlockType(t.name);}});});';
    }

    public function enqueue_conditional_panel(): void {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== "trinity_forms_form" ) {
            return;
        }
        $asset = TRINITY_FORMS_PATH . "build/conditional-panel.asset.php";
        if ( ! is_file( $asset ) ) {
            return;
        }
        $meta = require $asset;
        wp_enqueue_script(
            "trinity-forms-conditional-panel",
            TRINITY_FORMS_URL . "build/conditional-panel.js",
            $meta["dependencies"],
            $meta["version"],
            true
        );
        self::script_translations( "trinity-forms-conditional-panel" );
    }

    /** Prefer the built block (compiled editorScript); fall back to the source dir. */
    public static function register_block_dir( string $built, string $source ): void {
        $dir = is_file( $built . "/block.json" ) ? $built : $source;
        if ( ! is_file( $dir . "/block.json" ) ) {
            return;
        }
        $type = register_block_type( $dir );
        // block.json scripts only look for translations in wp-content/languages;
        // ours ship inside the plugin.
        if ( $type instanceof \WP_Block_Type ) {
            foreach ( (array) $type->editor_script_handles as $handle ) {
                self::script_translations( (string) $handle );
            }
        }
    }

    /** The plugin's own .po/.mo files live in languages/ and ship in the zip. */
    public static function load_textdomain(): void {
        load_plugin_textdomain( "trinity-forms", false, dirname( plugin_basename( TRINITY_FORMS_FILE ) ) . "/languages" );
    }

    /** Point a wp.i18n script at the JSON translations shipped in languages/. */
    public static function script_translations( string $handle ): void {
        if ( function_exists( "wp_set_script_translations" ) ) {
            wp_set_script_translations( $handle, "trinity-forms", TRINITY_FORMS_PATH . "languages" );
        }
    }

    public function register_block_category( array $categories ): array {
        foreach ( $categories as $c ) {
            if ( ( $c["slug"] ?? "" ) === "trinity-forms" ) {
                return $categories;
            }
        }
        array_unshift( $categories, [
            "slug"  => "trinity-forms",
            "title" => __( "Trinity Forms Fields", "trinity-forms" ),
            "icon"  => "feedback",
        ] );
        return $categories;
    }

    public function register_shortcodes(): void {
        Shortcode::register();
    }

    public function register_builder_routes(): void {
        ( new Builder_REST() )->register_routes();
    }

    public function register_rest_routes(): void {
        ( new REST_API() )->register_routes();
    }
}
