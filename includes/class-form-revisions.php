<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Form revisions with restore (TF-18).
 *
 * Every save of a form by a signed-in person (block editor, Visual Builder, form
 * settings, REST, an AI assistant) keeps a copy of the form: title, field markup
 * and the settings meta. The last N are kept (filter trinity_forms_revisions_keep,
 * default 20). Each one records who and when and a short summary of what changed
 * against the one before (fields added / removed / changed, title, settings).
 *
 * Saves are collected per request and written once at shutdown, so the block
 * editor's two requests (content, then the settings box) and the builder's
 * content + settings writes land as one revision. A second save by the same person
 * within two minutes replaces their previous revision instead of piling up.
 *
 * The first change to a form that has no revisions yet keeps the state it had
 * before ("Before revisions were kept"), so the very first edit can be undone too.
 * Restoring writes that copy back and is itself a revision.
 *
 * Storage: an index in post meta INDEX (newest first) and one meta row per copy
 * (REV_PREFIX . id). No custom table.
 */
class Form_Revisions {

    public const INDEX      = "_trinity_forms_revisions";
    public const REV_PREFIX = "_trinity_forms_rev_";
    public const PAGE       = "trinity-forms-revisions";
    private const MERGE_SECONDS = 120;

    /** @var array<int,bool> forms changed in this request */
    private static array $dirty = [];
    /** @var array<int,bool> forms whose baseline was checked in this request */
    private static array $checked = [];
    /** "", "assistant" or "restore": who is saving, when not a plain person. */
    public static string $source = "";
    private static string $restored_from = "";

    public static function register(): void {
        add_action( "pre_post_update", [ __CLASS__, "before_post_update" ], 10, 1 );
        add_action( "save_post_trinity_forms_form", [ __CLASS__, "mark_post" ], 10, 1 );
        add_filter( "update_post_metadata", [ __CLASS__, "before_meta" ], 10, 3 );
        add_filter( "add_post_metadata", [ __CLASS__, "before_meta" ], 10, 3 );
        add_filter( "delete_post_metadata", [ __CLASS__, "before_meta" ], 10, 3 );
        add_action( "shutdown", [ __CLASS__, "flush" ] );

        if ( is_admin() ) {
            add_action( "admin_menu", [ __CLASS__, "add_page" ], 30 );
            add_action( "admin_post_trinity_forms_restore_revision", [ __CLASS__, "handle_restore" ] );
            add_action( "add_meta_boxes_trinity_forms_form", [ __CLASS__, "add_meta_box" ] );
            add_filter( "post_row_actions", [ __CLASS__, "row_action" ], 20, 2 );
        }
    }

    /* ---------------------------------------------------------------- capture */

    private static function is_form( int $post_id ): bool {
        $post = get_post( $post_id );
        return $post && "trinity_forms_form" === $post->post_type && ! in_array( $post->post_status, [ "auto-draft", "trash" ], true );
    }

    public static function before_post_update( $post_id ): void {
        $post_id = (int) $post_id;
        if ( self::is_form( $post_id ) ) {
            self::ensure_baseline( $post_id );
        }
    }

    public static function mark_post( $post_id ): void {
        $post_id = (int) $post_id;
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! self::is_form( $post_id ) ) {
            return;
        }
        self::$dirty[ $post_id ] = true;
    }

    /** Filter callback: never short-circuits, only notes the coming settings write. */
    public static function before_meta( $check, $object_id, $meta_key ) {
        if ( Form::SETTINGS_META === $meta_key && self::is_form( (int) $object_id ) ) {
            self::ensure_baseline( (int) $object_id );
            self::$dirty[ (int) $object_id ] = true;
        }
        return $check;
    }

    private static function ensure_baseline( int $form_id ): void {
        if ( isset( self::$checked[ $form_id ] ) ) {
            return;
        }
        self::$checked[ $form_id ] = true;
        if ( self::index( $form_id ) ) {
            return;
        }
        $snap = self::snapshot( $form_id );
        $post = get_post( $form_id );
        self::push( $form_id, $snap, [
            "user"   => 0,
            "source" => "baseline",
            "time"   => $post && "0000-00-00 00:00:00" !== $post->post_modified_gmt ? $post->post_modified_gmt : Dates::now(),
        ] );
    }

    /** Write one revision per changed form. Runs at shutdown; tests call it directly. */
    public static function flush(): void {
        $forms       = array_keys( self::$dirty );
        self::$dirty = [];
        foreach ( $forms as $form_id ) {
            if ( ! self::is_form( $form_id ) ) {
                continue;
            }
            $user = get_current_user_id();
            if ( ! $user && "" === self::$source ) {
                continue; // Not made by a person (cron, a visitor-side write).
            }
            self::record( $form_id, $user );
        }
        self::$checked = [];
    }

    private static function record( int $form_id, int $user ): void {
        $snap  = self::snapshot( $form_id );
        $index = self::index( $form_id );
        $hash  = self::hash( $snap );
        if ( $index && $index[0]["hash"] === $hash ) {
            return; // Saved without a change.
        }
        $source = self::$source ?: "person";
        $now    = Dates::now();

        // The same person saving again a moment later: one revision, not two.
        if ( $index && "baseline" !== $index[0]["source"] && "restore" !== $source
            && (int) $index[0]["user"] === $user && $index[0]["source"] === $source
            && abs( strtotime( $now . " UTC" ) - strtotime( $index[0]["time"] . " UTC" ) ) <= self::MERGE_SECONDS ) {
            $prev                = isset( $index[1] ) ? self::get_copy( $form_id, (string) $index[1]["id"] ) : null;
            $index[0]["hash"]    = $hash;
            $index[0]["time"]    = $now;
            $index[0]["summary"] = self::summary( $prev, $snap );
            update_post_meta( $form_id, self::REV_PREFIX . $index[0]["id"], wp_slash( $snap ) );
            update_post_meta( $form_id, self::INDEX, $index );
            return;
        }

        $extra = [ "user" => $user, "source" => $source, "time" => $now ];
        if ( "restore" === $source && "" !== self::$restored_from ) {
            $extra["restored_from"] = self::$restored_from;
        }
        self::push( $form_id, $snap, $extra );
    }

    /** @param array<string,mixed> $meta user, source, time (+ restored_from) */
    private static function push( int $form_id, array $snap, array $meta ): void {
        $index = self::index( $form_id );
        $prev  = $index ? self::get_copy( $form_id, (string) $index[0]["id"] ) : null;
        $id    = strtolower( wp_generate_password( 10, false ) );
        $row   = array_merge( [
            "id"      => $id,
            "hash"    => self::hash( $snap ),
            "summary" => "baseline" === $meta["source"] ? null : self::summary( $prev, $snap ),
        ], $meta );
        array_unshift( $index, $row );

        $keep = max( 2, (int) apply_filters( "trinity_forms_revisions_keep", 20, $form_id ) );
        foreach ( array_slice( $index, $keep ) as $old ) {
            delete_post_meta( $form_id, self::REV_PREFIX . $old["id"] );
        }
        $index = array_slice( $index, 0, $keep );

        update_post_meta( $form_id, self::REV_PREFIX . $id, wp_slash( $snap ) );
        update_post_meta( $form_id, self::INDEX, $index );
    }

    /* ---------------------------------------------------------------- data */

    /** @return array{title:string,content:string,settings:array} */
    public static function snapshot( int $form_id ): array {
        $post = get_post( $form_id );
        $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
        return [
            "title"    => $post ? (string) $post->post_title : "",
            "content"  => $post ? (string) $post->post_content : "",
            "settings" => is_array( $meta ) ? $meta : [],
        ];
    }

    private static function hash( array $snap ): string {
        return md5( (string) wp_json_encode( $snap ) );
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function index( int $form_id ): array {
        $index = get_post_meta( $form_id, self::INDEX, true );
        return is_array( $index ) ? array_values( array_filter( $index, static fn( $r ) => is_array( $r ) && ! empty( $r["id"] ) ) ) : [];
    }

    public static function get_copy( int $form_id, string $rev_id ): ?array {
        $snap = get_post_meta( $form_id, self::REV_PREFIX . sanitize_key( $rev_id ), true );
        return is_array( $snap ) && isset( $snap["content"] ) ? $snap : null;
    }

    /** @return array<string,array{label:string,sig:string}> field id => label + config signature */
    private static function fields_of( array $snap ): array {
        $post = new \WP_Post( (object) [ "ID" => 0, "post_title" => $snap["title"], "post_content" => $snap["content"], "post_type" => "trinity_forms_form" ] );
        $out  = [];
        foreach ( ( new Form( $post ) )->fields() as $field ) {
            $label = wp_strip_all_tags( (string) ( $field->config["label"] ?? "" ) );
            $out[ (string) $field->id ] = [
                "label" => "" !== $label ? $label : (string) $field->id,
                "sig"   => md5( (string) wp_json_encode( $field->config ) ),
            ];
        }
        return $out;
    }

    /**
     * What changed from $prev to $cur.
     *
     * @return array{added:string[],removed:string[],changed:string[],title:bool,settings:bool}
     */
    public static function summary( ?array $prev, array $cur ): array {
        $before = $prev ? self::fields_of( $prev ) : [];
        $after  = self::fields_of( $cur );
        $out    = [
            "added"    => [],
            "removed"  => [],
            "changed"  => [],
            "title"    => $prev && $prev["title"] !== $cur["title"],
            "settings" => $prev && wp_json_encode( $prev["settings"] ) !== wp_json_encode( $cur["settings"] ),
        ];
        foreach ( $after as $id => $f ) {
            if ( ! isset( $before[ $id ] ) ) {
                $out["added"][] = $f["label"];
            } elseif ( $before[ $id ]["sig"] !== $f["sig"] ) {
                $out["changed"][] = $f["label"];
            }
        }
        foreach ( $before as $id => $f ) {
            if ( ! isset( $after[ $id ] ) ) {
                $out["removed"][] = $f["label"];
            }
        }
        return $out;
    }

    /** One line for people. */
    public static function describe( array $row ): string {
        if ( "baseline" === ( $row["source"] ?? "" ) ) {
            return __( "The form as it was before revisions were kept.", "trinity-forms" );
        }
        $s     = is_array( $row["summary"] ?? null ) ? $row["summary"] : [];
        $parts = [];
        $list  = static fn( array $labels ) => implode( ", ", array_slice( $labels, 0, 5 ) ) . ( count( $labels ) > 5 ? " …" : "" );
        if ( ! empty( $s["added"] ) ) {
            /* translators: %s: field labels, comma-separated. */
            $parts[] = sprintf( __( "Added: %s", "trinity-forms" ), $list( $s["added"] ) );
        }
        if ( ! empty( $s["removed"] ) ) {
            /* translators: %s: field labels, comma-separated. */
            $parts[] = sprintf( __( "Removed: %s", "trinity-forms" ), $list( $s["removed"] ) );
        }
        if ( ! empty( $s["changed"] ) ) {
            /* translators: %s: field labels, comma-separated. */
            $parts[] = sprintf( __( "Changed: %s", "trinity-forms" ), $list( $s["changed"] ) );
        }
        if ( ! empty( $s["title"] ) ) {
            $parts[] = __( "Title changed", "trinity-forms" );
        }
        if ( ! empty( $s["settings"] ) ) {
            $parts[] = __( "Settings changed", "trinity-forms" );
        }
        if ( ! $parts ) {
            $parts[] = __( "Layout or text changed", "trinity-forms" );
        }
        $line = implode( "; ", $parts );
        if ( "restore" === ( $row["source"] ?? "" ) && ! empty( $row["restored_from"] ) ) {
            /* translators: 1: date and time of the restored version, 2: what changed. */
            $line = sprintf( __( 'Restored the version from %1$s. %2$s', "trinity-forms" ), Dates::human( (string) $row["restored_from"], false ), $line );
        }
        return $line;
    }

    public static function who( array $row ): string {
        if ( "baseline" === ( $row["source"] ?? "" ) ) {
            return "—";
        }
        $user = get_userdata( (int) ( $row["user"] ?? 0 ) );
        $name = $user ? (string) $user->display_name : __( "Deleted user", "trinity-forms" );
        /* translators: %s: a person's name. */
        return "assistant" === ( $row["source"] ?? "" ) ? sprintf( __( "AI assistant signed in as %s", "trinity-forms" ), $name ) : $name;
    }

    /* ---------------------------------------------------------------- restore */

    public static function restore( int $form_id, string $rev_id ): bool {
        $index = self::index( $form_id );
        $row   = null;
        foreach ( $index as $r ) {
            if ( $r["id"] === $rev_id ) {
                $row = $r;
            }
        }
        $snap = $row ? self::get_copy( $form_id, $rev_id ) : null;
        if ( ! $snap || ! self::is_form( $form_id ) ) {
            return false;
        }
        self::$source        = "restore";
        self::$restored_from = (string) $row["time"];
        try {
            $ok = wp_update_post( [
                "ID"           => $form_id,
                "post_title"   => wp_slash( $snap["title"] ),
                "post_content" => wp_slash( $snap["content"] ),
            ], true );
            if ( is_wp_error( $ok ) ) {
                return false;
            }
            // A copy with no settings at all (a form saved before any were written)
            // keeps today's: wiping them would silently stop its notification emails.
            if ( $snap["settings"] ) {
                update_post_meta( $form_id, Form::SETTINGS_META, wp_slash( $snap["settings"] ) );
            }
            self::$dirty[ $form_id ] = true;
            self::flush();
        } finally {
            self::$source        = "";
            self::$restored_from = "";
        }
        do_action( "trinity_forms_form_restored", $form_id, $rev_id );
        return true;
    }

    public static function handle_restore(): void {
        $form_id = absint( wp_unslash( $_POST["form_id"] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
        $rev_id  = sanitize_key( wp_unslash( $_POST["revision"] ?? "" ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
        check_admin_referer( "trinity_forms_restore_" . $form_id );
        if ( ! current_user_can( "edit_post", $form_id ) ) {
            wp_die( esc_html__( "You do not have permission to edit this form.", "trinity-forms" ) );
        }
        $done = self::restore( $form_id, $rev_id ) ? "restored" : "restore_failed";
        wp_safe_redirect( add_query_arg( [ "form_id" => $form_id, "tf_done" => $done ], self::page_url() ) );
        exit;
    }

    /* ---------------------------------------------------------------- screens */

    public static function page_url( int $form_id = 0 ): string {
        $url = admin_url( Admin::PARENT . "&page=" . self::PAGE );
        return $form_id ? add_query_arg( "form_id", $form_id, $url ) : $url;
    }

    public static function add_page(): void {
        $hook = add_submenu_page( Admin::PARENT, __( "Form revisions", "trinity-forms" ), __( "Form revisions", "trinity-forms" ), Admin::CAP, self::PAGE, [ __CLASS__, "render_page" ] );
        // Out of the menu, WordPress has no title for the browser tab.
        if ( $hook ) {
            add_action( "load-" . $hook, static function () {
                $GLOBALS["title"] = __( "Form revisions", "trinity-forms" ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the admin page title.
            } );
        }
        // Reached from a form, not from the menu.
        remove_submenu_page( Admin::PARENT, self::PAGE );
    }

    public static function row_action( array $actions, $post ): array {
        if ( $post instanceof \WP_Post && "trinity_forms_form" === $post->post_type && current_user_can( "edit_post", $post->ID ) && "trash" !== $post->post_status ) {
            $actions["trinity_forms_revisions"] = '<a href="' . esc_url( self::page_url( (int) $post->ID ) ) . '">' . esc_html__( "Revisions", "trinity-forms" ) . "</a>";
        }
        return $actions;
    }

    public static function add_meta_box(): void {
        add_meta_box( "trinity-forms-revisions", __( "Form revisions", "trinity-forms" ), [ __CLASS__, "render_meta_box" ], "trinity_forms_form", "side", "low" );
    }

    public static function render_meta_box( \WP_Post $post ): void {
        $index = self::index( (int) $post->ID );
        if ( ! $index ) {
            echo "<p>" . esc_html__( "Each save keeps a copy you can go back to. None yet.", "trinity-forms" ) . "</p>";
            return;
        }
        echo '<ul style="margin:0">';
        foreach ( array_slice( $index, 0, 3 ) as $row ) {
            echo "<li><strong>" . esc_html( Dates::human( (string) $row["time"], true ) ) . "</strong><br>" . esc_html( self::who( $row ) ) . "</li>";
        }
        echo "</ul>";
        /* translators: %d: number of saved copies. */
        echo '<p><a href="' . esc_url( self::page_url( (int) $post->ID ) ) . '">' . esc_html( sprintf( _n( "See %d revision", "See all %d revisions", count( $index ), "trinity-forms" ), count( $index ) ) ) . "</a></p>";
    }

    public static function render_page(): void {
        $form_id = absint( wp_unslash( $_GET["form_id"] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
        $post    = $form_id ? get_post( $form_id ) : null;
        if ( ! $post || "trinity_forms_form" !== $post->post_type || ! current_user_can( "edit_post", $form_id ) ) {
            Admin_UI::open( __( "Form revisions", "trinity-forms" ) );
            echo Admin_UI::empty_state( __( "Pick a form", "trinity-forms" ), __( "Open revisions from a form's row in the forms list.", "trinity-forms" ), "", "form" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            Admin_UI::close();
            return;
        }
        $title = "" !== $post->post_title ? $post->post_title : __( "(no title)", "trinity-forms" );
        $back  = '<a class="button" href="' . esc_url( (string) get_edit_post_link( $form_id ) ) . '">' . esc_html__( "Edit form", "trinity-forms" ) . "</a>";
        /* translators: %s: form title. */
        Admin_UI::open( sprintf( __( "Revisions: %s", "trinity-forms" ), $title ), __( "Every save keeps a copy. Restoring one replaces the form's fields and settings, and is kept as a revision too, so it can be undone.", "trinity-forms" ), $back );

        $done = sanitize_key( wp_unslash( $_GET["tf_done"] ?? "" ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        if ( "restored" === $done ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( "Revision restored.", "trinity-forms" ) . "</p></div>";
        } elseif ( "restore_failed" === $done ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( "That revision could not be restored.", "trinity-forms" ) . "</p></div>";
        }

        $index = self::index( $form_id );
        if ( ! $index ) {
            echo Admin_UI::empty_state( __( "No revisions yet", "trinity-forms" ), __( "The next save of this form keeps the first copy.", "trinity-forms" ), "", "form" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            Admin_UI::close();
            return;
        }
        $confirm = __( "Restore this version? The form's fields and settings will be replaced. You can undo this from the list.", "trinity-forms" );
        echo '<div class="jnkui-card"><div class="jnkui-card-body" style="overflow-x:auto"><table class="widefat striped tf-revisions"><thead><tr>';
        echo "<th scope=\"col\">" . esc_html__( "Saved", "trinity-forms" ) . "</th><th scope=\"col\">" . esc_html__( "By", "trinity-forms" ) . "</th><th scope=\"col\">" . esc_html__( "What changed", "trinity-forms" ) . "</th><th scope=\"col\"><span class=\"screen-reader-text\">" . esc_html__( "Actions", "trinity-forms" ) . "</span></th></tr></thead><tbody>";
        foreach ( $index as $i => $row ) {
            echo "<tr><td>" . esc_html( Dates::human( (string) $row["time"], true ) ) . "</td><td>" . esc_html( self::who( $row ) ) . "</td><td>" . esc_html( self::describe( $row ) ) . "</td><td>";
            if ( 0 === $i ) {
                echo Admin_UI::pill( __( "Current", "trinity-forms" ), "success" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            } else {
                echo '<form method="post" action="' . esc_url( admin_url( "admin-post.php" ) ) . '" onsubmit="return window.confirm(this.dataset.confirm)" data-confirm="' . esc_attr( $confirm ) . '">';
                echo '<input type="hidden" name="action" value="trinity_forms_restore_revision"><input type="hidden" name="form_id" value="' . (int) $form_id . '"><input type="hidden" name="revision" value="' . esc_attr( (string) $row["id"] ) . '">';
                wp_nonce_field( "trinity_forms_restore_" . $form_id );
                echo '<button type="submit" class="button">' . esc_html__( "Restore", "trinity-forms" ) . "</button></form>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div></div>";
        Admin_UI::close();
    }
}
