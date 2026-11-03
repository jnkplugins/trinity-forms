<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- entry meta is a custom table.

/**
 * Campaign source capture (TF-31, free): where a visitor came from.
 *
 * Off by default, per form ("Record where visitors came from" on the General
 * tab). When on, public.js keeps the first page of the visit that showed this
 * form, its utm_* parameters and the outside referrer in sessionStorage (only on
 * pages that have such a form; nothing is stored anywhere else and no cookie is
 * set), and sends them with the entry in a hidden field. Done in the browser, so
 * it works on cached pages.
 *
 * Stored as internal entry meta (`_utm_source`, ..., `_utm_landing`), shown on the
 * entry, added as CSV columns and available as merge tags ({utm_source},
 * {landing_page}, {first_referrer} ...).
 */
class Campaign {

    /** Posted by public.js. */
    public const INPUT = "_trinity_forms_utm";

    /** What is kept: payload key => entry meta field id. */
    public const KEYS = [
        "utm_source"   => "_utm_source",
        "utm_medium"   => "_utm_medium",
        "utm_campaign" => "_utm_campaign",
        "utm_term"     => "_utm_term",
        "utm_content"  => "_utm_content",
        "referrer"     => "_utm_referrer",
        "landing"      => "_utm_landing",
    ];

    public static function register(): void {
        add_filter( "trinity_forms_form_data_attributes", [ self::class, "form_attribute" ], 10, 2 );
        // Before notifications (priority 10 listeners and send_notifications), so the
        // merge tags in the first email are already filled.
        add_action( "trinity_forms_entry_created", [ self::class, "store" ], 1, 2 );
        add_action( "trinity_forms_form_editor_general_rows", [ self::class, "render_row" ], 5, 2 );
    }

    public static function enabled( Form $form ): bool {
        return (bool) $form->get_setting( "capture_utm", false );
    }

    public static function form_attribute( string $attrs, $form ): string {
        return $form instanceof Form && self::enabled( $form ) ? $attrs . ' data-utm="1"' : $attrs;
    }

    /** A person-readable name for one value. */
    public static function label( string $key ): string {
        $key = ltrim( (string) preg_replace( '/^_utm_/', "", $key ), "_" );
        switch ( $key ) {
            case "referrer":
                return __( "First referrer", "trinity-forms" );
            case "landing":
                return __( "Landing page", "trinity-forms" );
            default:
                return "utm_" . preg_replace( '/^utm_/', "", $key );
        }
    }

    public static function is_campaign_field( string $field_id ): bool {
        return in_array( $field_id, self::KEYS, true );
    }

    /**
     * The posted JSON, cleaned: only the known keys, short text, URLs as URLs.
     *
     * @return array<string,string> payload key => value
     */
    public static function clean( string $json ): array {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return [];
        }
        $out = [];
        foreach ( array_keys( self::KEYS ) as $key ) {
            $v = $data[ $key ] ?? "";
            if ( ! is_scalar( $v ) || "" === trim( (string) $v ) ) {
                continue;
            }
            $v = in_array( $key, [ "referrer", "landing" ], true )
                ? esc_url_raw( substr( (string) $v, 0, 500 ) )
                : sanitize_text_field( mb_substr( (string) $v, 0, 200 ) );
            if ( "" !== $v ) {
                $out[ $key ] = $v;
            }
        }
        return $out;
    }

    public static function store( int $entry_id, int $form_id ): void {
        $form = Form_Manager::get( $form_id );
        if ( ! $form || ! self::enabled( $form ) ) {
            return;
        }
        $raw = Submission_Pipeline::current_value( self::INPUT );
        if ( "" === $raw && isset( $_POST[ self::INPUT ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the submission's nonce was verified before the pipeline ran.
            $raw = sanitize_text_field( wp_unslash( (string) $_POST[ self::INPUT ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        $values = self::clean( $raw );
        if ( ! $values ) {
            return;
        }
        global $wpdb;
        foreach ( $values as $key => $value ) {
            $wpdb->insert( Entries::meta_table(), [
                "entry_id"    => $entry_id,
                "field_id"    => self::KEYS[ $key ],
                "field_label" => self::label( $key ),
                "field_type"  => "internal",
                "value"       => $value,
            ], [ "%d", "%s", "%s", "%s", "%s" ] );
        }
    }

    /**
     * The campaign values of one entry, in KEYS order.
     *
     * @return array<string,string> meta field id => value
     */
    public static function for_entry( int $entry_id ): array {
        global $wpdb;
        $ids   = array_values( self::KEYS );
        $holds = implode( ",", array_fill( 0, count( $ids ), "%s" ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, value FROM " . Entries::meta_table() . " WHERE entry_id = %d AND field_id IN ({$holds})",
            $entry_id, ...$ids
        ), ARRAY_A );
        $found = [];
        foreach ( (array) $rows as $r ) {
            $found[ (string) $r["field_id"] ] = (string) $r["value"];
        }
        $out = [];
        foreach ( $ids as $id ) {
            if ( isset( $found[ $id ] ) && "" !== $found[ $id ] ) {
                $out[ $id ] = $found[ $id ];
            }
        }
        return $out;
    }

    /**
     * Merge tags filled from an entry's stored fields (Merge_Tags::load_entry()).
     *
     * @param array<string,array{value:mixed}> $fields
     * @return array<string,string>
     */
    public static function tags( array $fields ): array {
        $get = static fn( string $id ): string => isset( $fields[ $id ] ) && is_scalar( $fields[ $id ]["value"] ?? null ) ? (string) $fields[ $id ]["value"] : "";
        return [
            "{utm_source}"     => esc_html( $get( "_utm_source" ) ),
            "{utm_medium}"     => esc_html( $get( "_utm_medium" ) ),
            "{utm_campaign}"   => esc_html( $get( "_utm_campaign" ) ),
            "{utm_term}"       => esc_html( $get( "_utm_term" ) ),
            "{utm_content}"    => esc_html( $get( "_utm_content" ) ),
            "{first_referrer}" => esc_url( $get( "_utm_referrer" ) ),
            "{landing_page}"   => esc_url( $get( "_utm_landing" ) ),
        ];
    }

    /** For the merge tag picker. @return list<array{tag:string,label:string}> */
    public static function picker_tags(): array {
        return [
            [ "tag" => "{utm_source}", "label" => __( "Campaign source (utm_source)", "trinity-forms" ) ],
            [ "tag" => "{utm_medium}", "label" => __( "Campaign medium (utm_medium)", "trinity-forms" ) ],
            [ "tag" => "{utm_campaign}", "label" => __( "Campaign name (utm_campaign)", "trinity-forms" ) ],
            [ "tag" => "{landing_page}", "label" => __( "First page of the visit", "trinity-forms" ) ],
            [ "tag" => "{first_referrer}", "label" => __( "Site the visitor came from", "trinity-forms" ) ],
        ];
    }

    /** General tab: the switch. */
    public static function render_row( $form, $settings ): void {
        $on = ! empty( ( (array) $settings )["capture_utm"] );
        ?>
        <tr><th><?php esc_html_e( "Campaign source", "trinity-forms" ); ?></th>
            <td><label>
                <input type="hidden" name="trinity-forms[settings][capture_utm]" value="0" />
                <input type="checkbox" name="trinity-forms[settings][capture_utm]" value="1" <?php checked( $on ); ?> />
                <?php esc_html_e( "Record where visitors came from (utm_ tags, the site that sent them, the first page they saw)", "trinity-forms" ); ?>
            </label>
            <p class="description"><?php esc_html_e( "Shown on each entry, added to the CSV export and usable in emails as {utm_source}, {utm_medium}, {utm_campaign}, {landing_page} and {first_referrer}. Kept in the visitor's browser only for the visit, on pages with this form. Works on cached pages.", "trinity-forms" ); ?></p></td></tr>
        <?php
    }
}
