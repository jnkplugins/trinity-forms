<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Public_Front {

    public static function enqueue_assets(): void {
        wp_enqueue_style( "trinity-forms", TRINITY_FORMS_URL . "public/assets/css/public.css", [], TRINITY_FORMS_VERSION );
        wp_enqueue_script( "trinity-forms", TRINITY_FORMS_URL . "public/assets/js/public.js", [], TRINITY_FORMS_VERSION, true );

        $settings  = get_option( "trinity_forms_settings", [] );
        $provider   = Anti_Spam::captcha_provider();
        $site_key   = self::captcha_site_key();
        $script_url = Anti_Spam::CAPTCHA_PROVIDERS[ $provider ]["script"];

        wp_localize_script( "trinity-forms", "TrinityFormsData", [
            "rest_url"           => esc_url_raw( rest_url( "trinity-forms/v1/" ) ),
            "nonce"              => wp_create_nonce( "wp_rest" ),
            "captcha_provider"   => $provider,
            "turnstile_site_key" => $site_key,
        ] );

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
