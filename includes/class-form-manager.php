<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

class Form_Manager {

    /**
     * A form a visitor may see and submit: published, or any status for someone
     * who can edit it (so a draft can still be previewed on a page). A draft used
     * to render and accept submissions from anyone who found the page.
     */
    public static function get_public( int $id ): ?Form {
        $form = self::get( $id );
        if ( ! $form ) {
            return null;
        }
        $status = (string) get_post_status( $id );
        if ( "publish" === $status || current_user_can( "edit_post", $id ) ) {
            return $form;
        }
        return null;
    }

    public static function get( int $id ): ?Form {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== "trinity_forms_form" || $post->post_status === "trash" ) {
            return null;
        }
        return new Form( $post );
    }

    /**
     * Every form as id => title, for pickers.
     *
     * An untitled draft gets a stand-in label rather than an empty <option>, which
     * otherwise reads as a broken list rather than a form nobody has named yet.
     *
     * @return array<int,string>
     */
    public static function all_titles(): array {
        $posts = get_posts( [
            "post_type"     => "trinity_forms_form",
            "post_status"   => [ "publish", "draft" ],
            "numberposts"   => 200,
            "no_found_rows" => true,
        ] );

        $out = [];
        foreach ( $posts as $post ) {
            $out[ (int) $post->ID ] = "" !== $post->post_title
                ? $post->post_title
                /* translators: %d: form ID. */
                : sprintf( __( "(untitled form #%d)", "trinity-forms" ), (int) $post->ID );
        }
        return $out;
    }

    /** @return Form[] */
    public static function all_with_retention(): array {
        $posts = get_posts( [
            "post_type"      => "trinity_forms_form",
            "post_status"    => [ "publish", "draft" ],
            "numberposts"    => -1,
            "fields"         => "ids",
            "no_found_rows"  => true,
        ] );
        $forms = [];
        foreach ( $posts as $pid ) {
            $form = self::get( (int) $pid );
            if ( $form && $form->retention_days() > 0 ) {
                $forms[] = $form;
            }
        }
        return $forms;
    }

    /**
     * Forms are stored as block markup whenever the schema allows it, because that is
     * the only format the visual builder and the block editor can open. A schema that
     * block markup can't carry without loss stays JSON (see Form::blocks_from_schema()).
     */
    public static function save_schema( int $form_id, array $schema ): bool {
        $post = get_post( $form_id );
        if ( ! $post || $post->post_type !== "trinity_forms_form" ) {
            return false;
        }
        $blocks = Form::blocks_from_schema( $schema );
        $result = wp_update_post( [
            "ID"           => $form_id,
            // wp_update_post() runs wp_unslash() on its input, so the content must be
            // slashed first — otherwise wp_json_encode's \uXXXX / \" escapes get mangled.
            "post_content" => wp_slash( $blocks ?? (string) wp_json_encode( $schema ) ),
        ], true );
        if ( is_wp_error( $result ) ) {
            return false;
        }
        if ( null !== $blocks ) {
            self::merge_sections( $form_id, $schema );
        }
        return true;
    }

    public static function create( string $title, array $schema = [] ): int {
        $schema  = $schema ?: self::default_schema();
        $blocks  = Form::blocks_from_schema( $schema );
        $post_id = wp_insert_post( [
            "post_type"    => "trinity_forms_form",
            "post_status"  => "publish",
            "post_title"   => $title,
            // Slashed: wp_insert_post() unslashes its input (see save_schema).
            "post_content" => wp_slash( $blocks ?? (string) wp_json_encode( $schema ) ),
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return 0;
        }
        // Before the sections move to meta: seed_default_notification() only seeds a
        // form with no meta yet, which is what a JSON-stored form looked like here.
        do_action( "trinity_forms_form_created", (int) $post_id );
        if ( null !== $blocks ) {
            self::merge_sections( (int) $post_id, $schema );
        }
        return (int) $post_id;
    }

    /**
     * Turn a form still stored as JSON into block markup, so the editors can open it.
     *
     * Run when an editor is about to load the form. Does nothing to a block-built
     * form, and leaves a JSON form alone when the conversion would lose anything.
     * The form renders exactly as before: the same fields, and the same settings,
     * because meta sections keep winning over the ones the JSON carried.
     */
    public static function upgrade_json_content( int $form_id ): bool {
        $post = get_post( $form_id );
        if ( ! $post || $post->post_type !== "trinity_forms_form" ) {
            return false;
        }
        $schema = self::json_schema_of( (string) $post->post_content );
        if ( null === $schema ) {
            return false;
        }
        $blocks = Form::blocks_from_schema( $schema );
        if ( null === $blocks ) {
            return false;
        }
        $result = wp_update_post( [ "ID" => $form_id, "post_content" => wp_slash( $blocks ) ], true );
        if ( is_wp_error( $result ) ) {
            return false;
        }
        self::merge_sections( $form_id, $schema );
        return true;
    }

    /** load-post.php: convert a JSON form before the block editor or the builder loads it. */
    public static function upgrade_on_edit_screen(): void {
        if ( ( $_SERVER["REQUEST_METHOD"] ?? "GET" ) !== "GET" ) {
            return;
        }
        $id = isset( $_GET["post"] ) ? absint( $_GET["post"] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing; the upgrade keeps the form identical.
        if ( $id && current_user_can( "edit_post", $id ) ) {
            self::upgrade_json_content( $id );
        }
    }

    /**
     * True for a form stored as JSON that couldn't be turned into blocks. The editors
     * can't show it, so they must not offer to save over it either.
     */
    public static function is_uneditable_json( string $content ): bool {
        $schema = self::json_schema_of( $content );
        return null !== $schema && null === Form::blocks_from_schema( $schema );
    }

    /** The schema in a JSON-stored form's post_content, or null for anything else. */
    private static function json_schema_of( string $content ): ?array {
        $content = trim( $content );
        if ( "" === $content || "{" !== $content[0] || Form::looks_like_blocks( $content ) ) {
            return null;
        }
        $schema = json_decode( $content, true );
        return is_array( $schema ) && isset( $schema["steps"] ) ? $schema : null;
    }

    /** Move a schema's sections into meta; sections already in meta win, as in Form::__construct(). */
    private static function merge_sections( int $form_id, array $schema ): void {
        $sections = array_intersect_key( $schema, array_flip( Form::SECTIONS ) );
        if ( ! $sections ) {
            return;
        }
        $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
        update_post_meta( $form_id, Form::SETTINGS_META, array_merge( $sections, is_array( $meta ) ? $meta : [] ) );
    }

    /**
     * value => label for every choice field on a form, keyed by field id.
     *
     * Entries store the option's value; a human needs the label they picked. Shared
     * so an email, the entry screen and anything else agree on the wording.
     *
     * @return array<string,array<string,string>>
     */
    public static function choice_labels( int $form_id ): array {
        $form = $form_id ? self::get( $form_id ) : null;
        if ( ! $form ) {
            return [];
        }
        $map = [];
        foreach ( $form->fields() as $field ) {
            if ( empty( $field->config["options"] ) ) {
                continue;
            }
            foreach ( $field->config["options"] as $opt ) {
                $value = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
                $map[ $field->id ][ $value ] = (string) ( $opt["label"] ?? $value );
            }
        }
        return $map;
    }

    /**
     * The admin notification a newly created form starts with.
     *
     * A form that emails nobody is the single most common way this plugin looks
     * broken: the submission is stored, nothing arrives, and the owner concludes
     * notifications do not work.
     */
    public static function default_notification( int $form_id = 0 ): array {
        // Point Reply-To at the form's email field when there is exactly one, so
        // hitting Reply answers the person who wrote in without anyone configuring
        // anything. Two email fields and we cannot know which is the sender's.
        $reply_to = "";
        $form     = $form_id ? self::get( $form_id ) : null;
        if ( $form ) {
            $emails = array_values( array_filter( $form->fields(), static fn( $f ) => $f->type === "email" ) );
            if ( count( $emails ) === 1 ) {
                $reply_to = "{" . $emails[0]->id . "}";
            }
        }
        return [
            "name"       => __( "Admin notification", "trinity-forms" ),
            "to"         => (string) get_option( "admin_email" ),
            "reply_to"   => $reply_to,
            "from_email" => "",
            /* translators: {site_name} is a merge tag and must stay untranslated. */
            "subject"    => __( "New form entry on {site_name}", "trinity-forms" ),
            "body"       => "{all_fields}",
        ];
    }

    /**
     * Give a brand-new form its default notification.
     *
     * Hooked to `trinity_forms_form_created`, so it covers every route that makes a
     * form — the template gallery, the REST API, code — not just the ones that pass
     * through the block editor. Never touches a form that already has settings, so
     * it cannot resurrect notifications somebody deliberately removed.
     */
    public static function seed_default_notification( int $form_id ): void {
        if ( get_post_meta( $form_id, Form::SETTINGS_META, true ) ) {
            return;
        }
        // Only the notifications key. Form::__construct lets a meta section replace
        // the one parsed from post_content, so seeding an empty "settings" here
        // would silently wipe the settings a programmatic form was created with.
        update_post_meta( $form_id, Form::SETTINGS_META, [
            "notifications" => [ self::default_notification( $form_id ) ],
        ] );
    }

    public static function default_schema(): array {
        return [
            "version"  => "1.0",
            "settings" => [
                "submit_button_text"   => __( "Submit", "trinity-forms" ),
                "redirect_url"         => null,
                "confirmation_message" => __( "Thank you!", "trinity-forms" ),
                "ajax_submit"          => true,
                "honeypot"             => true,
                "turnstile"            => false,
                "retention_days"       => 365,
                "save_partial"         => false,
            ],
            "steps"         => [ [ "id" => "step_1", "title" => "", "fields" => [] ] ],
            "notifications" => [],
            "payment"       => [ "enabled" => false, "provider" => "stripe", "mode" => "test", "currency" => "USD" ],
            "integrations"  => [],
        ];
    }
}
