<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Anti_Spam {

    public const HONEYPOT_FIELD = "trinity_forms_hp";
    public const TIMETRAP_FIELD = "trinity_forms_ts";
    private const MIN_FILL_SECONDS = 3;

    /** Why the submission being checked was judged spam ("" when it was not, or no reason given). */
    private static string $reason = "";

    /**
     * Record why a submission is spam. A trinity_forms_is_spam listener calls this
     * before returning true, so the entry in the Spam tab says which rule caught it
     * and a false positive is easy to spot and restore.
     */
    public static function flag( string $reason ): void {
        if ( "" === self::$reason ) {
            self::$reason = $reason;
        }
    }

    public static function reason(): string {
        return self::$reason;
    }

    public static function check( Form $form, array $payload ): bool {
        self::$reason = "";
        if ( $form->get_setting( "honeypot", true ) && ! empty( $payload[ self::HONEYPOT_FIELD ] ) ) {
            self::flag( __( "The hidden trap field was filled in (a bot does that, a person cannot).", "trinity-forms" ) );
            return false;
        }

        $ts = isset( $payload[ self::TIMETRAP_FIELD ] ) ? (int) $payload[ self::TIMETRAP_FIELD ] : 0;
        if ( $ts > 0 && ( time() - $ts ) < self::MIN_FILL_SECONDS ) {
            self::flag( __( "Sent faster than a person can fill in a form.", "trinity-forms" ) );
            return false;
        }

        // The per-form "turnstile" flag enables the globally-configured CAPTCHA
        // provider (Turnstile, reCAPTCHA v2/v3 or hCaptcha — see captcha_provider).
        if ( $form->get_setting( "turnstile", false ) && ! self::verify_captcha( $payload ) ) {
            self::flag( __( "The bot check (CAPTCHA) did not pass.", "trinity-forms" ) );
            return false;
        }

        // Extension point for content-based spam filters (e.g. the bundled Akismet
        // check). Returning true from any listener rejects the submission; a
        // listener may call Anti_Spam::flag() first to say why.
        if ( apply_filters( "trinity_forms_is_spam", false, $form, $payload ) ) {
            return false;
        }

        return true;
    }

    public static function honeypot_html(): string {
        // Inline CSS — bots scrape class names; this hides without giving them a hook.
        // aria-hidden + tabindex=-1 keep screen readers and keyboard users away while
        // still presenting a fillable input to dumb crawlers.
        $hidden = "position:absolute !important;left:-9999px !important;top:auto !important;"
            . "height:1px !important;width:1px !important;overflow:hidden !important;opacity:0 !important;";
        return '<div aria-hidden="true" style="' . $hidden . '">'
            . '<label>Leave this field empty<input type="text" name="' . esc_attr( self::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="off" /></label>'
            . '</div>';
    }

    public static function timetrap_html(): string {
        return '<input type="hidden" name="' . esc_attr( self::TIMETRAP_FIELD ) . '" value="' . esc_attr( (string) time() ) . '" />';
    }

    /** Supported CAPTCHA providers → siteverify endpoint, response field, widget class, script. */
    public const CAPTCHA_PROVIDERS = [
        "turnstile"    => [ "endpoint" => "https://challenges.cloudflare.com/turnstile/v0/siteverify", "field" => "cf-turnstile-response", "class" => "cf-turnstile", "script" => "https://challenges.cloudflare.com/turnstile/v0/api.js", "label" => "Cloudflare Turnstile" ],
        "recaptcha"    => [ "endpoint" => "https://www.google.com/recaptcha/api/siteverify",           "field" => "g-recaptcha-response",  "class" => "g-recaptcha",  "script" => "https://www.google.com/recaptcha/api.js",              "label" => "Google reCAPTCHA v2" ],
        "recaptcha_v3" => [ "endpoint" => "https://www.google.com/recaptcha/api/siteverify",           "field" => "g-recaptcha-response",  "class" => "",             "script" => "https://www.google.com/recaptcha/api.js",              "label" => "Google reCAPTCHA v3", "score" => true ],
        "hcaptcha"     => [ "endpoint" => "https://api.hcaptcha.com/siteverify",                         "field" => "h-captcha-response",   "class" => "h-captcha",   "script" => "https://js.hcaptcha.com/1/api.js",                    "label" => "hCaptcha" ],
    ];

    /**
     * True when this form's bot challenge is on and the submission carries no
     * challenge token at all.
     *
     * That is not evidence of a bot: it is what a person sees when the widget failed
     * to load (blocked script, bad site key, a privacy extension). Filing those as
     * spam silently lost every real submission, so the pipeline answers them with a
     * message the visitor can act on instead.
     */
    public static function captcha_token_missing( Form $form, array $payload ): bool {
        if ( ! $form->get_setting( "turnstile", false ) ) {
            return false;
        }
        $field = self::CAPTCHA_PROVIDERS[ self::captcha_provider() ]["field"];
        $token = (string) ( $payload[ $field ] ?? $payload[ str_replace( "-", "_", $field ) ] ?? "" );
        return "" === $token;
    }

    /** The globally-selected CAPTCHA provider (defaults to Turnstile). */
    public static function captcha_provider(): string {
        $p = (string) ( get_option( "trinity_forms_settings", [] )["captcha_provider"] ?? "turnstile" );
        return isset( self::CAPTCHA_PROVIDERS[ $p ] ) ? $p : "turnstile";
    }

    /**
     * Verify the CAPTCHA response for the configured provider. Turnstile, reCAPTCHA
     * v2 and hCaptcha share the same siteverify contract (POST secret+response ->
     * JSON {success}). Fails closed when the token or secret is missing.
     */
    private static function verify_captcha( array $payload ): bool {
        $provider = self::captcha_provider();
        $conf     = self::CAPTCHA_PROVIDERS[ $provider ];
        $field    = $conf["field"];
        // Some transports normalize the dash in the response field to an underscore.
        $token    = (string) ( $payload[ $field ] ?? $payload[ str_replace( "-", "_", $field ) ] ?? "" );
        if ( $token === "" ) {
            return false;
        }
        $settings = get_option( "trinity_forms_settings", [] );
        $secret   = Secrets::decrypt( (string) ( ( $settings[ $provider ] ?? [] )["secret_key"]
            ?? ( "turnstile" === $provider ? ( $settings["turnstile_secret_key"] ?? "" ) : "" ) ) );
        if ( $secret === "" ) {
            // Fail closed — challenge enabled but no secret key configured.
            return false;
        }
        $resp = wp_remote_post( $conf["endpoint"], [
            "timeout" => 5,
            "body"    => [ "secret" => $secret, "response" => $token, "remoteip" => sanitize_text_field( wp_unslash( (string) ( $_SERVER["REMOTE_ADDR"] ?? "" ) ) ) ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body["success"] ) ) {
            return false;
        }
        // reCAPTCHA v3 is score-based (0.0–1.0): require a minimum confidence.
        if ( ! empty( $conf["score"] ) ) {
            $threshold = (float) ( ( $settings[ $provider ] ?? [] )["score_threshold"] ?? 0.5 );
            return (float) ( $body["score"] ?? 0 ) >= $threshold;
        }
        return true;
    }
}
