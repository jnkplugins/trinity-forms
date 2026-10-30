<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- every value read here is a read-only list filter from GET; all mutations go through admin-post.php handlers that check their own nonce.

use TrinityForms\Admin;
use TrinityForms\Entries;
use TrinityForms\Dates;
use TrinityForms\Admin_UI;
use TrinityForms\Form_Manager;

$entry_id = isset( $_GET["entry"] ) ? absint( wp_unslash( $_GET["entry"] ) ) : 0;
if ( $entry_id > 0 ) {
    require TRINITY_FORMS_PATH . "admin/views/entry-detail.php";
    return;
}

$form_filter = isset( $_GET["form_id"] ) ? absint( wp_unslash( $_GET["form_id"] ) ) : 0;
$view        = isset( $_GET["view"] ) ? sanitize_key( wp_unslash( $_GET["view"] ) ) : "all";
$search      = isset( $_GET["s"] ) ? sanitize_text_field( wp_unslash( $_GET["s"] ) ) : "";
$date_from   = isset( $_GET["from"] ) ? sanitize_text_field( wp_unslash( $_GET["from"] ) ) : "";
$date_to     = isset( $_GET["to"] ) ? sanitize_text_field( wp_unslash( $_GET["to"] ) ) : "";
$pay_filter  = isset( $_GET["payment_status"] ) ? sanitize_key( wp_unslash( $_GET["payment_status"] ) ) : "";
$page        = max( 1, isset( $_GET["paged"] ) ? absint( wp_unslash( $_GET["paged"] ) ) : 1 );
$per_page    = 25;

$query_args = [
    "form_id"        => $form_filter,
    "view"           => $view,
    "search"         => $search,
    "date_from"      => $date_from,
    "date_to"        => $date_to,
    "payment_status" => $pay_filter,
    "paged"          => $page,
    "per_page"       => $per_page,
];

$result  = Entries::query( $query_args );
$rows    = $result["rows"];
$total   = $result["total"];
$counts  = Entries::counts( $form_filter );
$senders = Entries::senders( array_map( static fn( $r ) => (int) $r->id, $rows ) );

$forms        = get_posts( [ "post_type" => "trinity_forms_form", "post_status" => [ "publish", "draft" ], "numberposts" => 200 ] );

// Payment status only means something on a form that takes payments. Showing "No
// payment" on every row of a contact form is a column of noise, so the column (and
// the payment filter) appear only when a form on this page takes payments, a row
// has a real payment status, or you are already filtering by payment.
$pay_forms = [];
foreach ( $forms as $f ) {
    $tf_form_obj = Form_Manager::get( (int) $f->ID );
    if ( $tf_form_obj && $tf_form_obj->payment_enabled() ) {
        $pay_forms[ (int) $f->ID ] = true;
    }
}
$row_takes_payment = static function ( $r ) use ( $pay_forms ): bool {
    return isset( $pay_forms[ (int) $r->form_id ] ) || ( $r->payment_status ?: "none" ) !== "none";
};
$show_payment = "" !== $pay_filter || ( $form_filter && isset( $pay_forms[ $form_filter ] ) );
foreach ( $rows as $r ) {
    if ( $row_takes_payment( $r ) ) {
        $show_payment = true;
        break;
    }
}
$show_payment_filter = $show_payment || ( $pay_forms && ( ! $form_filter || isset( $pay_forms[ $form_filter ] ) ) );

$titles_by_id = [];
foreach ( $forms as $f ) {
    // An untitled draft renders as an empty <option>, which looks like a broken
    // list rather than a form nobody has named yet.
    $titles_by_id[ (int) $f->ID ] = $f->post_title !== ""
        ? $f->post_title
        /* translators: %d: form ID. */
        : sprintf( __( "(untitled form #%d)", "trinity-forms" ), (int) $f->ID );
}

$base_url = admin_url( "edit.php?post_type=trinity_forms_form&page=" . Admin::ENTRIES );

/** URL for a filter-bar tab, keeping the form filter but resetting paging. */
$tf_view_url = static function ( string $v ) use ( $base_url, $form_filter, $search ): string {
    return add_query_arg( array_filter( [
        "view"    => $v,
        "form_id" => $form_filter ?: null,
        "s"       => $search ?: null,
    ] ), $base_url );
};

/**
 * A nonced single-row action link.
 *
 * The current filters ride along so Admin::entries_url() can put them back on the
 * redirect — otherwise trashing one row out of a search dumps you at the
 * unfiltered list and you have to type the search again.
 */
$tf_action_url = static function ( int $id, string $verb ) use ( $form_filter, $view, $search, $date_from, $date_to, $pay_filter, $page ): string {
    return wp_nonce_url(
        add_query_arg( array_filter( [
            "action"         => "trinity_forms_entry_action",
            "entry_id"       => $id,
            "verb"           => $verb,
            "form_id"        => $form_filter ?: null,
            "view"           => "all" !== $view ? $view : null,
            "s"              => $search ?: null,
            "from"           => $date_from ?: null,
            "to"             => $date_to ?: null,
            "payment_status" => $pay_filter ?: null,
            "paged"          => $page > 1 ? $page : null,
        ] ), admin_url( "admin-post.php" ) ),
        "trinity_forms_entry_action_" . $id
    );
};

// Answer columns only make sense once a single form is picked — across forms the
// field ids mean different things and the header would be a lie.
$available_columns = $form_filter ? Entries::field_columns( $form_filter ) : [];
$chosen_columns    = $form_filter ? Entries::user_columns( $form_filter ) : [];
$column_values     = $chosen_columns
    ? Entries::values_for( array_map( static fn( $r ) => (int) $r->id, $rows ), $chosen_columns )
    : [];

$in_trash = "trash" === $view;
$in_spam  = "spam" === $view;
// checkbox, star, Entry (who sent it), Form, Submitted, Status (+ Payment when shown).
$col_span = 6 + ( $show_payment ? 1 : 0 ) + count( $chosen_columns );

// Export honours the filters you are looking at: exporting something other than
// what is on screen is the classic way to lose trust in an export.
$export_url = add_query_arg( array_filter( [
    "form_id"  => $form_filter ?: null,
    "view"     => "all" !== $view ? $view : null,
    "s"        => $search ?: null,
    "from"     => $date_from ?: null,
    "to"       => $date_to ?: null,
    "_wpnonce" => wp_create_nonce( "wp_rest" ),
] ), rest_url( "trinity-forms/v1/submissions/export.csv" ) );

$tf_date_format = (string) get_option( "date_format", "M j, Y" );
$tf_time_format = (string) get_option( "time_format", "g:i a" );

Admin_UI::open(
    __( "Entries", "trinity-forms" ),
    __( "Everything people have sent through your forms. Open one to read it, reply or add a note.", "trinity-forms" ),
    $titles_by_id ? '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( "Export CSV", "trinity-forms" ) . "</a>" : ""
);
?>

    <?php
    // First run: no forms exist yet, so the filters and empty table below are noise.
    if ( ! $titles_by_id ) :
        echo Admin_UI::empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            __( "No forms yet", "trinity-forms" ),
            __( "Build a form, put its shortcode on a page, and every submission is stored here in your own database. No caps, no per-entry fees.", "trinity-forms" ),
            '<a class="button button-primary" href="' . esc_url( admin_url( "post-new.php?post_type=trinity_forms_form" ) ) . '">' . esc_html__( "Create your first form", "trinity-forms" ) . "</a>",
            "form"
        );
        Admin_UI::close();
        return;
    endif;
    ?>

    <?php
    // Result notices. One map rather than a branch per verb: every bulk action
    // needs to say the same shape of thing.
    $tf_done = isset( $_GET["tf_done"] ) ? sanitize_key( wp_unslash( $_GET["tf_done"] ) ) : "";
    $tf_n    = isset( $_GET["tf_n"] ) ? absint( wp_unslash( $_GET["tf_n"] ) ) : 0;
    if ( $tf_done ) :
        $tf_messages = [
            "read"         => __( "%s marked as read.", "trinity-forms" ),
            "unread"       => __( "%s marked as unread.", "trinity-forms" ),
            "star"         => __( "%s starred.", "trinity-forms" ),
            "unstar"       => __( "%s unstarred.", "trinity-forms" ),
            "spam"         => __( "%s marked as spam.", "trinity-forms" ),
            "trash"        => __( "%s moved to trash.", "trinity-forms" ),
            "restore"      => __( "%s restored.", "trinity-forms" ),
            "delete"       => __( "%s permanently deleted.", "trinity-forms" ),
            "deleted"      => __( "%s permanently deleted.", "trinity-forms" ),
            "resend"       => __( "Notifications resent for %s.", "trinity-forms" ),
            "note"         => __( "Note added.", "trinity-forms" ),
            "note_deleted" => __( "Note deleted.", "trinity-forms" ),
            "columns"      => __( "Columns updated.", "trinity-forms" ),
            "approve"      => __( "%s approved.", "trinity-forms" ),
            "reject"       => __( "%s rejected.", "trinity-forms" ),
            "noop"         => __( "Nothing selected.", "trinity-forms" ),
        ];
        if ( isset( $tf_messages[ $tf_done ] ) ) :
            $tf_count_label = sprintf(
                /* translators: %s: number of entries. */
                _n( "%s entry", "%s entries", max( 1, $tf_n ), "trinity-forms" ),
                number_format_i18n( max( 1, $tf_n ) )
            );
            $tf_class = "noop" === $tf_done ? "notice-warning" : "notice-success";
            ?>
            <div class="notice <?php echo esc_attr( $tf_class ); ?> is-dismissible">
                <p><?php echo esc_html( sprintf( $tf_messages[ $tf_done ], $tf_count_label ) ); ?></p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    // Filter tabs. Counts make "is there anything waiting for me?" answerable at a
    // glance. Spam and trash drop out while they are empty.
    $tf_tabs = [
        "all"     => __( "All", "trinity-forms" ),
        "unread"  => __( "Unread", "trinity-forms" ),
        "starred" => __( "Starred", "trinity-forms" ),
        "spam"    => __( "Spam", "trinity-forms" ),
        "trash"   => __( "Trash", "trinity-forms" ),
    ];
    // Extra tabs (Pro adds "Pending review"; abandoned partial entries get their
    // own tab because they are kept out of All). Shown only when non-empty.
    $tf_tabs = (array) apply_filters( "trinity_forms_entry_tabs", $tf_tabs + [ "partial" => __( "Partial", "trinity-forms" ) ] );
    $tf_visible = [];
    foreach ( $tf_tabs as $tf_key => $tf_label ) {
        $tf_n_tab = (int) ( $counts[ $tf_key ] ?? 0 );
        if ( ! in_array( $tf_key, [ "all", "unread", "starred" ], true ) && 0 === $tf_n_tab && $view !== $tf_key ) {
            continue;
        }
        $tf_visible[ $tf_key ] = [ "label" => $tf_label, "count" => $tf_n_tab ];
    }
    ?>
    <nav class="tf-segments" aria-label="<?php esc_attr_e( "Filter entries", "trinity-forms" ); ?>">
        <?php foreach ( $tf_visible as $tf_key => $tf_tab ) : ?>
            <a href="<?php echo esc_url( $tf_view_url( $tf_key ) ); ?>" class="<?php echo $view === $tf_key ? "is-active" : ""; ?>"<?php echo $view === $tf_key ? ' aria-current="page"' : ""; ?>>
                <?php echo esc_html( $tf_tab["label"] ); ?>
                <span class="tf-count"><?php echo esc_html( number_format_i18n( $tf_tab["count"] ) ); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="get" action="<?php echo esc_url( admin_url( "edit.php" ) ); ?>" class="trinity-forms-filter tf-filterbar">
        <input type="hidden" name="post_type" value="trinity_forms_form" />
        <input type="hidden" name="page" value="<?php echo esc_attr( Admin::ENTRIES ); ?>" />
        <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />

        <label class="screen-reader-text" for="tf-form-filter"><?php esc_html_e( "Form", "trinity-forms" ); ?></label>
        <select name="form_id" id="tf-form-filter">
            <option value="0"><?php esc_html_e( "All forms", "trinity-forms" ); ?></option>
            <?php foreach ( $titles_by_id as $id => $title ) : ?>
                <option value="<?php echo (int) $id; ?>" <?php selected( $form_filter, $id ); ?>><?php echo esc_html( $title ); ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ( $show_payment_filter ) : ?>
        <label class="screen-reader-text" for="tf-pay-filter"><?php esc_html_e( "Payment", "trinity-forms" ); ?></label>
        <select name="payment_status" id="tf-pay-filter">
            <option value=""><?php esc_html_e( "Any payment", "trinity-forms" ); ?></option>
            <?php foreach ( [ "paid", "pending", "failed", "refunded", "none" ] as $ps ) : ?>
                <option value="<?php echo esc_attr( $ps ); ?>" <?php selected( $pay_filter, $ps ); ?>><?php echo esc_html( Entries::payment_label( $ps ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <span class="tf-dates">
            <label class="screen-reader-text" for="tf-from"><?php esc_html_e( "From date", "trinity-forms" ); ?></label>
            <input type="date" id="tf-from" name="from" value="<?php echo esc_attr( $date_from ); ?>" />
            <span class="jnkui-muted" aria-hidden="true"><?php esc_html_e( "to", "trinity-forms" ); ?></span>
            <label class="screen-reader-text" for="tf-to"><?php esc_html_e( "To date", "trinity-forms" ); ?></label>
            <input type="date" id="tf-to" name="to" value="<?php echo esc_attr( $date_to ); ?>" />
        </span>

        <label class="screen-reader-text" for="tf-s"><?php esc_html_e( "Search entries", "trinity-forms" ); ?></label>
        <input type="search" id="tf-s" name="s" class="tf-search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( "Search answers…", "trinity-forms" ); ?>" />

        <button class="button"><?php esc_html_e( "Filter", "trinity-forms" ); ?></button>

        <?php if ( $search || $date_from || $date_to || $pay_filter ) : ?>
            <a class="button-link" href="<?php echo esc_url( $tf_view_url( $view ) ); ?>"><?php esc_html_e( "Clear", "trinity-forms" ); ?></a>
        <?php endif; ?>
    </form>

    <?php if ( $available_columns ) : ?>
        <?php // A plain <details> rather than WP's Screen Options: this list depends on
              // which form is selected, and Screen Options is rendered before that choice
              // is known. Keeping it here also puts it next to the table it changes. ?>
        <details class="tf-columns-panel">
            <summary><?php esc_html_e( "Choose answer columns", "trinity-forms" ); ?></summary>
            <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>">
                <input type="hidden" name="action" value="trinity_forms_entry_columns" />
                <input type="hidden" name="form_id" value="<?php echo (int) $form_filter; ?>" />
                <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
                <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
                <?php wp_nonce_field( "trinity_forms_entry_columns_" . $form_filter ); ?>
                <p class="description"><?php esc_html_e( "Show these answers as columns in the list.", "trinity-forms" ); ?></p>
                <div class="tf-columns-grid">
                    <?php foreach ( $available_columns as $tf_cid => $tf_clabel ) : ?>
                        <label>
                            <input type="checkbox" name="columns[]" value="<?php echo esc_attr( $tf_cid ); ?>"
                                <?php checked( in_array( $tf_cid, $chosen_columns, true ) ); ?> />
                            <?php echo esc_html( $tf_clabel ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p><button type="submit" class="button"><?php esc_html_e( "Apply columns", "trinity-forms" ); ?></button></p>
            </form>
        </details>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="tf-entries-form">
        <input type="hidden" name="action" value="trinity_forms_entry_bulk" />
        <input type="hidden" name="form_id" value="<?php echo (int) $form_filter; ?>" />
        <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
        <input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
        <input type="hidden" name="from" value="<?php echo esc_attr( $date_from ); ?>" />
        <input type="hidden" name="to" value="<?php echo esc_attr( $date_to ); ?>" />
        <input type="hidden" name="paged" value="<?php echo (int) $page; ?>" />
        <?php wp_nonce_field( "trinity_forms_entry_bulk" ); ?>

        <div class="jnkui-toolbar tf-bulkbar">
            <div class="tf-bulk">
                <label class="screen-reader-text" for="tf-bulk"><?php esc_html_e( "Bulk action", "trinity-forms" ); ?></label>
                <select name="bulk_action" id="tf-bulk">
                    <option value=""><?php esc_html_e( "With selected…", "trinity-forms" ); ?></option>
                    <?php if ( $in_trash || $in_spam ) : ?>
                        <option value="restore"><?php esc_html_e( "Restore", "trinity-forms" ); ?></option>
                        <option value="delete"><?php esc_html_e( "Delete permanently", "trinity-forms" ); ?></option>
                    <?php else : ?>
                        <option value="read"><?php esc_html_e( "Mark as read", "trinity-forms" ); ?></option>
                        <option value="unread"><?php esc_html_e( "Mark as unread", "trinity-forms" ); ?></option>
                        <option value="star"><?php esc_html_e( "Star", "trinity-forms" ); ?></option>
                        <option value="unstar"><?php esc_html_e( "Remove star", "trinity-forms" ); ?></option>
                        <option value="resend"><?php esc_html_e( "Resend notifications", "trinity-forms" ); ?></option>
                        <option value="print"><?php esc_html_e( "Print", "trinity-forms" ); ?></option>
                        <option value="export"><?php esc_html_e( "Export selected to CSV", "trinity-forms" ); ?></option>
                        <option value="spam"><?php esc_html_e( "Mark as spam", "trinity-forms" ); ?></option>
                        <option value="trash"><?php esc_html_e( "Move to trash", "trinity-forms" ); ?></option>
                        <?php foreach ( (array) apply_filters( "trinity_forms_entry_bulk_actions", [] ) as $tf_bulk_key => $tf_bulk_label ) : ?>
                            <option value="<?php echo esc_attr( $tf_bulk_key ); ?>"><?php echo esc_html( $tf_bulk_label ); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e( "Apply", "trinity-forms" ); ?></button>
            </div>
            <span class="jnkui-muted displaying-num">
                <?php
                printf(
                    /* translators: %s: number of entries. */
                    esc_html( _n( "%s entry", "%s entries", $total, "trinity-forms" ) ),
                    esc_html( number_format_i18n( $total ) )
                );
                ?>
            </span>
        </div>

        <div class="jnkui-table-wrap">
        <table class="jnkui-table tf-entries">
            <thead>
                <tr>
                    <td class="tf-col-cb">
                        <label class="screen-reader-text" for="tf-cb-all"><?php esc_html_e( "Select all", "trinity-forms" ); ?></label>
                        <input type="checkbox" id="tf-cb-all" />
                    </td>
                    <th class="tf-col-star"><span class="screen-reader-text"><?php esc_html_e( "Starred", "trinity-forms" ); ?></span></th>
                    <th class="tf-col-who"><?php esc_html_e( "Entry", "trinity-forms" ); ?></th>
                    <th class="tf-col-form"><?php esc_html_e( "Form", "trinity-forms" ); ?></th>
                    <?php foreach ( $chosen_columns as $tf_cid ) : ?>
                        <th class="tf-col-answer"><?php echo esc_html( $available_columns[ $tf_cid ] ?? $tf_cid ); ?></th>
                    <?php endforeach; ?>
                    <th class="tf-col-date"><?php esc_html_e( "Submitted", "trinity-forms" ); ?></th>
                    <th class="tf-col-status"><?php esc_html_e( "Status", "trinity-forms" ); ?></th>
                    <?php if ( $show_payment ) : ?><th class="tf-col-pay"><?php esc_html_e( "Payment", "trinity-forms" ); ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! $rows ) : ?>
                <tr class="tf-empty-row"><td colspan="<?php echo (int) $col_span; ?>">
                    <?php
                    // An empty trash or spam queue is a good thing, not an onboarding
                    // moment: telling someone to "add a form to a page" here reads as if
                    // the plugin has lost track of what they were doing.
                    if ( $search || $date_from || $date_to ) {
                        $tf_empty = [ __( "No entries match those filters.", "trinity-forms" ), __( "Try a wider date range or a different search.", "trinity-forms" ), '<a class="button" href="' . esc_url( $tf_view_url( $view ) ) . '">' . esc_html__( "Clear the filters", "trinity-forms" ) . "</a>", "search" ];
                    } elseif ( $in_trash ) {
                        $tf_empty = [ __( "The trash is empty.", "trinity-forms" ), __( "Entries you move to the trash wait here before they are deleted for good.", "trinity-forms" ), "", "check" ];
                    } elseif ( $in_spam ) {
                        $tf_empty = [ __( "No spam. Nice.", "trinity-forms" ), __( "Anything the spam checks catch lands here, so you can rescue a real message.", "trinity-forms" ), "", "check" ];
                    } elseif ( "unread" === $view ) {
                        $tf_empty = [ __( "You are all caught up.", "trinity-forms" ), __( "New entries show up here until you open them.", "trinity-forms" ), "", "check" ];
                    } elseif ( "starred" === $view ) {
                        $tf_empty = [ __( "No starred entries yet.", "trinity-forms" ), __( "Star an entry from the list to keep it handy here.", "trinity-forms" ), "", "inbox" ];
                    } else {
                        $tf_empty = [ __( "No entries yet.", "trinity-forms" ), __( "Entries appear here the moment someone submits. Add a form to a page to start collecting.", "trinity-forms" ), '<a class="button" href="' . esc_url( admin_url( Admin::PARENT ) ) . '">' . esc_html__( "Go to your forms", "trinity-forms" ) . "</a>", "inbox" ];
                    }
                    echo Admin_UI::empty_state( $tf_empty[0], $tf_empty[1], $tf_empty[2], $tf_empty[3] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
                    ?>
                </td></tr>
            <?php else : foreach ( $rows as $r ) :
                $rid       = (int) $r->id;
                $view_url  = add_query_arg( array_filter( [
                    "entry"   => $rid,
                    "form_id" => $form_filter ?: null,
                    "view"    => "all" !== $view ? $view : null,
                    "s"       => $search ?: null,
                ] ), $base_url );
                $starred   = ! empty( $r->is_starred );
                $unread    = empty( $r->is_read );
                $sender    = $senders[ $rid ] ?? [];
                $s_name    = (string) ( $sender["name"] ?? "" );
                $s_email   = (string) ( $sender["email"] ?? "" );
                $tf_ts     = Dates::timestamp( $r->submitted_at );
                ?>
                <tr class="<?php echo $unread ? "tf-unread" : ""; ?>">
                    <th scope="row" class="tf-col-cb">
                        <label class="screen-reader-text" for="tf-cb-<?php echo (int) $rid; ?>">
                            <?php
                            /* translators: %d: entry ID. */
                            echo esc_html( sprintf( __( "Select entry %d", "trinity-forms" ), $rid ) );
                            ?>
                        </label>
                        <input type="checkbox" id="tf-cb-<?php echo (int) $rid; ?>" name="entry_ids[]" value="<?php echo (int) $rid; ?>" />
                    </th>
                    <td class="tf-col-star">
                        <a class="tf-star<?php echo $starred ? " is-on" : ""; ?>"
                           href="<?php echo esc_url( $tf_action_url( $rid, $starred ? "unstar" : "star" ) ); ?>"
                           aria-label="<?php echo esc_attr( $starred ? __( "Remove star", "trinity-forms" ) : __( "Star this entry", "trinity-forms" ) ); ?>">
                            <span class="dashicons dashicons-<?php echo $starred ? "star-filled" : "star-empty"; ?>"></span>
                        </a>
                    </td>
                    <td class="tf-col-who">
                        <a class="tf-entry-link" href="<?php echo esc_url( $view_url ); ?>">
                            <?php if ( $unread ) : ?><span class="jnkui-dot is-brand" title="<?php esc_attr_e( "Unread", "trinity-forms" ); ?>"></span><span class="screen-reader-text"><?php esc_html_e( "Unread:", "trinity-forms" ); ?></span><?php endif; ?>
                            <?php
                            if ( "" !== $s_name ) {
                                echo esc_html( $s_name );
                            } elseif ( "" !== $s_email ) {
                                echo Entries::ltr( esc_html( $s_email ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
                            } else {
                                /* translators: %d: entry ID. */
                                echo esc_html( sprintf( __( "Entry #%d", "trinity-forms" ), $rid ) );
                            }
                            ?>
                        </a>
                        <span class="jnkui-sub">
                            <?php if ( "" !== $s_email && "" !== $s_name ) : ?>
                                <?php echo Entries::ltr( esc_html( $s_email ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?> &middot;
                            <?php endif; ?>
                            #<?php echo (int) $rid; ?>
                        </span>
                        <?php // Phones drop the date column; the age rides along here instead. ?>
                        <span class="jnkui-sub tf-mobile-only"><?php echo esc_html( Dates::relative( $r->submitted_at ) ); ?></span>
                        <div class="row-actions">
                            <span><a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( "View", "trinity-forms" ); ?></a></span>
                            <?php if ( "" !== $s_email ) : ?>
                                | <span><a href="<?php echo esc_url( "mailto:" . $s_email ); ?>"><?php esc_html_e( "Email", "trinity-forms" ); ?></a></span>
                            <?php endif; ?>
                            <?php if ( $in_trash || $in_spam ) : ?>
                                | <span><a href="<?php echo esc_url( $tf_action_url( $rid, "restore" ) ); ?>"><?php esc_html_e( "Restore", "trinity-forms" ); ?></a></span>
                                | <span class="trash"><a class="submitdelete" href="<?php echo esc_url( $tf_action_url( $rid, "delete" ) ); ?>"
                                    onclick="return confirm('<?php echo esc_js( __( "Permanently delete this entry? This cannot be undone.", "trinity-forms" ) ); ?>');"><?php esc_html_e( "Delete permanently", "trinity-forms" ); ?></a></span>
                            <?php else : ?>
                                | <span><a href="<?php echo esc_url( $tf_action_url( $rid, $unread ? "read" : "unread" ) ); ?>"><?php echo $unread ? esc_html__( "Mark read", "trinity-forms" ) : esc_html__( "Mark unread", "trinity-forms" ); ?></a></span>
                                | <span><a href="<?php echo esc_url( $tf_action_url( $rid, "spam" ) ); ?>"><?php esc_html_e( "Spam", "trinity-forms" ); ?></a></span>
                                | <span class="trash"><a class="submitdelete" href="<?php echo esc_url( $tf_action_url( $rid, "trash" ) ); ?>"><?php esc_html_e( "Trash", "trinity-forms" ); ?></a></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="tf-col-form"><?php echo esc_html( $titles_by_id[ (int) $r->form_id ] ?? "–" ); ?></td>
                    <?php foreach ( $chosen_columns as $tf_cid ) :
                        $tf_cval = (string) ( $column_values[ $rid ][ $tf_cid ] ?? "" );
                        ?>
                        <td class="tf-col-answer">
                            <?php if ( "" === $tf_cval ) : ?>
                                <span class="jnkui-muted">–</span>
                            <?php else : ?>
                                <?php // Truncated in the cell, full text on hover: a list row is
                                      // a scanning surface, and one long answer must not set the
                                      // height of every row on the page. ?>
                                <span title="<?php echo esc_attr( $tf_cval ); ?>"<?php echo Entries::is_ltr_value( "", $tf_cval ) ? ' dir="ltr"' : ""; ?>><?php echo esc_html( wp_html_excerpt( $tf_cval, 60, "…" ) ); ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="tf-col-date" title="<?php echo esc_attr( Dates::human( $r->submitted_at ) ); ?>">
                        <?php if ( $tf_ts ) : ?>
                            <?php echo esc_html( wp_date( $tf_date_format, $tf_ts ) ); ?>
                            <span class="jnkui-sub"><?php echo esc_html( wp_date( $tf_time_format, $tf_ts ) . " · " . Dates::relative( $r->submitted_at ) ); ?></span>
                        <?php else : ?>
                            <span class="jnkui-muted">–</span>
                        <?php endif; ?>
                    </td>
                    <?php
                    $entry_status = (string) $r->status;
                    $pay_status   = (string) ( $r->payment_status ?: "none" );
                    ?>
                    <td class="tf-col-status"><?php echo Admin_UI::pill( Entries::status_label( $entry_status ), Admin::pill_tone( "status", $entry_status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?></td>
                    <?php if ( $show_payment ) : ?>
                    <td class="tf-col-pay">
                        <?php if ( $row_takes_payment( $r ) ) : ?>
                            <?php echo Admin_UI::pill( Entries::payment_label( $pay_status ), Admin::pill_tone( "payment", $pay_status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in pill(). ?>
                        <?php else : ?>
                            <span class="jnkui-muted" title="<?php esc_attr_e( "This form does not take payments", "trinity-forms" ); ?>">–</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </form>

    <?php
    $total_pages = (int) ceil( $total / $per_page );
    if ( $total_pages > 1 ) :
        $paginate_base = add_query_arg( array_filter( [
            "view"           => "all" !== $view ? $view : null,
            "form_id"        => $form_filter ?: null,
            "s"              => $search ?: null,
            "from"           => $date_from ?: null,
            "to"             => $date_to ?: null,
            "payment_status" => $pay_filter ?: null,
            "paged"          => "%#%",
        ] ), $base_url );

        $links = paginate_links( [
            "base"      => $paginate_base,
            "format"    => "",
            "current"   => $page,
            "total"     => $total_pages,
            "prev_text" => "‹",
            "next_text" => "›",
        ] );
        ?>
        <div class="tf-pagination"><?php echo wp_kses_post( $links ); ?></div>
    <?php endif; ?>
<?php Admin_UI::close(); ?>

<script>
// Select-all for the bulk checkbox column. Twelve lines of inline JS rather than a
// script file + enqueue: it has one job and only exists on this screen.
document.addEventListener( "DOMContentLoaded", function () {
    var all = document.getElementById( "tf-cb-all" );
    if ( ! all ) { return; }
    all.addEventListener( "change", function () {
        document.querySelectorAll( 'input[name="entry_ids[]"]' ).forEach( function ( cb ) {
            cb.checked = all.checked;
        } );
    } );
} );
</script>
