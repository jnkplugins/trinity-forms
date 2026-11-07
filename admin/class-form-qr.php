<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * "QR code" screen for a form (TF-21): pick what the code opens (the form's
 * landing page when Pro has one on, a page the form is on, any other page, or an
 * address typed in), see the code, download it as SVG or PNG.
 *
 * Drawn on the server by QR_Code (no library, no outside service). PNG is made
 * in the browser from the SVG on a canvas, so it needs no GD.
 */
class Form_QR {

    public const PAGE = "trinity-forms-qr";

    public static function register(): void {
        add_action( "admin_menu", [ __CLASS__, "add_page" ], 30 );
        add_filter( "post_row_actions", [ __CLASS__, "row_action" ], 21, 2 );
    }

    public static function url( int $form_id, string $target = "" ): string {
        $args = [ "form_id" => $form_id ];
        if ( "" !== $target ) {
            $args["target"] = $target;
        }
        return add_query_arg( $args, admin_url( Admin::PARENT . "&page=" . self::PAGE ) );
    }

    public static function add_page(): void {
        $hook = add_submenu_page( Admin::PARENT, __( "QR code", "trinity-forms" ), __( "QR code", "trinity-forms" ), Admin::CAP, self::PAGE, [ __CLASS__, "render" ] );
        if ( $hook ) {
            add_action( "load-" . $hook, static function () {
                $GLOBALS["title"] = __( "QR code", "trinity-forms" ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the admin page title.
            } );
        }
        remove_submenu_page( Admin::PARENT, self::PAGE );
    }

    public static function row_action( array $actions, $post ): array {
        if ( $post instanceof \WP_Post && "trinity_forms_form" === $post->post_type && "trash" !== $post->post_status && current_user_can( "edit_post", $post->ID ) ) {
            $actions["trinity_forms_qr"] = '<a href="' . esc_url( self::url( (int) $post->ID ) ) . '">' . esc_html__( "QR code", "trinity-forms" ) . "</a>";
        }
        return $actions;
    }

    /**
     * Places a QR code for this form can open: key => [ label, url ].
     * Pro adds the landing page through trinity_forms_qr_targets.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function targets( int $form_id ): array {
        $targets = [];
        foreach ( self::pages_with_form( $form_id ) as $page ) {
            $link = get_permalink( $page );
            if ( $link ) {
                /* translators: %s: page title. */
                $targets[ "page-" . $page->ID ] = [ sprintf( __( "Page with this form: %s", "trinity-forms" ), get_the_title( $page ) ?: "#" . $page->ID ), $link ];
            }
        }
        return (array) apply_filters( "trinity_forms_qr_targets", $targets, $form_id );
    }

    /** Published posts and pages that embed the form (shortcode, block, Elementor widget, Beaver Builder module). @return \WP_Post[] */
    public static function pages_with_form( int $form_id ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one admin-screen lookup.
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','trinity_forms_form','nav_menu_item')
             AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_modified DESC LIMIT 20",
            "%" . $wpdb->esc_like( '[trinity-forms id="' . $form_id . '"' ) . "%",
            "%" . $wpdb->esc_like( "[trinity-forms id=" . $form_id ) . "%",
            "%" . $wpdb->esc_like( '"formId":' . $form_id ) . "%"
        ) );
        // Pages built with Elementor or Beaver Builder keep the layout in post meta.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one admin-screen lookup.
        $built = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key IN ('_elementor_data','_fl_builder_data') AND p.post_status = 'publish' AND m.meta_value LIKE %s LIMIT 50",
            "%" . $wpdb->esc_like( "trinity-forms" ) . "%"
        ) );
        $built_ids = [];
        foreach ( (array) $built as $row ) {
            $v = (string) $row->meta_value;
            if ( preg_match( '/"form_id":"?' . $form_id . '"?[,}]/', $v ) || preg_match( '/s:7:"form_id";(s:\d+:"' . $form_id . '"|i:' . $form_id . ');/', $v ) ) {
                $built_ids[] = (int) $row->post_id;
                $ids[]       = (int) $row->post_id;
            }
        }
        $ids = array_unique( array_map( "intval", $ids ) );

        $out = [];
        foreach ( $ids as $id ) {
            $built_ok = in_array( $id, $built_ids, true );
            $post = get_post( (int) $id );
            // "formId":1 also matches "formId":12; check the exact id.
            if ( $post && ( $built_ok || preg_match( '/(\[trinity-forms id="?' . $form_id . '"?[\s\]])|("formId":' . $form_id . '[,}])/', (string) $post->post_content ) ) && is_post_type_viewable( $post->post_type ) ) {
                $out[] = $post;
            }
        }
        return $out;
    }

    /** The address to encode for this request, "" when none is chosen. */
    public static function chosen_url( int $form_id, string $target, int $page_id, string $custom ): string {
        if ( "page" === $target && $page_id ) {
            $page = get_post( $page_id );
            return $page && "publish" === $page->post_status ? (string) get_permalink( $page ) : "";
        }
        if ( "custom" === $target ) {
            $url = esc_url_raw( trim( $custom ), [ "http", "https" ] );
            return wp_http_validate_url( $url ) || 0 === strpos( $url, home_url() ) ? $url : "";
        }
        $targets = self::targets( $form_id );
        if ( isset( $targets[ $target ] ) ) {
            return (string) $targets[ $target ][1];
        }
        $first = reset( $targets );
        return $first ? (string) $first[1] : "";
    }

    public static function with_campaign( string $url ): string {
        return add_query_arg( [ "utm_source" => "qr", "utm_medium" => "qr" ], $url );
    }

    public static function render(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen driven by GET choices.
        $form_id = absint( wp_unslash( $_GET["form_id"] ?? 0 ) );
        $post    = $form_id ? get_post( $form_id ) : null;
        if ( ! $post || "trinity_forms_form" !== $post->post_type || ! current_user_can( "edit_post", $form_id ) ) {
            Admin_UI::open( __( "QR code", "trinity-forms" ) );
            echo Admin_UI::empty_state( __( "Pick a form", "trinity-forms" ), __( "Open the QR code from a form's row in the forms list.", "trinity-forms" ), "", "form" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            Admin_UI::close();
            return;
        }
        $targets = self::targets( $form_id );
        $target  = sanitize_key( wp_unslash( $_GET["target"] ?? "" ) );
        if ( "" === $target ) {
            $target = $targets ? (string) array_key_first( $targets ) : "page";
        }
        $page_id = absint( wp_unslash( $_GET["page_id"] ?? 0 ) );
        $custom  = (string) wp_unslash( $_GET["custom"] ?? "" );
        $utm     = ! empty( $_GET["utm"] );
        // phpcs:enable

        $url = self::chosen_url( $form_id, $target, $page_id, $custom );
        if ( "" !== $url && $utm ) {
            $url = self::with_campaign( $url );
        }

        $title = "" !== $post->post_title ? $post->post_title : __( "(no title)", "trinity-forms" );
        /* translators: %s: form title. */
        Admin_UI::open( sprintf( __( "QR code: %s", "trinity-forms" ), $title ), __( "Print it on a flyer, a poster or a table card: scanning it opens the form.", "trinity-forms" ) );
        ?>
        <div class="jnkui-card tf-qr">
            <div class="jnkui-card-body">
                <form method="get" action="<?php echo esc_url( admin_url( "edit.php" ) ); ?>" class="tf-qr-form">
                    <input type="hidden" name="post_type" value="trinity_forms_form">
                    <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                    <input type="hidden" name="form_id" value="<?php echo (int) $form_id; ?>">
                    <fieldset>
                        <legend><?php esc_html_e( "Scanning opens", "trinity-forms" ); ?></legend>
                        <?php foreach ( $targets as $key => [ $label, $link ] ) : ?>
                            <label class="tf-qr-choice"><input type="radio" name="target" value="<?php echo esc_attr( $key ); ?>" <?php checked( $target, $key ); ?>> <?php echo esc_html( $label ); ?> <code dir="ltr"><?php echo esc_html( $link ); ?></code></label>
                        <?php endforeach; ?>
                        <label class="tf-qr-choice"><input type="radio" name="target" value="page" <?php checked( $target, "page" ); ?>> <?php esc_html_e( "Another page:", "trinity-forms" ); ?>
                            <?php
                            wp_dropdown_pages( [
                                "name"              => "page_id",
                                "selected"          => $page_id, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- int.
                                "show_option_none"  => esc_html__( "Choose a page", "trinity-forms" ),
                                "option_none_value" => 0,
                            ] );
                            ?>
                        </label>
                        <label class="tf-qr-choice"><input type="radio" name="target" value="custom" <?php checked( $target, "custom" ); ?>> <?php esc_html_e( "This address:", "trinity-forms" ); ?>
                            <input type="url" name="custom" dir="ltr" class="regular-text" value="<?php echo esc_attr( $custom ); ?>" placeholder="https://">
                        </label>
                    </fieldset>
                    <p><label><input type="checkbox" name="utm" value="1" <?php checked( $utm ); ?>> <?php esc_html_e( "Mark scans as coming from the QR code (adds utm_source=qr, shown on entries when the form records where visitors came from)", "trinity-forms" ); ?></label></p>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( "Show QR code", "trinity-forms" ); ?></button></p>
                </form>

                <?php if ( ! $targets && "page" !== $target && "custom" !== $target ) : ?>
                    <p><?php esc_html_e( "This form is not on any published page yet. Choose a page or type an address.", "trinity-forms" ); ?></p>
                <?php endif; ?>

                <?php
                if ( "" === $url ) {
                    if ( "custom" === $target && "" !== $custom ) {
                        echo '<div class="jnkui-callout is-warn"><p>' . esc_html__( "That is not a web address a phone can open. Start it with https://", "trinity-forms" ) . "</p></div>";
                    }
                } else {
                    $svg = QR_Code::svg( $url, 280, $title );
                    if ( "" === $svg ) {
                        /* translators: %d: number of characters. */
                        echo '<div class="jnkui-callout is-warn"><p>' . esc_html( sprintf( __( "That address is too long for a QR code (up to %d characters).", "trinity-forms" ), QR_Code::max_bytes() ) ) . "</p></div>";
                    } else {
                        $file = sanitize_file_name( "qr-" . ( $post->post_name ?: "form-" . $form_id ) );
                        ?>
                        <div class="tf-qr-result">
                            <div class="tf-qr-image" id="tf-qr-image"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from numbers and esc_html'd title. ?></div>
                            <div>
                                <p><?php esc_html_e( "Opens:", "trinity-forms" ); ?> <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( $url ); ?></a></p>
                                <p class="tf-qr-actions">
                                    <a class="button" download="<?php echo esc_attr( $file ); ?>.svg" href="data:image/svg+xml;charset=utf-8,<?php echo rawurlencode( $svg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rawurlencoded. ?>"><?php esc_html_e( "Download SVG", "trinity-forms" ); ?></a>
                                    <button type="button" class="button" id="tf-qr-png" data-file="<?php echo esc_attr( $file ); ?>.png"><?php esc_html_e( "Download PNG", "trinity-forms" ); ?></button>
                                </p>
                                <p class="description"><?php esc_html_e( "SVG stays sharp at any size, for print. PNG is 1024 pixels, for documents and social posts. Test-scan it with a phone before printing.", "trinity-forms" ); ?></p>
                            </div>
                        </div>
                        <script>
                        (function(){
                            var b=document.getElementById("tf-qr-png"),box=document.getElementById("tf-qr-image");
                            if(!b||!box)return;
                            b.addEventListener("click",function(){
                                var svg=box.querySelector("svg"),img=new Image(),c=document.createElement("canvas");
                                c.width=c.height=1024;
                                img.onload=function(){var x=c.getContext("2d");x.imageSmoothingEnabled=false;x.drawImage(img,0,0,1024,1024);
                                    var a=document.createElement("a");a.download=b.getAttribute("data-file");a.href=c.toDataURL("image/png");document.body.appendChild(a);a.click();a.remove();};
                                img.src="data:image/svg+xml;charset=utf-8,"+encodeURIComponent(new XMLSerializer().serializeToString(svg));
                            });
                        })();
                        </script>
                        <?php
                    }
                }
                ?>
            </div>
        </div>
        <style>
            .tf-qr fieldset{border:0;margin:0 0 8px;padding:0}
            .tf-qr legend{font-weight:600;margin-block-end:6px}
            .tf-qr-choice{display:block;margin-block:6px;overflow-wrap:anywhere}
            .tf-qr-result{display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start;margin-block-start:16px}
            .tf-qr-image{background:#fff;padding:8px;border-radius:8px;line-height:0;max-inline-size:100%}
            .tf-qr-image svg{max-inline-size:100%;height:auto}
            .tf-qr-result > div:last-child{flex:1 1 260px;min-inline-size:0;overflow-wrap:anywhere}
        </style>
        <?php
        Admin_UI::close();
    }
}
