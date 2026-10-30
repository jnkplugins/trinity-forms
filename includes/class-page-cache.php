<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Empty the page cache when a published form is saved.
 *
 * The submit text and labels are part of the cached page, so after the owner
 * changed "Submit" to "Send message" a cached Contact page kept the old button
 * until the cache expired (TF round, 29 Oct 2026, WP Super Cache). The common
 * cache plugins are told to empty their cache through their own public
 * functions or actions, only when installed, once per request. Same calls as
 * JnK Linkweave's rule purge, which were checked on real installs.
 */
final class Page_Cache {

    private static bool $queued = false;

    public static function register(): void {
        add_action( "save_post_trinity_forms_form", [ self::class, "on_save" ], 20, 2 );
    }

    /** @param \WP_Post $post */
    public static function on_save( int $post_id, $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! $post instanceof \WP_Post || $post->post_status !== "publish" ) {
            return;
        }
        self::request_purge();
    }

    /** Purge once, at the end of this request. */
    public static function request_purge(): void {
        if ( self::$queued ) {
            return;
        }
        self::$queued = true;
        add_action( "shutdown", [ self::class, "purge_queued" ], 5 );
    }

    public static function is_queued(): bool {
        return self::$queued;
    }

    public static function purge_queued(): void {
        if ( ! self::$queued ) {
            return;
        }
        self::$queued = false;
        self::purge_all();
    }

    /**
     * Ask every cache plugin that is present to empty its page cache.
     *
     * @return string[] the cache plugins that were asked
     */
    public static function purge_all(): array {
        if ( ! apply_filters( "trinity_forms_purge_page_cache", true ) ) {
            return [];
        }
        $done = [];
        if ( function_exists( "wp_cache_clear_cache" ) ) {
            wp_cache_clear_cache();
            $done[] = "wp-super-cache";
        }
        if ( function_exists( "w3tc_flush_all" ) ) {
            w3tc_flush_all();
            $done[] = "w3-total-cache";
        }
        if ( function_exists( "rocket_clean_domain" ) ) {
            rocket_clean_domain();
            $done[] = "wp-rocket";
        }
        if ( defined( "LSCWP_V" ) || has_action( "litespeed_purge_all" ) ) {
            do_action( "litespeed_purge_all", "Trinity Forms: a form was saved" );
            $done[] = "litespeed-cache";
        }
        if ( function_exists( "wpfc_clear_all_cache" ) ) {
            wpfc_clear_all_cache( true );
            $done[] = "wp-fastest-cache";
        }
        if ( $done ) {
            do_action( "trinity_forms_page_cache_purged", $done );
        }
        return $done;
    }
}
