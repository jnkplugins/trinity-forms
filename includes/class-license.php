<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Facade over the LicensorPro client. Handles tier checks and free-tier feature
 * gating.
 *
 * Two questions, never mixed up (licence-expiry policy, SDK 1.7.0):
 *
 *   is_pro() / can_use()          May the ADMIN use Pro tools right now? True while
 *                                 the licence is active or in its 30-day grace.
 *   is_pro_runtime() / can_run()  Does Pro run for VISITORS? True once the site has
 *                                 ever been licensed, whatever happened since: a
 *                                 forgotten renewal must never break a public site.
 *
 * Free vs Pro split lives in self::FEATURES — the canonical list per spec §14.2.
 */
final class License {

    public const PRODUCT_SLUG = "trinity-forms";

    /**
     * Premium feature slugs. These gate premium *code paths* (payment processing,
     * ESP dispatch, save-&-continue, the frontend submissions table). Premium
     * field types (signature / payment / repeater) and multi-step
     * (page_break) are gated by simple feature-absence: the free build never
     * registers those field types, so there is nothing to cap.
     *
     * File upload is free since 2.9.0, so it has no slug here: listing it would
     * silently re-gate it for any future can_use( "file_uploads" ) caller.
     *
     * Free core has NO usage caps — entry storage, the admin entries view, CSV
     * export, and conditional logic are all unlimited on the free tier.
     *
     * @var array<string,bool> feature-slug => is_premium
     */
    private const FEATURES = [
        "payments"          => true,
        "integrations"      => true,
        "submissions_table" => true,
        "save_progress"     => true,
        "signature"         => true,
        "repeater"          => true,
        "moderation"        => true,
        "user_registration" => true,
        "partial_entries"   => true,
        "geolocation"       => true,
        "chained_selects"   => true,
        "rich_text"         => true,
        "landing_page"      => true,
    ];

    /** @var object|null The licensing SDK client (only present in the Pro build). */
    private static ?object $client = null;

    /**
     * Called by pro/bootstrap.php to hand the configured SDK client to the gate.
     * Typed as `object` so no licensing-SDK class symbol appears in free core.
     */
    public static function set_client( object $client ): void {
        self::$client = $client;
    }

    public static function client(): ?object {
        return self::$client;
    }

    /**
     * True when the premium module is installed, licensed or not. An unlicensed Pro
     * install needs "activate your licence", not "buy Pro" — the customer already did.
     */
    public static function pro_installed(): bool {
        return is_dir( TRINITY_FORMS_PATH . "pro/includes" );
    }

    /**
     * May the admin use the Pro tools right now? Active licence or inside the
     * 30-day grace after expiry. Does NOT answer "does Pro run for visitors";
     * that is is_pro_runtime().
     *
     * The free wordpress.org build ships no client (pro/ is omitted), so this is
     * always false there and every premium feature is simply absent.
     */
    public static function is_pro(): bool {
        $override = apply_filters( "trinity_forms_is_premium", null );
        if ( is_bool( $override ) ) {
            return $override;
        }
        if ( self::forced_by_tests() ) {
            return true;
        }
        return ( self::$client && method_exists( self::$client, "is_licensed" ) )
            ? (bool) self::$client->is_licensed()
            : false;
    }

    /**
     * Does Pro run for visitors? True once this site has ever held a valid licence:
     * forms with Pro fields, payments, integrations fired on submit, save and
     * continue, Views and the rest of the public side keep working after the
     * licence expires, is refunded or the site is deactivated. Only the admin
     * tools lock (is_pro()).
     */
    public static function is_pro_runtime(): bool {
        $override = apply_filters( "trinity_forms_is_premium_runtime", null );
        if ( is_bool( $override ) ) {
            return $override;
        }
        $override = apply_filters( "trinity_forms_is_premium", null );
        if ( is_bool( $override ) ) {
            return $override;
        }
        if ( self::forced_by_tests() ) {
            return true;
        }
        if ( ! self::$client ) {
            return false;
        }
        if ( method_exists( self::$client, "was_ever_licensed" ) && self::$client->was_ever_licensed() ) {
            return true;
        }
        return method_exists( self::$client, "is_licensed" ) && (bool) self::$client->is_licensed();
    }

    /**
     * Ever licensed, but the admin tools are locked (expired past the grace
     * period, refunded, revoked, or this site deactivated).
     */
    public static function is_locked(): bool {
        return self::is_pro_runtime() && ! self::is_pro();
    }

    /**
     * Where the licence stands: unlicensed | active | grace | locked | revoked
     * (the SDK's states). "unlicensed" covers both a site that was never
     * licensed and one whose key was removed; is_pro_runtime() tells them apart.
     */
    public static function state(): string {
        $override = apply_filters( "trinity_forms_is_premium", null );
        if ( is_bool( $override ) ) {
            return $override ? "active" : "unlicensed";
        }
        if ( self::forced_by_tests() ) {
            return "active";
        }
        if ( self::$client && method_exists( self::$client, "status" ) ) {
            $status = self::$client->status();
            $state  = is_array( $status ) ? (string) ( $status["state"] ?? "" ) : "";
            if ( in_array( $state, [ "unlicensed", "active", "grace", "locked", "revoked" ], true ) ) {
                return $state;
            }
        }
        return self::is_pro() ? "active" : "unlicensed";
    }

    /**
     * HIGH-02: the test bypass only works with TRINITY_FORMS_TESTING defined (never
     * in production) AND the trinity_forms_force_pro filter opted in.
     */
    private static function forced_by_tests(): bool {
        return defined( "TRINITY_FORMS_TESTING" ) && TRINITY_FORMS_TESTING && apply_filters( "trinity_forms_force_pro", false );
    }

    /**
     * May the ADMIN use this premium feature (configure it, add it)? Unknown
     * feature slugs are free.
     */
    public static function can_use( string $feature ): bool {
        if ( ! array_key_exists( $feature, self::FEATURES ) ) {
            return true; // unknown feature => free
        }
        return self::is_pro();
    }

    /**
     * Does this premium feature run for visitors? Same slugs as can_use(), but
     * answered by is_pro_runtime().
     */
    public static function can_run( string $feature ): bool {
        if ( ! array_key_exists( $feature, self::FEATURES ) ) {
            return true;
        }
        return self::is_pro_runtime();
    }

    /**
     * Kept for callers written before the licence-expiry policy. It used to allow
     * payments for 15 days after a lapse (and never did: it read the expiry from
     * the wrong place in the stored licence). Payments are part of what visitors
     * use, so they now simply keep working on any site that was ever licensed.
     */
    public static function within_payment_grace(): bool {
        return self::is_pro_runtime();
    }
}
