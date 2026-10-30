<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * The submit button text and the default confirmation (a message, a page, or a
 * URL): the settings both editors show.
 *
 * Until 2.18 only the block editor's settings box could change them, so someone
 * who built a form in the Visual Builder could not change "Submit" or "Thank
 * you!" without finding a second editor (persona run ar-news, 29 Oct 2026). Both
 * editors now read and write them through this one class, so they cannot drift.
 */
final class Form_Basics {

    public const KEYS = [ "submit_button_text", "confirmation_type", "confirmation_message", "confirmation_page_id", "redirect_url" ];

    /**
     * What a form has stored, in the shape both editors use.
     *
     * @return array{submit_button_text:string,confirmation_type:string,confirmation_message:string,confirmation_page_id:int,redirect_url:string}
     */
    public static function get( int $form_id ): array {
        // Through Form, so a form whose settings live in its content (imported,
        // made in code) shows what visitors actually get.
        $form     = Form_Manager::get( $form_id );
        $settings = $form ? $form->settings() : [];
        $redirect = (string) ( $settings["redirect_url"] ?? "" );
        return [
            "submit_button_text"   => (string) ( $settings["submit_button_text"] ?? "" ),
            "confirmation_type"    => self::type( $settings["confirmation_type"] ?? null, $redirect ),
            "confirmation_message" => (string) ( $settings["confirmation_message"] ?? "" ),
            "confirmation_page_id" => (int) ( $settings["confirmation_page_id"] ?? 0 ),
            "redirect_url"         => $redirect,
        ];
    }

    /**
     * Clean what an editor sent. A form saved before there was a choice has no
     * type: it was a redirect when it had a URL, a message otherwise.
     *
     * @return array<string,mixed>
     */
    public static function sanitize( array $raw ): array {
        $redirect = esc_url_raw( (string) ( $raw["redirect_url"] ?? "" ) );
        return [
            "submit_button_text"   => sanitize_text_field( (string) ( $raw["submit_button_text"] ?? __( "Submit", "trinity-forms" ) ) ),
            "confirmation_type"    => self::type( $raw["confirmation_type"] ?? null, $redirect ),
            "confirmation_message" => self::message( (string) ( $raw["confirmation_message"] ?? "" ) ),
            "confirmation_page_id" => max( 0, (int) ( $raw["confirmation_page_id"] ?? 0 ) ),
            "redirect_url"         => $redirect ?: null,
        ];
    }

    /**
     * The confirmation message as typed. Markup (an imported "<p>Thanks, <a>…</a></p>")
     * is kept as post HTML, so the link survives a save in an editor; plain text is
     * cleaned as it always was, so a typed "&" is not stored as "&amp;" and shown
     * back in the box that way.
     */
    private static function message( string $text ): string {
        return preg_match( '#<[a-z/!]#i', $text ) ? wp_kses_post( $text ) : sanitize_textarea_field( $text );
    }

    /** Store them, leaving every other setting of the form as it was. */
    public static function save( int $form_id, array $raw ): void {
        $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
        $meta = is_array( $meta ) ? $meta : [];
        if ( is_array( $meta["settings"] ?? null ) ) {
            $base = $meta["settings"];
        } else {
            // A "settings" section in the meta replaces the one the form's content
            // carries, so start from what the form has now, not from nothing.
            $form = Form_Manager::get( $form_id );
            $base = $form ? $form->settings() : [];
        }
        $meta["settings"] = array_merge( $base, self::sanitize( $raw ) );
        update_post_meta( $form_id, Form::SETTINGS_META, $meta );
    }

    /** @param mixed $type */
    private static function type( $type, string $redirect ): string {
        $type = is_string( $type ) ? $type : "";
        if ( in_array( $type, [ Confirmations::TYPE_TEXT, Confirmations::TYPE_PAGE, Confirmations::TYPE_REDIRECT ], true ) ) {
            return $type;
        }
        return "" !== $redirect ? Confirmations::TYPE_REDIRECT : Confirmations::TYPE_TEXT;
    }

    /**
     * Published pages for the "Go to a page" picker.
     *
     * @return array<int,array{id:int,title:string}>
     */
    public static function pages(): array {
        $out = [];
        foreach ( get_pages( [ "post_status" => "publish", "sort_column" => "post_title", "number" => 500 ] ) as $page ) {
            /* translators: %d: page ID. */
            $out[] = [ "id" => (int) $page->ID, "title" => "" !== $page->post_title ? $page->post_title : sprintf( __( "(no title #%d)", "trinity-forms" ), (int) $page->ID ) ];
        }
        return $out;
    }
}
