<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET context; the capability check lives on the admin_menu wrapper and every mutation posts to a nonced admin-post handler.

use TrinityForms\Admin;
use TrinityForms\Admin_UI;
use TrinityForms\Entries;
use TrinityForms\Entry_Notes;
use TrinityForms\Dates;
use TrinityForms\Logger;
use TrinityForms\Form_Manager;

global $wpdb;

$entry_id = absint( wp_unslash( $_GET["entry"] ?? 0 ) );
$entry    = Entries::get( $entry_id );

if ( ! $entry ) {
    Admin_UI::open( __( "Entry not found", "trinity-forms" ) );
    echo Admin_UI::empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
        __( "This entry is gone", "trinity-forms" ),
        __( "It may have been deleted. The rest of your entries are still in the list.", "trinity-forms" ),
        '<a class="button" href="' . esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=" . Admin::ENTRIES ) ) . '">' . esc_html__( "Back to entries", "trinity-forms" ) . "</a>",
        "search"
    );
    Admin_UI::close();
    return;
}

// Opening an entry is what "reading" it means. Doing this on render rather than
// behind a button is the whole point of the unread count.
// Except on the way back from "Mark unread": that redirect lands here, and marking
// it read again on arrival made the button do nothing.
if ( empty( $entry["is_read"] ) && "unread" !== sanitize_key( wp_unslash( $_GET["tf_done"] ?? "" ) ) ) {
    Entries::set_read( [ $entry_id ], true );
    $entry["is_read"] = 1;
}

// Carry the list filters so prev/next walks the list you were actually looking at,
// not an unfiltered one.
$list_args = [
    "form_id"        => isset( $_GET["form_id"] ) ? absint( wp_unslash( $_GET["form_id"] ) ) : 0,
    "view"           => isset( $_GET["view"] ) ? sanitize_key( wp_unslash( $_GET["view"] ) ) : "all",
    "search"         => isset( $_GET["s"] ) ? sanitize_text_field( wp_unslash( $_GET["s"] ) ) : "",
    "date_from"      => isset( $_GET["from"] ) ? sanitize_text_field( wp_unslash( $_GET["from"] ) ) : "",
    "date_to"        => isset( $_GET["to"] ) ? sanitize_text_field( wp_unslash( $_GET["to"] ) ) : "",
    "payment_status" => isset( $_GET["payment_status"] ) ? sanitize_key( wp_unslash( $_GET["payment_status"] ) ) : "",
];

// The editor is the same screen in a different mode, so it shares the entry lookup,
// the read-marking and the filter args. Branching before the notes/files/logs
// queries keeps three queries off a screen that shows none of them.
if ( ! empty( $_GET["tf_edit"] ) ) {
    require TRINITY_FORMS_PATH . "admin/views/entry-edit.php";
    return;
}

$meta = $wpdb->get_results( $wpdb->prepare(
    "SELECT field_id, field_label, field_type, value FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d ORDER BY id ASC",
    $entry_id
), ARRAY_A );

$notes = Entry_Notes::for_entry( $entry_id );

$files = $wpdb->get_results( $wpdb->prepare(
    "SELECT filename, filepath, mime_type, size_bytes, attachment_id FROM {$wpdb->prefix}trinity_forms_files WHERE entry_id = %d ORDER BY id ASC",
    $entry_id
), ARRAY_A );

$logs = $wpdb->get_results( $wpdb->prepare(
    "SELECT level, type, message, context, created_at FROM {$wpdb->prefix}trinity_forms_logs WHERE entry_id = %d ORDER BY created_at DESC, id DESC LIMIT 50",
    $entry_id
), ARRAY_A );

$neighbours = Entries::neighbours( $entry_id, $list_args );

$form_id    = (int) $entry["form_id"];
$form_title = get_the_title( $form_id );
$ip         = ! empty( $entry["ip_address"] ) ? @inet_ntop( $entry["ip_address"] ) : "";
$base_url   = admin_url( "edit.php?post_type=trinity_forms_form&page=" . Admin::ENTRIES );
$back       = add_query_arg( array_filter( [
    "form_id" => $list_args["form_id"] ?: null,
    "view"    => "all" !== $list_args["view"] ? $list_args["view"] : null,
    "s"       => $list_args["search"] ?: null,
] ), $base_url );

$sibling_url = static function ( int $id ) use ( $base_url, $list_args ): string {
    return add_query_arg( array_filter( [
        "entry"   => $id,
        "form_id" => $list_args["form_id"] ?: null,
        "view"    => "all" !== $list_args["view"] ? $list_args["view"] : null,
        "s"       => $list_args["search"] ?: null,
    ] ), $base_url );
};

/**
 * A nonced action link for this entry.
 *
 * `entry` is carried deliberately: Admin::entries_url() rebuilds the redirect from
 * the request, so without it a non-destructive action here (star, mark unread,
 * resend) would bounce you back to the list instead of leaving you on the entry.
 * The destructive verbs strip it again in the handler, because their entry is gone.
 */
$action_url = static function ( string $verb ) use ( $entry_id, $list_args ): string {
    return wp_nonce_url(
        add_query_arg( array_filter( [
            "action"         => "trinity_forms_entry_action",
            "entry_id"       => $entry_id,
            "verb"           => $verb,
            "entry"          => $entry_id,
            "form_id"        => $list_args["form_id"] ?: null,
            "view"           => "all" !== $list_args["view"] ? $list_args["view"] : null,
            "s"              => $list_args["search"] ?: null,
            "from"           => $list_args["date_from"] ?: null,
            "to"             => $list_args["date_to"] ?: null,
            "payment_status" => $list_args["payment_status"] ?: null,
        ] ), admin_url( "admin-post.php" ) ),
        "trinity_forms_entry_action_" . $entry_id
    );
};

$starred = ! empty( $entry["is_starred"] );
$status  = (string) $entry["status"];
$pay     = (string) ( ( $entry["payment_status"] ?? "" ) ?: "none" );
$tf_form_obj   = Form_Manager::get( $form_id );
// Payment status only means something on a form that takes payments.
$tf_show_pay   = "none" !== $pay || ( $tf_form_obj && $tf_form_obj->payment_enabled() );
$tf_sender     = Entries::senders( [ $entry_id ] )[ $entry_id ] ?? [];
$tf_who        = (string) ( $tf_sender["name"] ?? "" );

$tf_pager = '<span class="tf-pager">'
    . ( $neighbours["prev"]
        ? '<a class="button" href="' . esc_url( $sibling_url( $neighbours["prev"] ) ) . '">&lsaquo; ' . esc_html__( "Newer", "trinity-forms" ) . "</a>"
        : '<span class="button disabled" aria-disabled="true">&lsaquo; ' . esc_html__( "Newer", "trinity-forms" ) . "</span>" )
    . ( $neighbours["next"]
        ? '<a class="button" href="' . esc_url( $sibling_url( $neighbours["next"] ) ) . '">' . esc_html__( "Older", "trinity-forms" ) . " &rsaquo;</a>"
        : '<span class="button disabled" aria-disabled="true">' . esc_html__( "Older", "trinity-forms" ) . " &rsaquo;</span>" )
    . "</span>";

Admin_UI::open(
    /* translators: %d: numeric entry ID. */
    sprintf( __( "Entry #%d", "trinity-forms" ), $entry_id ),
    "" !== $form_title
        /* translators: 1: person's name or "Someone", 2: form name, 3: when. */
        ? sprintf( __( "%1\$s sent %2\$s %3\$s.", "trinity-forms" ), "" !== $tf_who ? $tf_who : __( "Someone", "trinity-forms" ), $form_title, Dates::relative( $entry["submitted_at"] ) )
        : Dates::human( $entry["submitted_at"] ),
    '<a class="button" href="' . esc_url( $back ) . '">&larr; ' . esc_html__( "All entries", "trinity-forms" ) . "</a>" . $tf_pager
);
?>

    <?php
    $tf_done = isset( $_GET["tf_done"] ) ? sanitize_key( wp_unslash( $_GET["tf_done"] ) ) : "";
    $tf_msgs = [
        "note"         => __( "Note added.", "trinity-forms" ),
        "note_deleted" => __( "Note deleted.", "trinity-forms" ),
        "resend"       => __( "Notifications resent.", "trinity-forms" ),
        "star"         => __( "Entry starred.", "trinity-forms" ),
        "unstar"       => __( "Star removed.", "trinity-forms" ),
        "restore"      => __( "Entry restored.", "trinity-forms" ),
        "read"         => __( "Marked as read.", "trinity-forms" ),
        "unread"       => __( "Marked as unread.", "trinity-forms" ),
        "edited"       => __( "Entry saved.", "trinity-forms" ),
        "unchanged"    => __( "Nothing changed.", "trinity-forms" ),
        "approved"     => __( "Entry approved.", "trinity-forms" ),
        "rejected"     => __( "Entry rejected.", "trinity-forms" ),
    ];
    if ( isset( $tf_msgs[ $tf_done ] ) ) :
        ?>
        <div class="notice notice-<?php echo "unchanged" === $tf_done ? "info" : "success"; ?> is-dismissible">
            <p><?php echo esc_html( $tf_msgs[ $tf_done ] ); ?></p>
        </div>
    <?php endif; ?>

    <?php
    // Reading a submission and answering it is the same job. Without this the
    // address sits here as plain text and you retype it into your mail client.
    $tf_reply_to = "";
    foreach ( $meta as $m ) {
        if ( "email" === $m["field_type"] && is_email( (string) $m["value"] ) ) {
            $tf_reply_to = (string) $m["value"];
            break;
        }
    }
    $print_url = add_query_arg( [ "entry" => $entry_id, "tf_print" => 1 ], $base_url );
    ?>
    <div class="tf-entry-actions">
        <?php if ( "" !== $tf_reply_to ) : ?>
            <a class="button button-primary" href="<?php echo esc_url( "mailto:" . $tf_reply_to . "?subject=" . rawurlencode( sprintf( /* translators: %s: form name. */ __( "Re: %s", "trinity-forms" ), $form_title ) ) ); ?>">
                <?php esc_html_e( "Reply by email", "trinity-forms" ); ?>
            </a>
        <?php endif; ?>
        <a class="button" href="<?php echo esc_url( $action_url( $starred ? "unstar" : "star" ) ); ?>">
            <span class="dashicons dashicons-<?php echo $starred ? "star-filled" : "star-empty"; ?>"<?php echo $starred ? ' style="color:#dba617"' : ""; ?>></span>
            <?php echo $starred ? esc_html__( "Unstar", "trinity-forms" ) : esc_html__( "Star", "trinity-forms" ); ?>
        </a>
        <a class="button" href="<?php echo esc_url( $action_url( "unread" ) ); ?>"><?php esc_html_e( "Mark unread", "trinity-forms" ); ?></a>
        <a class="button" href="<?php echo esc_url( $action_url( "resend" ) ); ?>"
           onclick="return confirm('<?php echo esc_js( __( "Resend this entry's notifications?", "trinity-forms" ) ); ?>');">
            <?php esc_html_e( "Resend notifications", "trinity-forms" ); ?>
        </a>
        <?php
        // Only offered when the form still exists: there is no schema to edit
        // against once it is gone, and the editor says so rather than 404ing.
        if ( "" !== $form_title ) :
            $edit_url = add_query_arg( array_filter( [
                "entry"   => $entry_id,
                "tf_edit" => 1,
                "form_id" => $list_args["form_id"] ?: null,
                "view"    => "all" !== $list_args["view"] ? $list_args["view"] : null,
                "s"       => $list_args["search"] ?: null,
            ] ), $base_url );
            ?>
            <a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( "Edit", "trinity-forms" ); ?></a>
        <?php endif; ?>
        <a class="button" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( "Print", "trinity-forms" ); ?></a>
        <?php if ( "trash" === $status || "spam" === $status ) : ?>
            <a class="button" href="<?php echo esc_url( $action_url( "restore" ) ); ?>"><?php esc_html_e( "Restore", "trinity-forms" ); ?></a>
        <?php else : ?>
            <a class="button is-danger" href="<?php echo esc_url( $action_url( "trash" ) ); ?>"><?php esc_html_e( "Trash", "trinity-forms" ); ?></a>
        <?php endif; ?>
        <?php do_action( "trinity_forms_entry_detail_actions", $entry_id ); ?>
    </div>

    <?php
    // A failed form email is the one thing on this screen somebody must act on,
    // so it gets a callout instead of only a line in the activity log.
    $tf_mail_fails = array_values( array_filter( $logs, static fn( $l ) => "notification" === $l["type"] && "error" === $l["level"] ) );
    if ( $tf_mail_fails ) :
        $tf_mf_ctx = json_decode( (string) ( $tf_mail_fails[0]["context"] ?? "" ), true );
        ?>
        <div class="jnkui-callout is-warn tf-mail-failed"><p>
            <strong><?php echo esc_html( (string) $tf_mail_fails[0]["message"] ); ?>.</strong>
            <?php if ( is_array( $tf_mf_ctx ) && ! empty( $tf_mf_ctx["reason"] ) ) : ?>
                <?php /* translators: %s: the reason the mail system gave. */ echo esc_html( sprintf( __( "Reason: %s", "trinity-forms" ), (string) $tf_mf_ctx["reason"] ) ); ?>
            <?php endif; ?>
            <a href="<?php echo esc_url( admin_url( Admin::PARENT . "&page=" . Admin::SETTINGS . "#tf-mail-check" ) ); ?>"><?php esc_html_e( "Send a test email", "trinity-forms" ); ?></a>
        </p></div>
    <?php endif; ?>

    <div class="tf-entry-grid">
        <div class="tf-entry-main">
            <div class="jnkui-card">
                <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Answers", "trinity-forms" ); ?></h2></div></div>
                <dl class="tf-answers">
                <?php
                // Values are stored as slugs and, for composite fields, as JSON. Showing
                // either raw ({"first":"Ravi"}, "premium_finance_software") makes the screen
                // read like a database dump instead of somebody's message.
                $tf_choice_labels = Form_Manager::choice_labels( $form_id );
                foreach ( $meta as $m ) :
                    if ( Entries::is_internal_field( (string) $m["field_id"] ) ) {
                        continue; // quiz score, created-post marker: shown in Details, not as answers.
                    }
                    $map   = $tf_choice_labels[ $m["field_id"] ] ?? [];
                    $value = Entries::display_value( (string) $m["field_type"], $m["value"], $map );
                    ?>
                    <div>
                        <dt><?php echo esc_html( $m["field_label"] ?: $m["field_id"] ); ?></dt>
                        <dd>
                            <?php if ( "email" === $m["field_type"] && is_email( $value ) ) : ?>
                                <bdi dir="ltr"><a href="<?php echo esc_url( "mailto:" . $value ); ?>"><?php echo esc_html( $value ); ?></a></bdi>
                            <?php elseif ( "phone" === $m["field_type"] && "" !== $value ) : ?>
                                <bdi dir="ltr"><a href="<?php echo esc_url( "tel:" . preg_replace( '/[^0-9+]/', "", $value ) ); ?>"><?php echo esc_html( $value ); ?></a></bdi>
                            <?php elseif ( in_array( $m["field_type"], [ "rich_text", "file" ], true ) ) : ?>
                                <?php echo Entries::display_value( (string) $m["field_type"], $m["value"], $map, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- display_value() escapes (kses for rich text, esc_url/esc_html for file links). ?>
                            <?php elseif ( "" === trim( $value ) ) : ?>
                                <span class="jnkui-muted"><?php esc_html_e( "Not answered", "trinity-forms" ); ?></span>
                            <?php elseif ( Entries::is_ltr_value( (string) $m["field_type"], $value ) ) : ?>
                                <?php echo Entries::ltr( esc_html( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here, ltr() only wraps it. ?>
                            <?php else : ?>
                                <?php echo nl2br( esc_html( $value ) ); ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
                </dl>
            </div>

            <?php if ( $files ) : ?>
                <div class="jnkui-card">
                    <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Files", "trinity-forms" ); ?></h2></div></div>
                    <ul class="jnkui-list">
                    <?php foreach ( $files as $f ) : ?>
                        <li>
                            <span><?php echo esc_html( $f["filename"] ); ?><span class="jnkui-sub"><?php echo esc_html( size_format( (int) $f["size_bytes"] ) ); ?></span></span>
                            <?php if ( ! empty( $f["attachment_id"] ) ) : ?>
                                <a class="button button-small" href="<?php echo esc_url( (string) wp_get_attachment_url( (int) $f["attachment_id"] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( "Download", "trinity-forms" ); ?></a>
                            <?php elseif ( "" !== ( $tf_file_url = \TrinityForms\Private_Files::url_for( (string) $f["filepath"] ) ) ) : ?>
                                <a class="button button-small" href="<?php echo esc_url( $tf_file_url ); ?>"><?php esc_html_e( "Download", "trinity-forms" ); ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="jnkui-card" id="tf-notes">
                <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Notes", "trinity-forms" ); ?></h2>
                    <p><?php esc_html_e( "Private to your team. Never emailed or shown to the person who sent this.", "trinity-forms" ); ?></p></div></div>
                <?php if ( $notes ) : ?>
                    <ul class="tf-notes">
                        <?php foreach ( $notes as $n ) :
                            $is_system = Entry_Notes::TYPE_SYSTEM === $n["note_type"];
                            ?>
                            <li class="tf-note<?php echo $is_system ? " tf-note-system" : ""; ?>">
                                <div class="tf-note-head">
                                    <strong><?php echo esc_html( Entry_Notes::author_name( $n["author_id"] ? (int) $n["author_id"] : null ) ); ?></strong>
                                    <span class="description"><?php echo esc_html( Dates::human( $n["created_at"] ) ); ?></span>
                                    <?php if ( ! $is_system ) : ?>
                                        <a class="tf-note-delete" href="<?php echo esc_url( wp_nonce_url(
                                            add_query_arg( [
                                                "action"   => "trinity_forms_delete_note",
                                                "note_id"  => (int) $n["id"],
                                                "entry_id" => $entry_id,
                                            ], admin_url( "admin-post.php" ) ),
                                            "trinity_forms_delete_note_" . (int) $n["id"]
                                        ) ); ?>" onclick="return confirm('<?php echo esc_js( __( "Delete this note?", "trinity-forms" ) ); ?>');"><?php esc_html_e( "Delete", "trinity-forms" ); ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="tf-note-body"><?php echo nl2br( esc_html( $n["note"] ) ); ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="tf-note-form">
                    <input type="hidden" name="action" value="trinity_forms_add_note" />
                    <input type="hidden" name="entry_id" value="<?php echo (int) $entry_id; ?>" />
                    <?php wp_nonce_field( "trinity_forms_add_note_" . $entry_id ); ?>
                    <label class="screen-reader-text" for="tf-note"><?php esc_html_e( "Add a note", "trinity-forms" ); ?></label>
                    <textarea id="tf-note" name="note" rows="3" class="large-text" placeholder="<?php esc_attr_e( "Add a note…", "trinity-forms" ); ?>"></textarea>
                    <p><button type="submit" class="button"><?php esc_html_e( "Add note", "trinity-forms" ); ?></button></p>
                </form>
            </div>
        </div>

        <div class="tf-entry-side">
            <div class="jnkui-card">
                <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Details", "trinity-forms" ); ?></h2></div></div>
                <dl class="tf-facts">
                    <div><dt><?php esc_html_e( "Form", "trinity-forms" ); ?></dt>
                        <dd>
                            <?php
                            // A deleted form leaves its entries behind, and an empty cell
                            // reads as a rendering bug rather than as "that form is gone".
                            $tf_form_edit = get_edit_post_link( $form_id );
                            if ( "" !== $form_title && $tf_form_edit ) :
                                ?>
                                <a href="<?php echo esc_url( $tf_form_edit ); ?>"><?php echo esc_html( $form_title ); ?></a>
                            <?php elseif ( "" !== $form_title ) : ?>
                                <?php echo esc_html( $form_title ); ?>
                            <?php else : ?>
                                <span class="jnkui-muted"><?php esc_html_e( "Form deleted", "trinity-forms" ); ?></span>
                            <?php endif; ?>
                        </dd></div>
                    <div><dt><?php esc_html_e( "Submitted", "trinity-forms" ); ?></dt>
                        <dd><?php echo esc_html( Dates::human( $entry["submitted_at"], false ) ); ?><span class="jnkui-sub"><?php echo esc_html( Dates::relative( $entry["submitted_at"] ) ); ?></span></dd></div>
                    <div><dt><?php esc_html_e( "Status", "trinity-forms" ); ?></dt>
                        <dd><?php echo Admin_UI::pill( Entries::status_label( (string) $status ), Admin::pill_tone( "status", $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?></dd></div>
                    <?php if ( $tf_show_pay ) : ?>
                        <div><dt><?php esc_html_e( "Payment", "trinity-forms" ); ?></dt>
                            <dd>
                                <?php echo Admin_UI::pill( Entries::payment_label( (string) $pay ), Admin::pill_tone( "payment", $pay ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?>
                                <?php if ( ! empty( $entry["payment_amount"] ) ) : ?>
                                    <span class="jnkui-sub"><?php echo esc_html( $entry["payment_amount"] . " " . (string) $entry["payment_currency"] ); ?></span>
                                <?php endif; ?>
                            </dd></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $entry["user_id"] ) ) :
                        $tf_user = get_userdata( (int) $entry["user_id"] );
                        ?>
                        <div><dt><?php esc_html_e( "User", "trinity-forms" ); ?></dt>
                            <dd><?php echo esc_html( $tf_user ? $tf_user->display_name : __( "Deleted user", "trinity-forms" ) ); ?></dd></div>
                    <?php endif; ?>
                    <?php
                    foreach ( $meta as $tf_m ) {
                        if ( "_trinity_forms_created_post" === $tf_m["field_id"] && (int) $tf_m["value"] > 0 ) :
                            $tf_post_id = (int) $tf_m["value"];
                            $tf_link    = get_edit_post_link( $tf_post_id );
                            ?>
                            <div><dt><?php esc_html_e( "Created post", "trinity-forms" ); ?></dt>
                                <dd><?php if ( $tf_link ) : ?><a href="<?php echo esc_url( $tf_link ); ?>">#<?php echo (int) $tf_post_id; ?></a><?php else : ?>#<?php echo (int) $tf_post_id; ?><?php endif; ?></dd></div>
                            <?php
                        endif;
                    }
                    ?>
                    <div><dt><?php esc_html_e( "IP address", "trinity-forms" ); ?></dt><dd><?php echo $ip ? Entries::ltr( esc_html( $ip ) ) : "–"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></dd></div>
                    <?php if ( ! empty( $entry["source_url"] ) ) : ?>
                        <div><dt><?php esc_html_e( "Sent from", "trinity-forms" ); ?></dt>
                            <dd><a href="<?php echo esc_url( (string) $entry["source_url"] ); ?>" target="_blank" rel="noopener"><bdi dir="ltr"><?php echo esc_html( (string) wp_parse_url( (string) $entry["source_url"], PHP_URL_PATH ) ?: (string) $entry["source_url"] ); ?></bdi></a></dd></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $entry["referer"] ) ) : ?>
                        <div><dt><?php esc_html_e( "Came from", "trinity-forms" ); ?></dt><dd><?php echo Entries::ltr( esc_html( (string) $entry["referer"] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></dd></div>
                    <?php endif; ?>
                    <?php if ( ! empty( $entry["user_agent"] ) ) : ?>
                        <div><dt><?php esc_html_e( "Browser", "trinity-forms" ); ?></dt>
                            <dd><span class="jnkui-sub" dir="ltr" title="<?php echo esc_attr( (string) $entry["user_agent"] ); ?>"><?php echo esc_html( wp_html_excerpt( (string) $entry["user_agent"], 60, "…" ) ); ?></span></dd></div>
                    <?php endif; ?>
                </dl>
            </div>

            <?php
            // Always drawn, on Free too: the log used to appear only once a payment or
            // integration had written to it, so on most entries there was none at all.
            // The submission itself is the first event, then whatever was recorded.
            ?>
            <div class="jnkui-card">
                <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Activity", "trinity-forms" ); ?></h2></div></div>
                <ul class="tf-logs">
                    <?php foreach ( $logs as $l ) :
                        $tf_ctx = json_decode( (string) ( $l["context"] ?? "" ), true );
                        $tf_ctx = is_array( $tf_ctx ) ? $tf_ctx : [];
                        // A result that says "skipped" or "not ok" is not a success,
                        // whatever level it was written at.
                        $tf_level = (string) $l["level"];
                        // Logger writes "warn"; this view's tones say "warning".
                        $tf_level = "warn" === $tf_level ? "warning" : $tf_level;
                        if ( ! empty( $tf_ctx["skipped"] ) && "info" === $tf_level ) {
                            $tf_level = "warning";
                        } elseif ( array_key_exists( "ok", $tf_ctx ) && ! $tf_ctx["ok"] && "info" === $tf_level ) {
                            $tf_level = "error";
                        }
                        $tf_reason = (string) ( $tf_ctx["reason"] ?? $tf_ctx["error"] ?? "" );
                        ?>
                        <li>
                            <?php echo Admin_UI::pill( Logger::type_label( (string) $l["type"] ), "error" === $tf_level ? "danger" : ( "warning" === $tf_level ? "warning" : "muted" ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?>
                            <?php echo esc_html( $l["message"] ); ?>
                            <?php if ( "" !== $tf_reason ) : ?>
                                <span class="jnkui-sub"><?php echo esc_html( $tf_reason ); ?></span>
                            <?php endif; ?>
                            <span class="jnkui-sub"><?php echo esc_html( Dates::human( $l["created_at"] ) ); ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        <?php echo Admin_UI::pill( __( "entry", "trinity-forms" ), "brand" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?>
                        <?php esc_html_e( "Submitted", "trinity-forms" ); ?>
                        <span class="jnkui-sub"><?php echo esc_html( Dates::human( $entry["submitted_at"] ) ); ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
<?php Admin_UI::close(); ?>
