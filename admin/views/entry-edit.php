<?php
/**
 * Admin editor for a single entry.
 *
 * Rendered by entry-detail.php when ?tf_edit=1 is present. Fields are drawn by the
 * same Field_Base::render() the front end uses and saved through the same
 * sanitize()/validate() pair, so an edited value can never be something the form
 * would have rejected on submission.
 */

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET context; the save posts to a nonced admin-post handler.

use TrinityForms\Admin;
use TrinityForms\Admin_UI;
use TrinityForms\Entries;
use TrinityForms\Form_Manager;

/** @var array<string,mixed> $entry   Set by entry-detail.php. */
/** @var int                 $entry_id */
/** @var array<string,mixed> $list_args */

$form = Form_Manager::get( (int) $entry["form_id"] );
if ( ! $form ) {
    /* translators: %d: numeric entry ID. */
    Admin_UI::open( sprintf( __( "Edit entry #%d", "trinity-forms" ), $entry_id ) );
    echo '<div class="notice notice-error inline"><p>'
        . esc_html__( "This entry's form has been deleted, so there is nothing to edit it against.", "trinity-forms" )
        . "</p></div>";
    Admin_UI::close();
    return;
}

$values     = Entries::values( $entry_id );
$uneditable = Entries::uneditable_types();

// Validation errors survive one redirect in a transient keyed to this user + entry:
// re-showing the form with the values still in it beats bouncing someone back to a
// blank edit screen with "something was wrong".
$error_key  = "trinity_forms_edit_errors_" . get_current_user_id() . "_" . $entry_id;
$errors     = get_transient( $error_key );
$errors     = is_array( $errors ) ? $errors : [];
if ( $errors ) {
    delete_transient( $error_key );
    // Show what they typed, not what is stored.
    $values = array_merge( $values, (array) ( $errors["values"] ?? [] ) );
}
$field_errors = (array) ( $errors["fields"] ?? [] );

$base_url = admin_url( "edit.php?post_type=trinity_forms_form&page=" . Admin::ENTRIES );
$cancel   = add_query_arg( array_filter( [
    "entry"   => $entry_id,
    "form_id" => $list_args["form_id"] ?: null,
    "view"    => "all" !== $list_args["view"] ? $list_args["view"] : null,
    "s"       => $list_args["search"] ?: null,
] ), $base_url );
Admin_UI::open(
    /* translators: %d: numeric entry ID. */
    sprintf( __( "Edit entry #%d", "trinity-forms" ), $entry_id ),
    __( "Edits are saved as a note on this entry, with your name and the time. Nothing is emailed. Use \"Resend notifications\" if you want that.", "trinity-forms" ),
    '<a class="button" href="' . esc_url( $cancel ) . '">&larr; ' . esc_html__( "Back to entry", "trinity-forms" ) . "</a>"
);
?>

    <?php if ( $field_errors ) : ?>
        <div class="notice notice-error inline">
            <p><strong><?php esc_html_e( "This entry was not saved.", "trinity-forms" ); ?></strong></p>
            <ul style="list-style:disc;margin-left:20px">
                <?php foreach ( $field_errors as $tf_fid => $tf_msgs ) : ?>
                    <?php foreach ( (array) $tf_msgs as $tf_msg ) : ?>
                        <li><?php echo esc_html( $tf_msg ); ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>


    <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="trinity-forms-entry-edit">
        <input type="hidden" name="action" value="trinity_forms_update_entry" />
        <input type="hidden" name="entry_id" value="<?php echo (int) $entry_id; ?>" />
        <input type="hidden" name="form_id" value="<?php echo (int) $list_args["form_id"]; ?>" />
        <input type="hidden" name="view" value="<?php echo esc_attr( (string) $list_args["view"] ); ?>" />
        <input type="hidden" name="s" value="<?php echo esc_attr( (string) $list_args["search"] ); ?>" />
        <?php wp_nonce_field( "trinity_forms_update_entry_" . $entry_id ); ?>

        <table class="form-table">
            <tbody>
            <?php foreach ( $form->fields() as $tf_field ) :
                $tf_value  = $values[ $tf_field->id ] ?? null;
                $tf_locked = in_array( $tf_field->type, $uneditable, true );
                ?>
                <tr>
                    <th scope="row">
                        <?php echo esc_html( $tf_field->label ?: $tf_field->id ); ?>
                        <?php if ( $tf_field->required ) : ?>
                            <span class="trinity-forms-required" aria-hidden="true">*</span>
                        <?php endif; ?>
                    </th>
                    <td>
                        <?php if ( $tf_locked ) : ?>
                            <?php
                            // Shown, never editable: the value still belongs on the
                            // screen, it just isn't ours to retype.
                            $tf_display = is_array( $tf_value ) ? implode( ", ", array_filter( $tf_value, "is_scalar" ) ) : (string) $tf_value;
                            ?>
                            <?php if ( "" !== $tf_display ) : ?>
                                <code><?php echo esc_html( $tf_display ); ?></code>
                            <?php else : ?>
                                <span class="jnkui-muted">–</span>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e( "Not editable. This value is a record of what happened, not a setting.", "trinity-forms" ); ?></p>
                        <?php else : ?>
                            <?php
                            // The front-end renderer emits the input, including its
                            // name attribute, so admin edits and submissions post the
                            // same shape and one sanitizer covers both.
                            // The row header already names the field, so the control is drawn
                            // without its own label ("Name *" then "Name * First Last").
                            $tf_bare = new \TrinityForms\Field( array_merge( $tf_field->config, [ "label" => "" ] ) );
                            echo $tf_bare->render( $tf_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Field_Base::render() escapes its own attributes and values.
                            ?>
                            <?php if ( ! empty( $field_errors[ $tf_field->id ] ) ) : ?>
                                <p class="description" style="color:var(--jnk-bad)">
                                    <?php echo esc_html( implode( " ", (array) $field_errors[ $tf_field->id ] ) ); ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e( "Save entry", "trinity-forms" ); ?></button>
            <a class="button" href="<?php echo esc_url( $cancel ); ?>"><?php esc_html_e( "Cancel", "trinity-forms" ); ?></a>
        </p>
    </form>
<?php Admin_UI::close(); ?>
