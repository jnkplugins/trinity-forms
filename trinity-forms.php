<?php
/**
 * Plugin Name:       Trinity Forms
 * Plugin URI:        https://jnkplugins.com/trinity-forms/
 * Update URI:        https://api.jnkplugins.com/
 * Description:       Lightweight WordPress form builder — drag-and-drop fields, conditional logic, entries, notifications and CSV export.
 * Version:           2.12.2
 * Requires at least: 6.2
 * Requires PHP:      8.2
 * Author:            JNK Plugins
 * Author URI:        https://jnkplugins.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       trinity-forms
 * Domain Path:       /languages
 */

defined( "ABSPATH" ) || exit;

define( "TRINITY_FORMS_VERSION", "2.12.2" );
define( "TRINITY_FORMS_FILE", __FILE__ );
define( "TRINITY_FORMS_PATH", plugin_dir_path( __FILE__ ) );
define( "TRINITY_FORMS_URL",  plugin_dir_url( __FILE__ ) );

// PSR-4-ish autoloader mapping TrinityForms\Sub\Class_Name → includes/sub/class-class-name.php.
// Free classes live in includes/ (+ public/ + admin/). Premium classes live in
// pro/includes/, which is omitted from the free wordpress.org build — so those
// classes simply never load there and the plugin runs as the free tier.
spl_autoload_register( static function ( $class ) {
    if ( strpos( $class, "TrinityForms\\" ) !== 0 ) {
        return;
    }
    $rel   = substr( $class, strlen( "TrinityForms\\" ) );
    $parts = explode( "\\", $rel );
    $cls   = array_pop( $parts );
    $dir   = $parts ? strtolower( implode( "/", $parts ) ) . "/" : "";
    $file  = "class-" . str_replace( "_", "-", strtolower( $cls ) ) . ".php";
    foreach ( [ "includes/", "public/", "admin/", "pro/includes/" ] as $root ) {
        $path = TRINITY_FORMS_PATH . $root . $dir . $file;
        if ( is_file( $path ) ) {
            require_once $path;
            return;
        }
    }
} );

if ( is_file( TRINITY_FORMS_PATH . "vendor/autoload.php" ) ) {
    require_once TRINITY_FORMS_PATH . "vendor/autoload.php";
}

// Pro module: licensing SDK + premium features + premium composer deps. Absent
// from the free wordpress.org build, so the plugin runs as the fully-functional
// free tier (no SDK, no phone-home, no usage caps) when it isn't present.
if ( is_file( TRINITY_FORMS_PATH . "pro/bootstrap.php" ) ) {
    require_once TRINITY_FORMS_PATH . "pro/bootstrap.php";
}

register_activation_hook(   __FILE__, [ "TrinityForms\\Activator",   "activate"   ] );
register_deactivation_hook( __FILE__, [ "TrinityForms\\Deactivator", "deactivate" ] );

add_action( "plugins_loaded", static function () {
    \TrinityForms\Plugin::instance()->boot();
} );
