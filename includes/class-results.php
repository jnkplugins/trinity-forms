<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Results / analytics report. Turns any form with choice or rating fields into a
 * survey/poll: aggregates responses across entries into per-option counts and
 * percentages, and averages for numeric/rating fields. Read-only.
 */
class Results {

    const PAGE = "trinity-forms-results";

    /** Field types whose values are tallied as discrete choices. */
    private const CHOICE_TYPES  = [ "radio", "select", "checkbox" ];
    /** Field types averaged as numbers. */
    private const NUMERIC_TYPES = [ "rating", "range", "number" ];

    public static function bootstrap(): void {
        add_action( "admin_menu", [ self::class, "register_menu" ], 11 );
    }

    public static function register_menu(): void {
        add_submenu_page(
            Admin::PARENT,
            __( "Results", "trinity-forms" ),
            __( "Results", "trinity-forms" ),
            Admin::CAP,
            self::PAGE,
            [ self::class, "render_page" ]
        );
    }

    /**
     * Aggregate a form's responses.
     *
     * @return array{total:int, fields:array<int, array>}
     */
    public static function aggregate( int $form_id ): array {
        global $wpdb;
        $entries_t = $wpdb->prefix . "trinity_forms_entries";
        $meta_t    = $wpdb->prefix . "trinity_forms_entry_meta";

        // Completed and approved: once "Hold for review" is on, every approved entry
        // is a real answer and must not drop out of the numbers.
        $counted = Entries::counted_statuses();
        $holds   = implode( ",", array_fill( 0, count( $counted ), "%s" ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$entries_t} WHERE form_id = %d AND status IN ({$holds})",
            $form_id, ...$counted
        ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.field_id, m.field_label, m.field_type, m.value
             FROM {$meta_t} m
             INNER JOIN {$entries_t} e ON e.id = m.entry_id
             WHERE e.form_id = %d AND e.status IN ({$holds})",
            $form_id, ...$counted
        ), ARRAY_A );

        // Shared with notification emails and the entry screen, so a choice reads the
        // same wherever it is shown (Form_Manager::choice_labels).
        $choice_labels = Form_Manager::choice_labels( $form_id );

        $by_field = [];
        foreach ( (array) $rows as $r ) {
            $fid = (string) $r["field_id"];
            if ( ! isset( $by_field[ $fid ] ) ) {
                $by_field[ $fid ] = [
                    "id"      => $fid,
                    "label"   => (string) $r["field_label"],
                    "type"    => (string) $r["field_type"],
                    "counts"  => [],   // choice → value => n
                    "numbers" => [],   // numeric samples
                    "answered" => 0,
                ];
            }
            $val = (string) ( $r["value"] ?? "" );
            if ( $val === "" ) {
                continue;
            }
            $by_field[ $fid ]["answered"]++;
            $type = $by_field[ $fid ]["type"];
            if ( in_array( $type, self::CHOICE_TYPES, true ) ) {
                // Checkbox / multi values are stored comma-joined or JSON.
                $parts = self::split_multi( $val );
                foreach ( $parts as $p ) {
                    // Tally under the label the respondent read, not the slug we store.
                    // A poll whose bars read "premium_finance_software" is showing the
                    // reader our database instead of their own answers. An option
                    // renamed or removed since keeps whatever was stored.
                    $key = $choice_labels[ $fid ][ $p ] ?? $p;
                    $by_field[ $fid ]["counts"][ $key ] = ( $by_field[ $fid ]["counts"][ $key ] ?? 0 ) + 1;
                }
            } elseif ( in_array( $type, self::NUMERIC_TYPES, true ) && is_numeric( $val ) ) {
                $by_field[ $fid ]["numbers"][] = (float) $val;
            }
        }

        // Only surface fields we can chart (choice or numeric with data).
        $fields = [];
        foreach ( $by_field as $f ) {
            $charty = in_array( $f["type"], self::CHOICE_TYPES, true ) && ! empty( $f["counts"] );
            $numy   = in_array( $f["type"], self::NUMERIC_TYPES, true ) && ! empty( $f["numbers"] );
            if ( ! $charty && ! $numy ) {
                continue;
            }
            if ( $numy ) {
                $f["avg"] = array_sum( $f["numbers"] ) / count( $f["numbers"] );
                $f["min"] = min( $f["numbers"] );
                $f["max"] = max( $f["numbers"] );
            }
            if ( $charty ) {
                arsort( $f["counts"] );
            }
            $fields[] = $f;
        }

        return [ "total" => $total, "fields" => $fields ];
    }

    private static function split_multi( string $val ): array {
        $decoded = json_decode( $val, true );
        if ( is_array( $decoded ) ) {
            return array_map( "strval", $decoded );
        }
        // Comma-joined fallback (Field_Base::to_csv_value style).
        return array_filter( array_map( "trim", explode( ",", $val ) ), static fn( $s ) => $s !== "" );
    }

    public static function render_page(): void {
        $forms = get_posts( [ "post_type" => "trinity_forms_form", "post_status" => "publish", "numberposts" => -1, "orderby" => "title", "order" => "ASC" ] );
        $form_id = isset( $_GET["form_id"] ) ? absint( $_GET["form_id"] ) : ( $forms[0]->ID ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only form picker.

        $picker = "";
        if ( $forms ) {
            ob_start();
            ?>
            <form method="get" class="tf-res-picker">
                <input type="hidden" name="post_type" value="trinity_forms_form" />
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
                <label for="tf-results-form" class="jnkui-muted"><?php esc_html_e( "Form", "trinity-forms" ); ?></label>
                <select id="tf-results-form" name="form_id" onchange="this.form.submit()">
                    <?php foreach ( $forms as $f ) : ?>
                        <option value="<?php echo (int) $f->ID; ?>" <?php selected( $form_id, $f->ID ); ?>><?php echo esc_html( $f->post_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="button"><?php esc_html_e( "Show", "trinity-forms" ); ?></button></noscript>
            </form>
            <?php
            $picker = (string) ob_get_clean();
        }

        Admin_UI::open(
            __( "Results", "trinity-forms" ),
            __( "Answers added up across every entry. Choice and rating questions turn into charts, like a poll or survey.", "trinity-forms" ),
            $picker,
            [ "class" => "trinity-forms-results" ]
        );

        if ( ! $form_id ) {
            echo Admin_UI::empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
                __( "No published forms yet", "trinity-forms" ),
                __( "Publish a form and collect a few entries. Its results show up here.", "trinity-forms" ),
                '<a class="button" href="' . esc_url( admin_url( Admin::PARENT ) ) . '">' . esc_html__( "Go to your forms", "trinity-forms" ) . "</a>",
                "chart"
            );
            Admin_UI::close();
            return;
        }
        $data = self::aggregate( $form_id );
        $choice_fields  = count( array_filter( $data["fields"], static fn( $f ) => ! in_array( $f["type"], self::NUMERIC_TYPES, true ) ) );
        $numeric_fields = count( $data["fields"] ) - $choice_fields;
        ?>
        <div class="jnkui-grid">
            <div class="jnkui-stat">
                <div class="jnkui-stat-label"><span class="jnkui-dot is-brand"></span><?php esc_html_e( "Completed entries", "trinity-forms" ); ?></div>
                <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( (int) $data["total"] ) ); ?></div>
                <div class="jnkui-stat-foot"><?php esc_html_e( "Spam, trash and unfinished entries are left out", "trinity-forms" ); ?></div>
            </div>
            <div class="jnkui-stat">
                <div class="jnkui-stat-label"><span class="jnkui-dot is-ok"></span><?php esc_html_e( "Questions charted", "trinity-forms" ); ?></div>
                <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( count( $data["fields"] ) ) ); ?></div>
                <div class="jnkui-stat-foot"><?php
                    /* translators: 1: number of choice questions, 2: number of number/rating questions. */
                    echo esc_html( sprintf( __( "%1\$d choice, %2\$d number or rating", "trinity-forms" ), $choice_fields, $numeric_fields ) );
                ?></div>
            </div>
        </div>

        <?php if ( empty( $data["fields"] ) ) : ?>
            <?php echo Admin_UI::empty_state( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
                __( "Nothing to chart yet", "trinity-forms" ),
                __( "Add choice questions (radio, dropdown, checkboxes) or rating, range and number fields, then collect a few entries.", "trinity-forms" ),
                "",
                "chart"
            ); ?>
        <?php else : ?>
            <div class="tf-res-grid">
            <?php foreach ( $data["fields"] as $f ) : ?>
                <div class="jnkui-card">
                    <div class="jnkui-card-head"><div><h2><?php echo esc_html( $f["label"] ?: $f["id"] ); ?></h2>
                        <p><?php
                            /* translators: %d: number of people who answered. */
                            echo esc_html( sprintf( _n( "%d answer", "%d answers", (int) $f["answered"], "trinity-forms" ), (int) $f["answered"] ) );
                        ?></p></div></div>
                    <div class="jnkui-card-body">
                    <?php if ( in_array( $f["type"], self::NUMERIC_TYPES, true ) ) : ?>
                        <div class="tf-res-avg">
                            <b><?php echo esc_html( number_format_i18n( $f["avg"], floor( $f["avg"] ) == $f["avg"] ? 0 : 2 ) ); ?></b>
                            <span class="jnkui-muted"><?php
                                /* translators: 1: lowest value, 2: highest value, 3: count. */
                                printf( esc_html__( "average (range %1\$s to %2\$s, %3\$d responses)", "trinity-forms" ), esc_html( (string) $f["min"] ), esc_html( (string) $f["max"] ), (int) $f["answered"] );
                            ?></span>
                        </div>
                    <?php else :
                        $max = max( $f["counts"] ) ?: 1;
                        foreach ( $f["counts"] as $opt => $n ) :
                            $pct = $f["answered"] > 0 ? round( $n / $f["answered"] * 100 ) : 0; ?>
                            <div class="tf-res-row">
                                <span class="tf-res-label" title="<?php echo esc_attr( (string) $opt ); ?>"><?php echo esc_html( (string) $opt ); ?></span>
                                <span class="jnkui-bar"><i style="width:<?php echo (int) round( $n / $max * 100 ); ?>%"></i></span>
                                <span class="tf-res-num"><b><?php echo (int) $n; ?></b> &middot; <?php echo (int) $pct; ?>%</span>
                            </div>
                        <?php endforeach;
                    endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif;
        Admin_UI::close();
    }
}
