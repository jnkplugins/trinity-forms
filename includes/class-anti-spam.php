<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Anti_Spam {

    public const HONEYPOT_FIELD = "trinity_forms_hp";
    public const TIMETRAP_FIELD = "trinity_forms_ts";
    /** Set by the page script: "1" = the script ran, "2" = someone typed or tapped in the form. */
    public const HUMAN_FIELD    = "trinity_forms_js";
    private const MIN_FILL_SECONDS = 3;
    /** A filled trap is forgiven only when the visitor also took at least this long. */
    private const HUMAN_FILL_SECONDS = 5;

    /** A note for the entry log about a check that let the entry through. */
    private static string $note = "";

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

    public static function note(): string {
        return self::$note;
    }

    /**
     * True when the submission came from the page with someone typing or tapping
     * in the form, and taking a person's time over it.
     *
     * A real visitor's mobile browser can autofill the hidden trap field (seen on
     * a live site, 24 Oct 2026: an Android in-app browser, page scripts ran, sent
     * 43 s after the page opened, marked as spam). A bot posting straight at the
     * endpoint sends neither signal.
     */
    public static function looks_human( array $payload ): bool {
        $ts = isset( $payload[ self::TIMETRAP_FIELD ] ) ? (int) $payload[ self::TIMETRAP_FIELD ] : 0;
        return "2" === (string) ( $payload[ self::HUMAN_FIELD ] ?? "" )
            && $ts > 0
            && ( time() - $ts ) >= self::HUMAN_FILL_SECONDS;
    }

    /** True when the trap has text and nothing says a person filled the form in. */
    public static function honeypot_hit( Form $form, array $payload ): bool {
        return $form->get_setting( "honeypot", true )
            && ! empty( $payload[ self::HONEYPOT_FIELD ] )
            && ! self::looks_human( $payload );
    }

    public static function check( Form $form, array $payload ): bool {
        self::$reason = "";
        self::$note   = "";
        if ( $form->get_setting( "honeypot", true ) && ! empty( $payload[ self::HONEYPOT_FIELD ] ) ) {
            $trap = mb_substr( sanitize_text_field( (string) $payload[ self::HONEYPOT_FIELD ] ), 0, 80 );
            if ( ! self::looks_human( $payload ) ) {
                /* translators: %s: the text found in the hidden field. */
                self::flag( sprintf( __( "The hidden trap field was filled in with \"%s\". Bots do that; a browser's autofill can too.", "trinity-forms" ), $trap ) );
                return false;
            }
            // Not spam on this alone: the other checks below still run.
            /* translators: %s: the text found in the hidden field. */
            self::$note = sprintf( __( "The hidden trap field had \"%s\" in it, most likely the browser's autofill. The visitor typed in the form and took a person's time, so it was not marked as spam.", "trinity-forms" ), $trap );
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
        // No physical offset (left:-9999px): on a right-to-left site the left is the
        // scrollable side, so that made every form page about 10,000 px wide and a
        // phone opened it on a blank screen. Clipped in place instead.
        $hidden = "position:absolute !important;height:1px !important;width:1px !important;"
            . "margin:-1px !important;padding:0 !important;border:0 !important;overflow:hidden !important;"
            . "clip:rect(0 0 0 0) !important;clip-path:inset(50%) !important;white-space:nowrap !important;"
            . "opacity:0 !important;pointer-events:none !important;";
        // No label text and autocomplete="new-password": browsers and password
        // managers autofill a field that looks like name/email/website, and many
        // mobile browsers ignore autocomplete="off". The data-* flags ask
        // LastPass, 1Password and Dashlane to leave it alone too.
        return '<div aria-hidden="true" style="' . $hidden . '">'
            . '<input type="text" name="' . esc_attr( self::HONEYPOT_FIELD ) . '" value="" tabindex="-1" autocomplete="new-password"'
            . ' data-lpignore="true" data-1p-ignore="true" data-form-type="other" aria-label="-" />'
            . '</div>';
    }

    public static function timetrap_html(): string {
        return '<input type="hidden" name="' . esc_attr( self::TIMETRAP_FIELD ) . '" value="' . esc_attr( (string) time() ) . '" />'
            . '<input type="hidden" name="' . esc_attr( self::HUMAN_FIELD ) . '" value="" />';
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
