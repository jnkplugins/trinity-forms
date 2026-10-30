<?php
/**
 * Trinity Forms → Import forms: every form builder an importer found on this site.
 */

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

use TrinityForms\Importers\Registry;

$tf_importers = Registry::all();
$tf_found     = Registry::found();
$tf_names     = implode( ", ", array_map( static fn( $i ) => $i->label(), $tf_importers ) );
?>
<?php
\TrinityForms\Admin_UI::open(
    __( "Import forms", "trinity-forms" ),
    /* translators: %s: list of form plugins, e.g. "Gravity Forms, Contact Form 7". */
    sprintf( __( "Bring your forms across from %s. Nothing in the other plugin is changed or deleted, and it does not need to be active.", "trinity-forms" ), $tf_names )
);
?>

    <?php
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result flag on a redirect; the import itself was nonce-checked.
    $tf_done = isset( $_GET["tf_imported"] ) ? absint( wp_unslash( $_GET["tf_imported"] ) ) : 0;
    if ( $tf_done ) :
        $tf_report = get_transient( "trinity_forms_import_report_" . get_current_user_id() );
        if ( ! is_array( $tf_report ) ) {
            $tf_report = get_transient( "trinity_forms_gf_import_report" );
            delete_transient( "trinity_forms_gf_import_report" );
        }
        delete_transient( "trinity_forms_import_report_" . get_current_user_id() );
        $tf_dry = is_array( $tf_report ) && ! empty( $tf_report["dry_run"] );
        $tf_ok  = is_array( $tf_report ) && ( $tf_dry || ! empty( $tf_report["form_id"] ) );
        ?>
        <div class="notice <?php echo $tf_ok ? "notice-success" : "notice-error"; ?>">
            <p><strong><?php
            if ( ! $tf_ok ) {
                esc_html_e( "Nothing was imported: that form could not be read.", "trinity-forms" );
            } else {
                echo $tf_dry ? esc_html__( "Dry run finished. Nothing was changed.", "trinity-forms" ) : esc_html__( "Import finished.", "trinity-forms" );
            }
            ?></strong></p>
            <?php if ( $tf_ok ) : ?>
                <p>
                    <?php
                    printf(
                        $tf_dry
                            /* translators: 1: form title, 2: number of fields, 3: number of entries. */
                            ? esc_html__( "Would import %1\$s: %2\$d fields and %3\$d entries.", "trinity-forms" )
                            /* translators: 1: form title, 2: number of fields, 3: number of entries. */
                            : esc_html__( "Imported %1\$s: %2\$d fields and %3\$d entries.", "trinity-forms" ),
                        "<strong>" . esc_html( (string) $tf_report["title"] ) . "</strong>",
                        (int) $tf_report["fields"],
                        (int) $tf_report["entries"]
                    );
                    ?>
                </p>
                <?php if ( ! empty( $tf_report["skipped"] ) ) : ?>
                    <p><?php esc_html_e( "These parts had no equivalent and were not imported. Check them in the new form:", "trinity-forms" ); ?></p>
                    <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ( (array) $tf_report["skipped"] as $tf_skipped ) : ?>
                            <li><?php echo esc_html( $tf_skipped ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ( ! empty( $tf_report["form_id"] ) ) : ?>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url( (string) get_edit_post_link( (int) $tf_report["form_id"] ) ); ?>">
                            <?php esc_html_e( "Open the imported form", "trinity-forms" ); ?>
                        </a>
                        <code style="margin-inline-start:8px">[trinity-forms id="<?php echo (int) $tf_report["form_id"]; ?>"]</code>
                    </p>
                    <p class="description"><?php esc_html_e( "The page that shows the old form still uses the old shortcode: replace it with the one above.", "trinity-forms" ); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( ! $tf_found ) : ?>
        <?php
        echo \TrinityForms\Admin_UI::empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            __( "No forms to import were found on this site", "trinity-forms" ),
            /* translators: %s: list of form plugins. */
            sprintf( __( "The importer reads the forms that %s left on this site, even when the plugin is deactivated. To move forms from another site, install Trinity Forms there, import, then use Import / Export to copy the forms here.", "trinity-forms" ), $tf_names ),
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
        <input type="hidden" name="action" value="trinity_forms_import" />
        <?php wp_nonce_field( "trinity_forms_import" ); ?>

        <div class="jnkui-table-wrap"><table class="jnkui-table">
            <thead>
                <tr>
                    <th style="width:2.5em"><span class="screen-reader-text"><?php esc_html_e( "Select", "trinity-forms" ); ?></span></th>
                    <th><?php esc_html_e( "Form", "trinity-forms" ); ?></th>
                    <th><?php esc_html_e( "From", "trinity-forms" ); ?></th>
                    <th class="is-num"><?php esc_html_e( "Entries", "trinity-forms" ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $tf_first = true;
            foreach ( array_keys( $tf_found ) as $tf_slug ) :
                $tf_importer = $tf_importers[ $tf_slug ];
                foreach ( $tf_importer->forms() as $tf_id => $tf_form ) :
                    $tf_value = $tf_slug . ":" . $tf_id;
                    $tf_dom   = "tf-import-" . sanitize_html_class( $tf_slug . "-" . $tf_id );
                    ?>
                <tr>
                    <td><input type="radio" name="source" value="<?php echo esc_attr( $tf_value ); ?>" id="<?php echo esc_attr( $tf_dom ); ?>" required <?php checked( $tf_first ); ?> /></td>
                    <td><label for="<?php echo esc_attr( $tf_dom ); ?>"><?php echo esc_html( "" !== $tf_form["title"] ? $tf_form["title"] : "#" . $tf_id ); ?></label></td>
                    <td><?php echo esc_html( $tf_importer->label() ); ?></td>
                    <td class="is-num"><?php echo null === $tf_form["entries"] ? '<span title="' . esc_attr__( "This plugin does not keep submissions, so there are none to bring.", "trinity-forms" ) . '">–</span>' : esc_html( number_format_i18n( (int) $tf_form["entries"] ) ); ?></td>
                </tr>
                    <?php
                    $tf_first = false;
                endforeach;
            endforeach;
            ?>
            </tbody>
        </table></div>

        <h2 class="tf-section-title"><?php esc_html_e( "2. Choose what to bring", "trinity-forms" ); ?></h2>
        <div class="jnkui-card"><div class="jnkui-card-body">
        <p>
            <label>
                <input type="checkbox" name="with_entries" value="1" checked />
                <?php esc_html_e( "Bring the entries across as well (where the other plugin kept them)", "trinity-forms" ); ?>
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
