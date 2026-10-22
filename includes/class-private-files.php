<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Where form uploads live, and the only way to download them.
 *
 * Uploads used to land in the public media folder under their original name
 * (uploads/2026/09/cv.pdf): anyone who saw or guessed the URL could fetch a CV, an
 * ID scan or a medical letter without logging in.
 *
 * Deny rules alone are not enough: .htaccess is Apache-only, and nginx or PHP's own
 * server serve anything under the web root. So uploads go into a folder OUTSIDE the
 * web root when the host allows it (next to the WordPress directory, one folder per
 * site), and only fall back to uploads/trinity-forms/ (random sub-folder plus deny
 * rules) when nothing outside is writable. Every link the plugin prints is a signed
 * admin-post URL that streams the file only to someone allowed to manage entries.
 *
 * Override the location with the TRINITY_FORMS_PRIVATE_DIR constant or the
 * `trinity_forms_private_upload_root` filter.
 */
class Private_Files {

    public const ACTION = "trinity_forms_file";
    public const DIR    = "trinity-forms";

    /** Link prefixes: which root a signed relative path belongs to. */
    private const ROOT_PRIVATE = "p";
    private const ROOT_UPLOADS = "u";

    public static function register(): void {
        add_action( "admin_post_" . self::ACTION, [ self::class, "handle_download" ] );
        // A logged-out click (e.g. from a notification email) goes to the login page
        // and comes back, instead of admin-post's bare 400.
        add_action( "admin_post_nopriv_" . self::ACTION, static function (): void {
            auth_redirect();
        } );
    }

    /**
     * The folder form uploads are written to (no trailing slash).
     *
     * Preferred: a per-site folder beside the WordPress directory, which no web
     * server maps to a URL. Fallback: uploads/trinity-forms, protected by deny rules
     * and random sub-folders.
     */
    public static function root(): string {
        $root = "";
        if ( defined( "TRINITY_FORMS_PRIVATE_DIR" ) && "" !== (string) TRINITY_FORMS_PRIVATE_DIR ) {
            $root = (string) TRINITY_FORMS_PRIVATE_DIR;
        } else {
            $parent    = dirname( untrailingslashit( ABSPATH ) );
            $candidate = $parent . "/" . self::DIR . "-private-" . substr( md5( (string) home_url() ), 0, 12 );
            if ( ( is_dir( $candidate ) && is_writable( $candidate ) ) || ( is_writable( $parent ) && wp_mkdir_p( $candidate ) ) ) {
                $root = $candidate;
            }
        }
        $root = (string) apply_filters( "trinity_forms_private_upload_root", $root );
        if ( "" === $root ) {
            $root = self::uploads_base() . "/" . self::DIR;
        }
        return untrailingslashit( wp_normalize_path( $root ) );
    }

    private static function uploads_base(): string {
        return untrailingslashit( wp_normalize_path( (string) ( wp_upload_dir( null, false )["basedir"] ?? "" ) ) );
    }

    /** Run $callback (a wp_handle_upload call) with uploads redirected to the private root. */
    public static function with_private_dir( callable $callback ) {
        $root = self::root();
        self::protect( $root );
        $filter = static function ( array $dirs ) use ( $root ): array {
            $sub            = "/" . gmdate( "Y/m" ) . "/" . bin2hex( random_bytes( 16 ) );
            $dirs["path"]   = $root . $sub;
            $dirs["subdir"] = $sub;
            // Never published: every link goes through url_for(). Kept non-empty
            // because wp_handle_upload() builds a URL from it regardless.
            $dirs["url"]    = $dirs["baseurl"] . "/" . self::DIR . $sub;
            return $dirs;
        };
        add_filter( "upload_dir", $filter );
        try {
            return $callback();
        } finally {
            remove_filter( "upload_dir", $filter );
        }
    }

    /** Deny-all rules and a silent index in the private root (belt and braces). */
    public static function protect( string $root ): void {
        if ( "" === $root ) {
            return;
        }
        if ( ! is_dir( $root ) ) {
            wp_mkdir_p( $root );
        }
        $files = [
            ".htaccess"  => "# Trinity Forms: uploads are served only through WordPress.\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n",
            "index.php"  => "<?php\n// Silence is golden.\n",
            "web.config" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];
        foreach ( $files as $name => $body ) {
            $path = $root . "/" . $name;
            if ( ! file_exists( $path ) ) {
                file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard files in our own folder.
            }
        }
    }

    /** True when $path is a file this plugin manages (private root or, for older entries, uploads). */
    public static function is_managed_path( string $path ): bool {
        return "" !== self::encode( $path );
    }

    /** "p:<relative>" / "u:<relative>", or "" when the path is outside both roots. */
    private static function encode( string $path ): string {
        $path = wp_normalize_path( $path );
        foreach ( [ self::ROOT_PRIVATE => self::root(), self::ROOT_UPLOADS => self::uploads_base() ] as $key => $base ) {
            if ( "" !== $base && 0 === strpos( $path, $base . "/" ) ) {
                return $key . ":" . substr( $path, strlen( $base ) + 1 );
            }
        }
        return "";
    }

    private static function sign( string $encoded ): string {
        return hash_hmac( "sha256", "tf-file|" . $encoded, wp_salt( "auth" ) );
    }

    /** The download link for a stored file path. */
    public static function url_for( string $path ): string {
        $encoded = self::encode( $path );
        if ( "" === $encoded ) {
            return "";
        }
        return add_query_arg( [
            "action" => self::ACTION,
            "file"   => rawurlencode( $encoded ),
            "sig"    => self::sign( $encoded ),
        ], admin_url( "admin-post.php" ) );
    }

    /** Resolve a signed request to an absolute path, or "" when it must be refused. */
    public static function resolve( string $encoded, string $sig ): string {
        $encoded = rawurldecode( $encoded );
        if ( "" === $encoded || "" === $sig || ! hash_equals( self::sign( $encoded ), $sig ) ) {
            return "";
        }
        $parts = explode( ":", $encoded, 2 );
        if ( 2 !== count( $parts ) ) {
            return "";
        }
        $bases = [ self::ROOT_PRIVATE => self::root(), self::ROOT_UPLOADS => self::uploads_base() ];
        $base  = isset( $bases[ $parts[0] ] ) ? realpath( $bases[ $parts[0] ] ) : false;
        $real  = $base ? realpath( $base . "/" . $parts[1] ) : false;
        if ( ! $real || 0 !== strpos( wp_normalize_path( $real ), wp_normalize_path( $base ) . "/" ) || ! is_file( $real ) ) {
            return "";
        }
        return $real;
    }

    public static function handle_download(): void {
        if ( ! current_user_can( Admin::CAP ) ) {
            wp_die( esc_html__( "You do not have permission to download this file.", "trinity-forms" ), "", [ "response" => 403 ] );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the HMAC signature is the per-file authorisation, on top of the capability check above.
        $encoded = isset( $_GET["file"] ) ? sanitize_text_field( wp_unslash( $_GET["file"] ) ) : "";
        $sig     = isset( $_GET["sig"] ) ? sanitize_text_field( wp_unslash( $_GET["sig"] ) ) : "";
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $path = self::resolve( $encoded, $sig );
        if ( "" === $path ) {
            wp_die( esc_html__( "File not found.", "trinity-forms" ), "", [ "response" => 404 ] );
        }
        $type = wp_check_filetype( $path );
        nocache_headers();
        header( "Content-Type: " . ( $type["type"] ?: "application/octet-stream" ) );
        header( "Content-Length: " . (string) filesize( $path ) );
        header( 'Content-Disposition: attachment; filename="' . str_replace( [ '"', "\r", "\n" ], "", basename( $path ) ) . '"' );
        header( "X-Content-Type-Options: nosniff" );
        readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming an authorised download.
        exit;
    }
}
