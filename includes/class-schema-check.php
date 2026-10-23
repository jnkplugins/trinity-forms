<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Do the plugin's database tables exist?
 *
 * dbDelta() fails without a word: on a database that refuses a statement the
 * plugin still activates, and nothing says the tables are missing. That is how
 * Linkweave ran for a while on MariaDB 11.7+ with no index (23 Oct 2026). Every
 * JnK plugin now checks after creating its tables, says so on its screens with
 * the database's own error, and reports in Tools > Site Health, where the Pro
 * build also confirms its own data is ready.
 *
 * The Pro module adds its tables through the trinity_forms_expected_tables filter.
 */
final class Schema_Check {

    /** Where the last check's findings are kept: [ "tables" => string[], "error" => string ]. */
    public const OPTION = "trinity_forms_schema_problem";

    /** The free tables, without the prefix. */
    private const FREE_TABLES = [ "entries", "entry_meta", "entry_notes", "files", "logs" ];

    public static function register(): void {
        add_action( "admin_notices", [ self::class, "notice" ] );
        add_filter( "site_status_tests", [ self::class, "site_status_tests" ] );
    }

    /** @return string[] full table names this build needs */
    public static function expected(): array {
        global $wpdb;
        $names = array_merge( self::FREE_TABLES, (array) apply_filters( "trinity_forms_expected_tables", [] ) );
        return array_values( array_unique( array_map( static fn( $t ) => $wpdb->prefix . "trinity_forms_" . $t, $names ) ) );
    }

    /** @return string[] the expected tables the database does not have */
    public static function missing(): array {
        global $wpdb;
        $missing = [];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema introspection; no cache applies.
        foreach ( self::expected() as $name ) {
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $name ) ) ) !== $name ) {
                $missing[] = $name;
            }
        }
        // phpcs:enable
        return $missing;
    }

    /** Check straight after creating tables, and remember what is missing. */
    public static function record(): array {
        global $wpdb;
        $missing = self::missing();
        if ( $missing ) {
            update_option( self::OPTION, [ "tables" => $missing, "error" => (string) $wpdb->last_error ], false );
        } else {
            delete_option( self::OPTION );
        }
        return $missing;
    }

    /** @return array{tables:string[],error:string}|null */
    public static function problem(): ?array {
        $p = get_option( self::OPTION, null );
        return is_array( $p ) && ! empty( $p["tables"] ) ? $p : null;
    }

    public static function notice(): void {
        $problem = self::problem();
        if ( ! $problem || ! current_user_can( "manage_options" ) ) {
            return;
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : "";
        if ( false === strpos( $id, "trinity" ) && ! in_array( $id, [ "plugins", "dashboard" ], true ) ) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
            esc_html__( "Trinity Forms could not create its database tables.", "trinity-forms" ),
            esc_html__( "Forms cannot save entries without them. Send the details below to support (jnkplugins.com/contact) and we will sort it out with you.", "trinity-forms" ),
            esc_html( implode( ", ", $problem["tables"] ) . ( "" !== $problem["error"] ? ": " . $problem["error"] : "" ) )
        );
    }

    /** @param array<string,mixed> $tests */
    public static function site_status_tests( $tests ): array {
        $tests                                 = (array) $tests;
        $tests["direct"]["trinity_forms_tables"] = [
            "label" => __( "Trinity Forms database tables", "trinity-forms" ),
            "test"  => [ self::class, "site_health" ],
        ];
        return $tests;
    }

    /** Tools > Site Health. Checked live, not from the stored result. */
    public static function site_health(): array {
        $missing = self::missing();
        $badge   = [ "label" => "Trinity Forms", "color" => $missing ? "red" : "blue" ];
        if ( $missing ) {
            $problem = self::problem();
            return [
                "label"       => __( "Trinity Forms is missing database tables", "trinity-forms" ),
                "status"      => "critical",
                "badge"       => $badge,
                "description" => "<p>" . esc_html__( "Forms cannot save entries without these tables. Send this list to support (jnkplugins.com/contact):", "trinity-forms" ) . "</p><p><code>"
                    . esc_html( implode( ", ", $missing ) . ( $problem && "" !== $problem["error"] ? ": " . $problem["error"] : "" ) ) . "</code></p>",
                "actions"     => "",
                "test"        => "trinity_forms_tables",
            ];
        }
        $pro = License::is_pro_build();
        return [
            "label"       => $pro ? __( "Trinity Forms Pro data is ready", "trinity-forms" ) : __( "Trinity Forms database tables are ready", "trinity-forms" ),
            "status"      => "good",
            "badge"       => $badge,
            "description" => "<p>" . esc_html( sprintf(
                /* translators: %d: number of database tables */
                _n( "The %d table Trinity Forms needs is in the database.", "All %d tables Trinity Forms needs are in the database.", count( self::expected() ), "trinity-forms" ),
                count( self::expected() )
            ) ) . "</p>",
            "actions"     => "",
            "test"        => "trinity_forms_tables",
        ];
    }
}
