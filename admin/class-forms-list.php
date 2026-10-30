<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

/**
 * Customizations for the WP-default `edit.php?post_type=trinity_forms_form` listing.
 *
 * Adds informational columns (shortcode, fields count, submissions count, last
 * submission timestamp) and a "Duplicate" row action.
 */
class Forms_List {

    public const DUP_ACTION    = "trinity_forms_duplicate_form";
    public const EXPORT_ACTION = "trinity_forms_export_form";
    public const IMPORT_ACTION = "trinity_forms_import_form";
    public const TRANSFER_PAGE = "trinity-forms-transfer";

    public static function bootstrap(): void {
        add_filter( "manage_trinity_forms_form_posts_columns",       [ self::class, "register_columns" ] );
        add_action( "manage_trinity_forms_form_posts_custom_column", [ self::class, "render_column" ], 10, 2 );
        add_filter( "post_row_actions",                         [ self::class, "row_actions" ], 10, 2 );
        add_action( "admin_post_" . self::DUP_ACTION,           [ self::class, "handle_duplicate" ] );
        add_action( "admin_post_" . self::EXPORT_ACTION,        [ self::class, "handle_export" ] );
        add_action( "admin_post_" . self::IMPORT_ACTION,        [ self::class, "handle_import" ] );
        add_action( "admin_notices",                            [ self::class, "maybe_render_notice" ] );
        add_action( "admin_notices",                            [ self::class, "maybe_render_getting_started" ] );
        add_action( "admin_menu",                               [ self::class, "register_transfer_page" ], 12 );
    }

    /**
     * First run: the forms list is the first screen a new install lands on, and with
     * no forms it showed WordPress' bare "No posts found." Point at the two ways to
     * make a form instead. Disappears by itself once one exists.
     */
    public static function maybe_render_getting_started(): void {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( ! $screen || $screen->id !== "edit-trinity_forms_form" ) {
            return;
        }
        if ( (int) wp_count_posts( "trinity_forms_form" )->publish + (int) wp_count_posts( "trinity_forms_form" )->draft > 0 ) {
            return;
        }
        ?>
        <div class="notice notice-info" style="padding:14px 16px">
            <p style="margin-top:0"><strong><?php esc_html_e( "Create your first form", "trinity-forms" ); ?></strong></p>
            <p><?php esc_html_e( "Start from one of twelve ready-made forms, or build one from scratch. Either way you get a shortcode to drop on any page, and every submission is stored in your own database.", "trinity-forms" ); ?></p>
            <?php $tf_found = Importers\Registry::summary(); ?>
            <?php if ( "" !== $tf_found ) : ?>
                <p class="tf-import-found">
                    <?php
                    /* translators: %s: what was found, e.g. "1 Contact Form 7 form, 2 Gravity Forms forms". */
                    echo esc_html( sprintf( __( "Found on this site: %s. Import instead of building from scratch.", "trinity-forms" ), $tf_found ) );
                    ?>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( Admin::PARENT . "&page=" . Admin::IMPORT ) ); ?>"><?php esc_html_e( "Import", "trinity-forms" ); ?></a>
                </p>
            <?php endif; ?>
            <p style="margin-bottom:0">
                <a class="button <?php echo "" === $tf_found ? "button-primary" : ""; ?>" href="<?php echo esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=" . Templates::PAGE ) ); ?>"><?php esc_html_e( "Start from a template", "trinity-forms" ); ?></a>
                <a class="button" href="<?php echo esc_url( admin_url( "post-new.php?post_type=trinity_forms_form" ) ); ?>"><?php esc_html_e( "Add a blank form", "trinity-forms" ); ?></a>
            </p>
        </div>
        <?php
    }

    public static function register_columns( array $columns ): array {
        // Preserve cb + title at the front; drop the date column to the end.
        $date = $columns["date"] ?? null;
        unset( $columns["date"] );

        $columns["trinity_forms_shortcode"]   = __( "Shortcode", "trinity-forms" );
        $columns["trinity_forms_fields"]      = __( "Fields", "trinity-forms" );
        // "Entries" is the Entries screen's All count (no spam, trash or abandoned
        // partials), so the two screens agree; the Funnel column counts completions.
        $columns["trinity_forms_submissions"] = __( "Entries", "trinity-forms" );
        $columns["trinity_forms_last_sub"]    = __( "Last submission", "trinity-forms" );

        if ( $date !== null ) {
            $columns["date"] = $date;
        }
        return $columns;
    }

    public static function render_column( string $column, int $post_id ): void {
        global $wpdb;

        switch ( $column ) {
            case "trinity_forms_shortcode":
                echo '<code>[trinity-forms id="' . (int) $post_id . '"]</code>';
                return;

            case "trinity_forms_fields":
                $form  = Form_Manager::get( $post_id );
                $count = $form ? count( $form->fields() ) : 0;
                echo (int) $count;
                return;

            case "trinity_forms_submissions":
                $count = (int) ( Entries::counts( (int) $post_id )["all"] ?? 0 );
                printf(
                    '<a href="%s">%s</a>',
                    esc_url( Admin::entries_url( [ "form_id" => (int) $post_id ] ) ),
                    esc_html( number_format_i18n( $count ) )
                );
                return;

            case "trinity_forms_last_sub":
                $table = $wpdb->prefix . "trinity_forms_entries";
                $last  = $wpdb->get_var( $wpdb->prepare(
                    "SELECT submitted_at FROM %i WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 1",
                    $table,
                    $post_id
                ) );
                if ( ! $last ) {
                    echo "—";
                    return;
                }
                echo esc_html( Dates::human( (string) $last, false, (string) $last ) );
                return;
        }
    }

    public static function row_actions( array $actions, $post ): array {
        if ( ! $post instanceof \WP_Post || $post->post_type !== "trinity_forms_form" ) {
            return $actions;
        }
        if ( ! current_user_can( "edit_posts" ) ) {
            return $actions;
        }
        $url = wp_nonce_url(
            add_query_arg(
                [
                    "action"  => self::DUP_ACTION,
                    "post_id" => $post->ID,
                ],
                admin_url( "admin-post.php" )
            ),
            self::DUP_ACTION . "_" . $post->ID
        );
        $actions["trinity_forms_duplicate"] = sprintf(
            '<a href="%s">%s</a>',
            esc_url( $url ),
            esc_html__( "Duplicate", "trinity-forms" )
        );
        $actions["trinity_forms_export"] = sprintf(
            '<a href="%s">%s</a>',
            esc_url( self::export_url( (int) $post->ID ) ),
            esc_html__( "Export JSON", "trinity-forms" )
        );
        return $actions;
    }

    public static function export_url( int $form_id ): string {
        return wp_nonce_url(
            add_query_arg( [ "action" => self::EXPORT_ACTION, "form_id" => $form_id ], admin_url( "admin-post.php" ) ),
            self::EXPORT_ACTION . "_" . $form_id
        );
    }

    /**
     * Download a form as JSON — the same file `wp trinity form export` writes, so a
     * site owner without shell access can move a form from staging to production.
     * Credentials are stripped by Form_Transfer.
     */
    public static function handle_export(): void {
        if ( ! current_user_can( "edit_posts" ) ) {
            wp_die( esc_html__( "You do not have permission to export forms.", "trinity-forms" ) );
        }
        $form_id = absint( wp_unslash( $_REQUEST["form_id"] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.
        check_admin_referer( self::EXPORT_ACTION . "_" . $form_id );
        if ( ! current_user_can( "edit_post", $form_id ) ) {
            wp_die( esc_html__( "You do not have permission to export this form.", "trinity-forms" ) );
        }
        $json = Form_Transfer::export_json( $form_id );
        if ( "" === $json ) {
            wp_die( esc_html__( "Form not found.", "trinity-forms" ) );
        }
        $name = sanitize_file_name( ( sanitize_title( (string) get_the_title( $form_id ) ) ?: "form" ) . "-" . $form_id . ".json" );
        nocache_headers();
        header( "Content-Type: application/json; charset=utf-8" );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON file download, not HTML.
        exit;
    }

    /** Create a form from an uploaded export file (as `wp trinity form import`). */
    public static function handle_import(): void {
        if ( ! current_user_can( "edit_posts" ) ) {
            wp_die( esc_html__( "You do not have permission to import forms.", "trinity-forms" ) );
        }
        check_admin_referer( self::IMPORT_ACTION );
        $back = admin_url( "edit.php?post_type=trinity_forms_form&page=" . self::TRANSFER_PAGE );

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- PHP-populated upload metadata; the file is only read as text and parsed as JSON.
        $file  = $_FILES["trinity_forms_import"] ?? null;
        $title = isset( $_POST["title"] ) ? sanitize_text_field( wp_unslash( $_POST["title"] ) ) : "";
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash

        $error = "";
        if ( ! is_array( $file ) || (int) ( $file["error"] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( (string) $file["tmp_name"] ) ) {
            $error = __( "Choose a .json file exported from Trinity Forms.", "trinity-forms" );
        } elseif ( (int) $file["size"] > 2 * MB_IN_BYTES ) {
            $error = __( "That file is too large to be a form export.", "trinity-forms" );
        }
        $result = [ "form_id" => 0, "title" => "", "error" => $error ];
        if ( "" === $error ) {
            $result = Form_Transfer::import_json( (string) file_get_contents( (string) $file["tmp_name"] ), $title ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading PHP's temp upload file.
        }
        set_transient( "trinity_forms_import_result_" . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( "tf_imported", 1, $back ) );
        exit;
    }

    public static function register_transfer_page(): void {
        add_submenu_page(
            Admin::PARENT,
            __( "Import / Export forms", "trinity-forms" ),
            __( "Import / Export", "trinity-forms" ),
            "edit_posts",
            self::TRANSFER_PAGE,
            [ self::class, "render_transfer_page" ]
        );
    }

    public static function render_transfer_page(): void {
        require TRINITY_FORMS_PATH . "admin/views/transfer.php";
    }

    public static function handle_duplicate(): void {
        if ( ! current_user_can( "edit_posts" ) ) {
            wp_die( esc_html__( "You do not have permission to duplicate forms.", "trinity-forms" ) );
        }
        $post_id = absint( wp_unslash( $_REQUEST["post_id"] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via check_admin_referer() on the next line.
        check_admin_referer( self::DUP_ACTION . "_" . $post_id );

        $original = get_post( $post_id );
        if ( ! $original || $original->post_type !== "trinity_forms_form" ) {
            wp_die( esc_html__( "Form not found.", "trinity-forms" ) );
        }

        $new_id = wp_insert_post( [
            "post_type"    => "trinity_forms_form",
            "post_status"  => "draft",
            "post_title"   => sprintf(
                /* translators: %s: original form title */
                __( "%s (Copy)", "trinity-forms" ),
                $original->post_title
            ),
            "post_content" => $original->post_content,
        ], true );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            wp_die( esc_html__( "Failed to duplicate form.", "trinity-forms" ) );
        }

        $settings = get_post_meta( $post_id, Form::SETTINGS_META, true );
        if ( is_array( $settings ) ) {
            // MED-05: strip integration credentials from the copy so they are not
            // silently shared between forms; the admin must reconfigure them.
            if ( isset( $settings["integrations"] ) && is_array( $settings["integrations"] ) ) {
                foreach ( $settings["integrations"] as $idx => $integration ) {
                    if ( isset( $integration["config"] ) && is_array( $integration["config"] ) ) {
                        foreach ( array_keys( $integration["config"] ) as $key ) {
                            if ( \TrinityForms\Secrets::looks_secret( (string) $key ) ) {
                                unset( $settings["integrations"][ $idx ]["config"][ $key ] );
                            }
                        }
                    }
                }
            }
            update_post_meta( (int) $new_id, Form::SETTINGS_META, $settings );
        }

        do_action( "trinity_forms_form_duplicated", (int) $new_id, (int) $post_id );

        wp_safe_redirect( add_query_arg(
            [
                "post_type"               => "trinity_forms_form",
                "trinity_forms_duplicated"     => (int) $new_id,
                "trinity_forms_creds_stripped" => "1",
            ],
            admin_url( "edit.php" )
        ) );
        exit;
    }

    public static function maybe_render_notice(): void {
        if ( empty( $_GET["trinity_forms_duplicated"] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
            return;
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== "trinity_forms_form" ) {
            return;
        }
        $new_id = (int) $_GET["trinity_forms_duplicated"]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
        $edit   = get_edit_post_link( $new_id );
        $creds_stripped = ! empty( $_GET["trinity_forms_creds_stripped"] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
        // Integrations are a Pro feature; a Free user has none to reconfigure.
        $msg = $creds_stripped && License::is_pro_runtime()
            ? esc_html__( "Form duplicated. Integration credentials were not copied — please reconfigure them on the new form.", "trinity-forms" )
            : esc_html__( "Form duplicated.", "trinity-forms" );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $msg is built from esc_html__() above.
            $msg,
            $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html__( "Edit copy", "trinity-forms" ) . "</a>" : ""
        );
    }
}
