<?php
/**
 * Tools → Import from Gravity Forms.
 */

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

use TrinityForms\GF_Importer;

$tf_available = GF_Importer::available_forms();
?>
<?php
\TrinityForms\Admin_UI::open(
    __( "Import from Gravity Forms", "trinity-forms" ),
    __( "Bring your Gravity Forms forms, notifications and entries across. Nothing in Gravity Forms is changed or deleted.", "trinity-forms" )
);
?>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result flag on a redirect; the import itself was nonce-checked.
    $tf_done = isset( $_GET["tf_imported"] ) ? absint( wp_unslash( $_GET["tf_imported"] ) ) : 0;
    if ( $tf_done ) :
        $tf_report = get_transient( "trinity_forms_gf_import_report" );
        delete_transient( "trinity_forms_gf_import_report" );
        ?>
        <div class="notice notice-success">
            <?php $tf_dry = is_array( $tf_report ) && ! empty( $tf_report["dry_run"] ); ?>
            <p><strong><?php echo $tf_dry ? esc_html__( "Dry run finished. Nothing was changed.", "trinity-forms" ) : esc_html__( "Import finished.", "trinity-forms" ); ?></strong></p>
            <?php if ( is_array( $tf_report ) ) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: 1: form title, 2: number of fields, 3: number of entries. */
                        $tf_dry
                            /* translators: 1: form title, 2: number of fields, 3: number of entries. */
                            ? esc_html__( "Would import %1\$s: %2\$d fields and %3\$d entries.", "trinity-forms" )
                            : esc_html__( "Imported %1\$s: %2\$d fields and %3\$d entries.", "trinity-forms" ),
                        "<strong>" . esc_html( (string) $tf_report["title"] ) . "</strong>",
                        (int) $tf_report["fields"],
                        (int) $tf_report["entries"]
                    );
                    ?>
                </p>
                <?php if ( ! empty( $tf_report["skipped"] ) ) : ?>
                    <p><?php esc_html_e( "These fields had no equivalent and were not imported:", "trinity-forms" ); ?></p>
                    <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ( (array) $tf_report["skipped"] as $tf_skipped ) : ?>
                            <li><?php echo esc_html( $tf_skipped ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ( ! empty( $tf_report["form_id"] ) ) : ?>
                    <p><a class="button button-primary" href="<?php echo esc_url( (string) get_edit_post_link( (int) $tf_report["form_id"] ) ); ?>">
                        <?php esc_html_e( "Open the imported form", "trinity-forms" ); ?>
                    </a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! $tf_available ) : ?>
        <?php
        echo \TrinityForms\Admin_UI::empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            __( "No Gravity Forms data found on this site", "trinity-forms" ),
            __( "The importer reads Gravity Forms' own database tables on this site. Gravity Forms does not need to be active, so you can deactivate it first and still import. To move forms from another site, install Trinity Forms there, import, then use Import / Export to copy the forms here.", "trinity-forms" ),
            '<a class="button" href="' . esc_url( admin_url( \TrinityForms\Admin::PARENT . "&page=" . \TrinityForms\Forms_List::TRANSFER_PAGE ) ) . '">' . esc_html__( "Go to Import / Export", "trinity-forms" ) . "</a>",
            "search"
        );
        \TrinityForms\Admin_UI::close();
        ?>
        <?php return; ?>
    <?php endif; ?>

    <h2 class="tf-section-title"><?php esc_html_e( "1. Pick a form", "trinity-forms" ); ?></h2>
    <p class="description"><?php esc_html_e( "Each import makes a new Trinity Forms form, so you can look at the result and import again if you want to start over.", "trinity-forms" ); ?></p>
    <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>">
        <input type="hidden" name="action" value="trinity_forms_gf_import" />
        <?php wp_nonce_field( "trinity_forms_gf_import" ); ?>

        <div class="jnkui-table-wrap"><table class="jnkui-table">
            <thead>
                <tr>
                    <th style="width:2.5em"><span class="screen-reader-text"><?php esc_html_e( "Select", "trinity-forms" ); ?></span></th>
                    <th><?php esc_html_e( "Form", "trinity-forms" ); ?></th>
                    <th class="is-num"><?php esc_html_e( "Entries", "trinity-forms" ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $tf_available as $tf_id => $tf_form ) : ?>
                <tr>
                    <td><input type="radio" name="gf_form_id" value="<?php echo (int) $tf_id; ?>" id="tf-gf-<?php echo (int) $tf_id; ?>" required /></td>
                    <td><label for="tf-gf-<?php echo (int) $tf_id; ?>"><?php echo esc_html( $tf_form["title"] ); ?></label></td>
                    <td class="is-num"><?php echo esc_html( number_format_i18n( $tf_form["entries"] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <h2 class="tf-section-title"><?php esc_html_e( "2. Choose what to bring", "trinity-forms" ); ?></h2>
        <div class="jnkui-card"><div class="jnkui-card-body">
        <p>
            <label>
                <input type="checkbox" name="with_entries" value="1" checked />
                <?php esc_html_e( "Bring the entries across as well", "trinity-forms" ); ?>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="dry_run" value="1" />
                <?php esc_html_e( "Dry run: tell me what would happen, change nothing", "trinity-forms" ); ?>
            </label>
        </p>

        </div></div>
        <p><button type="submit" class="button button-primary"><?php esc_html_e( "Import", "trinity-forms" ); ?></button></p>
    </form>
<?php \TrinityForms\Admin_UI::close(); ?>
