<?php
namespace TrinityForms\Beaver_Builder;

defined( "ABSPATH" ) || exit;

/**
 * Beaver Builder module. Loaded only by TrinityForms\Beaver_Builder::register_module(),
 * when Beaver Builder is active. Output: includes/frontend.php in this folder.
 */
class Form_Module extends \FLBuilderModule {

    public function __construct() {
        parent::__construct( [
            "name"            => __( "Trinity Forms", "trinity-forms" ),
            "description"     => __( "Show a Trinity Forms form.", "trinity-forms" ),
            "category"        => __( "Basic", "fl-builder" ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Beaver Builder's own category name.
            "dir"             => TRINITY_FORMS_PATH . "includes/beaver-builder/",
            "url"             => TRINITY_FORMS_URL . "includes/beaver-builder/",
            "icon"            => "editor-table.svg",
            // No frozen copy of the form (with its one-time nonce) in post_content.
            "editor_export"   => false,
            "partial_refresh" => true,
        ] );
    }
}
