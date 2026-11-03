<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Entry export: which columns, in what order, and the Export panel (TF-17).
 *
 * The choice is remembered per user and per form, so the next export of the
 * same form comes out the same way, from the panel or from "Export selected".
 */
class Export_Columns {

    private const META = "trinity_forms_export_cols";

    /**
     * Every column an export of this form can have: key => label.
     *
     * @return array<string,string>
     */
    public static function options( int $form_id ): array {
        $out = Entries::base_headers();
        if ( $form_id <= 0 ) {
            return $out;
        }
        $out += Entries::field_columns( $form_id );
        $form = Form_Manager::get( $form_id );
        if ( $form && Campaign::enabled( $form ) ) {
            foreach ( Campaign::KEYS as $meta_id ) {
                $out[ $meta_id ] = Campaign::label( $meta_id );
            }
        }
        // Only while some entry of this form still holds one; never ticked by default.
        if ( Ip_Privacy::count_stored( $form_id ) > 0 ) {
            $out[ Ip_Privacy::COLUMN ] = __( "IP address", "trinity-forms" );
        }
        return $out;
    }

    /** @return array<int,string>|null The current user's last choice for this form. */
    public static function saved( int $form_id ): ?array {
        if ( $form_id <= 0 ) {
            return null;
        }
        $all  = get_user_meta( get_current_user_id(), self::META, true );
        $list = is_array( $all ) && isset( $all[ $form_id ] ) && is_array( $all[ $form_id ] ) ? $all[ $form_id ] : [];
        return $list ? array_values( array_map( "strval", $list ) ) : null;
    }

    /**
     * The columns asked for in an export request (only keys this form has), saved
     * as the user's choice for the form. Nothing asked for: their saved choice, or
     * every column.
     *
     * @param array<int,mixed> $cols
     * @return array<int,string>|null
     */
    public static function from_request( array $cols, int $form_id ): ?array {
        $valid = self::options( $form_id );
        $list  = [];
        foreach ( $cols as $c ) {
            $c = sanitize_text_field( (string) $c );
            if ( "" !== $c && ( $form_id <= 0 || isset( $valid[ $c ] ) ) && ! in_array( $c, $list, true ) ) {
                $list[] = $c;
            }
        }
        if ( ! $list ) {
            return self::saved( $form_id );
        }
        if ( $form_id > 0 && get_current_user_id() ) {
            $all = get_user_meta( get_current_user_id(), self::META, true );
            $all = is_array( $all ) ? $all : [];
            // Keep the meta small: a handful of forms, most recent last.
            unset( $all[ $form_id ] );
            $all[ $form_id ] = $list;
            update_user_meta( get_current_user_id(), self::META, array_slice( $all, -20, null, true ) );
        }
        return $list;
    }

    /**
     * The Export panel on the entries screen: format, and for one form, which
     * columns in which order. A plain GET form, so it works without JavaScript;
     * the inline script only adds the move up / move down buttons.
     *
     * @param array<string,mixed> $filters form_id, view, s, from, to (the screen's filters)
     */
    public static function panel( array $filters ): string {
        $form_id = (int) ( $filters["form_id"] ?? 0 );
        $options = self::options( $form_id );
        $saved   = self::saved( $form_id );
        $order   = $saved ? array_values( array_unique( array_merge( array_intersect( $saved, array_keys( $options ) ), array_keys( $options ) ) ) ) : array_keys( $options );
        $zip     = class_exists( "\\ZipArchive" );

        ob_start();
        ?>
        <details class="tf-export">
            <summary class="button"><?php esc_html_e( "Export", "trinity-forms" ); ?></summary>
            <form method="get" action="<?php echo esc_url( rest_url( "trinity-forms/v1/submissions/export" ) ); ?>" class="tf-export-panel">
                <?php foreach ( [ "form_id", "view", "s", "from", "to" ] as $key ) : ?>
                    <?php if ( ! empty( $filters[ $key ] ) && ( "view" !== $key || "all" !== $filters[ $key ] ) ) : ?>
                        <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $filters[ $key ] ); ?>" />
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( "wp_rest" ) ); ?>" />
                <fieldset>
                    <legend><?php esc_html_e( "Format", "trinity-forms" ); ?></legend>
                    <label><input type="radio" name="format" value="csv" checked /> <?php esc_html_e( "CSV", "trinity-forms" ); ?></label>
                    <label><input type="radio" name="format" value="xlsx" <?php disabled( ! $zip ); ?> /> <?php esc_html_e( "Excel (.xlsx)", "trinity-forms" ); ?></label>
                    <?php if ( ! $zip ) : ?>
                        <p class="description"><?php esc_html_e( "Excel files need the PHP zip extension, which this server does not have.", "trinity-forms" ); ?></p>
                    <?php endif; ?>
                </fieldset>
                <?php if ( $form_id > 0 ) : ?>
                    <fieldset>
                        <legend><?php esc_html_e( "Columns, in this order", "trinity-forms" ); ?></legend>
                        <ol class="tf-export-cols">
                            <?php foreach ( $order as $key ) : ?>
                                <li><label><input type="checkbox" name="cols[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $saved ? in_array( $key, $saved, true ) : Ip_Privacy::COLUMN !== $key ); ?> /> <?php echo esc_html( $options[ $key ] ); ?></label></li>
                            <?php endforeach; ?>
                        </ol>
                        <p class="description"><?php esc_html_e( "Your choice is remembered for this form, and used by Export selected too.", "trinity-forms" ); ?></p>
                    </fieldset>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( "Every column is exported. Filter the list to one form to choose and order its columns.", "trinity-forms" ); ?></p>
                <?php endif; ?>
                <button type="submit" class="button button-primary"><?php esc_html_e( "Download", "trinity-forms" ); ?></button>
            </form>
        </details>
        <script>
        ( function () {
            var list = document.querySelector( ".tf-export-cols" );
            if ( ! list ) return;
            var up = <?php echo wp_json_encode( __( "Move up", "trinity-forms" ) ); ?>, down = <?php echo wp_json_encode( __( "Move down", "trinity-forms" ) ); ?>;
            list.querySelectorAll( "li" ).forEach( function ( li ) {
                var name = li.textContent.trim();
                [ [ "↑", up, -1 ], [ "↓", down, 1 ] ].forEach( function ( d ) {
                    var b = document.createElement( "button" );
                    b.type = "button";
                    b.className = "button-link tf-export-move";
                    b.textContent = d[0];
                    b.setAttribute( "aria-label", d[1] + ": " + name );
                    b.addEventListener( "click", function () {
                        var sib = d[2] < 0 ? li.previousElementSibling : li.nextElementSibling;
                        if ( ! sib ) return;
                        list.insertBefore( li, d[2] < 0 ? sib : sib.nextElementSibling );
                        b.focus();
                    } );
                    li.appendChild( b );
                } );
            } );
        } )();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
