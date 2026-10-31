<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

use TrinityForms\Abilities;
use TrinityForms\AI_Privacy;
use TrinityForms\Assistants;

$available = Abilities::available();
$notice    = Assistants::take_notice();
$adapter   = Assistants::adapter_state();
$levels    = Assistants::levels();
$user      = wp_get_current_user();

/**
 * What each switch allows, in plain words. The Pro module adds its own levels.
 *
 * @var array<string,array{label:string,text:string}> $level_info
 */
$level_info = (array) apply_filters( 'trinity_forms_ai_level_info', [
    'read' => [
        'label' => __( 'Read forms and entries', 'trinity-forms' ),
        'text'  => __( 'See how your forms are built and how they perform, and read their entries. Nothing is changed. What an assistant sees of each entry is set below.', 'trinity-forms' ),
    ],
] );

$notices = [
    'saved'           => [ 'success', __( 'Saved.', 'trinity-forms' ) ],
    'adapter_ready'   => [ 'success', __( 'The MCP Adapter plugin is installed and active.', 'trinity-forms' ) ],
    'install_failed'  => [ 'error', __( 'WordPress could not install the MCP Adapter plugin here (it may not be allowed to write files). Install it from Plugins > Add New: search for “MCP Adapter”.', 'trinity-forms' ) ],
    'activate_failed' => [ 'error', __( 'The MCP Adapter plugin is installed but could not be activated. Activate it on the Plugins screen.', 'trinity-forms' ) ],
];

// The forms, for "Never show to assistants".
$forms = get_posts( [ 'post_type' => 'trinity_forms_form', 'post_status' => [ 'publish', 'draft', 'private' ], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ] );
$hidden = AI_Privacy::hidden_forms();

\TrinityForms\Admin_UI::open(
    __( 'AI assistants', 'trinity-forms' ),
    __( 'Let an AI assistant such as Claude or Cursor work with your forms and entries. You choose what it may do and what it may see.', 'trinity-forms' )
);

if ( isset( $notices[ $notice ] ) ) {
    printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notices[ $notice ][0] ), esc_html( $notices[ $notice ][1] ) );
}
?>

<?php if ( ! $available ) : ?>
    <div class="jnkui-card"><div class="jnkui-card-body">
        <p><?php esc_html_e( 'This needs WordPress 6.9 or newer, which added the Abilities API that AI tools use. Update WordPress to use it.', 'trinity-forms' ); ?></p>
    </div></div>
<?php else : ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="trinity_forms_assistants_save">
<?php wp_nonce_field( 'trinity_forms_assistants_save' ); ?>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( '1. What assistants may do', 'trinity-forms' ); ?></h2>
        <p><?php esc_html_e( 'Off by default. An assistant only ever acts as the user it signs in as, so it can never do more than that user could do on these screens.', 'trinity-forms' ); ?></p></div></div>
    <div class="jnkui-card-body">
        <?php foreach ( $levels as $level ) :
            if ( ! isset( $level_info[ $level ] ) ) {
                continue;
            }
            $key = Abilities::LEVELS[ $level ];
            ?>
            <p>
                <label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( Abilities::exposed( $level ) ); ?>>
                <strong><?php echo esc_html( $level_info[ $level ]['label'] ); ?></strong></label><br>
                <span class="jnkui-sub" style="margin-inline-start:24px"><?php echo esc_html( $level_info[ $level ]['text'] ); ?></span>
            </p>
        <?php endforeach; ?>
        <?php
        /** The Pro module adds a line here about the abilities that change entries. */
        do_action( 'trinity_forms_assistants_after_switches' );
        ?>
    </div>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( '2. What assistants may see', 'trinity-forms' ); ?></h2>
        <p><?php esc_html_e( 'Your entries hold other people’s personal details. Decide how much of them reaches an AI service.', 'trinity-forms' ); ?></p></div></div>
    <div class="jnkui-card-body">
        <p>
            <label><input type="checkbox" name="ai_show_contact" value="1" <?php checked( ! AI_Privacy::masking() ); ?>>
            <strong><?php esc_html_e( 'Show contact details', 'trinity-forms' ); ?></strong></label><br>
            <span class="jnkui-sub" style="margin-inline-start:24px"><?php esc_html_e( 'Off by default: emails become j***@example.com, phone numbers ***42, names their initials, and addresses are left out, also inside messages and notes. Turn it on only if your assistant needs to write to people.', 'trinity-forms' ); ?></span>
        </p>
        <p class="jnkui-sub"><?php esc_html_e( 'Always left out, whatever you choose: uploaded files, passwords, card numbers and signatures.', 'trinity-forms' ); ?></p>
        <?php if ( $forms ) : ?>
            <h3><?php esc_html_e( 'Never show to assistants', 'trinity-forms' ); ?></h3>
            <p class="jnkui-sub"><?php esc_html_e( 'Entries of the forms you tick are not listed and cannot be opened by an assistant, for example a job application or a medical form. The form itself can still be read.', 'trinity-forms' ); ?></p>
            <fieldset>
                <?php foreach ( $forms as $f ) :
                    $form_key = (int) $f->ID;
                    $count    = \TrinityForms\Entries::counts( $form_key )['all'];
                    ?>
                    <label style="display:block;margin:4px 0">
                        <input type="checkbox" name="ai_hidden_forms[]" value="<?php echo esc_attr( (string) $form_key ); ?>" <?php checked( in_array( $form_key, $hidden, true ) ); ?>>
                        <?php echo esc_html( $f->post_title ?: '#' . $form_key ); ?>
                        <span class="jnkui-sub" style="display:inline"><?php
                            /* translators: %s: number of entries. */
                            echo esc_html( sprintf( _n( '%s entry', '%s entries', (int) $count, 'trinity-forms' ), number_format_i18n( (int) $count ) ) );
                        ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <p class="jnkui-sub"><?php esc_html_e( 'Every entry an assistant opens gets a note, so you can see what was read.', 'trinity-forms' ); ?></p>
        <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'trinity-forms' ); ?></button></p>
    </div>
</div>
</form>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( '3. Connect your assistant', 'trinity-forms' ); ?></h2>
        <p><?php esc_html_e( 'Assistants reach WordPress through the free MCP Adapter plugin, made by the WordPress AI team.', 'trinity-forms' ); ?></p></div></div>
    <div class="jnkui-card-body">
        <h3 style="margin-top:0"><?php esc_html_e( 'MCP Adapter plugin', 'trinity-forms' ); ?>
            <?php if ( 'active' === $adapter ) : ?>
                <span class="jnkui-pill is-ok"><?php esc_html_e( 'Active', 'trinity-forms' ); ?></span>
            <?php elseif ( 'installed' === $adapter ) : ?>
                <span class="jnkui-pill is-warn"><?php esc_html_e( 'Installed, not active', 'trinity-forms' ); ?></span>
            <?php else : ?>
                <span class="jnkui-pill is-warn"><?php esc_html_e( 'Not installed', 'trinity-forms' ); ?></span>
            <?php endif; ?>
        </h3>
        <?php if ( 'active' !== $adapter ) : ?>
            <?php if ( current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS && 'missing' === $adapter ) ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="trinity_forms_install_adapter">
                    <?php wp_nonce_field( 'trinity_forms_install_adapter' ); ?>
                    <button type="submit" class="button button-primary">
                        <?php echo 'missing' === $adapter ? esc_html__( 'Install and activate MCP Adapter', 'trinity-forms' ) : esc_html__( 'Activate MCP Adapter', 'trinity-forms' ); ?>
                    </button>
                    <span class="jnkui-sub"><?php esc_html_e( 'From wordpress.org. You can remove it any time on the Plugins screen.', 'trinity-forms' ); ?></span>
                </form>
            <?php else : ?>
                <p><?php esc_html_e( 'Ask the site administrator to install and activate the MCP Adapter plugin from wordpress.org.', 'trinity-forms' ); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <h3><?php esc_html_e( 'Application password', 'trinity-forms' ); ?></h3>
        <?php $no_app_passwords = function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available(); ?>
        <?php if ( $no_app_passwords ) : ?>
            <p><span class="jnkui-pill is-warn"><?php esc_html_e( 'Not available', 'trinity-forms' ); ?></span>
            <?php esc_html_e( 'WordPress only offers application passwords on sites served over HTTPS. Switch the site to HTTPS first.', 'trinity-forms' ); ?></p>
            <p><?php esc_html_e( 'On a site on your own computer with WP-CLI, an assistant can connect without one. Use this as the command in its MCP settings:', 'trinity-forms' ); ?></p>
            <textarea class="large-text code" rows="2" readonly onclick="this.select()" dir="ltr"><?php echo esc_textarea( sprintf( 'wp --path=%s mcp-adapter serve --server=mcp-adapter-default-server --user=%s', escapeshellarg( untrailingslashit( ABSPATH ) ), escapeshellarg( $user->user_login ) ) ); ?></textarea>
        <?php else : ?>
            <p><?php esc_html_e( 'Your assistant signs in with an application password: a separate password you can revoke without changing your own. Name it after the assistant, for example “Claude”.', 'trinity-forms' ); ?>
            <a class="button" href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Create an application password', 'trinity-forms' ); ?></a></p>
            <p class="jnkui-sub"><?php esc_html_e( 'Tip: an assistant can do whatever the user it signs in as can do. A separate user with only the role it needs is the safest choice.', 'trinity-forms' ); ?></p>
        <?php endif; ?>

        <?php
        $config = wp_json_encode( [
            'mcpServers' => [
                'wordpress' => [
                    'command' => 'npx',
                    'args'    => [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
                    'env'     => [
                        'WP_API_URL'      => Assistants::endpoint(),
                        'WP_API_USERNAME' => $user->user_login,
                        'WP_API_PASSWORD' => 'YOUR-APPLICATION-PASSWORD',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $cli = sprintf(
            'claude mcp add wordpress -e WP_API_URL=%s -e WP_API_USERNAME=%s -e WP_API_PASSWORD=YOUR-APPLICATION-PASSWORD -- npx -y @automattic/mcp-wordpress-remote@latest',
            escapeshellarg( Assistants::endpoint() ),
            escapeshellarg( $user->user_login )
        );
        ?>
        <h3><?php esc_html_e( 'Claude Desktop, Cursor and other desktop apps', 'trinity-forms' ); ?></h3>
        <p><?php esc_html_e( 'Add this to the app’s MCP settings file (in Claude Desktop: Settings > Developer > Edit Config), put your application password in, and restart the app. It needs Node.js on your computer.', 'trinity-forms' ); ?></p>
        <textarea class="large-text code" rows="13" readonly onclick="this.select()" dir="ltr"><?php echo esc_textarea( (string) $config ); ?></textarea>

        <h3><?php esc_html_e( 'Claude Code', 'trinity-forms' ); ?></h3>
        <textarea class="large-text code" rows="3" readonly onclick="this.select()" dir="ltr"><?php echo esc_textarea( $cli ); ?></textarea>

        <?php $oauth = Assistants::oauth_endpoint(); ?>
        <?php if ( '' !== $oauth ) : ?>
            <p class="jnkui-sub"><?php
                printf(
                    /* translators: %s: web address of the other MCP server. */
                    esc_html__( 'Another plugin on this site (for example Rank Math) added an MCP server that signs in with OAuth, at %s. Apps in a web browser, such as ChatGPT or Claude, may be able to connect there; follow that plugin’s instructions. It offers the same Trinity Forms abilities you allow above, with the same privacy settings.', 'trinity-forms' ),
                    '<code dir="ltr">' . esc_html( $oauth ) . '</code>'
                );
            ?></p>
        <?php else : ?>
            <p class="jnkui-sub"><?php esc_html_e( 'ChatGPT and Claude in a web browser cannot connect to a WordPress site this way yet: they need a sign-in method (OAuth) that WordPress does not offer yet.', 'trinity-forms' ); ?></p>
        <?php endif; ?>
        <p class="jnkui-sub"><?php esc_html_e( 'Try asking: “Which of my forms gets the most views but the fewest entries?”', 'trinity-forms' ); ?></p>
    </div>
</div>

<?php $ours = Abilities::ours(); ?>
<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( '4. What an assistant can use', 'trinity-forms' ); ?></h2>
        <p><?php esc_html_e( 'Every Trinity Forms ability on this site and whether assistants can use it now.', 'trinity-forms' ); ?></p></div></div>
    <div class="jnkui-table-wrap"><table class="widefat striped">
        <thead><tr><th><?php esc_html_e( 'Ability', 'trinity-forms' ); ?></th><th><?php esc_html_e( 'What it does', 'trinity-forms' ); ?></th><th><?php esc_html_e( 'Kind', 'trinity-forms' ); ?></th><th><?php esc_html_e( 'Assistants', 'trinity-forms' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $ours as $ability ) :
            $level = (string) $ability->get_meta_item( 'tf_level', 'read' );
            $kinds = [
                'read'   => [ 'is-ok', __( 'Reads', 'trinity-forms' ) ],
                'edit'   => [ 'is-warn', __( 'Changes forms', 'trinity-forms' ) ],
                'delete' => [ 'is-bad', __( 'Deletes', 'trinity-forms' ) ],
            ];
            [ $kind_class, $kind_label ] = $kinds[ $level ] ?? $kinds['read'];
            $on = (bool) $ability->get_meta_item( 'show_in_rest', false );
            ?>
            <tr>
                <td dir="ltr"><code><?php echo esc_html( $ability->get_name() ); ?></code><span class="jnkui-sub"><?php echo esc_html( $ability->get_label() ); ?></span></td>
                <td><?php echo esc_html( $ability->get_description() ); ?></td>
                <td><span class="jnkui-pill <?php echo esc_attr( $kind_class ); ?>"><?php echo esc_html( $kind_label ); ?></span></td>
                <td><?php echo $on ? '<span class="jnkui-pill is-ok">' . esc_html__( 'Allowed', 'trinity-forms' ) . '</span>' : '<span class="jnkui-pill">' . esc_html__( 'Off', 'trinity-forms' ) . '</span>'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php $entries = Abilities::log_entries( 50 ); ?>
<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( '5. What assistants did', 'trinity-forms' ); ?></h2>
        <p><?php esc_html_e( 'The last 50 requests. Trinity Forms keeps the newest 200.', 'trinity-forms' ); ?></p></div></div>
    <?php if ( ! $entries ) : ?>
        <div class="jnkui-card-body"><p class="jnkui-sub"><?php esc_html_e( 'No requests yet.', 'trinity-forms' ); ?></p></div>
    <?php else : ?>
    <div class="jnkui-table-wrap"><table class="widefat striped">
        <thead><tr><th><?php esc_html_e( 'When', 'trinity-forms' ); ?></th><th><?php esc_html_e( 'User', 'trinity-forms' ); ?></th><th><?php esc_html_e( 'Ability', 'trinity-forms' ); ?></th><th><?php esc_html_e( 'Asked', 'trinity-forms' ); ?></th><th><?php esc_html_e( 'Result', 'trinity-forms' ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $entries as $e ) :
            $who = get_userdata( (int) ( $e['u'] ?? 0 ) );
            ?>
            <tr>
                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $e['t'] ?? 0 ) ) ); ?></td>
                <td><?php echo esc_html( $who ? $who->display_name : '-' ); ?></td>
                <td dir="ltr"><code><?php echo esc_html( (string) ( $e['a'] ?? '' ) ); ?></code></td>
                <td dir="ltr"><code><?php echo esc_html( (string) ( $e['i'] ?? '' ) ); ?></code></td>
                <td><?php echo ! empty( $e['o'] ) ? '<span class="jnkui-pill is-ok">' . esc_html__( 'Done', 'trinity-forms' ) . '</span>' : '<span class="jnkui-pill is-bad">' . esc_html__( 'Failed', 'trinity-forms' ) . '</span>'; ?>
                    <?php if ( ! empty( $e['m'] ) ) : ?><span class="jnkui-sub"><?php echo esc_html( (string) $e['m'] ); ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php
/** The Pro module lists recent entry changes with an Undo button here. */
do_action( 'trinity_forms_assistants_after_log' );
?>

<?php endif; ?>
<?php \TrinityForms\Admin_UI::close(); ?>
