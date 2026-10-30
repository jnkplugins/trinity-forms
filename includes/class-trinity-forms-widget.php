<?php
/**
 * The "Trinity Forms" classic widget under a class name without a namespace.
 *
 * SiteOrigin Page Builder stores each widget by its PHP class name and escapes the
 * backslash of a namespaced one on every save, so after a reopen its editor showed
 * "Missing widget: TrinityForms\\Form Widget". A plain name has nothing to escape.
 * Everything lives in TrinityForms\Form_Widget; this only gives it the safe name.
 */

defined( "ABSPATH" ) || exit;

if ( ! class_exists( "Trinity_Forms_Widget", false ) ) {
    class Trinity_Forms_Widget extends \TrinityForms\Form_Widget {
    }
}
