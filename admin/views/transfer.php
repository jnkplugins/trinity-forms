<?php
/**
 * Forms → Import / Export: move a form between sites as a JSON file.
 *
 * The admin face of `wp trinity form export|import`; both go through Form_Transfer,
 * so a file made here imports with the CLI and the other way round.
 */

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

use TrinityForms\Forms_List;

if ( ! current_user_can( "edit_posts" ) ) {
    return;
}

$tf_forms  = get_posts( [ "post_type" => "trinity_forms_form", "post_status" => [ "publish", "draft" ], "numberposts" => -1, "orderby" => "title", "order" => "ASC" ] );
$tf_result = null;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result flag; the import itself was nonce-checked.
if ( ! empty( $_GET["tf_imported"] ) ) {
    $tf_key    = "trinity_forms_import_result_" . get_current_user_id();
    $tf_result = get_transient( $tf_key );
    delete_transient( $tf_key );
}
?>
<?php
\TrinityForms\Admin_UI::open(
    __( "Import / Export", "trinity-forms" ),
    __( "Move a form between sites as a small .json file. Handy for going from a test site to the live one.", "trinity-forms" )
);
?>

    <?php if ( is_array( $tf_result ) && ! empty( $tf_result["form_id"] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php
            printf(
                /* translators: %s: imported form title. */
                esc_html__( "Imported “%s”.", "trinity-forms" ),
                esc_html( (string) $tf_result["title"] )
            );
            ?>
            <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $tf_result["form_id"] ) ); ?>"><?php esc_html_e( "Edit form", "trinity-forms" ); ?></a>.
            <?php esc_html_e( "Integration and payment keys are never included in an export, so set them up again on this site.", "trinity-forms" ); ?>
        </p></div>
    <?php elseif ( is_array( $tf_result ) ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( (string) $tf_result["error"] ); ?></p></div>
    <?php endif; ?>

    <div class="jnkui-grid-2 tf-transfer">
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Export a form", "trinity-forms" ); ?></h2>
            <p><?php esc_html_e( "Fields, settings, notifications and confirmations. Entries and keys are left out.", "trinity-forms" ); ?></p></div></div>
        <?php if ( ! $tf_forms ) : ?>
            <div class="jnkui-card-body"><?php echo \TrinityForms\Admin_UI::empty_state( __( "No forms yet", "trinity-forms" ), __( "Once you have a form, you can download it here.", "trinity-forms" ), "", "form" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
        <?php else : ?>
            <ul class="jnkui-list">
            <?php foreach ( $tf_forms as $tf_form ) : ?>
                <li>
                    <span><?php echo esc_html( $tf_form->post_title ?: __( "(no title)", "trinity-forms" ) ); ?> <span class="jnkui-muted">#<?php echo (int) $tf_form->ID; ?></span></span>
                    <a class="button button-small" href="<?php echo esc_url( Forms_List::export_url( (int) $tf_form->ID ) ); ?>"><?php esc_html_e( "Download", "trinity-forms" ); ?></a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div>
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Import a form", "trinity-forms" ); ?></h2>
            <p><?php esc_html_e( "Pick a .json file exported from Trinity Forms. It becomes a new form.", "trinity-forms" ); ?></p></div></div>
        <div class="jnkui-card-body">
    <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?php echo esc_attr( Forms_List::IMPORT_ACTION ); ?>" />
        <?php wp_nonce_field( Forms_List::IMPORT_ACTION ); ?>
        <div style="display:grid;gap:14px">
            <div class="jnkui-field"><label for="tf-import-file"><?php esc_html_e( "Export file (.json)", "trinity-forms" ); ?></label>
                <input type="file" id="tf-import-file" name="trinity_forms_import" accept=".json,application/json" required /></div>
            <div class="jnkui-field"><label for="tf-import-title"><?php esc_html_e( "Title (optional)", "trinity-forms" ); ?></label>
                <input type="text" id="tf-import-title" name="title" class="regular-text" />
                <p class="description" style="margin:0"><?php esc_html_e( "Leave blank to keep the title from the file.", "trinity-forms" ); ?></p></div>
            <div><button type="submit" class="button button-primary"><?php esc_html_e( "Import form", "trinity-forms" ); ?></button></div>
        </div>
    </form>
        </div>
    </div>
    <div class="jnkui-card">
        <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Coming from Gravity Forms?", "trinity-forms" ); ?></h2>
            <p><?php esc_html_e( "Bring forms, conditional logic, notifications and entries across, with a dry run first.", "trinity-forms" ); ?></p></div>
            <a class="button button-small" href="<?php echo esc_url( admin_url( \TrinityForms\Admin::PARENT . "&page=" . \TrinityForms\Admin::IMPORT ) ); ?>"><?php esc_html_e( "Open", "trinity-forms" ); ?></a></div>
    </div>
    <p class="description"><?php esc_html_e( "Same files as the WP-CLI commands: wp trinity form export <id> and wp trinity form import <file>.", "trinity-forms" ); ?></p>
    </div>
    </div>
<?php \TrinityForms\Admin_UI::close(); ?>
