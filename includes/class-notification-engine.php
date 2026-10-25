<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Notification_Engine {

    /**
     * Send the notifications for an entry.
     *
     * @param int    $entry_id
     * @param string $event Which moment this is. Notifications opt into an event;
     *                      the default "submission" keeps every existing form
     *                      behaving exactly as before.
     */
    public static function dispatch( int $entry_id, string $event = "submission" ): void {
        global $wpdb;
        $form_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT form_id FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d",
            $entry_id
        ) );
        if ( ! $form_id ) {
            return;
        }
        $form = Form_Manager::get( $form_id );
        if ( ! $form ) {
            return;
        }

        foreach ( $form->notifications() as $notif ) {
            // Explicitly disabled, rather than deleted — turning one off to test
            // something should not mean retyping it afterwards.
            if ( isset( $notif["enabled"] ) && ! $notif["enabled"] ) {
                continue;
            }
            if ( ( $notif["event"] ?? "submission" ) !== $event ) {
                continue;
            }
            if ( ! self::should_send( $notif, $entry_id ) ) {
                continue;
            }
            $to = self::resolve_recipients( $notif, $entry_id );
            if ( "" === $to ) {
                continue;
            }
            $to      = apply_filters( "trinity_forms_email_to",      $to, $entry_id, $notif );
            $subject = apply_filters( "trinity_forms_email_subject", self::plain( Merge_Tags::replace( (string) ( $notif["subject"] ?? "" ), $entry_id ) ), $entry_id, $notif );
            $body    = apply_filters( "trinity_forms_email_body",    Merge_Tags::replace( self::body_html( (string) ( $notif["body"] ?? "" ) ), $entry_id ), $entry_id, $notif );

            $headers   = [ "Content-Type: text/html; charset=UTF-8" ];
            $from_name = self::plain( Merge_Tags::replace( (string) ( $notif["from_name"] ?? "" ), $entry_id ) );
            // A From name with no From email (the screen says to leave the email
            // blank) used to be dropped, so mail came from "WordPress". Set only the
            // name for this one send and keep the site's own address.
            $name_only = "" !== $from_name && empty( $notif["from_email"] )
                ? static fn() => $from_name
                : null;
            if ( ! empty( $notif["from_email"] ) ) {
                $from      = Merge_Tags::replace( (string) $notif["from_email"], $entry_id );
                // "Acme Support <forms@acme.com>" rather than a bare address: an
                // inbox shows the name, and a nameless From reads as machine spam.
                $headers[] = "" !== $from_name
                    ? sprintf( 'From: %s <%s>', $from_name, $from )
                    : "From: " . $from;
            }

            foreach ( [ "cc" => "Cc", "bcc" => "Bcc" ] as $key => $header ) {
                $list = trim( Merge_Tags::replace( (string) ( $notif[ $key ] ?? "" ), $entry_id ) );
                if ( "" !== $list ) {
                    $headers[] = $header . ": " . $list;
                }
            }
            // Reply-To is its own setting now. Deriving it from From meant the only
            // way to make Reply reach the sender was to send *as* the sender, which
            // fails SPF and puts the notification in spam. Falling back to from_email
            // keeps forms already configured that way behaving exactly as before.
            $reply_to = trim( (string) ( $notif["reply_to"] ?? "" ) );
            if ( $reply_to === "" ) {
                $reply_to = (string) ( $notif["from_email"] ?? "" );
            }
            if ( $reply_to !== "" ) {
                $reply_to = Merge_Tags::replace( $reply_to, $entry_id );
                if ( is_email( $reply_to ) ) {
                    $headers[] = "Reply-To: " . $reply_to;
                }
            }

            // The form's own uploads, when the notification asks for them, plus
            // whatever Pro adds through the filter (e.g. a submission PDF).
            $attachments = empty( $notif["attach_files"] ) ? [] : self::entry_files( $entry_id );
            $attachments = (array) apply_filters( "trinity_forms_email_attachments", $attachments, $entry_id, $notif );
            Mail_Check::begin();
            if ( $name_only ) {
                add_filter( "wp_mail_from_name", $name_only, 99 );
            }
            $sent = wp_mail( $to, $subject, $body, $headers, $attachments );
            if ( $name_only ) {
                remove_filter( "wp_mail_from_name", $name_only, 99 );
            }
            do_action( "trinity_forms_notification_sent", $entry_id, $notif["id"] ?? "", $sent );
            if ( $sent ) {
                Mail_Check::record_success();
                // "Handed to the mail server", not "delivered": a bounce after this
                // point never reaches WordPress.
                Logger::info( "notification", sprintf(
                    /* translators: 1: notification name, 2: email address(es). */
                    __( "Email \"%1\$s\" handed to the mail server for %2\$s", "trinity-forms" ),
                    (string) ( $notif["name"] ?? $subject ),
                    $to
                ), $entry_id );
            } else {
                // The reason comes from wp_mail_failed (Mail_Check::capture), which
                // used to be thrown away: "wp_mail returned false" told nobody what to fix.
                $reason = Mail_Check::reason();
                Logger::error( "notification", sprintf(
                    /* translators: %s: email address(es). */
                    __( "Email to %s could not be sent", "trinity-forms" ),
                    $to
                ), $entry_id, [ "to" => $to, "reason" => $reason ] );
                Mail_Check::record_failure( $form_id, $entry_id, $to, $reason );
                do_action( "trinity_forms_notification_failed", $entry_id, $notif, $to, $reason );
            }
        }
    }

    /**
     * Merge tags are escaped for the HTML body, so a subject or From name read
     * "Filmy Duniya &amp; Friends" and "Sean O&#039;Brien" in the inbox. Those are
     * plain-text headers: decode, and keep them on one line.
     */
    private static function plain( string $text ): string {
        $text = html_entity_decode( wp_specialchars_decode( $text, ENT_QUOTES ), ENT_QUOTES | ENT_HTML5, "UTF-8" );
        return trim( (string) preg_replace( "/[\r\n\t]+/", " ", $text ) );
    }

    /**
     * Work out who this notification actually goes to.
     *
     * Two modes. The default is a literal address list (merge tags allowed, which is
     * how {admin_email} has always worked). "Route to a field" instead sends to
     * whatever email the visitor typed into a chosen field — the autoresponder case,
     * which previously meant knowing that {email} happened to be the field's id.
     */
    private static function resolve_recipients( array $notif, int $entry_id ): string {
        if ( "field" === ( $notif["to_type"] ?? "email" ) ) {
            $field_id = (string) ( $notif["to_field"] ?? "" );
            if ( "" === $field_id ) {
                return "";
            }
            $payload = self::entry_payload( $entry_id );
            $value   = $payload[ $field_id ] ?? "";
            $value   = is_array( $value ) ? reset( $value ) : $value;
            $value   = trim( (string) $value );

            // Never send to something that isn't an address: a mistyped field id
            // would otherwise hand wp_mail() a person's name.
            return is_email( $value ) ? $value : "";
        }

        return Merge_Tags::replace( (string) ( $notif["to"] ?? "" ), $entry_id );
    }

    private static function should_send( array $notif, int $entry_id ): bool {
        $cond = $notif["conditional"] ?? null;
        if ( ! $cond || empty( $cond["conditions"] ) ) {
            return true;
        }
        $payload = self::entry_payload( $entry_id );
        $faux    = new Field( [ "id" => "_notif", "type" => "hidden", "conditional" => $cond ] );
        return Conditional_Logic::is_visible( $faux, $payload );
    }

    /**
     * Readable paths of the files uploaded with an entry.
     *
     * Only files still on disk and still under the uploads directory: a stale row
     * pointing anywhere else would either break wp_mail or, worse, attach something
     * that was never part of the submission.
     *
     * @return array<int,string>
     */
    private static function entry_files( int $entry_id ): array {
        global $wpdb;

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT filepath FROM {$wpdb->prefix}trinity_forms_files WHERE entry_id = %d",
            $entry_id
        ) );

        $out = [];

        foreach ( (array) $rows as $path ) {
            $path = (string) $path;
            if ( "" === $path ) {
                continue;
            }
            // Private root (2.4.1+) or uploads (older entries); nothing else.
            if ( Private_Files::is_managed_path( $path ) && is_readable( $path ) ) {
                $out[] = $path;
            }
        }
        return $out;
    }

    /**
     * The notification body as HTML.
     *
     * Mail goes out as text/html, so the line breaks someone typed into the body box
     * collapsed into one paragraph ("Hi Meera, Thanks for writing. Your plan: Pro").
     * A body that already carries its own block markup is left exactly as written.
     */
    public static function body_html( string $body ): string {
        if ( "" === trim( $body ) || preg_match( '/<(p|br|div|table|ul|ol|h[1-6])\b/i', $body ) ) {
            return $body;
        }
        return nl2br( str_replace( [ "\r\n", "\r" ], "\n", $body ), false );
    }

    private static function entry_payload( int $entry_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, value FROM {$wpdb->prefix}trinity_forms_entry_meta WHERE entry_id = %d",
            $entry_id
        ), ARRAY_A );
        $payload = [];
        foreach ( $rows as $r ) {
            $decoded = json_decode( (string) $r["value"], true );
            $payload[ $r["field_id"] ] = is_array( $decoded ) ? $decoded : $r["value"];
        }
        return $payload;
    }
}
