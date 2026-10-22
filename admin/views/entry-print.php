<?php
/**
 * Standalone print view for one or many entries.
 *
 * Rendered from Admin::maybe_render_print_view() (the Print button on an entry) and
 * from the "Print" bulk action — in both cases before any admin chrome loads, then
 * exit, because printing the normal admin screen gives you the menu, the toolbar and
 * the notices, which is exactly what nobody wants on paper.
 *
 * Input: $tf_print_entry_id (one) or $tf_print_entry_ids (many).
 */

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required.

use TrinityForms\Entries;
use TrinityForms\Entry_Notes;
use TrinityForms\Dates;
use TrinityForms\Form_Manager;

global $wpdb;

// Callers pass either one id (the Print button on an entry) or many (the bulk
// action). Normalising to a list here means one template covers both, and a bulk
// print is genuinely one document rather than N tabs.
$tf_print_ids = isset( $tf_print_entry_ids )
    ? array_values( array_filter( array_map( "absint", (array) $tf_print_entry_ids ) ) )
    : [ absint( $tf_print_entry_id ?? 0 ) ];

$tf_entries = [];
foreach ( $tf_print_ids as $tf_id ) {
    $tf_entry = Entries::get( $tf_id );
    if ( $tf_entry ) {
        $tf_entries[] = $tf_entry;
    }
}

if ( ! $tf_entries ) {
    wp_die( esc_html__( "Entry not found.", "trinity-forms" ) );
}

$tf_multiple = count( $tf_entries ) > 1;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( "charset" ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php
echo esc_html(
    $tf_multiple
        /* translators: %d: number of entries. */
        ? sprintf( __( "%d entries", "trinity-forms" ), count( $tf_entries ) )
        /* translators: %d: entry ID. */
        : sprintf( __( "Entry #%d", "trinity-forms" ), (int) $tf_entries[0]["id"] )
);
?></title>
<style>
body{font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#111;max-width:800px;margin:0 auto;padding:2rem 1.25rem}
h1{font-size:1.5rem;margin:0 0 .25rem}
.tf-sub{color:#555;margin:0 0 1.5rem}
table{width:100%;border-collapse:collapse;margin:0 0 1.5rem}
th,td{text-align:left;padding:.5rem .6rem;border-bottom:1px solid #ddd;vertical-align:top}
th{width:32%;font-weight:600;color:#333}
h2{font-size:1.05rem;margin:1.5rem 0 .5rem;border-bottom:1px solid #ddd;padding-bottom:.3rem}
.tf-note{border-bottom:1px solid #eee;padding:.5rem 0}
.tf-note-head{color:#555;font-size:.85rem}
.tf-print-bar{margin-bottom:1.5rem}
.tf-entry + .tf-entry{margin-top:2.5rem;padding-top:2rem;border-top:2px solid #999}
@media print{.tf-print-bar{display:none}body{padding:0;max-width:none}
/* One entry per sheet: a bulk print is a stack of records, not a scroll. */
.tf-entry{break-before:page;page-break-before:always}
.tf-entry:first-of-type{break-before:auto;page-break-before:auto}
.tf-entry + .tf-entry{margin-top:0;padding-top:0;border-top:0}}
</style>
</head>
<body>
<div class="tf-print-bar">
    <button onclick="window.print()"><?php esc_html_e( "Print", "trinity-forms" ); ?></button>
</div>

<?php foreach ( $tf_entries as $entry ) :
    $entry_id      = (int) $entry["id"];
    $form_id       = (int) $entry["form_id"];
    $form_title    = get_the_title( $form_id );
    $choice_labels = Form_Manager::choice_labels( $form_id );
    $ip            = ! empty( $entry["ip_address"] ) ? @inet_ntop( $entry["ip_address"] ) : "";
    $notes         = Entry_Notes::for_entry( $entry_id );
    $meta          = $wpdb->get_results( $wpdb->prepare(
        "SELECT field_id, field_label, field_type, value FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d ORDER BY id ASC",
        $entry_id
    ), ARRAY_A );
    ?>
<div class="tf-entry">
    <h1><?php echo esc_html( sprintf( /* translators: %d: entry ID. */ __( "Entry #%d", "trinity-forms" ), $entry_id ) ); ?></h1>
    <?php
    // Built as a list and joined, so a missing piece (a deleted form leaves no title)
    // can't leave a stray leading or doubled separator on the line.
    $subtitle = array_filter( [
        $form_title,
        // Absolute only: "(2 minutes ago)" is wrong as soon as it is on paper.
        Dates::human( $entry["submitted_at"], false ),
        $ip,
    ] );
    ?>
    <p class="tf-sub"><?php echo esc_html( implode( " · ", $subtitle ) ); ?></p>

    <table>
        <tbody>
        <?php foreach ( $meta as $m ) :
            if ( \TrinityForms\Entries::is_internal_field( (string) $m["field_id"] ) ) {
                continue;
            }
            $map = $choice_labels[ $m["field_id"] ] ?? [];
            ?>
            <tr>
                <th><?php echo esc_html( $m["field_label"] ?: $m["field_id"] ); ?></th>
                <td><?php echo \TrinityForms\Entries::display_value( (string) $m["field_type"], $m["value"], $map, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- display_value() escapes its output. ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $notes ) : ?>
        <h2><?php esc_html_e( "Notes", "trinity-forms" ); ?></h2>
        <?php foreach ( $notes as $n ) : ?>
            <div class="tf-note">
                <div class="tf-note-head">
                    <strong><?php echo esc_html( Entry_Notes::author_name( $n["author_id"] ? (int) $n["author_id"] : null ) ); ?></strong>
                    · <?php echo esc_html( Dates::human( $n["created_at"], false ) ); ?>
                </div>
                <div><?php echo nl2br( esc_html( $n["note"] ) ); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

</body>
</html>
