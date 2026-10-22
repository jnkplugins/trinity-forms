<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * What the visitor sees after submitting.
 *
 * A form can carry several confirmations, each with its own conditional logic, and
 * the first one whose conditions are met wins. There is always exactly one fallback
 * — the default — which cannot be deleted, because every submission has to end in
 * *something*.
 *
 * Three types:
 *   - text     — a message shown in place of the form
 *   - page     — send the visitor to a WordPress page
 *   - redirect — send the visitor to any URL, optionally with a query string
 *
 * The conditional logic here is not a second implementation: it reuses
 * Conditional_Logic through the same faux-field trick Notification_Engine uses, so
 * an operator added there works in all three places at once.
 */
class Confirmations {

    public const TYPE_TEXT     = "text";
    public const TYPE_PAGE     = "page";
    public const TYPE_REDIRECT = "redirect";

    /**
     * What a submission classified as spam is told.
     *
     * Deliberately indistinguishable from success by default. Telling a bot "that
     * looked like spam" is free feedback for tuning around the filter, and telling
     * a wrongly-flagged human the same thing is worse than useless. Site owners who
     * want to be honest about it can set their own wording.
     */
    /**
     * The form's confirmation message, or "Thank you!" when it is blank.
     *
     * A form made from a template stores no message, and saving it once in the
     * block editor wrote an empty one, so visitors got the untranslated
     * JavaScript fallback instead of "Thank you!".
     */
    public static function default_message( Form $form ): string {
        $text = trim( (string) $form->get_setting( "confirmation_message", "" ) );
        return "" !== $text ? $text : __( "Thank you!", "trinity-forms" );
    }

    public static function spam_message( Form $form, int $entry_id = 0 ): string {
        $custom = trim( (string) $form->get_setting( "spam_confirmation_message", "" ) );
        $text   = "" !== $custom
            ? $custom
            : self::default_message( $form );

        // The spam path must read exactly like the real one, merge tags included: a
        // person the filter got wrong should not be shown a raw "{name}".
        return $entry_id > 0 ? Merge_Tags::replace( $text, $entry_id ) : $text;
    }

    /**
     * Resolve the confirmation for a completed entry.
     *
     * @return array{type:string,message:string,redirect:string|null}
     */
    public static function resolve( Form $form, int $entry_id, array $payload = [] ): array {
        foreach ( self::all( $form ) as $confirmation ) {
            if ( self::matches( $confirmation, $payload ) ) {
                return self::build( $confirmation, $entry_id );
            }
        }

        return self::build( self::fallback( $form ), $entry_id );
    }

    /**
     * Every configured confirmation except the default, in evaluation order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all( Form $form ): array {
        $raw = $form->get_setting( "confirmations", [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $out = [];
        foreach ( $raw as $c ) {
            if ( ! is_array( $c ) || ! empty( $c["is_default"] ) ) {
                continue;
            }
            // A confirmation with no conditions would swallow every submission
            // before any later one could be reached; that job belongs to the
            // default, which is evaluated last by construction.
            if ( empty( $c["conditional"]["conditions"] ) ) {
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }

    /**
     * The default confirmation, assembled from the plain settings the form has
     * always had so that forms built before this feature keep working untouched.
     *
     * @return array<string,mixed>
     */
    public static function fallback( Form $form ): array {
        $redirect = (string) $form->get_setting( "redirect_url", "" );

        return [
            "is_default" => true,
            "type"       => "" !== $redirect ? self::TYPE_REDIRECT : self::TYPE_TEXT,
            "message"    => self::default_message( $form ),
            "url"        => $redirect,
        ];
    }

    /**
     * Does this confirmation's conditional logic pass?
     *
     * @param array<string,mixed> $confirmation
     * @param array<string,mixed> $payload
     */
    private static function matches( array $confirmation, array $payload ): bool {
        $cond = $confirmation["conditional"] ?? null;
        if ( ! $cond || empty( $cond["conditions"] ) ) {
            return false;
        }

        // Same trick as Notification_Engine::should_send(): wrap the rules in a
        // throwaway field so Conditional_Logic can evaluate them unchanged.
        $faux = new Field( [ "id" => "_confirmation", "type" => "hidden", "conditional" => $cond ] );
        return Conditional_Logic::is_visible( $faux, $payload );
    }

    /**
     * Turn a stored confirmation into the envelope the client acts on.
     *
     * @param array<string,mixed> $confirmation
     * @return array{type:string,message:string,redirect:string|null}
     */
    private static function build( array $confirmation, int $entry_id ): array {
        $type = (string) ( $confirmation["type"] ?? self::TYPE_TEXT );

        if ( self::TYPE_PAGE === $type ) {
            $page_id = (int) ( $confirmation["page_id"] ?? 0 );
            $url     = $page_id ? (string) get_permalink( $page_id ) : "";
            return [
                "type"     => self::TYPE_REDIRECT,
                "message"  => "",
                "redirect" => $url ? self::with_query( $url, $confirmation, $entry_id ) : null,
            ];
        }

        if ( self::TYPE_REDIRECT === $type ) {
            $url = Merge_Tags::replace( (string) ( $confirmation["url"] ?? "" ), $entry_id );
            return [
                "type"     => self::TYPE_REDIRECT,
                "message"  => "",
                "redirect" => "" !== $url ? self::with_query( $url, $confirmation, $entry_id ) : null,
            ];
        }

        return [
            "type"     => self::TYPE_TEXT,
            "message"  => Merge_Tags::replace( (string) ( $confirmation["message"] ?? "" ), $entry_id ),
            "redirect" => null,
        ];
    }

    /**
     * Append the configured query string, with merge tags resolved.
     *
     * Written as "utm_source=form&email={email}" the way Gravity Forms does it,
     * because that is the shape people already have in their notes.
     *
     * @param array<string,mixed> $confirmation
     */
    private static function with_query( string $url, array $confirmation, int $entry_id ): string {
        $query = trim( (string) ( $confirmation["query_string"] ?? "" ) );
        if ( "" === $query ) {
            return esc_url_raw( $url );
        }

        $resolved = Merge_Tags::replace( $query, $entry_id );

        $args = [];
        parse_str( $resolved, $args );
        // Re-encode through add_query_arg so a merge tag that resolved to something
        // with an & or a space can't split into extra parameters.
        return esc_url_raw( add_query_arg( array_map( "rawurlencode", array_map( "strval", $args ) ), $url ) );
    }

    /**
     * Sanitize the confirmations posted from the form editor.
     *
     * @param mixed $raw
     * @return array<int,array<string,mixed>>
     */
    public static function sanitize( $raw ): array {
        $out = [];
        foreach ( (array) $raw as $c ) {
            if ( ! is_array( $c ) ) {
                continue;
            }

            $type = (string) ( $c["type"] ?? self::TYPE_TEXT );
            if ( ! in_array( $type, [ self::TYPE_TEXT, self::TYPE_PAGE, self::TYPE_REDIRECT ], true ) ) {
                $type = self::TYPE_TEXT;
            }

            $row = [
                "name"         => sanitize_text_field( (string) ( $c["name"] ?? "" ) ),
                "type"         => $type,
                "message"      => wp_kses_post( (string) ( $c["message"] ?? "" ) ),
                "page_id"      => (int) ( $c["page_id"] ?? 0 ),
                "url"          => esc_url_raw( (string) ( $c["url"] ?? "" ) ),
                "query_string" => sanitize_text_field( (string) ( $c["query_string"] ?? "" ) ),
            ];

            $rules = self::sanitize_conditional( $c["conditional"] ?? null );
            if ( $rules ) {
                $row["conditional"] = $rules;
            }

            // Nothing to show and nowhere to go — an empty row from the "add" button.
            if ( "" === $row["message"] && 0 === $row["page_id"] && "" === $row["url"] ) {
                continue;
            }

            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>|null
     */
    private static function sanitize_conditional( $raw ): ?array {
        // Shared with fields and notifications, so a nested group written in the
        // editor survives here too.
        $rules = Conditional_Logic::sanitize_rules( $raw );
        return $rules ?: null;
    }
}
