<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * The visual builder's block contract.
 *
 * Forms are Gutenberg block markup in post_content, and the builder must edit that
 * markup without losing anything the block editor can express — conditional logic,
 * premium field settings, foreign blocks, nesting. That is the whole problem, and
 * this class is where it is solved.
 *
 * Three rules make it lossless:
 *
 * 1. Parse and serialize in PHP, never in JS. parse_blocks() is registry-free, so it
 *    keeps attributes no block declares and attributes of blocks that aren't even
 *    registered (a premium field on a site whose licence lapsed). wp.blocks.parse()
 *    drops both — it is the very stripping we are avoiding.
 * 2. Anything the canvas can't fully model travels as an opaque REF into the current
 *    post_content, never as client-supplied markup. The client can reorder or delete
 *    such a block but can never author its bytes — so a contributor cannot inject
 *    HTML, and round-trip is byte-exact by construction rather than by trust.
 * 3. An attribute is only dropped when the registry declares it, gives it a default,
 *    and the value equals that default — mirroring Gutenberg, so a no-op save is
 *    byte-identical. Unknown attributes are NEVER dropped.
 *
 * A content_hash guards the refs: they index into post_content, so if the form
 * changed underneath (the block editor open in another tab) the save is refused
 * with 409 rather than silently clobbering.
 */
class Builder_REST {

    private const NS     = "trinity-forms/v1";
    private const PREFIX = "trinity-forms/field-";

    /** Attributes are capped to keep a hostile payload from ballooning post_content. */
    private const MAX_DEPTH       = 6;
    private const MAX_STRING      = 20000;
    private const MAX_ARRAY_ITEMS = 200;

    /** Must stay in lockstep with Conditional_Logic::compare(). */
    /**
     * The accepted operators, taken from the evaluator rather than restated here.
     *
     * This was a hard-coded list of eight, which silently became a rejection list
     * the moment Conditional_Logic grew a ninth: saving a form that used
     * "starts with" from the visual builder failed with "Unknown condition".
     *
     * @return array<int,string>
     */
    private static function operators(): array {
        return array_keys( Conditional_Logic::operators() );
    }

    public function register_routes(): void {
        register_rest_route( self::NS, "/builder/forms/(?P<id>\d+)", [
            [
                "methods"             => "GET",
                "callback"            => [ $this, "get_form" ],
                "permission_callback" => [ $this, "can_edit" ],
            ],
            [
                "methods"             => "POST",
                "callback"            => [ $this, "save_form" ],
                "permission_callback" => [ $this, "can_edit" ],
            ],
        ] );

        // Starter templates for the in-builder gallery — served as the same block
        // contract as a form, so the canvas can load one in place.
        register_rest_route( self::NS, "/builder/templates", [
            "methods"             => "GET",
            "callback"            => [ $this, "list_templates" ],
            "permission_callback" => [ $this, "can_list_templates" ],
        ] );
    }

    /** Templates are not per-post; gate on the create-forms capability + nonce. */
    public function can_list_templates( \WP_REST_Request $request ): bool {
        if ( ! current_user_can( "edit_posts" ) ) {
            return false;
        }
        $nonce = $request->get_header( "x_wp_nonce" );
        return $nonce && wp_verify_nonce( $nonce, "wp_rest" );
    }

    /**
     * Every starter template, rendered into the builder's block contract so the
     * canvas can drop one in without a page reload.
     */
    public function list_templates( \WP_REST_Request $request ) {
        $out = [];
        foreach ( Templates::templates() as $slug => $tpl ) {
            $out[] = [
                "slug"   => (string) $slug,
                "label"  => (string) ( $tpl["name"] ?? $slug ),
                "desc"   => (string) ( $tpl["desc"] ?? "" ),
                "icon"   => (string) ( $tpl["icon"] ?? "feedback" ),
                "blocks" => self::blocks_for( Templates::content_for( (string) $slug ) ),
            ];
        }
        return rest_ensure_response( $out );
    }

    /** House standard: capability AND an explicit wp_rest nonce. */
    public function can_edit( \WP_REST_Request $request ): bool {
        $id = (int) $request["id"];
        if ( ! current_user_can( "edit_post", $id ) ) {
            return false;
        }
        $nonce = $request->get_header( "x_wp_nonce" );
        return $nonce && wp_verify_nonce( $nonce, "wp_rest" );
    }

    /* ---------- GET ---------- */

    public function get_form( \WP_REST_Request $request ) {
        // A form imported or created over REST may still be JSON, which this builder
        // can't read; convert it first, or it opens as an empty canvas.
        Form_Manager::upgrade_json_content( (int) $request["id"] );
        $post = $this->form_post( (int) $request["id"] );
        if ( ! $post ) {
            return $this->error( 404, "Form not found." );
        }
        return rest_ensure_response( [
            // JSON the conversion refused. The canvas shows a notice instead of an
            // empty form that a save would overwrite.
            "uneditable"   => Form_Manager::is_uneditable_json( (string) $post->post_content ),
            "id"           => (int) $post->ID,
            "title"        => $post->post_title,
            "status"       => self::public_status( $post ),
            "can_publish"  => current_user_can( "publish_post", (int) $post->ID ),
            "content_hash" => self::content_hash( $post->post_content ),
            "blocks"       => self::blocks_for( $post->post_content ),
            "palette"      => self::palette(),
            // Field types this install has but may not use yet (an unlicensed Pro
            // build), shown as locked cards instead of silently missing. Empty on
            // Free and on a licensed site.
            "locked"       => array_values( array_filter( (array) apply_filters( "trinity_forms_builder_locked_palette", [] ), "is_array" ) ),
        ] );
    }

    /**
     * The contract's view of a form's top-level blocks.
     *
     * @return array<int,array>
     */
    public static function blocks_for( string $content ): array {
        $parsed   = parse_blocks( $content );
        $palette  = array_flip( Admin::form_palette( "edit" ) );
        $registry = \WP_Block_Type_Registry::get_instance();
        $out      = [];

        foreach ( $parsed as $ref => $block ) {
            $name = (string) ( $block["blockName"] ?? "" );

            // parse_blocks() emits nameless blocks for the whitespace between real
            // ones. They carry no information; POST re-creates the separation.
            if ( $name === "" ) {
                continue;
            }

            if ( self::is_plain_field( $block, $palette, $registry ) ) {
                $out[] = [
                    "key"   => "b$ref",
                    "kind"  => "field",
                    "name"  => $name,
                    "attrs" => is_array( $block["attrs"] ?? null ) ? $block["attrs"] : [],
                ];
                continue;
            }

            $type = $registry->get_registered( $name );
            $out[] = [
                "key"   => "b$ref",
                "kind"  => "opaque",
                "ref"   => $ref,
                "name"  => $name,
                "title" => $type && $type->title ? $type->title : $name,
            ];
        }
        return $out;
    }

    /**
     * A block the canvas may edit attribute-by-attribute.
     *
     * Everything else is opaque — including an UNREGISTERED trinity-forms/field-*,
     * which is exactly what a premium field looks like on a site without the licence.
     * Treating it as opaque is what stops the builder from rewriting (and thereby
     * gutting) a payment or calculation field it cannot understand.
     */
    private static function is_plain_field( array $block, array $palette, \WP_Block_Type_Registry $registry ): bool {
        $name = (string) ( $block["blockName"] ?? "" );
        if ( ! preg_match( '#^trinity-forms/field-[a-z0-9-]+$#', $name ) ) {
            return false;
        }
        if ( ! isset( $palette[ $name ] ) || ! $registry->get_registered( $name ) ) {
            return false;
        }
        // serialize_block() rebuilds nesting from innerContent; the canvas is a flat
        // list, so anything with children stays opaque.
        return empty( $block["innerBlocks"] );
    }

    /** "publish" or "draft", as the builder bar shows it. */
    private static function public_status( \WP_Post $post ): string {
        return "publish" === $post->post_status ? "publish" : "draft";
    }

    /** Attributes that hold several lines: sanitize_text_field() would join them into one. */
    private const MULTILINE_ATTRS = [ "chainedData", "subFieldsJson", "formula", "consentText" ];

    /**
     * Every field type the canvas can edit, each marked with whether it may be
     * ADDED. They differ only on a Pro site whose licence lapsed: its Pro fields
     * stay editable (the canvas needs their settings) but are not offered to add.
     *
     * @return array<int,array>
     */
    private static function palette(): array {
        $registry = \WP_Block_Type_Registry::get_instance();
        $addable  = array_flip( Admin::form_palette() );
        $out      = [];
        foreach ( Admin::form_palette( "edit" ) as $name ) {
            if ( strpos( $name, self::PREFIX ) !== 0 ) {
                continue;
            }
            $type = $registry->get_registered( $name );
            if ( ! $type ) {
                continue;
            }
            $out[] = [
                "name"       => $name,
                "title"      => $type->title,
                "icon"       => is_string( $type->icon ) ? $type->icon : "",
                "attributes" => (array) $type->attributes,
                "addable"    => isset( $addable[ $name ] ),
            ];
        }
        return $out;
    }

    /* ---------- POST ---------- */

    public function save_form( \WP_REST_Request $request ) {
        $post = $this->form_post( (int) $request["id"] );
        if ( ! $post ) {
            return $this->error( 404, "Form not found." );
        }

        // The builder shows such a form as empty; saving would replace its fields with
        // whatever the canvas held.
        if ( Form_Manager::is_uneditable_json( (string) $post->post_content ) ) {
            return $this->error(
                409,
                __( "This form can't be edited in the builder. Your form is unchanged.", "trinity-forms" ),
                "trinity_forms_builder_uneditable"
            );
        }

        $blocks = $request->get_param( "blocks" );
        if ( ! is_array( $blocks ) ) {
            return $this->error( 400, "A blocks array is required." );
        }

        // The refs below index into this exact post_content. If it moved, the refs
        // are meaningless — refuse rather than resolve them against the wrong parse.
        $sent_hash = (string) $request->get_param( "content_hash" );
        if ( $sent_hash !== self::content_hash( $post->post_content ) ) {
            return $this->error(
                409,
                __( "This form changed since you opened it. Reload before saving.", "trinity-forms" ),
                "trinity_forms_builder_conflict"
            );
        }

        $content = self::content_from( $blocks, $post->post_content );
        if ( is_wp_error( $content ) ) {
            return $this->error( 400, $content->get_error_message() );
        }

        // Last word before saving (pro/ uses it to refuse Pro fields added while
        // its licence is not active). A WP_Error is shown to the person as is.
        $refused = apply_filters( "trinity_forms_builder_pre_save", null, $content, $post );
        if ( is_wp_error( $refused ) ) {
            $data = (array) $refused->get_error_data();
            return $this->error( (int) ( $data["status"] ?? 403 ), $refused->get_error_message(), (string) $refused->get_error_code() );
        }

        // The form title is editable in the builder bar. Persist it alongside the
        // content; only touch post_title when a non-empty title was sent and changed.
        $update = [ "ID" => $post->ID ];
        if ( $content !== $post->post_content ) {
            $update["post_content"] = $content;
        }
        if ( $request->has_param( "title" ) ) {
            $title = sanitize_text_field( (string) $request->get_param( "title" ) );
            if ( $title !== "" && $title !== $post->post_title ) {
                $update["post_title"] = $title;
            }
        }

        // Publish / Draft from the builder bar. A draft form is not shown to visitors,
        // and the builder used to have no way to publish one.
        $status = $request->has_param( "status" ) ? sanitize_key( (string) $request->get_param( "status" ) ) : "";
        if ( "publish" === $status && "publish" !== $post->post_status ) {
            if ( ! current_user_can( "publish_post", (int) $post->ID ) ) {
                return $this->error( 403, __( "You are not allowed to publish this form.", "trinity-forms" ), "trinity_forms_cannot_publish" );
            }
            $update["post_status"] = "publish";
        } elseif ( "draft" === $status && "draft" !== $post->post_status ) {
            $update["post_status"] = "draft";
        } elseif ( "auto-draft" === $post->post_status ) {
            // First save of a form started from Add Form or the menu: keep it.
            $update["post_status"] = "draft";
        }

        if ( count( $update ) > 1 ) {
            // wp_update_post() unslashes its input. Unslashed block markup lost every
            // backslash, so a "\n" inside a JSON attribute came back as a bare "n"
            // (Chained Selects data saved as one line).
            $result = wp_update_post( wp_slash( $update ), true );
            if ( is_wp_error( $result ) ) {
                return $this->error( 500, "Could not save the form." );
            }
        }

        // However the form was started, a saved form emails the site admin by default.
        Form_Manager::seed_default_notification( (int) $post->ID );

        $fresh = get_post( $post->ID );
        return rest_ensure_response( [
            "id"           => (int) $post->ID,
            "status"       => self::public_status( $fresh ),
            "title"        => $fresh->post_title,
            "content_hash" => self::content_hash( $fresh->post_content ),
            "blocks"       => self::blocks_for( $fresh->post_content ),
        ] );
    }

    /**
     * Rebuild post_content from the contract.
     *
     * @param array<int,array> $blocks   Client payload.
     * @param string           $original The post_content the client's refs point into.
     * @return string|\WP_Error
     */
    public static function content_from( array $blocks, string $original ) {
        $parsed   = parse_blocks( $original );
        $palette  = array_flip( Admin::form_palette( "edit" ) );
        $registry = \WP_Block_Type_Registry::get_instance();
        $chunks   = [];

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                return new \WP_Error( "bad_block", "Malformed block entry." );
            }
            $kind = (string) ( $block["kind"] ?? "" );

            if ( $kind === "opaque" ) {
                $ref = $block["ref"] ?? null;
                if ( ! is_int( $ref ) && ! ctype_digit( (string) $ref ) ) {
                    return new \WP_Error( "bad_ref", "Malformed opaque reference." );
                }
                $ref = (int) $ref;
                if ( ! isset( $parsed[ $ref ] ) || ( $parsed[ $ref ]["blockName"] ?? "" ) === "" ) {
                    return new \WP_Error( "bad_ref", "Unknown opaque reference." );
                }
                // Serialized from the ORIGINAL parse — the client never supplies these
                // bytes, so they cannot be tampered with and cannot drift.
                // Serialized from the ORIGINAL parse — the client never supplies these
                // bytes, so they cannot be tampered with and cannot drift.
                $chunks[] = serialize_block( $parsed[ $ref ] );
                continue;
            }

            if ( $kind !== "field" ) {
                return new \WP_Error( "bad_kind", "Unknown block kind." );
            }

            $name = (string) ( $block["name"] ?? "" );
            $type = $registry->get_registered( $name );
            if ( ! preg_match( '#^trinity-forms/field-[a-z0-9-]+$#', $name ) || ! isset( $palette[ $name ] ) || ! $type ) {
                return new \WP_Error( "bad_name", sprintf( "Block %s is not allowed in a form.", $name ) );
            }

            $attrs = is_array( $block["attrs"] ?? null ) ? $block["attrs"] : [];
            $attrs = self::sanitize_attrs( $name, $attrs, $type );
            if ( is_wp_error( $attrs ) ) {
                return $attrs;
            }
            if ( (string) ( $attrs["fieldId"] ?? "" ) === "" ) {
                return new \WP_Error( "no_field_id", sprintf( "Block %s needs a field ID.", $name ) );
            }

            $chunks[] = serialize_block( [
                "blockName"    => $name,
                "attrs"        => $attrs,
                "innerBlocks"  => [],
                "innerHTML"    => "",
                "innerContent" => [],
            ] );
        }

        return implode( "\n\n", $chunks );
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    private static function sanitize_attrs( string $block_name, array $attrs, \WP_Block_Type $type ) {
        $declared = (array) $type->attributes;
        $clean    = [];

        foreach ( $attrs as $key => $value ) {
            if ( ! is_string( $key ) || ! preg_match( '#^[A-Za-z_][A-Za-z0-9_]*$#', $key ) ) {
                return new \WP_Error( "bad_attr", "Malformed attribute name." );
            }

            if ( $key === "conditional" ) {
                $rules = self::sanitize_conditional( $value );
                if ( is_wp_error( $rules ) ) {
                    return $rules;
                }
                if ( $rules !== null ) {
                    $clean[ $key ] = $rules;
                }
                continue;
            }

            if ( $key === "html" ) {
                // Field_Html re-filters on render, but post_content is read by more
                // than that one renderer, so don't store what the author may not post.
                $clean[ $key ] = current_user_can( "unfiltered_html" )
                    ? (string) $value
                    : wp_kses_post( (string) $value );
                continue;
            }

            if ( in_array( $key, (array) apply_filters( "trinity_forms_builder_multiline_attrs", self::MULTILINE_ATTRS ), true ) && is_scalar( $value ) ) {
                // Chained Selects CSV lost every line break here, so the front end said
                // "No data configured for this field yet".
                $clean[ $key ] = sanitize_textarea_field( substr( (string) $value, 0, self::MAX_STRING ) );
                continue;
            }

            if ( $key === "pattern" ) {
                // A regex, never echoed — only Field_Base::validate() consumes it.
                // sanitize_text_field() would silently corrupt any pattern using < or >.
                $clean[ $key ] = substr( (string) $value, 0, self::MAX_STRING );
                continue;
            }

            $declared_type = isset( $declared[ $key ]["type"] ) ? (string) $declared[ $key ]["type"] : null;
            $coerced       = self::coerce( $value, $declared_type );
            if ( is_wp_error( $coerced ) ) {
                return $coerced;
            }
            $clean[ $key ] = $coerced;
        }

        return self::strip_defaults( $clean, $declared );
    }

    /**
     * Drop only what Gutenberg itself would drop, so a no-op save is byte-identical.
     *
     * The three conditions are load-bearing together: an attribute the registry does
     * not declare has no default, so a laxer check (`$v === ($defaults[$k] ?? null)`)
     * would delete every unknown attribute — conditional included — on every save.
     *
     * @param array<string,mixed> $attrs
     * @param array<string,mixed> $declared
     * @return array<string,mixed>
     */
    private static function strip_defaults( array $attrs, array $declared ): array {
        foreach ( $attrs as $key => $value ) {
            if ( ! isset( $declared[ $key ] ) || ! array_key_exists( "default", (array) $declared[ $key ] ) ) {
                continue;
            }
            if ( $value === $declared[ $key ]["default"] ) {
                unset( $attrs[ $key ] );
            }
        }
        return $attrs;
    }

    /**
     * @return mixed|\WP_Error
     */
    private static function coerce( $value, ?string $declared_type, int $depth = 0 ) {
        if ( $depth > self::MAX_DEPTH ) {
            return new \WP_Error( "attr_depth", "Attribute nested too deeply." );
        }

        switch ( $declared_type ) {
            case "string":
                return sanitize_text_field( substr( (string) $value, 0, self::MAX_STRING ) );
            case "number":
                return is_numeric( $value ) ? ( $value + 0 ) : 0;
            case "integer":
                return (int) $value;
            case "boolean":
                return (bool) $value;
        }

        // Undeclared, or declared as array/object: keep the shape but allow only
        // JSON-safe scalars and arrays.
        if ( is_array( $value ) ) {
            if ( count( $value ) > self::MAX_ARRAY_ITEMS ) {
                return new \WP_Error( "attr_size", "Attribute has too many items." );
            }
            $out = [];
            foreach ( $value as $k => $v ) {
                if ( is_string( $k ) && ! preg_match( '#^[A-Za-z_][A-Za-z0-9_-]*$#', $k ) ) {
                    return new \WP_Error( "bad_attr", "Malformed attribute key." );
                }
                $child = self::coerce( $v, null, $depth + 1 );
                if ( is_wp_error( $child ) ) {
                    return $child;
                }
                $out[ $k ] = $child;
            }
            return $out;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || $value === null ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            return sanitize_text_field( substr( $value, 0, self::MAX_STRING ) );
        }
        return new \WP_Error( "bad_attr", "Unsupported attribute value." );
    }

    /**
     * Conditional rules are executed by Conditional_Logic (PHP) and public.js, so the
     * shape is validated strictly rather than merely sanitized.
     *
     * @return array|null|\WP_Error  null clears the attribute.
     */
    private static function sanitize_conditional( $value ) {
        if ( is_string( $value ) ) {
            $value = json_decode( $value, true );
        }
        if ( ! is_array( $value ) || empty( $value["conditions"] ) || ! is_array( $value["conditions"] ) ) {
            return null;
        }

        $group = self::sanitize_condition_group( $value, 0 );
        if ( is_wp_error( $group ) ) {
            return $group;
        }
        if ( null === $group ) {
            return null;
        }

        // The action only exists on the outermost group — nested ones are just
        // and/or containers. Prepended so the serialized key order stays
        // action/match/conditions and a save does not rewrite every block.
        return array_merge(
            [ "action" => ( ( $value["action"] ?? "show" ) === "hide" ) ? "hide" : "show" ],
            $group
        );
    }

    /**
     * One group of conditions, recursing into nested groups.
     *
     * A node with its own "conditions" is a group; anything else is a leaf. That is
     * the same distinction Conditional_Logic::evaluate_group() makes, so what the
     * editor writes, what this accepts and what the evaluator reads cannot drift.
     *
     * @param array<string,mixed> $group
     * @return array<string,mixed>|\WP_Error|null
     */
    private static function sanitize_condition_group( array $group, int $depth ) {
        // Matches the evaluator's own limit; a hand-crafted payload should not be
        // able to make the parser recurse indefinitely.
        if ( $depth > 10 ) {
            return new \WP_Error( "too_deep", "Conditions are nested too deeply." );
        }

        $raw = $group["conditions"] ?? [];
        if ( ! is_array( $raw ) || ! $raw ) {
            return null;
        }
        if ( count( $raw ) > self::MAX_ARRAY_ITEMS ) {
            return new \WP_Error( "attr_size", "Too many conditions." );
        }

        $operators  = self::operators();
        $conditions = [];

        foreach ( $raw as $cond ) {
            if ( ! is_array( $cond ) ) {
                return new \WP_Error( "bad_condition", "Malformed condition." );
            }

            if ( isset( $cond["conditions"] ) ) {
                $nested = self::sanitize_condition_group( $cond, $depth + 1 );
                if ( is_wp_error( $nested ) ) {
                    return $nested;
                }
                // An empty group is dropped rather than stored — it would evaluate
                // to "true" forever and read as a rule that does nothing.
                if ( null !== $nested ) {
                    $conditions[] = $nested;
                }
                continue;
            }

            $operator = (string) ( $cond["operator"] ?? "equals" );
            if ( ! in_array( $operator, $operators, true ) ) {
                return new \WP_Error( "bad_operator", sprintf( "Unknown condition: %s.", $operator ) );
            }
            $cond_value = $cond["value"] ?? "";
            $conditions[] = [
                "field"    => sanitize_text_field( (string) ( $cond["field"] ?? "" ) ),
                "operator" => $operator,
                "value"    => is_scalar( $cond_value ) ? sanitize_text_field( (string) $cond_value ) : "",
            ];
        }

        if ( ! $conditions ) {
            return null;
        }

        return [
            "match"      => ( ( $group["match"] ?? "all" ) === "any" ) ? "any" : "all",
            "conditions" => $conditions,
        ];
    }

    /* ---------- helpers ---------- */

    public static function content_hash( string $content ): string {
        return md5( $content );
    }

    private function form_post( int $id ): ?\WP_Post {
        $post = get_post( $id );
        return $post && $post->post_type === "trinity_forms_form" ? $post : null;
    }

    /**
     * A WP_Error, not a hand-rolled envelope.
     *
     * The REST server turns this into the shape apiFetch understands — it throws an
     * object carrying `message` and `data.status`. A custom body would arrive with
     * neither, so the client could show neither the reason nor the right UI: a 409
     * would read as a generic failure and the conflict prompt would never appear.
     */
    private function error( int $status, string $message, string $code = "trinity_forms_builder" ): \WP_Error {
        return new \WP_Error( $code, $message, [ "status" => $status ] );
    }
}
