<?php
defined( "ABSPATH" ) || exit;

// The same output as the shortcode, so the two can never disagree.
echo \TrinityForms\Poll_Results::shortcode( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
    "id"         => (int) ( $attributes["formId"] ?? 0 ),
    "field"      => (string) ( $attributes["field"] ?? "" ),
    "after_vote" => ! empty( $attributes["afterVote"] ) ? "1" : "0",
] );
