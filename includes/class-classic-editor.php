<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * "Add Trinity form" next to Add Media in every classic editor (the Classic Editor
 * plugin, WooCommerce product descriptions, SiteOrigin's Editor widget, any
 * wp_editor() with media buttons). It opens a small picker and inserts the shortcode.
 */
class Classic_Editor {

    private static bool $printed = false;

    public static function register(): void {
        add_action( "media_buttons", [ self::class, "button" ], 15 );
        // Editors that arrive later over AJAX (Page Builder dialogs, widget forms)
        // need the picker on the page even when no editor was on it at load.
        add_action( "admin_footer", [ self::class, "picker" ] );
    }

    /** Tests only: print the picker again. */
    public static function reset(): void {
        self::$printed = false;
    }

    /** @return array<int,string> published form id => title */
    public static function forms(): array {
        $posts = get_posts( [
            "post_type"     => "trinity_forms_form",
            "post_status"   => "publish",
            "numberposts"   => 500,
            "orderby"       => "title",
            "order"         => "ASC",
            "no_found_rows" => true,
        ] );
        $out = [];
        foreach ( $posts as $post ) {
            /* translators: %d: form ID. */
            $out[ (int) $post->ID ] = "" !== $post->post_title ? $post->post_title : sprintf( __( "(untitled form #%d)", "trinity-forms" ), (int) $post->ID );
        }
        return $out;
    }

    public static function button( $editor_id = "content" ): void {
        // Admin only: the picker that goes with it is printed in the admin footer.
        if ( ! is_admin() || ! current_user_can( "edit_posts" ) || self::on_form_screen() ) {
            return;
        }
        printf(
            '<button type="button" class="button tf-add-form" data-editor="%1$s" aria-haspopup="dialog"><span class="dashicons dashicons-feedback" style="vertical-align:text-top;margin-inline-end:2px" aria-hidden="true"></span>%2$s</button>',
            esc_attr( (string) $editor_id ),
            esc_html__( "Add Trinity form", "trinity-forms" )
        );
    }

    /** The form editor itself: a form inside a form makes no sense. */
    private static function on_form_screen(): bool {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        return $screen && "trinity_forms_form" === $screen->post_type;
    }

    public static function picker(): void {
        if ( self::$printed || ! current_user_can( "edit_posts" ) || self::on_form_screen() ) {
            return;
        }
        // Only where a classic editor can appear: post screens, widgets, and the
        // pages some builders put their editor on.
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( ! did_action( "media_buttons" ) && ( ! $screen || ! in_array( $screen->base, [ "post", "widgets", "customize" ], true ) ) ) {
            return;
        }
        self::$printed = true;
        $forms = self::forms();
        ?>
        <dialog id="tf-add-form-dialog" aria-labelledby="tf-add-form-title" style="max-width:420px;width:90%;padding:20px;border:1px solid #c3c4c7;border-radius:6px">
            <form method="dialog">
                <h2 id="tf-add-form-title" style="margin-top:0"><?php esc_html_e( "Insert a form", "trinity-forms" ); ?></h2>
                <?php if ( $forms ) : ?>
                    <p>
                        <label for="tf-add-form-select"><?php esc_html_e( "Form", "trinity-forms" ); ?></label><br />
                        <select id="tf-add-form-select" style="width:100%">
                            <?php foreach ( $forms as $id => $title ) : ?>
                                <option value="<?php echo (int) $id; ?>"><?php echo esc_html( $title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="description"><?php esc_html_e( "This adds the form's shortcode where your cursor is.", "trinity-forms" ); ?></p>
                    <p style="text-align:end;margin-bottom:0">
                        <button value="cancel" class="button"><?php esc_html_e( "Cancel", "trinity-forms" ); ?></button>
                        <button value="insert" class="button button-primary tf-add-form-insert"><?php esc_html_e( "Insert form", "trinity-forms" ); ?></button>
                    </p>
                <?php else : ?>
                    <p><?php esc_html_e( "You have no published forms yet.", "trinity-forms" ); ?></p>
                    <p style="text-align:end;margin-bottom:0">
                        <button value="cancel" class="button"><?php esc_html_e( "Cancel", "trinity-forms" ); ?></button>
                        <a class="button button-primary" target="_blank" href="<?php echo esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=" . Templates::PAGE ) ); ?>"><?php esc_html_e( "Create a form", "trinity-forms" ); ?></a>
                    </p>
                <?php endif; ?>
            </form>
        </dialog>
        <script>
        ( function () {
            var dlg = document.getElementById( "tf-add-form-dialog" ), target = "content";
            if ( ! dlg ) return;
            // Resolve the editor when clicked, not from our own data-editor: some
            // builders (SiteOrigin's Editor widget) rename the textarea after the
            // buttons were printed and only update Add Media's data-editor.
            function editorFor( b ) {
                var own = b.getAttribute( "data-editor" ) || "", box = b.parentNode, i, id, wrap, ta;
                if ( box && box.querySelectorAll ) {
                    var sibs = box.querySelectorAll( "[data-editor]" );
                    for ( i = 0; i < sibs.length; i++ ) {
                        id = sibs[ i ].getAttribute( "data-editor" );
                        if ( sibs[ i ] !== b && id && document.getElementById( id ) ) return id;
                    }
                }
                wrap = b.closest( ".wp-editor-wrap" );
                ta = wrap && wrap.querySelector( "textarea.wp-editor-area" );
                if ( ta && ta.id ) return ta.id;
                if ( own && document.getElementById( own ) ) return own;
                return window.wpActiveEditor || own || "content";
            }
            document.addEventListener( "click", function ( e ) {
                var b = e.target.closest && e.target.closest( ".tf-add-form" );
                if ( ! b ) return;
                e.preventDefault();
                target = editorFor( b );
                if ( typeof dlg.showModal === "function" ) dlg.showModal(); else dlg.setAttribute( "open", "" );
            } );
            dlg.addEventListener( "close", function () {
                if ( dlg.returnValue !== "insert" ) return;
                var sel = document.getElementById( "tf-add-form-select" );
                if ( ! sel ) return;
                var code = '[trinity-forms id="' + parseInt( sel.value, 10 ) + '"]';
                var ed = window.tinymce && window.tinymce.get( target );
                if ( ed && ! ed.isHidden() ) {
                    ed.focus(); ed.execCommand( "mceInsertContent", false, code );
                    return;
                }
                var ta = document.getElementById( target );
                if ( ! ta ) { if ( window.send_to_editor ) { window.wpActiveEditor = target; window.send_to_editor( code ); } return; }
                var s = ta.selectionStart || 0, en = ta.selectionEnd || s;
                ta.value = ta.value.slice( 0, s ) + code + ta.value.slice( en );
                ta.selectionStart = ta.selectionEnd = s + code.length;
                ta.dispatchEvent( new Event( "input", { bubbles: true } ) );
                ta.dispatchEvent( new Event( "change", { bubbles: true } ) );
                ta.focus();
            } );
        } )();
        </script>
        <?php
    }
}
