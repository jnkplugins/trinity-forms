<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Public_Front {

    /** Set once something on this request draws Trinity output (see need_assets()). */
    private static bool $wanted = false;

    /** The page CSS/JS were put in the queue on this request. */
    private static bool $queued = false;

    /**
     * wp_enqueue_scripts: register the page CSS/JS, and queue them only when this page
     * shows a form. A page without one gets no Trinity file at all (until 2.26.0 every
     * page of the site loaded about 105 KB). Found two ways:
     *  - early, here: a Trinity shortcode or block in the content of the posts this page
     *    shows, so the CSS goes in <head> as before;
     *  - late, from need_assets(): a form drawn anywhere else (a widget, a page builder,
     *    a theme template, a popup). The script still runs; the stylesheet is printed in
     *    the footer, which WordPress supports for styles queued after <head>.
     * Forms fetched by AJAX are invisible to both: the trinity_forms_load_assets_everywhere
     * filter loads the files on every page for such sites.
     */
    public static function enqueue_assets(): void {
        self::register_assets();
        if ( self::$wanted || self::page_needs_assets() ) {
            self::queue();
        }
    }

    /**
     * Something on this page draws Trinity output: make sure its CSS/JS load. Safe to
     * call any number of times, before or after wp_enqueue_scripts.
     */
    public static function need_assets(): void {
        self::$wanted = true;
        if ( ! did_action( "wp_enqueue_scripts" ) ) {
            return; // enqueue_assets() will see the flag.
        }
        if ( ! wp_script_is( "trinity-forms", "registered" ) ) {
            self::register_assets();
        }
        self::queue();
    }

    /** Whether the posts this request shows hold a Trinity shortcode or block. */
    public static function page_needs_assets(): bool {
        /**
         * Filter: trinity_forms_load_assets_everywhere. Return true to load the form
         * CSS/JS on every front-end page, for a site that fetches forms with AJAX
         * (infinite scroll, a modal that loads a page) where no form is in the page
         * at first.
         */
        if ( apply_filters( "trinity_forms_load_assets_everywhere", false ) ) {
            return true;
        }
        global $wp_query;
        foreach ( (array) ( $wp_query->posts ?? [] ) as $post ) {
            if ( $post instanceof \WP_Post && self::content_has_trinity( (string) $post->post_content ) ) {
                return true;
            }
        }
        return false;
    }

    /** A Trinity shortcode ([trinity-forms ...], [trinity_forms_...]) or block. */
    public static function content_has_trinity( string $content ): bool {
        return false !== strpos( $content, "[trinity" ) || false !== strpos( $content, "<!-- wp:trinity-forms/" );
    }

    private static function queue(): void {
        if ( self::$queued ) {
            return;
        }
        self::$queued = true;
        $styles_before  = wp_styles()->queue;
        $scripts_before = wp_scripts()->queue;
        wp_enqueue_style( "trinity-forms" );
        wp_enqueue_script( "trinity-forms" );
        /** Action: trinity_forms_enqueue_assets. The page shows Trinity output; queue add-on CSS/JS here. */
        do_action( "trinity_forms_enqueue_assets" );

        // A form drawn after WordPress printed the footer scripts (a theme or a popup
        // plugin printing after wp_footer's scripts): nothing would print the queue any
        // more, and the form would show unstyled with no script (QA 7 Nov). Print our
        // own files here instead; other plugins' queued files are left alone.
        if ( did_action( "wp_print_footer_scripts" ) ) {
            wp_print_styles( array_values( array_diff( wp_styles()->queue, $styles_before ) ) );
            wp_print_scripts( array_values( array_diff( wp_scripts()->queue, $scripts_before ) ) );
        }
    }

    /** Minified files when the build made them and SCRIPT_DEBUG is off. */
    public static function asset_url( string $path ): string {
        $min = preg_replace( '/\.(css|js)$/', '.min.$1', $path );
        if ( ! ( defined( "SCRIPT_DEBUG" ) && SCRIPT_DEBUG ) && is_string( $min ) && is_readable( TRINITY_FORMS_PATH . $min ) ) {
            $path = $min;
        }
        return TRINITY_FORMS_URL . $path;
    }

    /** Register the page CSS/JS and their data (queued separately, see enqueue_assets()). */
    public static function register_assets(): void {
        wp_register_style( "trinity-forms", self::asset_url( "public/assets/css/public.css" ), [], TRINITY_FORMS_VERSION );
        wp_register_script( "trinity-forms", self::asset_url( "public/assets/js/public.js" ), [], TRINITY_FORMS_VERSION, true );

        $settings  = get_option( "trinity_forms_settings", [] );
        $provider   = Anti_Spam::captcha_provider();
        $site_key   = self::captcha_site_key();
        $script_url = Anti_Spam::CAPTCHA_PROVIDERS[ $provider ]["script"];

        wp_localize_script( "trinity-forms", "TrinityFormsData", [
            "rest_url"           => esc_url_raw( rest_url( "trinity-forms/v1/" ) ),
            "nonce"              => wp_create_nonce( "wp_rest" ),
            "captcha_provider"   => $provider,
            "turnstile_site_key" => $site_key,
            // A fresh nonce for a page served from a cache (see ajax_nonce()).
            "ajax_url"           => admin_url( "admin-ajax.php" ),
        ] );
        wp_localize_script( "trinity-forms", "TrinityFormsI18n", self::script_strings() );

        if ( $site_key !== "" ) {
            // reCAPTCHA v3 needs the site key in the script URL and an executor that
            // fetches a fresh score token into each form's hidden field.
            if ( $provider === "recaptcha_v3" ) {
                $script_url = add_query_arg( "render", rawurlencode( $site_key ), $script_url );
            }
            // Registered, not enqueued: the vendor script is only printed when a form
            // with its bot challenge switched on is actually rendered on the page
            // (see enqueue_captcha()). Saving site-wide keys alone must not send
            // every visitor of every form page to Cloudflare / Google / hCaptcha.
            // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent,WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the external CAPTCHA widget script (Cloudflare Turnstile / Google reCAPTCHA / hCaptcha) must be served from its vendor (cannot be bundled) and is intentionally unversioned; loaded only on a form whose bot challenge is enabled (opt-in, off by default), disclosed in readme.txt.
            wp_register_script( "trinity-forms-turnstile", $script_url, [], null, true );
            add_filter( "script_loader_tag", [ self::class, "make_turnstile_async" ], 10, 2 );

            if ( $provider === "recaptcha_v3" ) {
                $js = "grecaptcha.ready(function(){function tf(){grecaptcha.execute(" . wp_json_encode( $site_key )
                    . ",{action:'submit'}).then(function(t){document.querySelectorAll('.trinity-forms-recaptcha-v3-token').forEach(function(el){el.value=t;});});}tf();setInterval(tf,100000);});";
                wp_add_inline_script( "trinity-forms-turnstile", $js );
            }
        }
    }

    /** Everything the page script says to a visitor, in the site's language. */
    public static function script_strings(): array {
        return [
            "oneProblem"   => __( "There is one thing to fix:", "trinity-forms" ),
            "someProblems" => __( "There are some things to fix:", "trinity-forms" ),
            "thanks"       => __( "Thanks — your submission was received.", "trinity-forms" ),
            "failed"       => __( "Submission failed.", "trinity-forms" ),
            "network"      => __( "Network error — please try again.", "trinity-forms" ),
            "stale"        => __( "This page is out of date. Please reload it and try again.", "trinity-forms" ),
            /* translators: 1: file name, 2: the largest size accepted, e.g. "2 MB". */
            "fileTooBig"   => __( "%1\$s is too big. Files can be up to %2\$s.", "trinity-forms" ),
            "tooLarge"     => __( "The file is too big to send.", "trinity-forms" ),
            /* translators: 1: current step number, 2: number of steps. */
            "stepOf"       => __( "Step %1\$d of %2\$d", "trinity-forms" ),
            "required"     => __( "This field is required.", "trinity-forms" ),
            "badEmail"     => __( "Please enter a valid email address.", "trinity-forms" ),
            "resumeAsk"    => __( "Email me a link to finish this form later", "trinity-forms" ),
            "sendLink"     => __( "Send link", "trinity-forms" ),
            "cancel"       => __( "Cancel", "trinity-forms" ),
            "saving"       => __( "Saving…", "trinity-forms" ),
            "savedEmailed" => __( "Saved. We've emailed you a link to resume.", "trinity-forms" ),
            /* translators: %s: the link that resumes the form. */
            "savedLink"    => __( "Saved. Bookmark this URL to resume: %s", "trinity-forms" ),
            "saveFailed"   => __( "Could not save progress.", "trinity-forms" ),
            "addItem"      => __( "Add item", "trinity-forms" ),
            "remove"       => __( "Remove", "trinity-forms" ),
        ];
    }

    /**
     * A fresh wp_rest nonce for the page script.
     *
     * A page served from a full-page cache (or a tab left open overnight) carries a
     * nonce that expires after 12-24 hours, and WordPress refuses the submit with
     * "Cookie check failed" before our route runs, so every lead was lost without a
     * trace. admin-ajax, not REST: the REST API answers any origin with credentials,
     * which would hand a logged-in user's nonce to another site; admin-ajax sends no
     * CORS headers (the same reason core's own "rest-nonce" action lives there).
     */
    public static function ajax_nonce(): void {
        nocache_headers();
        wp_send_json_success( [ "nonce" => wp_create_nonce( "wp_rest" ) ] );
    }

    /**
     * Fresh time tokens for the forms on the page, fetched by the page script when
     * the page opens. admin-ajax is never served from a page cache, so a cached
     * page still times each visitor from when they arrived (Anti_Spam::time_token()).
     */
    public static function ajax_time_token(): void {
        nocache_headers();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public and read-only: a nonce would itself be stale on a cached page.
        $ids    = isset( $_POST["form_ids"] ) ? array_slice( array_map( "absint", (array) wp_unslash( $_POST["form_ids"] ) ), 0, 20 ) : [];
        $tokens = [];
        foreach ( array_unique( $ids ) as $id ) {
            if ( $id > 0 && Form_Manager::get_public( $id ) ) {
                $tokens[ (string) $id ] = Anti_Spam::time_token( $id, Anti_Spam::TOKEN_FETCHED );
            }
        }
        wp_send_json_success( [ "tokens" => (object) $tokens ] );
    }

    /** The configured provider's site key, in plain text (older installs stored it encrypted). */
    public static function captcha_site_key(): string {
        $settings = get_option( "trinity_forms_settings", [] );
        $provider = Anti_Spam::captcha_provider();
        return Secrets::decrypt( (string) ( ( is_array( $settings ) ? ( $settings[ $provider ] ?? [] ) : [] )["site_key"] ?? "" ) );
    }

    /** Print the CAPTCHA vendor script — called by the renderer when a widget is drawn. */
    public static function enqueue_captcha(): void {
        if ( ! wp_script_is( "trinity-forms-turnstile", "registered" ) && did_action( "wp_enqueue_scripts" ) === 0 ) {
            // Rendered before wp_enqueue_scripts (e.g. a REST preview): register first.
            self::enqueue_assets();
        }
        if ( wp_script_is( "trinity-forms-turnstile", "registered" ) ) {
            wp_enqueue_script( "trinity-forms-turnstile" );
        }
    }

    public static function make_turnstile_async( string $tag, string $handle ): string {
        if ( $handle !== "trinity-forms-turnstile" ) {
            return $tag;
        }
        return str_replace( " src=", " async defer src=", $tag );
    }
}
