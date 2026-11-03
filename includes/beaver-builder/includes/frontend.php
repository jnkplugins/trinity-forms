<?php
/**
 * Beaver Builder module output. $settings comes from Beaver Builder.
 *
 * @var object $settings
 */
defined( "ABSPATH" ) || exit;

echo \TrinityForms\Beaver_Builder::render( $settings ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes.
