<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Per-form settings UI on the Form CPT edit screen.
 *
 * Field structure is edited via the Gutenberg editor (post_content JSON);
 * everything else — notifications, payment, integrations, behavior — is edited
 * here and merged into the same schema on save.
 */
class Form_Editor {

    private const NONCE = "trinity_forms_form_editor_nonce";

    /**
     * The moments a notification can be attached to.
     *
     * "submission" is the default and the only one a free form reaches on its own;
     * the rest are fired by the payment flow, the moderation feature and the entry
     * editor respectively.
     *
     * @var array<int,string>
     */
    /** Field types that are layout, not answers. */
    public const NON_ANSWER_TYPES = [ "html", "section", "page_break" ];

    public const NOTIFICATION_EVENTS = [ "submission", "payment_completed", "entry_approved", "entry_edited" ];

    public static function bootstrap(): void {
        add_action( "add_meta_boxes",        [ self::class, "register_meta_boxes" ] );
        add_action( "save_post_trinity_forms_form", [ self::class, "save" ], 10, 2 );
        add_action( "admin_enqueue_scripts", [ self::class, "enqueue_assets" ] );
    }

    /** Enqueue the form-editor CSS/JS (extracted from inline blocks) on the form CPT edit screen. */
    public static function enqueue_assets( string $hook ): void {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== "trinity_forms_form" || ! in_array( $screen->base, [ "post", "post-new" ], true ) ) {
            return;
        }
        // The front-end stylesheet too, for the style preview on the Layout tab.
        wp_enqueue_style( "trinity-forms", TRINITY_FORMS_URL . "public/assets/css/public.css", [], TRINITY_FORMS_VERSION );
        do_action( "trinity_forms_enqueue_style_preview" );
        wp_enqueue_style( "trinity-forms-form-editor", TRINITY_FORMS_URL . "admin/assets/css/form-editor.css", [], TRINITY_FORMS_VERSION );
        wp_enqueue_script( "trinity-forms-form-editor", TRINITY_FORMS_URL . "admin/assets/js/form-editor.js", [], TRINITY_FORMS_VERSION, true );
        // The "add notification" template needs the form's fields so a newly added
        // notification can be routed to one without saving and reloading first.
        $editor_post = get_post();
        $tpl_fields  = $editor_post ? self::field_options( (int) $editor_post->ID ) : [];

        wp_localize_script( "trinity-forms-form-editor", "TrinityFormsFormEditor", [
            "notifTemplate"   => self::notification_template_html( $tpl_fields ),
            "confirmTemplate" => self::confirmation_template_html( $tpl_fields ),
            // The picker's list comes from Merge_Tags itself, so it can never offer
            // a tag that renders literally.
            "mergeTags"     => Merge_Tags::catalogue( $tpl_fields ),
            "restUrl"       => esc_url_raw( rest_url( "trinity-forms/v1/integrations/test" ) ),
            "mailTestUrl"   => esc_url_raw( rest_url( "trinity-forms/v1/mail-test" ) ),
            "stylePresets"  => Form_Style::presets(),
            "userEmail"     => (string) wp_get_current_user()->user_email,
            "nonce"         => wp_create_nonce( "wp_rest" ),
            "i18n"          => [
                "testing"      => __( "Testing…", "trinity-forms" ),
                "connected"    => __( "✓ Connected", "trinity-forms" ),
                "failed"       => __( "✗ Failed", "trinity-forms" ),
                "networkError" => __( "✗ Network error", "trinity-forms" ),
                "insertTag"    => __( "Insert a merge tag", "trinity-forms" ),
                "sending"      => __( "Sending…", "trinity-forms" ),
                "noAddress"    => __( "Type an address in Send to first.", "trinity-forms" ),
                "mergeTagAddress" => __( "A merge tag only becomes an address when someone submits the form. Type a real address to send a test.", "trinity-forms" ),
            ],
        ] );
    }

    public static function register_meta_boxes(): void {
        add_meta_box(
            "trinity-forms-form-settings",
            __( "Trinity Forms — Settings", "trinity-forms" ),
            [ self::class, "render_meta_box" ],
            "trinity_forms_form",
            "normal",
            "high"
        );
    }

    public static function render_meta_box( \WP_Post $post ): void {
        $form     = Form_Manager::get( $post->ID );
        $settings = $form ? $form->settings()      : [];
        $notifs   = $form ? $form->notifications() : [];
        $payment  = $form ? $form->payment_config(): [];
        $integr   = $form ? $form->integrations()  : [];

        // A form that has never been saved from this screen starts with an admin
        // notification already filled in: without one, submissions are stored but
        // nothing is emailed, which reads as "the plugin doesn't send email".
        // Only ever a prefill — it is persisted when the form is saved, so a form
        // deliberately left with no notifications (meta present, list empty) keeps
        // that choice.
        if ( ! $notifs && ! get_post_meta( $post->ID, Form::SETTINGS_META, true ) ) {
            $notifs = [ Form_Manager::default_notification( $post->ID ) ];
        }

        $fields_options = self::field_options( (int) $post->ID );

        wp_nonce_field( self::NONCE, self::NONCE );
        ?>
        <div class="trinity-forms-tabs">
            <button type="button" class="active" data-tab="general"><?php esc_html_e( "General", "trinity-forms" ); ?></button>
            <button type="button" data-tab="notifications"><?php esc_html_e( "Notifications", "trinity-forms" ); ?></button>
            <button type="button" data-tab="confirmations"><?php esc_html_e( "Confirmations", "trinity-forms" ); ?></button>
            <button type="button" data-tab="layout"><?php esc_html_e( "Layout", "trinity-forms" ); ?></button>
            <button type="button" data-tab="restrictions"><?php esc_html_e( "Restrictions", "trinity-forms" ); ?></button>
            <?php
            // Premium tab buttons (Payment, Integrations) are injected by pro/.
            do_action( "trinity_forms_form_editor_tabs", $form );
            ?>
        </div>

        <div class="trinity-forms-tab-panel active" data-panel="general">
            <table class="form-table">
                <tr><th><?php esc_html_e( "Submit button text", "trinity-forms" ); ?></th>
                    <td><input type="text" name="trinity-forms[settings][submit_button_text]" value="<?php echo esc_attr( $settings["submit_button_text"] ?? "Submit" ); ?>" /></td></tr>
                <tr><th><?php esc_html_e( "Confirmation message", "trinity-forms" ); ?></th>
                    <td><textarea name="trinity-forms[settings][confirmation_message]" rows="2"><?php echo esc_textarea( $settings["confirmation_message"] ?? "" ); ?></textarea></td></tr>
                <tr><th><?php esc_html_e( "Redirect URL", "trinity-forms" ); ?></th>
                    <td><input type="text" name="trinity-forms[settings][redirect_url]" value="<?php echo esc_attr( $settings["redirect_url"] ?? "" ); ?>" placeholder="https://…" /></td></tr>
                <?php // "Save & continue later" is a Pro-only feature. Only render the control
                      // when Pro is active — the free build shows no disabled/teaser UI. With
                      // the Pro tools locked, a form that already uses it can still turn it
                      // off. The hidden "0" tells save() the control was on the page: when it
                      // is not, the stored value is kept rather than switched off. ?>
                <?php if ( License::is_pro() || ( License::is_pro_runtime() && ! empty( $settings["allow_save_progress"] ) ) ) : ?>
                <tr><th><?php esc_html_e( "Allow save & continue later", "trinity-forms" ); ?></th>
                    <td><input type="hidden" name="trinity-forms[settings][allow_save_progress]" value="0" /><label><input type="checkbox" name="trinity-forms[settings][allow_save_progress]" value="1" <?php checked( ! empty( $settings["allow_save_progress"] ) ); ?> /> <?php esc_html_e( "Show a save button so users can resume later via emailed link", "trinity-forms" ); ?></label></td></tr>
                <?php endif; ?>
                <?php
                $captcha_provider   = Anti_Spam::captcha_provider();
                $captcha_label      = Anti_Spam::CAPTCHA_PROVIDERS[ $captcha_provider ]["label"];
                $global_captcha     = get_option( "trinity_forms_settings", [] )[ $captcha_provider ] ?? [];
                $has_turnstile_keys = is_array( $global_captcha ) && ! empty( $global_captcha["site_key"] ) && ! empty( $global_captcha["secret_key"] );
                ?>
                <tr><th><?php esc_html_e( "Bot challenge (CAPTCHA)", "trinity-forms" ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="trinity-forms[settings][turnstile]" value="1" <?php checked( ! empty( $settings["turnstile"] ) ); ?> <?php disabled( ! $has_turnstile_keys ); ?> />
                            <?php
                            /* translators: %s: CAPTCHA provider name, e.g. "Google reCAPTCHA v2". */
                            echo esc_html( sprintf( __( "Enable the bot challenge for this form (provider: %s)", "trinity-forms" ), $captcha_label ) );
                            ?>
                        </label>
                        <?php if ( ! $has_turnstile_keys ) : ?>
                            <p class="description"><?php
                                printf(
                                    /* translators: %s = link to settings page */
                                    esc_html__( "Add the CAPTCHA provider's site + secret keys in %s first.", "trinity-forms" ),
                                    '<a href="' . esc_url( admin_url( "edit.php?post_type=trinity_forms_form&page=" . Admin::SETTINGS ) ) . '">' . esc_html__( "Trinity Forms Settings", "trinity-forms" ) . '</a>'
                                );
                            ?></p>
                        <?php endif; ?>
                    </td></tr>
                <tr><th><?php esc_html_e( "Retention (days)", "trinity-forms" ); ?></th>
                    <td><input type="number" min="0" name="trinity-forms[settings][retention_days]" value="<?php echo esc_attr( (string) ( $settings["retention_days"] ?? 0 ) ); ?>" />
                    <p class="description"><?php esc_html_e( "0 = inherit global setting. Entries older than this are deleted by the daily cron.", "trinity-forms" ); ?></p></td></tr>
                <?php
                // Premium General-tab rows (e.g. conversational render mode) are
                // injected here by pro/. Free core adds nothing.
                do_action( "trinity_forms_form_editor_general_rows", $form, $settings );
                ?>
                <?php if ( License::is_locked() ) : ?>
                <tr><th></th><td>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: opening link tag, 2: closing link tag. */
                            esc_html__( "Your Trinity Forms Pro license is not active, so new Pro features can't be added to this form. Everything it already uses keeps working for your visitors, and you can still change it. %1\$sRenew or enter your license key in Settings%2\$s.", "trinity-forms" ),
                            '<a href="' . esc_url( admin_url( \TrinityForms\Admin::PARENT . "&page=" . \TrinityForms\Admin::SETTINGS ) ) . '">',
                            '</a>'
                        );
                        ?>
                    </p>
                </td></tr>
                <?php elseif ( ! License::is_pro() && License::pro_installed() ) : ?>
                <tr><th></th><td>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: opening link tag, 2: closing link tag. */
                            esc_html__( "Pro is installed but not activated. %1\$sEnter your license key in Settings%2\$s to turn on file uploads, payments, integrations and the other Pro features.", "trinity-forms" ),
                            '<a href="' . esc_url( admin_url( \TrinityForms\Admin::PARENT . "&page=" . \TrinityForms\Admin::SETTINGS ) ) . '">',
                            '</a>'
                        );
                        ?>
                    </p>
                </td></tr>
                <?php elseif ( ! License::is_pro() ) : ?>
                    <?php // One restrained line at the point of intent — someone configuring a
                          // form is exactly who wants to know payments and uploads exist. This is
                          // not the disabled/teaser UI the policy above rejects: nothing is
                          // padlocked, nothing pretends to be clickable. It is a table of
                          // contents, and without it a free user can finish their whole
                          // lifecycle never learning Pro exists. ?>
                <tr><th></th><td>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: opening link tag, 2: closing link tag. */
                            esc_html__( "Need signatures, payments or save and continue? %1\$sSee what Pro adds%2\$s.", "trinity-forms" ),
                            '<a href="' . esc_url( admin_url( \TrinityForms\Admin::PARENT . "&page=trinity-forms-upgrade&src=form-editor" ) ) . '">',
                            '</a>'
                        );
                        ?>
                    </p>
                </td></tr>
                <?php endif; ?>
            </table>
            <p class="description"><?php
                printf(
                    /* translators: %s: the embed shortcode, wrapped in a <code> tag. */
                    esc_html__( "Embed shortcode: %s", "trinity-forms" ),
                    '<code>[trinity-forms id="' . (int) $post->ID . '"]</code>'
                );
            ?></p>
            <?php if ( current_user_can( "edit_pages" ) && "auto-draft" !== $post->post_status ) : ?>
                <p><a class="button" href="<?php echo esc_url( Onboarding::create_page_url( (int) $post->ID ) ); ?>"><?php esc_html_e( "Create a page with this form", "trinity-forms" ); ?></a>
                <span class="description"><?php esc_html_e( "Makes a draft page holding this form and opens it. Nothing goes live until you publish it.", "trinity-forms" ); ?></span></p>
            <?php endif; ?>
        </div>

        <div class="trinity-forms-tab-panel" data-panel="notifications">
            <p class="description"><?php esc_html_e( "Send an email each time the form is submitted.", "trinity-forms" ); ?></p>
            <?php
            // Field ids are generated (e.g. text_a1b2c3) and are otherwise only
            // visible one block at a time in the editor sidebar, so "use {field_id}"
            // reads as a literal tag and everyone guesses something like
            // {field_name}. List this form's real tags instead of describing them.
            ?>
            <p class="description">
                <?php esc_html_e( "Merge tags for this form — click to copy:", "trinity-forms" ); ?>
                <span class="trinity-forms-tags">
                    <?php foreach ( $fields_options as $fid => $flabel ) : ?>
                        <button type="button" class="trinity-forms-tag" data-tag="<?php echo esc_attr( "{" . $fid . "}" ); ?>" title="<?php echo esc_attr( $flabel ); ?>"><?php echo esc_html( "{" . $fid . "}" ); ?></button>
                    <?php endforeach; ?>
                    <?php foreach ( [ "all_fields", "site_name", "site_url", "entry_id", "date" ] as $core ) : ?>
                        <button type="button" class="trinity-forms-tag" data-tag="<?php echo esc_attr( "{" . $core . "}" ); ?>"><?php echo esc_html( "{" . $core . "}" ); ?></button>
                    <?php endforeach; ?>
                </span>
                <?php if ( ! $fields_options ) : ?>
                    <em><?php esc_html_e( "Add fields to the form and save it to see their tags here.", "trinity-forms" ); ?></em>
                <?php endif; ?>
            </p>
            <div class="trinity-forms-notifications" data-template="notification">
                <?php foreach ( $notifs as $i => $n ) : self::render_notification_card( $i, $n, $fields_options ); endforeach; ?>
            </div>
            <p><button type="button" class="button" data-add="notification"><?php esc_html_e( "+ Add notification", "trinity-forms" ); ?></button></p>
        </div>

        <div class="trinity-forms-tab-panel" data-panel="confirmations">
            <p class="description">
                <?php esc_html_e( "What the visitor sees after submitting. The first confirmation whose conditions match wins; if none do, the default on the General tab is used.", "trinity-forms" ); ?>
            </p>
            <div class="trinity-forms-confirmations" data-template="confirmation">
                <?php
                $confirmations = (array) ( $settings["confirmations"] ?? [] );
                foreach ( $confirmations as $ci => $c ) {
                    self::render_confirmation_card( (int) $ci, (array) $c, $fields_options );
                }
                ?>
            </div>
            <p><button type="button" class="button" data-add="confirmation"><?php esc_html_e( "+ Add confirmation", "trinity-forms" ); ?></button></p>

            <h3><?php esc_html_e( "Suspected spam", "trinity-forms" ); ?></h3>
            <table class="form-table">
                <tr><th><?php esc_html_e( "Message", "trinity-forms" ); ?></th>
                    <td>
                        <input type="text" class="large-text" name="trinity-forms[settings][spam_confirmation_message]" value="<?php echo esc_attr( (string) ( $settings["spam_confirmation_message"] ?? "" ) ); ?>" placeholder="<?php esc_attr_e( "Leave blank to show the normal confirmation", "trinity-forms" ); ?>" />
                        <p class="description">
                            <?php esc_html_e( "Blank is the safer default: telling a bot it was caught helps it tune around the filter, and telling a wrongly-flagged person the same thing helps nobody. The submission is kept either way — look under Entries → Spam.", "trinity-forms" ); ?>
                        </p>
                    </td></tr>
            </table>
        </div>

        <div class="trinity-forms-tab-panel" data-panel="layout">
            <p class="description"><?php esc_html_e( "How the form looks. Every setting here is a default — a field that makes its own choice keeps it.", "trinity-forms" ); ?></p>

            <table class="form-table">
                <?php
                $tf_style_preset = (string) ( $settings["style_preset"] ?? "default" );
                $tf_style_accent = (string) ( $settings["style_accent"] ?? "" );
                ?>
                <tr><th><?php esc_html_e( "Style", "trinity-forms" ); ?></th>
                    <td>
                        <select name="trinity-forms[settings][style_preset]" class="tf-style-input" data-tf-style="preset">
                            <?php foreach ( Form_Style::preset_labels() as $tf_pk => $tf_pl ) : ?>
                                <option value="<?php echo esc_attr( (string) $tf_pk ); ?>" <?php selected( $tf_style_preset, (string) $tf_pk ); ?>><?php echo esc_html( (string) $tf_pl ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td></tr>
                <tr><th><?php esc_html_e( "Accent colour", "trinity-forms" ); ?></th>
                    <td>
                        <input type="color" name="trinity-forms[settings][style_accent]" class="tf-style-input" data-tf-style="--trinity-forms-accent" value="<?php echo esc_attr( "" !== $tf_style_accent ? $tf_style_accent : Form_Style::DEFAULT_ACCENT ); ?>" data-default="<?php echo esc_attr( Form_Style::DEFAULT_ACCENT ); ?>" />
                        <button type="button" class="button-link" data-tf-style-reset><?php esc_html_e( "Reset", "trinity-forms" ); ?></button>
                        <p class="description"><?php esc_html_e( "Used for the button, the focus ring and ticked boxes.", "trinity-forms" ); ?></p>
                    </td></tr>
                <?php
                // Pro adds finer controls (colours, borders, spacing, text size) here.
                do_action( "trinity_forms_form_editor_style_rows", $form, $settings );
                ?>
                <tr><th><?php esc_html_e( "Preview", "trinity-forms" ); ?></th>
                    <td>
                        <?php
                        $tf_preview_classes = trim( "trinity-forms-form tf-style-preview " . ( $form ? Form_Style::css_class( $form ) : "" ) );
                        $tf_preview_style   = $form ? Form_Style::inline( $form ) : "";
                        ?>
                        <div class="<?php echo esc_attr( $tf_preview_classes ); ?>" style="<?php echo esc_attr( $tf_preview_style ); ?>" aria-hidden="true">
                            <div class="trinity-forms-field"><label><?php esc_html_e( "Your name", "trinity-forms" ); ?></label><input type="text" value="" tabindex="-1" /></div>
                            <div class="trinity-forms-field"><label class="trinity-forms-checkbox"><input type="checkbox" checked tabindex="-1" /> <?php esc_html_e( "Send me a copy", "trinity-forms" ); ?></label></div>
                            <div class="trinity-forms-actions"><button type="button" class="trinity-forms-submit" tabindex="-1"><?php esc_html_e( "Submit", "trinity-forms" ); ?></button></div>
                        </div>
                        <p class="description"><?php esc_html_e( "A rough preview. Your theme's fonts and colours also apply on the real page.", "trinity-forms" ); ?></p>
                    </td></tr>

                <tr><th><?php esc_html_e( "Labels", "trinity-forms" ); ?></th>
                    <td>
                        <?php $tf_lp = (string) ( $settings["label_placement"] ?? "top" ); ?>
                        <select name="trinity-forms[settings][label_placement]">
                            <option value="top"    <?php selected( $tf_lp, "top" ); ?>><?php esc_html_e( "Above the field", "trinity-forms" ); ?></option>
                            <option value="left"   <?php selected( $tf_lp, "left" ); ?>><?php esc_html_e( "To the left", "trinity-forms" ); ?></option>
                            <option value="right"  <?php selected( $tf_lp, "right" ); ?>><?php esc_html_e( "To the right", "trinity-forms" ); ?></option>
                            <option value="hidden" <?php selected( $tf_lp, "hidden" ); ?>><?php esc_html_e( "Hidden (use placeholders)", "trinity-forms" ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( "Hidden keeps the label for screen readers — it is only removed visually.", "trinity-forms" ); ?></p>
                    </td></tr>

                <tr><th><?php esc_html_e( "Help text", "trinity-forms" ); ?></th>
                    <td>
                        <?php $tf_dp = (string) ( $settings["description_placement"] ?? "below" ); ?>
                        <select name="trinity-forms[settings][description_placement]">
                            <option value="below" <?php selected( $tf_dp, "below" ); ?>><?php esc_html_e( "Below the field", "trinity-forms" ); ?></option>
                            <option value="above" <?php selected( $tf_dp, "above" ); ?>><?php esc_html_e( "Above the field", "trinity-forms" ); ?></option>
                        </select>
                    </td></tr>

                <tr><th><?php esc_html_e( "Sub-labels", "trinity-forms" ); ?></th>
                    <td>
                        <?php $tf_sp = (string) ( $settings["sub_label_placement"] ?? "below" ); ?>
                        <select name="trinity-forms[settings][sub_label_placement]">
                            <option value="below"  <?php selected( $tf_sp, "below" ); ?>><?php esc_html_e( "Below the input", "trinity-forms" ); ?></option>
                            <option value="above"  <?php selected( $tf_sp, "above" ); ?>><?php esc_html_e( "Above the input", "trinity-forms" ); ?></option>
                            <option value="hidden" <?php selected( $tf_sp, "hidden" ); ?>><?php esc_html_e( "Hidden", "trinity-forms" ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( "The \"First\" and \"Last\" under a name field, or the parts of an address.", "trinity-forms" ); ?></p>
                    </td></tr>

                <tr><th><?php esc_html_e( "Required fields", "trinity-forms" ); ?></th>
                    <td>
                        <?php $tf_ri = (string) ( $settings["required_indicator"] ?? "asterisk" ); ?>
                        <select name="trinity-forms[settings][required_indicator]">
                            <option value="asterisk" <?php selected( $tf_ri, "asterisk" ); ?>><?php esc_html_e( "Mark required with *", "trinity-forms" ); ?></option>
                            <option value="text"     <?php selected( $tf_ri, "text" ); ?>><?php esc_html_e( "Mark required with \"(required)\"", "trinity-forms" ); ?></option>
                            <option value="optional" <?php selected( $tf_ri, "optional" ); ?>><?php esc_html_e( "Mark the optional ones instead", "trinity-forms" ); ?></option>
                            <option value="none"     <?php selected( $tf_ri, "none" ); ?>><?php esc_html_e( "Do not mark either", "trinity-forms" ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( "On a form where nearly everything is required, marking the optional ones reads better.", "trinity-forms" ); ?></p>
                    </td></tr>

                <tr><th><?php esc_html_e( "Errors", "trinity-forms" ); ?></th>
                    <td>
                        <?php $tf_vp = (string) ( $settings["validation_placement"] ?? "below" ); ?>
                        <select name="trinity-forms[settings][validation_placement]">
                            <option value="below" <?php selected( $tf_vp, "below" ); ?>><?php esc_html_e( "Below the field", "trinity-forms" ); ?></option>
                            <option value="above" <?php selected( $tf_vp, "above" ); ?>><?php esc_html_e( "Above the field", "trinity-forms" ); ?></option>
                        </select>
                        <br>
                        <label style="display:inline-block;margin-top:.5rem">
                            <input type="hidden" name="trinity-forms[settings][validation_summary]" value="0" />
                            <input type="checkbox" name="trinity-forms[settings][validation_summary]" value="1" <?php checked( ! empty( $settings["validation_summary"] ) ); ?> />
                            <?php esc_html_e( "Also list every problem at the top of the form", "trinity-forms" ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( "Worth turning on for a long form, where the inline errors can all be below the fold and a failed submit looks like nothing happened.", "trinity-forms" ); ?></p>
                    </td></tr>

                <tr><th><?php esc_html_e( "Form CSS class", "trinity-forms" ); ?></th>
                    <td><input type="text" name="trinity-forms[settings][form_css_class]" value="<?php echo esc_attr( (string) ( $settings["form_css_class"] ?? "" ) ); ?>" /></td></tr>
            </table>

            <h3><?php esc_html_e( "Submit button", "trinity-forms" ); ?></h3>
            <table class="form-table">
                <tr><th><?php esc_html_e( "Image", "trinity-forms" ); ?></th>
                    <td>
                        <input type="text" class="large-text" name="trinity-forms[settings][submit_button_image]" value="<?php echo esc_attr( (string) ( $settings["submit_button_image"] ?? "" ) ); ?>" placeholder="https://…" />
                        <p class="description"><?php esc_html_e( "Leave blank for a normal button. The button text above is used as the image's alt text.", "trinity-forms" ); ?></p>
                    </td></tr>

                <tr><th><?php esc_html_e( "Only show when", "trinity-forms" ); ?></th>
                    <td>
                        <?php
                        $tf_sc    = (array) ( $settings["submit_conditional"] ?? [] );
                        $tf_sc_c  = (array) ( $tf_sc["conditions"] ?? [] );
                        $tf_first = (array) ( $tf_sc_c[0] ?? [] );
                        ?>
                        <div class="trinity-forms-inline-rule">
                            <select name="trinity-forms[settings][submit_conditional][conditions][0][field]">
                                <option value=""><?php esc_html_e( "— always show it —", "trinity-forms" ); ?></option>
                                <?php foreach ( $fields_options as $tf_fid => $tf_flabel ) : ?>
                                    <option value="<?php echo esc_attr( $tf_fid ); ?>" <?php selected( (string) ( $tf_first["field"] ?? "" ), (string) $tf_fid ); ?>>
                                        <?php echo esc_html( $tf_flabel ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="trinity-forms[settings][submit_conditional][conditions][0][operator]">
                                <?php foreach ( Conditional_Logic::operators() as $tf_op => $tf_meta ) : ?>
                                    <option value="<?php echo esc_attr( $tf_op ); ?>" <?php selected( (string) ( $tf_first["operator"] ?? "equals" ), $tf_op ); ?>>
                                        <?php echo esc_html( $tf_meta["label"] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="trinity-forms[settings][submit_conditional][conditions][0][value]" value="<?php echo esc_attr( (string) ( $tf_first["value"] ?? "" ) ); ?>" />
                        </div>
                        <p class="description"><?php esc_html_e( "Useful for a consent checkbox that has to be ticked before the form can be sent.", "trinity-forms" ); ?></p>
                    </td></tr>
            </table>
        </div>

        <div class="trinity-forms-tab-panel" data-panel="restrictions">
            <p class="description"><?php esc_html_e( "Control when the form accepts submissions, and how many it takes. Leave a field blank or at 0 to leave that rule off.", "trinity-forms" ); ?></p>

            <h3><?php esc_html_e( "Schedule", "trinity-forms" ); ?></h3>
            <table class="form-table">
                <tr><th><?php esc_html_e( "Opens", "trinity-forms" ); ?></th>
                    <td>
                        <input type="datetime-local" name="trinity-forms[settings][schedule_start]" value="<?php echo esc_attr( self::to_local_input( $settings["schedule_start"] ?? "" ) ); ?>" />
                        <p class="description"><?php esc_html_e( "Before this, visitors see the message below instead of the form. Uses your site's timezone.", "trinity-forms" ); ?></p>
                    </td></tr>
                <tr><th><?php esc_html_e( "Message before it opens", "trinity-forms" ); ?></th>
                    <td><input type="text" class="large-text" name="trinity-forms[settings][schedule_pending_message]" value="<?php echo esc_attr( (string) ( $settings["schedule_pending_message"] ?? "" ) ); ?>" placeholder="<?php esc_attr_e( "This form is not open yet.", "trinity-forms" ); ?>" /></td></tr>
                <tr><th><?php esc_html_e( "Closes", "trinity-forms" ); ?></th>
                    <td><input type="datetime-local" name="trinity-forms[settings][schedule_end]" value="<?php echo esc_attr( self::to_local_input( $settings["schedule_end"] ?? "" ) ); ?>" /></td></tr>
                <tr><th><?php esc_html_e( "Message after it closes", "trinity-forms" ); ?></th>
                    <td><input type="text" class="large-text" name="trinity-forms[settings][schedule_closed_message]" value="<?php echo esc_attr( (string) ( $settings["schedule_closed_message"] ?? "" ) ); ?>" placeholder="<?php esc_attr_e( "This form is now closed.", "trinity-forms" ); ?>" /></td></tr>
            </table>

            <h3><?php esc_html_e( "Entry limits", "trinity-forms" ); ?></h3>
            <table class="form-table">
                <tr><th><?php esc_html_e( "Total entries", "trinity-forms" ); ?></th>
                    <td>
                        <input type="number" min="0" name="trinity-forms[settings][entry_limit]" value="<?php echo esc_attr( (string) ( $settings["entry_limit"] ?? 0 ) ); ?>" />
                        <p class="description"><?php esc_html_e( "0 = unlimited. Spam and trashed entries do not count towards the limit.", "trinity-forms" ); ?></p>
                    </td></tr>
                <tr><th><?php esc_html_e( "Per logged-in user", "trinity-forms" ); ?></th>
                    <td><input type="number" min="0" name="trinity-forms[settings][entry_limit_per_user]" value="<?php echo esc_attr( (string) ( $settings["entry_limit_per_user"] ?? 0 ) ); ?>" /></td></tr>
                <tr><th><?php esc_html_e( "Per IP address", "trinity-forms" ); ?></th>
                    <td>
                        <input type="number" min="0" name="trinity-forms[settings][entry_limit_per_ip]" value="<?php echo esc_attr( (string) ( $settings["entry_limit_per_ip"] ?? 0 ) ); ?>" />
                        <p class="description"><?php esc_html_e( "Blunt: everyone behind one office router or mobile network shares an address.", "trinity-forms" ); ?></p>
                    </td></tr>
                <tr><th><?php esc_html_e( "Message when full", "trinity-forms" ); ?></th>
                    <td><input type="text" class="large-text" name="trinity-forms[settings][entry_limit_message]" value="<?php echo esc_attr( (string) ( $settings["entry_limit_message"] ?? "" ) ); ?>" placeholder="<?php esc_attr_e( "This form is no longer accepting submissions.", "trinity-forms" ); ?>" /></td></tr>
                <tr><th><?php esc_html_e( "Message when you've already submitted", "trinity-forms" ); ?></th>
                    <td><input type="text" class="large-text" name="trinity-forms[settings][entry_limit_user_message]" value="<?php echo esc_attr( (string) ( $settings["entry_limit_user_message"] ?? "" ) ); ?>" placeholder="<?php esc_attr_e( "You have already submitted this form.", "trinity-forms" ); ?>" /></td></tr>
            </table>

            <h3><?php esc_html_e( "Who can submit", "trinity-forms" ); ?></h3>
            <table class="form-table">
                <tr><th><?php esc_html_e( "Require login", "trinity-forms" ); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="trinity-forms[settings][require_login]" value="0" />
                            <input type="checkbox" name="trinity-forms[settings][require_login]" value="1" <?php checked( ! empty( $settings["require_login"] ) ); ?> />
                            <?php esc_html_e( "Only logged-in users may see and submit this form", "trinity-forms" ); ?>
                        </label>
                    </td></tr>
                <tr><th><?php esc_html_e( "Message for logged-out visitors", "trinity-forms" ); ?></th>
                    <td><input type="text" class="large-text" name="trinity-forms[settings][login_required_message]" value="<?php echo esc_attr( (string) ( $settings["login_required_message"] ?? "" ) ); ?>" placeholder="<?php esc_attr_e( "Please log in to fill in this form.", "trinity-forms" ); ?>" /></td></tr>
            </table>
        </div>

        <?php
        // Premium tab panels (Payment, Integrations) are injected by pro/. The
        // saved payment/integration config is passed through so pro/ can prefill.
        do_action( "trinity_forms_form_editor_panels", [
            "form"           => $form,
            "payment"        => $payment,
            "integrations"   => $integr,
            "fields_options" => $fields_options,
            // Field id => type, so pickers can offer only fields that fit (a
            // signature is not a payment amount).
            "fields_types"   => self::field_types( (int) $post->ID ),
        ] );
        ?>

        <?php
    }

    /**
     * A form's fields as a picker list, used by the notification cards and the
     * merge-tag hints.
     *
     * @return array<string,string> field id => "Label (type)"
     */
    /** @return array<string,string> field id => type */
    public static function field_types( int $form_id ): array {
        $form = $form_id ? Form_Manager::get( $form_id ) : null;
        $out  = [];
        if ( $form ) {
            foreach ( $form->fields() as $f ) {
                $out[ $f->id ] = (string) $f->type;
            }
        }
        return $out;
    }

    private static function field_options( int $form_id ): array {
        $form = $form_id ? Form_Manager::get( $form_id ) : null;
        if ( ! $form ) {
            return [];
        }
        $out = [];
        foreach ( $form->fields() as $f ) {
            // Layout blocks hold no answer: no merge tag, nothing to route or match on.
            if ( in_array( $f->type, self::NON_ANSWER_TYPES, true ) ) {
                continue;
            }
            $out[ $f->id ] = ( $f->label ?: $f->id ) . " ({$f->type})";
        }
        return $out;
    }

    /**
     * @param array<string,mixed>  $n
     * @param array<string,string> $fields_options Field id => label, for routing.
     */
    private static function render_notification_card( int $index, array $n, array $fields_options = [] ): void {
        $enabled = ! isset( $n["enabled"] ) || ! empty( $n["enabled"] );
        $to_type = "field" === ( $n["to_type"] ?? "email" ) ? "field" : "email";
        ?>
        <div class="trinity-forms-list-card">
            <label><?php esc_html_e( "Name", "trinity-forms" ); ?></label>
            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][name]" value="<?php echo esc_attr( $n["name"] ?? "" ); ?>" />

            <label>
                <input type="hidden" name="trinity-forms[notifications][<?php echo (int) $index; ?>][enabled]" value="0" />
                <input type="checkbox" name="trinity-forms[notifications][<?php echo (int) $index; ?>][enabled]" value="1" <?php checked( $enabled ); ?> />
                <?php esc_html_e( "Enabled", "trinity-forms" ); ?>
            </label>

            <label><?php esc_html_e( "Send when", "trinity-forms" ); ?></label>
            <select name="trinity-forms[notifications][<?php echo (int) $index; ?>][event]">
                <?php
                $event_labels = [
                    "submission"        => __( "The form is submitted", "trinity-forms" ),
                    "payment_completed" => __( "A payment completes", "trinity-forms" ),
                    "entry_approved"    => __( "An entry is approved", "trinity-forms" ),
                    "entry_edited"      => __( "An entry is edited", "trinity-forms" ),
                ];
                $current_event = (string) ( $n["event"] ?? "submission" );
                foreach ( self::NOTIFICATION_EVENTS as $event_key ) :
                    // Payments and approval are Pro features: on Free (or a Pro install
                    // that was never licensed) these triggers can never fire, so they
                    // are not offered — unless a form already uses one, which stays
                    // visible. Once licensed they keep firing, so they stay offered.
                    if ( in_array( $event_key, [ "payment_completed", "entry_approved" ], true ) && ! License::is_pro_runtime() && $current_event !== $event_key ) {
                        continue;
                    }
                    ?>
                    <option value="<?php echo esc_attr( $event_key ); ?>" <?php selected( (string) ( $n["event"] ?? "submission" ), $event_key ); ?>>
                        <?php echo esc_html( $event_labels[ $event_key ] ?? $event_key ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label><?php esc_html_e( "Send to", "trinity-forms" ); ?></label>
            <select name="trinity-forms[notifications][<?php echo (int) $index; ?>][to_type]">
                <option value="email" <?php selected( $to_type, "email" ); ?>><?php esc_html_e( "These addresses", "trinity-forms" ); ?></option>
                <option value="field" <?php selected( $to_type, "field" ); ?>><?php esc_html_e( "An email field on the form", "trinity-forms" ); ?></option>
            </select>

            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][to]" value="<?php echo esc_attr( $n["to"] ?? "" ); ?>" placeholder="admin@example.com, someone@example.com" />

            <?php // Routing by field is how an autoresponder is built; before this you
                  // had to know that {email} happened to be the field's id. ?>
            <select name="trinity-forms[notifications][<?php echo (int) $index; ?>][to_field]">
                <option value=""><?php esc_html_e( "— choose a field —", "trinity-forms" ); ?></option>
                <?php foreach ( $fields_options as $tf_fid => $tf_flabel ) : ?>
                    <option value="<?php echo esc_attr( $tf_fid ); ?>" <?php selected( (string) ( $n["to_field"] ?? "" ), (string) $tf_fid ); ?>>
                        <?php echo esc_html( $tf_flabel ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label><?php esc_html_e( "Cc", "trinity-forms" ); ?></label>
            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][cc]" value="<?php echo esc_attr( $n["cc"] ?? "" ); ?>" />

            <label><?php esc_html_e( "Bcc", "trinity-forms" ); ?></label>
            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][bcc]" value="<?php echo esc_attr( $n["bcc"] ?? "" ); ?>" />

            <label><?php esc_html_e( "Reply-To", "trinity-forms" ); ?></label>
            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][reply_to]" value="<?php echo esc_attr( $n["reply_to"] ?? "" ); ?>" placeholder="{your_email_field}" />
            <p class="description"><?php esc_html_e( "Where your reply goes when you hit Reply. Put the tag of the form's email field here to answer the sender directly.", "trinity-forms" ); ?></p>

            <label><?php esc_html_e( "From name", "trinity-forms" ); ?></label>
            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][from_name]" value="<?php echo esc_attr( $n["from_name"] ?? "" ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( "name" ) ); ?>" />

            <label><?php esc_html_e( "From email", "trinity-forms" ); ?></label>
            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][from_email]" value="<?php echo esc_attr( $n["from_email"] ?? "" ); ?>" />
            <p class="description"><?php esc_html_e( "Leave blank. Sending as the visitor's address makes your notification fail SPF checks and land in spam — use Reply-To above instead.", "trinity-forms" ); ?></p>

            <label><?php esc_html_e( "Subject", "trinity-forms" ); ?></label>
            <input type="text" name="trinity-forms[notifications][<?php echo (int) $index; ?>][subject]" value="<?php echo esc_attr( $n["subject"] ?? "" ); ?>" />

            <label><?php esc_html_e( "Body", "trinity-forms" ); ?></label>
            <textarea name="trinity-forms[notifications][<?php echo (int) $index; ?>][body]" rows="6"><?php echo esc_textarea( $n["body"] ?? "" ); ?></textarea>

            <label>
                <input type="hidden" name="trinity-forms[notifications][<?php echo (int) $index; ?>][attach_files]" value="0" />
                <input type="checkbox" name="trinity-forms[notifications][<?php echo (int) $index; ?>][attach_files]" value="1" <?php checked( ! empty( $n["attach_files"] ) ); ?> />
                <?php esc_html_e( "Attach any files uploaded with the submission", "trinity-forms" ); ?>
            </label>

            <div class="trinity-forms-row-actions">
                <?php // Sends to the first address typed above (saved or not), or to you when the card routes to a form field. ?>
                <button type="button" class="button-link" data-test-email="notification"><?php esc_html_e( "Send a test", "trinity-forms" ); ?></button>
                <button type="button" class="button-link" data-duplicate="notification"><?php esc_html_e( "Duplicate", "trinity-forms" ); ?></button>
                <button type="button" class="button-link-delete" data-remove="notification"><?php esc_html_e( "Remove", "trinity-forms" ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * One conditional confirmation.
     *
     * @param array<string,mixed>  $c
     * @param array<string,string> $fields_options
     */
    private static function render_confirmation_card( int $index, array $c, array $fields_options = [] ): void {
        $type      = (string) ( $c["type"] ?? Confirmations::TYPE_TEXT );
        $cond      = (array) ( $c["conditional"] ?? [] );
        $conds     = (array) ( $cond["conditions"] ?? [] );
        $first     = (array) ( $conds[0] ?? [] );
        $name_attr = "trinity-forms[confirmations][" . $index . "]";
        ?>
        <div class="trinity-forms-list-card">
            <label><?php esc_html_e( "Name", "trinity-forms" ); ?></label>
            <input type="text" name="<?php echo esc_attr( $name_attr ); ?>[name]" value="<?php echo esc_attr( (string) ( $c["name"] ?? "" ) ); ?>" placeholder="<?php esc_attr_e( "Enterprise enquiries", "trinity-forms" ); ?>" />

            <label><?php esc_html_e( "Then show", "trinity-forms" ); ?></label>
            <select name="<?php echo esc_attr( $name_attr ); ?>[type]">
                <option value="text"     <?php selected( $type, Confirmations::TYPE_TEXT ); ?>><?php esc_html_e( "A message", "trinity-forms" ); ?></option>
                <option value="page"     <?php selected( $type, Confirmations::TYPE_PAGE ); ?>><?php esc_html_e( "A page on this site", "trinity-forms" ); ?></option>
                <option value="redirect" <?php selected( $type, Confirmations::TYPE_REDIRECT ); ?>><?php esc_html_e( "Another URL", "trinity-forms" ); ?></option>
            </select>

            <label><?php esc_html_e( "Message", "trinity-forms" ); ?></label>
            <textarea name="<?php echo esc_attr( $name_attr ); ?>[message]" rows="3"><?php echo esc_textarea( (string) ( $c["message"] ?? "" ) ); ?></textarea>

            <label><?php esc_html_e( "Page", "trinity-forms" ); ?></label>
            <?php
            wp_dropdown_pages( [
                "name"              => $name_attr . "[page_id]",
                "selected"          => (int) ( $c["page_id"] ?? 0 ),
                "show_option_none"  => __( "— select —", "trinity-forms" ),
                "option_none_value" => 0,
            ] );
            ?>

            <label><?php esc_html_e( "URL", "trinity-forms" ); ?></label>
            <input type="text" name="<?php echo esc_attr( $name_attr ); ?>[url]" value="<?php echo esc_attr( (string) ( $c["url"] ?? "" ) ); ?>" placeholder="https://…" />

            <label><?php esc_html_e( "Query string", "trinity-forms" ); ?></label>
            <input type="text" name="<?php echo esc_attr( $name_attr ); ?>[query_string]" value="<?php echo esc_attr( (string) ( $c["query_string"] ?? "" ) ); ?>" placeholder="utm_source=form&amp;name={name}" />
            <p class="description"><?php esc_html_e( "Appended to the page or URL above. Merge tags are resolved first.", "trinity-forms" ); ?></p>

            <?php // A single condition row covers the overwhelming case ("if plan is
                  // enterprise"); the full rule editor lives on fields, and putting a
                  // second copy of it here would be a lot of UI for a rare need. ?>
            <label><?php esc_html_e( "Only when", "trinity-forms" ); ?></label>
            <div class="trinity-forms-inline-rule">
                <select name="<?php echo esc_attr( $name_attr ); ?>[conditional][conditions][0][field]">
                    <option value=""><?php esc_html_e( "— always (use the default instead) —", "trinity-forms" ); ?></option>
                    <?php foreach ( $fields_options as $tf_fid => $tf_flabel ) : ?>
                        <option value="<?php echo esc_attr( $tf_fid ); ?>" <?php selected( (string) ( $first["field"] ?? "" ), (string) $tf_fid ); ?>>
                            <?php echo esc_html( $tf_flabel ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="<?php echo esc_attr( $name_attr ); ?>[conditional][conditions][0][operator]">
                    <?php
                    // The same operators field and submit-button logic offer; the
                    // confirmation evaluator runs them through Conditional_Logic too.
                    $ops = array_map( static fn( $op ) => (string) $op["label"], Conditional_Logic::operators() );
                    foreach ( $ops as $op_key => $op_label ) :
                        ?>
                        <option value="<?php echo esc_attr( $op_key ); ?>" <?php selected( (string) ( $first["operator"] ?? "equals" ), $op_key ); ?>>
                            <?php echo esc_html( $op_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="<?php echo esc_attr( $name_attr ); ?>[conditional][conditions][0][value]" value="<?php echo esc_attr( (string) ( $first["value"] ?? "" ) ); ?>" />
            </div>

            <div class="trinity-forms-row-actions">
                <button type="button" class="button-link-delete" data-remove="confirmation"><?php esc_html_e( "Remove", "trinity-forms" ); ?></button>
            </div>
        </div>
        <?php
    }

    private static function confirmation_template_html( array $fields_options = [] ): string {
        ob_start();
        self::render_confirmation_card( -1, [], $fields_options );
        $html = (string) ob_get_clean();
        return str_replace( "[-1]", "[__INDEX__]", $html );
    }

    private static function notification_template_html( array $fields_options = [] ): string {
        ob_start();
        self::render_notification_card( -1, [], $fields_options );
        $html = (string) ob_get_clean();
        return str_replace( "[-1]", "[__INDEX__]", $html );
    }

    public static function save( int $post_id, \WP_Post $post ): void {
        if ( $post->post_type !== "trinity_forms_form" ) { return; }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
        if ( ! current_user_can( "edit_post", $post_id ) ) { return; }
        if ( empty( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) { return; }
        if ( empty( $_POST["trinity-forms"] ) || ! is_array( $_POST["trinity-forms"] ) ) { return; }

        // Nonce verified above; the raw array is deeply sanitized by sanitize_settings()/sanitize_notifications() below.
        $raw = wp_unslash( $_POST["trinity-forms"] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array sanitized recursively downstream.

        $existing = get_post_meta( $post_id, Form::SETTINGS_META, true );
        $existing = is_array( $existing ) ? $existing : [];

        $meta = [
            "settings"      => self::sanitize_settings( $raw["settings"] ?? [], $existing["settings"] ?? [] ),
            "notifications" => self::sanitize_notifications( $raw["notifications"] ?? [] ),
        ];

        // Confirmations live inside settings so Form::get_setting() reaches them and
        // no new storage surface is introduced for one list.
        $meta["settings"]["confirmations"] = Confirmations::sanitize( $raw["confirmations"] ?? [] );

        // Preserve any premium sections (payment, integrations) that pro/ owns so
        // saving from a free install doesn't wipe a previously-configured Pro form.
        foreach ( [ "payment", "integrations" ] as $section ) {
            if ( isset( $existing[ $section ] ) ) {
                $meta[ $section ] = $existing[ $section ];
            }
        }

        // Premium meta sections (payment, integrations) are sanitized + merged by
        // pro/ through this filter. In the free build the meta passes through as-is.
        $meta = (array) apply_filters( "trinity_forms_save_form_meta", $meta, map_deep( $raw, "sanitize_text_field" ), $existing, $post_id );

        update_post_meta( $post_id, Form::SETTINGS_META, $meta );
    }

    private static function sanitize_settings( array $raw, array $existing ): array {
        return array_merge( $existing, [
            "submit_button_text"   => sanitize_text_field( (string) ( $raw["submit_button_text"]   ?? "Submit" ) ),
            "confirmation_message" => sanitize_textarea_field( (string) ( $raw["confirmation_message"] ?? "" ) ),
            "redirect_url"         => esc_url_raw( (string) ( $raw["redirect_url"] ?? "" ) ) ?: null,
            // Absent from the POST means the control was not on the page (Free, or the
            // Pro tools are locked): keep what was saved. Switching it off silently on
            // every save was how a locked site lost save and continue.
            "allow_save_progress"  => array_key_exists( "allow_save_progress", $raw )
                ? ( ! empty( $raw["allow_save_progress"] ) && "0" !== (string) $raw["allow_save_progress"] )
                : ! empty( $existing["allow_save_progress"] ),
            "turnstile"            => ! empty( $raw["turnstile"] ),
            "retention_days"       => max( 0, (int) ( $raw["retention_days"] ?? 0 ) ),

            "spam_confirmation_message" => sanitize_text_field( (string) ( $raw["spam_confirmation_message"] ?? "" ) ),

            // Layout.
            "label_placement"       => in_array( $raw["label_placement"] ?? "", [ "top", "left", "right", "hidden" ], true ) ? (string) $raw["label_placement"] : "top",
            "description_placement" => "above" === ( $raw["description_placement"] ?? "" ) ? "above" : "below",
            "sub_label_placement"   => in_array( $raw["sub_label_placement"] ?? "", [ "below", "above", "hidden" ], true ) ? (string) $raw["sub_label_placement"] : "below",
            "required_indicator"    => in_array( $raw["required_indicator"] ?? "", [ "asterisk", "text", "optional", "none" ], true ) ? (string) $raw["required_indicator"] : "asterisk",
            "validation_placement"  => "above" === ( $raw["validation_placement"] ?? "" ) ? "above" : "below",
            "validation_summary"    => ! empty( $raw["validation_summary"] ),
            "form_css_class"        => sanitize_text_field( (string) ( $raw["form_css_class"] ?? "" ) ),
        ] + Form_Style::sanitize( $raw ) + [
            "submit_button_image"   => esc_url_raw( (string) ( $raw["submit_button_image"] ?? "" ) ),
            "submit_conditional"    => Conditional_Logic::sanitize_rules( $raw["submit_conditional"] ?? [] ),

            // Restrictions.
            "schedule_start"           => self::from_local_input( (string) ( $raw["schedule_start"] ?? "" ) ),
            "schedule_end"             => self::from_local_input( (string) ( $raw["schedule_end"] ?? "" ) ),
            "schedule_pending_message" => sanitize_text_field( (string) ( $raw["schedule_pending_message"] ?? "" ) ),
            "schedule_closed_message"  => sanitize_text_field( (string) ( $raw["schedule_closed_message"] ?? "" ) ),
            "entry_limit"              => max( 0, (int) ( $raw["entry_limit"] ?? 0 ) ),
            "entry_limit_per_user"     => max( 0, (int) ( $raw["entry_limit_per_user"] ?? 0 ) ),
            "entry_limit_per_ip"       => max( 0, (int) ( $raw["entry_limit_per_ip"] ?? 0 ) ),
            "entry_limit_message"      => sanitize_text_field( (string) ( $raw["entry_limit_message"] ?? "" ) ),
            "entry_limit_user_message" => sanitize_text_field( (string) ( $raw["entry_limit_user_message"] ?? "" ) ),
            "require_login"            => ! empty( $raw["require_login"] ),
            "login_required_message"   => sanitize_text_field( (string) ( $raw["login_required_message"] ?? "" ) ),
        ] );
    }

    /**
     * Store a datetime-local value ("2026-08-30T17:00") as the plain "Y-m-d H:i:s"
     * this codebase uses everywhere else.
     *
     * Plain, never ISO-8601: an ISO string with a timezone offset once reached the
     * database through a payment webhook and every date comparison downstream broke.
     */
    private static function from_local_input( string $value ): string {
        $value = trim( $value );
        if ( "" === $value ) {
            return "";
        }
        $ts = strtotime( str_replace( "T", " ", $value ) );
        return $ts ? gmdate( "Y-m-d H:i:s", $ts ) : "";
    }

    /** The inverse: stored "Y-m-d H:i:s" back into what datetime-local expects. */
    private static function to_local_input( string $value ): string {
        $value = trim( $value );
        if ( "" === $value ) {
            return "";
        }
        $ts = strtotime( $value );
        return $ts ? gmdate( "Y-m-d\TH:i", $ts ) : "";
    }

    private static function sanitize_notifications( $raw ): array {
        $out = [];
        foreach ( (array) $raw as $n ) {
            if ( ! is_array( $n ) ) { continue; }
            $to = trim( (string) ( $n["to"] ?? "" ) );
            // A notification routed to a field carries no literal address, so an
            // empty "to" is only a reason to discard the row in address mode.
            $routes_to_field = "field" === ( $n["to_type"] ?? "email" ) && "" !== trim( (string) ( $n["to_field"] ?? "" ) );
            if ( "" === $to && ! $routes_to_field ) { continue; }
            $row = [
                "name"       => sanitize_text_field( (string) ( $n["name"] ?? "" ) ),
                "to"         => sanitize_text_field( $to ),
                "to_type"    => "field" === ( $n["to_type"] ?? "email" ) ? "field" : "email",
                "to_field"   => sanitize_text_field( (string) ( $n["to_field"] ?? "" ) ),
                "cc"         => sanitize_text_field( (string) ( $n["cc"] ?? "" ) ),
                "bcc"        => sanitize_text_field( (string) ( $n["bcc"] ?? "" ) ),
                "reply_to"   => sanitize_text_field( (string) ( $n["reply_to"] ?? "" ) ),
                "from_email" => sanitize_text_field( (string) ( $n["from_email"] ?? "" ) ),
                "from_name"  => sanitize_text_field( (string) ( $n["from_name"] ?? "" ) ),
                "subject"    => sanitize_text_field( (string) ( $n["subject"] ?? "" ) ),
                "body"       => wp_kses_post( (string) ( $n["body"] ?? "" ) ),
                // Absent means enabled: forms saved before this setting existed must
                // keep sending, not go quiet on upgrade.
                "enabled"    => ! isset( $n["enabled"] ) || ! empty( $n["enabled"] ),
                "attach_files" => ! empty( $n["attach_files"] ),
                "event"      => in_array( (string) ( $n["event"] ?? "" ), self::NOTIFICATION_EVENTS, true )
                    ? (string) $n["event"]
                    : "submission",
            ];

            // Persist optional per-notification conditional logic so
            // Notification_Engine::should_send() can honour it. No UI collects this
            // yet — a future builder panel or the trinity_forms_save_form_meta filter
            // can supply it — so we only store a well-formed, whitelisted structure.
            $conditional = self::sanitize_conditional( $n["conditional"] ?? null );
            if ( $conditional ) {
                $row["conditional"] = $conditional;
            }

            $out[] = $row;
        }
        return $out;
    }

    /**
     * Whitelist a conditional-logic block to the shape Conditional_Logic expects
     * (see includes/class-conditional-logic.php). Returns [] when there is nothing
     * usable so callers can simply skip the key.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    /**
     * Delegated to the evaluator's own sanitizer so nested groups survive and the
     * operator list has exactly one definition.
     *
     * This used to restate eight operators, which meant a rule saved as "starts
     * with" came back as "is" — a silent downgrade, worse than a rejection.
     */
    private static function sanitize_conditional( $raw ): array {
        return Conditional_Logic::sanitize_rules( $raw );
    }
}
