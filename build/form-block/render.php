<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

$form_id = (int) ( $attributes["formId"] ?? 0 );
if ( ! $form_id ) {
    return;
}
$form = \TrinityForms\Form_Manager::get_public( $form_id );
if ( ! $form ) {
    return;
}
if ( ! empty( $attributes["showTitle"] ) && $form->title ) {
    echo '<h2 class="trinity-forms-form-title">' . esc_html( $form->title ) . '</h2>';
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form_Renderer::render() returns assembled form HTML that is escaped at the point each field/value is built.
echo \TrinityForms\Form_Renderer::render( $form );
