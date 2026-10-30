<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Starter form template library. Adds a "Templates" admin submenu listing ready
 * forms; picking one creates a new form whose post_content is Gutenberg block
 * markup, so the new form opens fully editable in the block editor.
 */
class Templates {

    const PAGE   = "trinity-forms-templates";
    const ACTION = "trinity_forms_use_template";

    public static function bootstrap(): void {
        add_action( "admin_menu", [ self::class, "register_menu" ], 9 );
        add_action( "admin_post_" . self::ACTION, [ self::class, "handle_use" ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            Admin::PARENT,
            __( "Templates", "trinity-forms" ),
            __( "Templates", "trinity-forms" ),
            Admin::CAP,
            self::PAGE,
            [ self::class, "render_page" ]
        );
    }

    /** Serialize one field block from [ type, attrs ]. */
    private static function blk( string $type, array $attrs ): string {
        return "<!-- wp:trinity-forms/field-" . $type . " " . serialize_block_attributes( $attrs ) . " /-->";
    }

    /**
     * Choices from [ value => label ]. The label is translated; the value stays the
     * same slug in every language so entries, rules and exports agree across sites.
     *
     * @param array<string, string> $choices
     */
    private static function opts( array $choices ): array {
        $out = [];
        foreach ( $choices as $value => $label ) {
            $out[] = [ "label" => $label, "value" => (string) $value ];
        }
        return $out;
    }

    /** Build the block-markup post_content for a template slug. */
    public static function content_for( string $slug ): string {
        $tpl = self::templates()[ $slug ] ?? null;
        if ( ! $tpl ) {
            return "";
        }
        $out = [];
        foreach ( $tpl["fields"] as $f ) {
            $out[] = self::blk( $f[0], $f[1] );
        }
        return implode( "\n\n", $out ) . "\n";
    }

    /**
     * @return array<string, array{name:string, desc:string, icon:string, fields:array<int, array{0:string,1:array}>}>
     */
    public static function templates(): array {
        $t = [
            "contact" => [ "name" => __( "Contact form", "trinity-forms" ), "desc" => __( "Name, email and a message. The classic.", "trinity-forms" ), "icon" => "email", "fields" => [
                [ "name",     [ "fieldId" => "name",    "label" => __( "Name", "trinity-forms" ),    "required" => true ] ],
                [ "email",    [ "fieldId" => "email",   "label" => __( "Email", "trinity-forms" ),   "required" => true ] ],
                [ "textarea", [ "fieldId" => "message", "label" => __( "Message", "trinity-forms" ), "required" => true ] ],
            ] ],
            "quote" => [ "name" => __( "Quote request", "trinity-forms" ), "desc" => __( "Capture leads with service, budget and details.", "trinity-forms" ), "icon" => "money-alt", "fields" => [
                [ "name",     [ "fieldId" => "name",    "label" => __( "Name", "trinity-forms" ),    "required" => true ] ],
                [ "email",    [ "fieldId" => "email",   "label" => __( "Email", "trinity-forms" ),   "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone",   "label" => __( "Phone", "trinity-forms" ) ] ],
                [ "select",   [ "fieldId" => "service", "label" => __( "Service", "trinity-forms" ), "required" => true, "placeholder" => __( "Choose a service", "trinity-forms" ), "options" => self::opts( [ "web-design" => __( "Web design", "trinity-forms" ), "seo" => __( "SEO", "trinity-forms" ), "consulting" => __( "Consulting", "trinity-forms" ), "other" => __( "Other", "trinity-forms" ) ] ) ] ],
                [ "range",    [ "fieldId" => "budget",  "label" => __( "Budget (USD)", "trinity-forms" ), "min" => 500, "max" => 20000, "step" => 500, "default" => 2500 ] ],
                [ "textarea", [ "fieldId" => "details", "label" => __( "Project details", "trinity-forms" ) ] ],
            ] ],
            "event" => [ "name" => __( "Event registration", "trinity-forms" ), "desc" => __( "Register attendees with a guest count and session.", "trinity-forms" ), "icon" => "tickets-alt", "fields" => [
                [ "name",   [ "fieldId" => "name",    "label" => __( "Name", "trinity-forms" ),  "required" => true ] ],
                [ "email",  [ "fieldId" => "email",   "label" => __( "Email", "trinity-forms" ), "required" => true ] ],
                [ "phone",  [ "fieldId" => "phone",   "label" => __( "Phone", "trinity-forms" ) ] ],
                [ "number", [ "fieldId" => "guests",  "label" => __( "Number attending", "trinity-forms" ), "min" => 1, "max" => 10 ] ],
                [ "select", [ "fieldId" => "session", "label" => __( "Session", "trinity-forms" ), "placeholder" => __( "Pick a session", "trinity-forms" ), "options" => self::opts( [ "morning" => __( "Morning", "trinity-forms" ), "afternoon" => __( "Afternoon", "trinity-forms" ), "full-day" => __( "Full day", "trinity-forms" ) ] ) ] ],
            ] ],
            "appointment" => [ "name" => __( "Appointment booking", "trinity-forms" ), "desc" => __( "Let visitors request a date and time.", "trinity-forms" ), "icon" => "calendar-alt", "fields" => [
                [ "name",     [ "fieldId" => "name",  "label" => __( "Name", "trinity-forms" ),  "required" => true ] ],
                [ "email",    [ "fieldId" => "email", "label" => __( "Email", "trinity-forms" ), "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone", "label" => __( "Phone", "trinity-forms" ) ] ],
                [ "date",     [ "fieldId" => "date",  "label" => __( "Preferred date", "trinity-forms" ), "required" => true ] ],
                [ "time",     [ "fieldId" => "time",  "label" => __( "Preferred time", "trinity-forms" ) ] ],
                [ "textarea", [ "fieldId" => "notes", "label" => __( "Notes", "trinity-forms" ) ] ],
            ] ],
            "rsvp" => [ "name" => __( "RSVP", "trinity-forms" ), "desc" => __( "Yes/No plus a guest count.", "trinity-forms" ), "icon" => "yes-alt", "fields" => [
                [ "name",   [ "fieldId" => "name",      "label" => __( "Name", "trinity-forms" ),  "required" => true ] ],
                [ "email",  [ "fieldId" => "email",     "label" => __( "Email", "trinity-forms" ), "required" => true ] ],
                [ "radio",  [ "fieldId" => "attending", "label" => __( "Will you attend?", "trinity-forms" ), "required" => true, "options" => self::opts( [ "yes" => __( "Yes", "trinity-forms" ), "no" => __( "No", "trinity-forms" ) ] ) ] ],
                [ "number", [ "fieldId" => "guests",    "label" => __( "Additional guests", "trinity-forms" ), "min" => 0, "max" => 10 ] ],
            ] ],
            "newsletter" => [ "name" => __( "Newsletter signup", "trinity-forms" ), "desc" => __( "Email plus a consent checkbox.", "trinity-forms" ), "icon" => "megaphone", "fields" => [
                [ "name",    [ "fieldId" => "name",    "label" => __( "Name", "trinity-forms" ) ] ],
                [ "email",   [ "fieldId" => "email",   "label" => __( "Email", "trinity-forms" ), "required" => true ] ],
                [ "consent", [ "fieldId" => "consent", "label" => __( "Consent", "trinity-forms" ), "consent_text" => __( "I agree to receive email updates.", "trinity-forms" ), "required" => true ] ],
            ] ],
            "support" => [ "name" => __( "Support request", "trinity-forms" ), "desc" => __( "Subject, priority and a message.", "trinity-forms" ), "icon" => "sos", "fields" => [
                [ "name",     [ "fieldId" => "name",     "label" => __( "Name", "trinity-forms" ),    "required" => true ] ],
                [ "email",    [ "fieldId" => "email",    "label" => __( "Email", "trinity-forms" ),   "required" => true ] ],
                [ "text",     [ "fieldId" => "subject",  "label" => __( "Subject", "trinity-forms" ), "required" => true ] ],
                [ "radio",    [ "fieldId" => "priority", "label" => __( "Priority", "trinity-forms" ), "options" => self::opts( [ "low" => __( "Low", "trinity-forms" ), "normal" => __( "Normal", "trinity-forms" ), "high" => __( "High", "trinity-forms" ), "urgent" => __( "Urgent", "trinity-forms" ) ] ) ] ],
                [ "textarea", [ "fieldId" => "message",  "label" => __( "How can we help?", "trinity-forms" ), "required" => true ] ],
            ] ],
            "feedback" => [ "name" => __( "Feedback & satisfaction", "trinity-forms" ), "desc" => __( "A star rating, an NPS slider and comments.", "trinity-forms" ), "icon" => "star-filled", "fields" => [
                [ "name",     [ "fieldId" => "name",      "label" => __( "Name", "trinity-forms" ) ] ],
                [ "rating",   [ "fieldId" => "rating",    "label" => __( "How would you rate us?", "trinity-forms" ) ] ],
                [ "range",    [ "fieldId" => "recommend", "label" => __( "How likely are you to recommend us? (0–10)", "trinity-forms" ), "min" => 0, "max" => 10, "step" => 1, "default" => 7 ] ],
                [ "textarea", [ "fieldId" => "comments",  "label" => __( "Comments", "trinity-forms" ) ] ],
            ] ],
            "job" => [ "name" => __( "Job application", "trinity-forms" ), "desc" => __( "Applicant details and a cover letter.", "trinity-forms" ), "icon" => "id", "fields" => [
                [ "name",     [ "fieldId" => "name",     "label" => __( "Full name", "trinity-forms" ), "required" => true ] ],
                [ "email",    [ "fieldId" => "email",    "label" => __( "Email", "trinity-forms" ),     "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone",    "label" => __( "Phone", "trinity-forms" ) ] ],
                [ "text",     [ "fieldId" => "position", "label" => __( "Position applying for", "trinity-forms" ), "required" => true ] ],
                [ "textarea", [ "fieldId" => "cover",    "label" => __( "Cover letter", "trinity-forms" ) ] ],
            ] ],
            "order" => [ "name" => __( "Order form", "trinity-forms" ), "desc" => __( "Address, country, product and quantity.", "trinity-forms" ), "icon" => "cart", "fields" => [
                [ "name",    [ "fieldId" => "name",     "label" => __( "Name", "trinity-forms" ),    "required" => true ] ],
                [ "email",   [ "fieldId" => "email",    "label" => __( "Email", "trinity-forms" ),   "required" => true ] ],
                [ "address", [ "fieldId" => "address",  "label" => __( "Shipping address", "trinity-forms" ), "required" => true ] ],
                [ "country", [ "fieldId" => "country",  "label" => __( "Country", "trinity-forms" ), "required" => true ] ],
                [ "select",  [ "fieldId" => "product",  "label" => __( "Product", "trinity-forms" ), "required" => true, "placeholder" => __( "Choose a product", "trinity-forms" ), "options" => self::opts( [ "starter" => __( "Starter", "trinity-forms" ), "standard" => __( "Standard", "trinity-forms" ), "premium" => __( "Premium", "trinity-forms" ) ] ) ] ],
                [ "number",  [ "fieldId" => "quantity", "label" => __( "Quantity", "trinity-forms" ), "min" => 1, "default" => 1 ] ],
            ] ],
            "volunteer" => [ "name" => __( "Volunteer signup", "trinity-forms" ), "desc" => __( "Availability and skills.", "trinity-forms" ), "icon" => "groups", "fields" => [
                [ "name",     [ "fieldId" => "name",         "label" => __( "Name", "trinity-forms" ),  "required" => true ] ],
                [ "email",    [ "fieldId" => "email",        "label" => __( "Email", "trinity-forms" ), "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone",        "label" => __( "Phone", "trinity-forms" ) ] ],
                [ "checkbox", [ "fieldId" => "availability", "label" => __( "Availability", "trinity-forms" ), "options" => self::opts( [ "weekdays" => __( "Weekdays", "trinity-forms" ), "weekends" => __( "Weekends", "trinity-forms" ), "evenings" => __( "Evenings", "trinity-forms" ) ] ) ] ],
                [ "textarea", [ "fieldId" => "skills",       "label" => __( "Skills / interests", "trinity-forms" ) ] ],
            ] ],
            "testimonial" => [ "name" => __( "Testimonial", "trinity-forms" ), "desc" => __( "Collect a rating and a quote with consent to publish.", "trinity-forms" ), "icon" => "format-quote", "fields" => [
                [ "name",     [ "fieldId" => "name",        "label" => __( "Name", "trinity-forms" ),   "required" => true ] ],
                [ "rating",   [ "fieldId" => "rating",      "label" => __( "Your rating", "trinity-forms" ) ] ],
                [ "textarea", [ "fieldId" => "testimonial", "label" => __( "Your testimonial", "trinity-forms" ), "required" => true ] ],
                [ "consent",  [ "fieldId" => "consent",     "label" => __( "Consent", "trinity-forms" ), "consent_text" => __( "I agree to have this testimonial published.", "trinity-forms" ), "required" => true ] ],
            ] ],
        ];
        return (array) apply_filters( "trinity_forms_templates", $t );
    }

    /** Create a published form from a template; 0 when it could not be made. */
    public static function create( string $slug ): int {
        $tpl = self::templates()[ $slug ] ?? null;
        if ( ! $tpl ) {
            return 0;
        }
        // wp_insert_post() unslashes what it is given: unslashed, a translated label
        // lost the backslash of its \u escapes ("Correo electru00f3nico").
        $post_id = wp_insert_post( wp_slash( [
            "post_type"    => "trinity_forms_form",
            "post_status"  => "publish",
            "post_title"   => $tpl["name"],
            "post_content" => self::content_for( $slug ),
        ] ), true );
        return is_wp_error( $post_id ) ? 0 : (int) $post_id;
    }

    public static function handle_use(): void {
        if ( ! current_user_can( Admin::CAP ) ) {
            wp_die( esc_html__( "You are not allowed to do this.", "trinity-forms" ) );
        }
        check_admin_referer( self::ACTION );
        $slug = isset( $_POST["template"] ) ? sanitize_key( wp_unslash( $_POST["template"] ) ) : "";
        $tpl  = self::templates()[ $slug ] ?? null;
        if ( ! $tpl ) {
            wp_safe_redirect( admin_url( "edit.php?post_type=trinity_forms_form&page=" . self::PAGE ) );
            exit;
        }
        $post_id = self::create( $slug );
        if ( ! $post_id ) {
            wp_die( esc_html__( "Could not create the form.", "trinity-forms" ) );
        }
        do_action( "trinity_forms_form_created", (int) $post_id );
        wp_safe_redirect( admin_url( "post.php?post=" . (int) $post_id . "&action=edit" ) );
        exit;
    }

    public static function render_page(): void {
        $action = esc_url( admin_url( "admin-post.php" ) );
        Admin_UI::open(
            __( "Templates", "trinity-forms" ),
            __( "Pick a ready-made form to create it in one click, then change it like any other form.", "trinity-forms" ),
            '<a class="button" href="' . esc_url( admin_url( "post-new.php?post_type=trinity_forms_form" ) ) . '">' . esc_html__( "Start from blank", "trinity-forms" ) . "</a>",
            [ "class" => "trinity-forms-templates" ]
        );
        ?>
            <div class="tf-tpl-grid">
                <?php foreach ( self::templates() as $slug => $tpl ) :
                    $tf_count = count( (array) ( $tpl["fields"] ?? [] ) );
                    ?>
                    <div class="jnkui-card tf-tpl-card">
                        <span class="tf-tpl-icon"><span class="dashicons dashicons-<?php echo esc_attr( $tpl["icon"] ); ?>" aria-hidden="true"></span></span>
                        <h2><?php echo esc_html( $tpl["name"] ); ?></h2>
                        <p><?php echo esc_html( $tpl["desc"] ); ?></p>
                        <div class="tf-tpl-foot">
                            <span class="jnkui-muted"><?php
                                /* translators: %d: number of fields in the template. */
                                echo esc_html( sprintf( _n( "%d field", "%d fields", $tf_count, "trinity-forms" ), $tf_count ) );
                            ?></span>
                            <form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() above. ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
                                <input type="hidden" name="template" value="<?php echo esc_attr( $slug ); ?>" />
                                <?php wp_nonce_field( self::ACTION ); ?>
                                <button type="submit" class="button button-primary button-small"><?php esc_html_e( "Use template", "trinity-forms" ); ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php
        Admin_UI::close();
    }
}
