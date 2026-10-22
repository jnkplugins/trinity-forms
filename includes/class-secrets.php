<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * At-rest encryption for credentials in trinity_forms_settings and per-form
 * integration config (post_meta._trinity_forms_settings.integrations[]).
 *
 * Format: "trinity-forms:v1:" + base64( iv(12) || tag(16) || ciphertext )
 * Cipher: AES-256-GCM, key derived from wp_salt('auth') via SHA-256.
 *
 * Legacy plaintext values pass through decrypt() unchanged so existing
 * installs keep working until their next save.
 *
 * Spec ref: §15.2 #5 — Stripe secret keys (and analogous credentials)
 * encrypted at rest.
 */
final class Secrets {

    const PREFIX = "trinity-forms:v1:";
    const CIPHER = "aes-256-gcm";

    /** Keys (case-insensitive suffix match) considered secret. */
    private static array $secret_suffixes = [
        "_key",
        "_secret",
        "_token",
        "webhook_secret",
        "api_key",
        "api_secret",
        "api_token",
        "access_token",
        "auth_token",
        "secret_key",
        "password",
    ];

    /** Keys that look secret by suffix but are public identifiers. */
    private static array $public_suffixes = [
        "site_key",
        "publishable_key",
    ];

    /**
     * Returns true if the given config key (e.g. "secret_key", "webhook_secret",
     * "api_token") looks like it holds a secret and should be encrypted at rest.
     */
    public static function looks_secret( string $key ): bool {
        $k = strtolower( $key );
        // Keys that end in "_key" but are public by design: a CAPTCHA site key is
        // printed into every form page and a Stripe publishable key into every
        // checkout. Encrypting them handed the ciphertext to the widget (so no
        // challenge ever rendered) and showed it back in Settings.
        foreach ( self::$public_suffixes as $public ) {
            if ( $k === $public || str_ends_with( $k, $public ) ) {
                return false;
            }
        }
        foreach ( self::$secret_suffixes as $suffix ) {
            if ( $k === $suffix || str_ends_with( $k, $suffix ) ) {
                return true;
            }
        }
        return false;
    }

    /** Returns true if $v already carries the trinity-forms:v1: prefix. */
    public static function is_encrypted( string $v ): bool {
        return strncmp( $v, self::PREFIX, strlen( self::PREFIX ) ) === 0;
    }

    /**
     * Encrypts a plaintext string. Idempotent — already-encrypted values are
     * returned unchanged, and empty strings are returned as-is.
     */
    public static function encrypt( string $plain ): string {
        if ( $plain === "" || self::is_encrypted( $plain ) ) {
            return $plain;
        }
        if ( ! function_exists( "openssl_encrypt" ) ) {
            // MED-02: warn the admin so they know credentials are unprotected.
            add_action( "admin_notices", static function() {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__( "Trinity Forms: The PHP OpenSSL extension is required to encrypt credentials at rest. Credentials are currently stored in plaintext — please enable the openssl extension.", "trinity-forms" )
                    . '</p></div>';
            } );
            return $plain;
        }

        $key = self::derive_key();
        $iv  = self::random_bytes( 12 );
        $tag = "";

        $ct = openssl_encrypt(
            $plain,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            "",
            16
        );

        if ( $ct === false ) {
            return $plain;
        }

        return self::PREFIX . base64_encode( $iv . $tag . $ct );
    }

    /**
     * Decrypts a value. Non-prefixed input is returned as-is so legacy
     * plaintext secrets continue to work. Returns "" if decryption fails.
     */
    public static function decrypt( string $cipher ): string {
        if ( $cipher === "" || ! self::is_encrypted( $cipher ) ) {
            return $cipher;
        }
        if ( ! function_exists( "openssl_decrypt" ) ) {
            return "";
        }

        $blob = base64_decode( substr( $cipher, strlen( self::PREFIX ) ), true );
        if ( $blob === false || strlen( $blob ) < 12 + 16 + 1 ) {
            return "";
        }

        $iv  = substr( $blob, 0, 12 );
        $tag = substr( $blob, 12, 16 );
        $ct  = substr( $blob, 28 );

        $key = self::derive_key();
        $pt  = openssl_decrypt(
            $ct,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ""
        );

        return $pt === false ? "" : $pt;
    }

    /**
     * Walks $cfg and decrypts the values whose keys are listed in $keys
     * (or, if $keys is empty, every key matched by looks_secret()).
     * Non-string values are passed through unchanged.
     */
    public static function reveal_array( array $cfg, array $keys = [] ): array {
        foreach ( $cfg as $k => $v ) {
            if ( ! is_string( $v ) ) {
                continue;
            }
            $match = empty( $keys )
                ? self::looks_secret( (string) $k )
                : in_array( $k, $keys, true );
            if ( $match ) {
                $cfg[ $k ] = self::decrypt( $v );
            }
        }
        return $cfg;
    }

    /**
     * Recursively walks an array and encrypts every string value whose key
     * matches looks_secret(). Idempotent — already-encrypted values are
     * skipped.
     */
    public static function encrypt_recursive( array $data ): array {
        foreach ( $data as $k => $v ) {
            if ( is_array( $v ) ) {
                $data[ $k ] = self::encrypt_recursive( $v );
            } elseif ( is_string( $v ) && self::looks_secret( (string) $k ) && ! self::is_encrypted( $v ) && $v !== "" ) {
                $data[ $k ] = self::encrypt( $v );
            }
        }
        return $data;
    }

    /**
     * Recursively decrypt values stored under public-key names (site_key,
     * publishable_key). Installs from before 2.4.1 encrypted them; this turns them
     * back into the plain identifiers the widget and the settings screen need.
     */
    public static function decrypt_public( array $data ): array {
        foreach ( $data as $k => $v ) {
            if ( is_array( $v ) ) {
                $data[ $k ] = self::decrypt_public( $v );
                continue;
            }
            if ( ! is_string( $v ) || ! self::is_encrypted( $v ) ) {
                continue;
            }
            $key = strtolower( (string) $k );
            foreach ( self::$public_suffixes as $public ) {
                if ( $key === $public || str_ends_with( $key, $public ) ) {
                    $data[ $k ] = self::decrypt( $v );
                    break;
                }
            }
        }
        return $data;
    }

    /** SHA-256 of wp_salt('auth'); 32 bytes for AES-256. */
    private static function derive_key(): string {
        $salt = function_exists( "wp_salt" ) ? wp_salt( "auth" ) : ( defined( "AUTH_KEY" ) ? AUTH_KEY : "trinity-forms-fallback-salt" );
        return hash( "sha256", $salt, true );
    }

    private static function random_bytes( int $n ): string {
        if ( function_exists( "random_bytes" ) ) {
            return random_bytes( $n );
        }
        if ( function_exists( "openssl_random_pseudo_bytes" ) ) {
            return openssl_random_pseudo_bytes( $n );
        }
        // MED-03: removed mt_rand() fallback — it is cryptographically weak.
        // PHP 7.4+ (our minimum) always has random_bytes(); this branch is unreachable.
        throw new \RuntimeException( "No CSPRNG available. Please check your PHP installation." );
    }
}
