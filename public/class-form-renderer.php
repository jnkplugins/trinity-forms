<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Form_Renderer {

    /**
     * The form currently being drawn, for fields whose output depends on form-level
     * settings (the payment summary mirrors the Payment tab). Null outside render().
     */
    public static ?Form $rendering = null;

    /** How many forms this request has drawn, so a second one gets unique element ids. */
    private static int $instances = 0;

    /** Nesting depth, so a render inside a render is not counted as another form. */
    private static int $depth = 0;

    public static function render( Form $form ): string {
        $previous        = self::$rendering;
        self::$rendering = $form;
        self::$depth++;
        try {
            $html = self::render_form( $form );
        } finally {
            self::$rendering = $previous;
            self::$depth--;
        }
        if ( self::$depth > 0 ) {
            return $html;
        }
        // Only on a real front-end page, where several forms can share one document.
        // template_redirect, not wp_head: a block theme renders the page's blocks
        // before wp_head runs, so a wp_head check never counted a form there.
        if ( is_admin() || ! did_action( "template_redirect" ) ) {
            return $html;
        }
        self::$instances++;
        return self::$instances > 1 ? self::prefix_ids( $html, "tf" . self::$instances . "-" ) : $html;
    }

    /**
     * Two forms on one page used to share ids (#email, #name_first), so a label in
     * the second form pointed at the first form's input. The first form keeps its
     * ids (links and custom CSS aimed at them keep working); every later one gets
     * its ids, and every attribute that refers to an id, prefixed. The original id
     * is kept in data-tf-base-id for scripts that build field names from it.
     */
    public static function prefix_ids( string $html, string $prefix ): string {
        $html = (string) preg_replace_callback(
            '/\sid="([^"]+)"/',
            static fn( $m ) => ' id="' . $prefix . $m[1] . '" data-tf-base-id="' . $m[1] . '"',
            $html
        );
        return (string) preg_replace_callback(
            '/\s(for|aria-describedby|aria-labelledby|aria-controls|data-target)="([^"]+)"/',
            static fn( $m ) => " " . $m[1] . '="' . implode( " ", array_map( static fn( $t ) => $prefix . $t, preg_split( '/\s+/', trim( $m[2] ) ) ) ) . '"',
            $html
        );
    }

    /**
     * The outcome of a submit sent with JavaScript off (see REST_API::back_to_the_page()).
     * Shown once, on the form it belongs to.
     */
    public static function plain_post_notice( Form $form ): string {
        $token = isset( $_GET["tf_result"] ) ? sanitize_key( wp_unslash( (string) $_GET["tf_result"] ) ) : ""; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only lookup key, not an action.
        if ( "" === $token ) {
            return "";
        }
        $note = get_transient( "trinity_forms_plain_" . $token );
        if ( ! is_array( $note ) || (int) ( $note["form_id"] ?? 0 ) !== (int) $form->id ) {
            return "";
        }
        delete_transient( "trinity_forms_plain_" . $token );
        $type  = ! empty( $note["ok"] ) ? "success" : "error";
        $items = array_map( static fn( $m ) => "<li>" . wp_kses_post( (string) $m ) . "</li>", (array) ( $note["messages"] ?? [] ) );
        return '<div id="trinity-forms-' . (int) $form->id . '" class="trinity-forms-messages trinity-forms-' . $type . '" role="status"><ul>' . implode( "", $items ) . "</ul></div>";
    }

    /**
     * Shown where a form should be when it is gone. Deleting a form left its page
     * published and silently empty; now the people who can fix the page see why.
     * Visitors still get nothing.
     */
    public static function missing_notice( int $form_id ): string {
        if ( ! current_user_can( "edit_posts" ) ) {
            return "";
        }
        return '<p class="trinity-forms-missing" style="border:1px dashed #b32d2e;padding:.75em 1em;color:#b32d2e;">'
            . esc_html( sprintf(
                /* translators: %d: form ID. */
                __( "Visitors see nothing here: form #%d was deleted or is not published. Only people who can edit the site see this note.", "trinity-forms" ),
                $form_id
            ) )
            . "</p>";
    }

    /** Tests only: start counting forms again. */
    public static function reset_instances(): void {
        self::$instances = 0;
    }

    private static function render_form( Form $form ): string {
        // A form that is closed, not open yet, full, or login-gated is replaced by
        // its message rather than drawn and then rejected on submit. Same gate the
        // pipeline runs, so the two can't drift.
        $blocked = Restrictions::check( $form );
        if ( $blocked ) {
            return '<div class="trinity-forms-closed trinity-forms-closed-' . esc_attr( $blocked["reason"] ) . '">'
                . wp_kses_post( wpautop( $blocked["message"] ) )
                . "</div>";
        }

        $action   = esc_url( rest_url( "trinity-forms/v1/submit/" . $form->id ) );
        $save_url = esc_url( rest_url( "trinity-forms/v1/save-progress/" . $form->id ) );
        $nonce    = wp_create_nonce( "wp_rest" );
        $submit   = esc_html( $form->get_setting( "submit_button_text", __( "Submit", "trinity-forms" ) ) );

        // Save-&-continue is premium: the resume context (prefilled values + token)
        // is provided by pro/ through this filter. Free build => null => empty form.
        $resume = apply_filters( "trinity_forms_resume_context", null, $form->id );
        $values = is_array( $resume ) ? ( $resume["values"] ?? [] ) : [];
        $token  = is_array( $resume ) ? ( $resume["token"]  ?? "" ) : "";

        // Group fields into wizard steps (split on step objects and page-break
        // fields). One group => a normal single-page form; more => a stepper.
        $groups = self::step_groups( $form, $values );
        $multi  = count( $groups ) > 1;

        $fields_html = "";
        if ( $multi ) {
            foreach ( $groups as $i => $group_html ) {
                $hidden       = 0 === $i ? "" : " hidden";
                $fields_html .= '<div class="trinity-forms-step" data-step="' . (int) $i . '"' . $hidden . '>' . $group_html . '</div>';
            }
        } else {
            $fields_html = $groups[0] ?? "";
        }

        $allow_save = (bool) $form->get_setting( "allow_save_progress", false ) && License::can_run( "save_progress" );

        // Layout is expressed as classes on the form so the stylesheet owns the
        // actual positioning; nothing here needs to know what "left labels" means.
        $form_classes = [ "trinity-forms-form" ];

        $label_placement = (string) $form->get_setting( "label_placement", "top" );
        if ( in_array( $label_placement, [ "left", "right", "hidden" ], true ) ) {
            $form_classes[] = "trinity-forms-labels-" . $label_placement;
        }

        $validation_placement = (string) $form->get_setting( "validation_placement", "below" );
        if ( "above" === $validation_placement ) {
            $form_classes[] = "trinity-forms-errors-above";
        }

        $style_class = Form_Style::css_class( $form );
        if ( "" !== $style_class ) {
            $form_classes[] = $style_class;
        }

        foreach ( preg_split( '/\s+/', (string) $form->get_setting( "form_css_class", "" ) ) as $custom ) {
            $custom = sanitize_html_class( (string) $custom );
            if ( "" !== $custom ) {
                $form_classes[] = $custom;
            }
        }

        $html  = '<form class="' . esc_attr( implode( " ", $form_classes ) ) . '" data-form-id="' . esc_attr( (string) $form->id ) . '"';
        // Style preset + colours as CSS variables (Form_Style); empty for the default look.
        $style_vars = Form_Style::inline( $form );
        $html .= "" !== $style_vars ? ' style="' . esc_attr( $style_vars ) . '"' : "";
        $html .= ' data-save-url="' . $save_url . '"';
        $html .= $multi ? ' data-steps="' . count( $groups ) . '"' : "";
        $html .= $allow_save ? ' data-allow-save="1"' : "";
        // Extension point for premium render modes (e.g. conversational one-question
        // display adds data-conversational="1"). Filtered value must be a pre-escaped
        // attribute fragment; free core adds nothing.
        $html .= (string) apply_filters( "trinity_forms_form_data_attributes", "", $form, $multi );
        $html .= ' method="post" action="' . $action . '" enctype="multipart/form-data">';
        $html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '" />';
        $html .= self::plain_post_notice( $form );
        if ( $token ) {
            $html .= '<input type="hidden" name="_trinity_forms_resume_token" value="' . esc_attr( $token ) . '" />';
            // Say what just happened: a half-filled form appearing out of nowhere
            // reads like somebody else's answers.
            $html .= '<div class="trinity-forms-messages trinity-forms-success trinity-forms-resume-restored" role="status">'
                . esc_html__( "Welcome back — we restored the answers you saved. Check them and carry on.", "trinity-forms" )
                . "</div>";
        }
        // Which page the form was embedded on. The submit is an AJAX POST, so its
        // HTTP_REFERER is that same page and the *original* referrer is long gone —
        // capturing it at render time is the only place the two can differ.
        $source_url = is_singular() || is_page() ? get_permalink() : "";
        if ( ! $source_url && isset( $_SERVER["REQUEST_URI"] ) ) {
            $source_url = home_url( esc_url_raw( wp_unslash( $_SERVER["REQUEST_URI"] ) ) );
        }
        if ( $source_url ) {
            $html .= '<input type="hidden" name="_trinity_forms_source_url" value="' . esc_url( $source_url ) . '" />';
        }
        $html .= Anti_Spam::honeypot_html();
        $html .= Anti_Spam::timetrap_html();
        // Without JavaScript nothing evaluates a "show" condition, so its field
        // stayed hidden for ever and a required one could not be answered. Show
        // them all; the server still applies the conditions on submit.
        if ( false !== strpos( $fields_html, "trinity-forms-conditional" ) ) {
            $html .= '<noscript><style>.trinity-forms-form .trinity-forms-conditional{display:block !important}</style></noscript>';
        }
        if ( $multi ) {
            $html .= '<p class="trinity-forms-step-status" role="status" aria-live="polite"></p>';
            // Without JavaScript nothing switches steps, so Next did nothing and the
            // form could not be sent. Show every step and a Submit of its own.
            $html .= '<noscript><style>.trinity-forms-form .trinity-forms-step[hidden]{display:block !important}'
                . '.trinity-forms-form .trinity-forms-next,.trinity-forms-form .trinity-forms-submit:not(.trinity-forms-submit-nojs){display:none !important}</style></noscript>';
        }

        // A summary of everything wrong, at the top. On a long form the inline
        // errors can all be below the fold, so a failed submit looks like nothing
        // happened. role="alert" so it is announced rather than merely drawn.
        if ( $form->get_setting( "validation_summary", false ) ) {
            $html .= '<div class="trinity-forms-summary" role="alert" hidden></div>';
        }

        $html .= $fields_html;
        $html .= self::turnstile_widget( $form );
        $html .= '<div class="trinity-forms-actions">';
        if ( $multi ) {
            $html .= '<button type="button" class="trinity-forms-prev" hidden>' . esc_html__( "Previous", "trinity-forms" ) . '</button> ';
            $html .= '<button type="button" class="trinity-forms-next">' . esc_html__( "Next", "trinity-forms" ) . '</button> ';
            $html .= '<button type="submit" class="trinity-forms-submit" hidden>' . $submit . '</button>';
            // formnovalidate: a required field whose condition is not met is still on
            // screen without the page script, and would block the browser's own check.
            // The server validates (and skips hidden fields) either way.
            $html .= '<noscript><button type="submit" class="trinity-forms-submit trinity-forms-submit-nojs" formnovalidate>' . $submit . '</button></noscript>';
        } else {
            $html .= self::submit_button( $form, $submit );
        }
        if ( $allow_save ) {
            $html .= ' <button type="button" class="trinity-forms-save-progress">' . esc_html__( "Save & continue later", "trinity-forms" ) . '</button>';
        }
        $html .= '</div>';
        $html .= '<div class="trinity-forms-messages" role="status" aria-live="polite"></div>';
        $html .= '</form>';

        // Analytics + add-ons: a form was rendered on the page.
        do_action( "trinity_forms_form_rendered", $form->id );

        return $html;
    }

    /**
     * Split a form's fields into wizard-step groups. A new group starts at each
     * step object boundary and at each page-break field (the page-break itself is
     * a delimiter and is not rendered). Returns an array of HTML strings, one per
     * step; a single element means the form is single-page.
     *
     * @param array<string,mixed> $values Prefilled values (resume context).
     * @return string[]
     */
    private static function step_groups( Form $form, array $values ): array {
        $groups  = [];
        $current = "";
        $has     = false;
        // Premium "conversational" mode turns every field into its own step (one
        // question per view); free core keeps the page-break/step grouping.
        $one_per_step = (bool) apply_filters( "trinity_forms_one_field_per_step", false, $form );
        foreach ( $form->steps() as $step ) {
            if ( $has ) {
                $groups[] = $current;
                $current  = "";
                $has      = false;
            }
            foreach ( $step["fields"] ?? [] as $config ) {
                if ( ! isset( $config["id"], $config["type"] ) ) {
                    continue;
                }
                $type = (string) $config["type"];
                if ( "page_break" === $type || "page-break" === $type ) {
                    if ( $has ) {
                        $groups[] = $current;
                        $current  = "";
                        $has      = false;
                    }
                    continue;
                }
                // Who-is-looking visibility, which is separate from conditional
                // logic (that is about answers). An admin-only or logged-in-only
                // field is not rendered at all rather than hidden with CSS, so its
                // existence isn't visible in the page source either.
                if ( ! Fields\Field_Base::is_visible_to_user( $config ) ) {
                    continue;
                }

                // Form-level layout defaults are folded into the field's own config
                // before it renders, so Field_Base::wrap() sees one resolved value
                // and needs no knowledge of the form at all. A field that sets its
                // own placement keeps it.
                $field = new Field( self::with_layout_defaults( $config, $form ) );

                // A saved-progress value always beats a prefill: someone who already
                // started answering has said more than the URL has.
                $value = $values[ $field->id ] ?? null;
                if ( null === $value ) {
                    $value = Prefill::resolve( $config );
                }

                $current .= self::wrap_conditional( $field, $field->render( $value ) );
                $has      = true;
                // One-question mode: flush after every field so each becomes a step.
                if ( $one_per_step ) {
                    $groups[] = $current;
                    $current  = "";
                    $has      = false;
                }
            }
        }
        if ( $has || "" !== $current ) {
            $groups[] = $current;
        }
        return $groups ?: [ "" ];
    }

    /**
     * The submit button, which may be an image and may be conditional.
     *
     * An image button is still <button type="submit">, with the image inside it and
     * the text as its accessible name — an <input type="image"> submits x/y
     * coordinates instead of a value and reads as a graphic to a screen reader.
     */
    private static function submit_button( Form $form, string $label ): string {
        $image = (string) $form->get_setting( "submit_button_image", "" );

        $attrs = "";
        $rules = $form->get_setting( "submit_conditional", [] );
        if ( is_array( $rules ) && ! empty( $rules["conditions"] ) ) {
            // The same attribute the field wrapper uses, so public.js shows and
            // hides the button with exactly the code that handles every field.
            $attrs = " data-trinity-forms-conditional='" . esc_attr( (string) wp_json_encode( $rules ) ) . "'";
            // A "show when" rule starts hidden, or the button flashes on load.
            if ( "show" === ( $rules["action"] ?? "show" ) ) {
                $attrs .= ' hidden';
            }
        }

        if ( "" !== $image ) {
            return '<button type="submit" class="trinity-forms-submit trinity-forms-submit-image"' . $attrs . '>'
                . '<img src="' . esc_url( $image ) . '" alt="' . esc_attr( wp_strip_all_tags( $label ) ) . '" />'
                . "</button>";
        }

        return '<button type="submit" class="trinity-forms-submit"' . $attrs . ">" . $label . "</button>";
    }

    /**
     * Merge the form's layout settings into one field's config.
     *
     * Only where the field has not answered for itself — a per-field choice is more
     * specific than a form-wide default and must win.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function with_layout_defaults( array $config, Form $form ): array {
        $defaults = [
            "description_placement" => (string) $form->get_setting( "description_placement", "below" ),
            "sub_label_placement"   => (string) $form->get_setting( "sub_label_placement", "below" ),
            "required_indicator"    => (string) $form->get_setting( "required_indicator", "asterisk" ),
        ];

        foreach ( $defaults as $key => $value ) {
            if ( ! isset( $config[ $key ] ) || "" === $config[ $key ] ) {
                $config[ $key ] = $value;
            }
        }

        return $config;
    }

    /**
     * Wrap a rendered field in a container carrying its conditional-logic rules
     * (as a JSON data attribute) so public.js can show/hide it client-side. The
     * server remains authoritative — Submission_Pipeline re-evaluates the same
     * rules via Conditional_Logic on submit.
     *
     * If the field has a "show when" rule it starts hidden (and its inputs
     * disabled) until the client evaluates the rule on load; "hide when" rules
     * start visible. This avoids a flash of a field that should be hidden.
     */
    private static function wrap_conditional( Field $field, string $html ): string {
        $rules = $field->config["conditional"] ?? null;
        if ( ! is_array( $rules ) || empty( $rules["conditions"] ) ) {
            return $html;
        }

        $payload = wp_json_encode( [
            "action"     => (string) ( $rules["action"] ?? "show" ),
            "match"      => (string) ( $rules["match"]  ?? "all" ),
            "conditions" => array_values( (array) $rules["conditions"] ),
        ] );
        if ( $payload === false ) {
            return $html;
        }

        // A "show" rule defaults to hidden until proven true; a "hide" rule
        // defaults to visible. The JS recomputes the real state immediately.
        $start_hidden = ( (string) ( $rules["action"] ?? "show" ) ) === "show";
        $style        = $start_hidden ? ' style="display:none"' : "";
        $hidden_attr  = $start_hidden ? ' aria-hidden="true"' : "";

        return '<div class="trinity-forms-conditional" data-trinity-forms-conditional="' . esc_attr( $payload ) . '"'
            . $style . $hidden_attr . '>' . $html . '</div>';
    }

    private static function turnstile_widget( Form $form ): string {
        if ( ! $form->get_setting( "turnstile", false ) ) {
            return "";
        }
        $provider = Anti_Spam::captcha_provider();
        $site_key = Public_Front::captcha_site_key();
        if ( $site_key === "" ) {
            return "";
        }
        Public_Front::enqueue_captcha();
        // reCAPTCHA v3 has no visible widget: the token is fetched by script and
        // parked in a hidden field (populated by Public_Front's executor).
        if ( $provider === "recaptcha_v3" ) {
            $field = Anti_Spam::CAPTCHA_PROVIDERS[ $provider ]["field"];
            return '<input type="hidden" name="' . esc_attr( $field ) . '" class="trinity-forms-recaptcha-v3-token" value="" />';
        }
        $class = Anti_Spam::CAPTCHA_PROVIDERS[ $provider ]["class"];
        return '<div class="' . esc_attr( $class ) . '" data-sitekey="' . esc_attr( $site_key ) . '"></div>';
    }

    public static function maybe_render_resume_notice( string $content ): string {
        if ( empty( $_GET["trinity_forms_success"] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
            return $content;
        }
        // HIGH-04: validate the opaque token; never expose or accept raw entry IDs.
        $token = sanitize_text_field( wp_unslash( (string) $_GET["trinity_forms_success"] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list/UI parameter from GET; no state change.
        if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
            return $content;
        }
        $entry_id = (int) get_transient( "trinity_forms_success_" . $token );
        if ( ! $entry_id ) {
            return $content;
        }
        delete_transient( "trinity_forms_success_" . $token );

        // Use the form's configured confirmation message (matching the AJAX path)
        // rather than a hardcoded string. Fall back to a generic default.
        $message = __( "Thank you — your submission was received.", "trinity-forms" );
        global $wpdb;
        $form_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT form_id FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d",
            $entry_id
        ) );
        if ( $form_id > 0 ) {
            $form = Form_Manager::get( $form_id );
            if ( $form ) {
                $configured = (string) $form->get_setting( "confirmation_message", "" );
                if ( "" !== $configured ) {
                    $message = $configured;
                }
            }
        }

        return '<div class="trinity-forms-confirmation">' . esc_html( $message ) . '</div>' . $content;
    }
}
