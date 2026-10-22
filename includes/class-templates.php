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
        return "<!-- wp:trinity-forms/field-" . $type . " " . wp_json_encode( $attrs ) . " /-->";
    }

    private static function opts( array $labels ): array {
        return array_map( static fn( $l ) => [ "label" => $l, "value" => sanitize_title( $l ) ], $labels );
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
                [ "name",     [ "fieldId" => "name",    "label" => "Name",    "required" => true ] ],
                [ "email",    [ "fieldId" => "email",   "label" => "Email",   "required" => true ] ],
                [ "textarea", [ "fieldId" => "message", "label" => "Message", "required" => true ] ],
            ] ],
            "quote" => [ "name" => __( "Quote request", "trinity-forms" ), "desc" => __( "Capture leads with service, budget and details.", "trinity-forms" ), "icon" => "money-alt", "fields" => [
                [ "name",     [ "fieldId" => "name",    "label" => "Name",    "required" => true ] ],
                [ "email",    [ "fieldId" => "email",   "label" => "Email",   "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone",   "label" => "Phone" ] ],
                [ "select",   [ "fieldId" => "service", "label" => "Service", "required" => true, "placeholder" => "Choose a service", "options" => self::opts( [ "Web design", "SEO", "Consulting", "Other" ] ) ] ],
                [ "range",    [ "fieldId" => "budget",  "label" => "Budget (USD)", "min" => 500, "max" => 20000, "step" => 500, "default" => 2500 ] ],
                [ "textarea", [ "fieldId" => "details", "label" => "Project details" ] ],
            ] ],
            "event" => [ "name" => __( "Event registration", "trinity-forms" ), "desc" => __( "Register attendees with a guest count and session.", "trinity-forms" ), "icon" => "tickets-alt", "fields" => [
                [ "name",   [ "fieldId" => "name",    "label" => "Name",  "required" => true ] ],
                [ "email",  [ "fieldId" => "email",   "label" => "Email", "required" => true ] ],
                [ "phone",  [ "fieldId" => "phone",   "label" => "Phone" ] ],
                [ "number", [ "fieldId" => "guests",  "label" => "Number attending", "min" => 1, "max" => 10 ] ],
                [ "select", [ "fieldId" => "session", "label" => "Session", "placeholder" => "Pick a session", "options" => self::opts( [ "Morning", "Afternoon", "Full day" ] ) ] ],
            ] ],
            "appointment" => [ "name" => __( "Appointment booking", "trinity-forms" ), "desc" => __( "Let visitors request a date and time.", "trinity-forms" ), "icon" => "calendar-alt", "fields" => [
                [ "name",     [ "fieldId" => "name",  "label" => "Name",  "required" => true ] ],
                [ "email",    [ "fieldId" => "email", "label" => "Email", "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone", "label" => "Phone" ] ],
                [ "date",     [ "fieldId" => "date",  "label" => "Preferred date", "required" => true ] ],
                [ "time",     [ "fieldId" => "time",  "label" => "Preferred time" ] ],
                [ "textarea", [ "fieldId" => "notes", "label" => "Notes" ] ],
            ] ],
            "rsvp" => [ "name" => __( "RSVP", "trinity-forms" ), "desc" => __( "Yes/No plus a guest count.", "trinity-forms" ), "icon" => "yes-alt", "fields" => [
                [ "name",   [ "fieldId" => "name",      "label" => "Name",  "required" => true ] ],
                [ "email",  [ "fieldId" => "email",     "label" => "Email", "required" => true ] ],
                [ "radio",  [ "fieldId" => "attending", "label" => "Will you attend?", "required" => true, "options" => self::opts( [ "Yes", "No" ] ) ] ],
                [ "number", [ "fieldId" => "guests",    "label" => "Additional guests", "min" => 0, "max" => 10 ] ],
            ] ],
            "newsletter" => [ "name" => __( "Newsletter signup", "trinity-forms" ), "desc" => __( "Email plus a consent checkbox.", "trinity-forms" ), "icon" => "megaphone", "fields" => [
                [ "name",    [ "fieldId" => "name",    "label" => "Name" ] ],
                [ "email",   [ "fieldId" => "email",   "label" => "Email", "required" => true ] ],
                [ "consent", [ "fieldId" => "consent", "label" => "Consent", "consent_text" => "I agree to receive email updates.", "required" => true ] ],
            ] ],
            "support" => [ "name" => __( "Support request", "trinity-forms" ), "desc" => __( "Subject, priority and a message.", "trinity-forms" ), "icon" => "sos", "fields" => [
                [ "name",     [ "fieldId" => "name",     "label" => "Name",    "required" => true ] ],
                [ "email",    [ "fieldId" => "email",    "label" => "Email",   "required" => true ] ],
                [ "text",     [ "fieldId" => "subject",  "label" => "Subject", "required" => true ] ],
                [ "radio",    [ "fieldId" => "priority", "label" => "Priority", "options" => self::opts( [ "Low", "Normal", "High", "Urgent" ] ) ] ],
                [ "textarea", [ "fieldId" => "message",  "label" => "How can we help?", "required" => true ] ],
            ] ],
            "feedback" => [ "name" => __( "Feedback & satisfaction", "trinity-forms" ), "desc" => __( "A star rating, an NPS slider and comments.", "trinity-forms" ), "icon" => "star-filled", "fields" => [
                [ "name",     [ "fieldId" => "name",      "label" => "Name" ] ],
                [ "rating",   [ "fieldId" => "rating",    "label" => "How would you rate us?" ] ],
                [ "range",    [ "fieldId" => "recommend", "label" => "How likely are you to recommend us? (0–10)", "min" => 0, "max" => 10, "step" => 1, "default" => 7 ] ],
                [ "textarea", [ "fieldId" => "comments",  "label" => "Comments" ] ],
            ] ],
            "job" => [ "name" => __( "Job application", "trinity-forms" ), "desc" => __( "Applicant details and a cover letter.", "trinity-forms" ), "icon" => "id", "fields" => [
                [ "name",     [ "fieldId" => "name",     "label" => "Full name", "required" => true ] ],
                [ "email",    [ "fieldId" => "email",    "label" => "Email",     "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone",    "label" => "Phone" ] ],
                [ "text",     [ "fieldId" => "position", "label" => "Position applying for", "required" => true ] ],
                [ "textarea", [ "fieldId" => "cover",    "label" => "Cover letter" ] ],
            ] ],
            "order" => [ "name" => __( "Order form", "trinity-forms" ), "desc" => __( "Address, country, product and quantity.", "trinity-forms" ), "icon" => "cart", "fields" => [
                [ "name",    [ "fieldId" => "name",     "label" => "Name",    "required" => true ] ],
                [ "email",   [ "fieldId" => "email",    "label" => "Email",   "required" => true ] ],
                [ "address", [ "fieldId" => "address",  "label" => "Shipping address", "required" => true ] ],
                [ "country", [ "fieldId" => "country",  "label" => "Country", "required" => true ] ],
                [ "select",  [ "fieldId" => "product",  "label" => "Product", "required" => true, "placeholder" => "Choose a product", "options" => self::opts( [ "Starter", "Standard", "Premium" ] ) ] ],
                [ "number",  [ "fieldId" => "quantity", "label" => "Quantity", "min" => 1, "default" => 1 ] ],
            ] ],
            "volunteer" => [ "name" => __( "Volunteer signup", "trinity-forms" ), "desc" => __( "Availability and skills.", "trinity-forms" ), "icon" => "groups", "fields" => [
                [ "name",     [ "fieldId" => "name",         "label" => "Name",  "required" => true ] ],
                [ "email",    [ "fieldId" => "email",        "label" => "Email", "required" => true ] ],
                [ "phone",    [ "fieldId" => "phone",        "label" => "Phone" ] ],
                [ "checkbox", [ "fieldId" => "availability", "label" => "Availability", "options" => self::opts( [ "Weekdays", "Weekends", "Evenings" ] ) ] ],
                [ "textarea", [ "fieldId" => "skills",       "label" => "Skills / interests" ] ],
            ] ],
            "testimonial" => [ "name" => __( "Testimonial", "trinity-forms" ), "desc" => __( "Collect a rating and a quote with consent to publish.", "trinity-forms" ), "icon" => "format-quote", "fields" => [
                [ "name",     [ "fieldId" => "name",        "label" => "Name",   "required" => true ] ],
                [ "rating",   [ "fieldId" => "rating",      "label" => "Your rating" ] ],
                [ "textarea", [ "fieldId" => "testimonial", "label" => "Your testimonial", "required" => true ] ],
                [ "consent",  [ "fieldId" => "consent",     "label" => "Consent", "consent_text" => "I agree to have this testimonial published.", "required" => true ] ],
            ] ],
        ];
        return (array) apply_filters( "trinity_forms_templates", $t );
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
        $post_id = wp_insert_post( [
            "post_type"    => "trinity_forms_form",
            "post_status"  => "publish",
            "post_title"   => $tpl["name"],
            "post_content" => self::content_for( $slug ),
        ], true );
        if ( is_wp_error( $post_id ) || ! $post_id ) {
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
