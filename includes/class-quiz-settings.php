<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * A form's Quiz settings (2.29.0), in the shape both editors use: the block
 * editor's Quiz tab and the Visual Builder's Settings. Same pattern as
 * Restriction_Settings, so the two editors cannot drift.
 *
 * Free: negative marking default, whether the answer key reveals the correct
 * answers. Pro (pro/includes/class-quiz-pro.php acts on them): time limit and
 * countdown, shuffled questions and options. The keys themselves are plain data
 * and live here so a form keeps its settings across a Free/Pro switch.
 */
final class Quiz_Settings {

    public const KEYS = [
        "quiz_wrong_points", "quiz_reveal_answers",
        "quiz_time_limit", "quiz_show_countdown",
        "quiz_shuffle_questions", "quiz_shuffle_options",
    ];

    /** Longest time limit accepted, in minutes (24 hours). */
    public const MAX_MINUTES = 1440;

    /**
     * @param array<string,mixed> $settings A form's settings.
     * @return array<string,mixed>
     */
    public static function for_editor( array $settings ): array {
        return [
            "quiz_wrong_points"      => self::wrong_points( $settings ),
            "quiz_reveal_answers"    => self::reveal_answers( $settings ),
            "quiz_time_limit"        => self::time_limit( $settings ),
            "quiz_show_countdown"    => self::show_countdown( $settings ),
            "quiz_shuffle_questions" => ! empty( $settings["quiz_shuffle_questions"] ),
            "quiz_shuffle_options"   => ! empty( $settings["quiz_shuffle_options"] ),
        ];
    }

    /** @return array<string,mixed> */
    public static function get( int $form_id ): array {
        $form = Form_Manager::get( $form_id );
        return self::for_editor( $form ? $form->settings() : [] );
    }

    /**
     * Clean what an editor sent; a missing key means "off" / the default.
     *
     * @return array<string,mixed>
     */
    public static function sanitize( array $raw ): array {
        return [
            "quiz_wrong_points"      => self::points( $raw["quiz_wrong_points"] ?? 0 ),
            "quiz_reveal_answers"    => self::flag( $raw["quiz_reveal_answers"] ?? true ),
            "quiz_time_limit"        => min( self::MAX_MINUTES, max( 0, (int) ( $raw["quiz_time_limit"] ?? 0 ) ) ),
            "quiz_show_countdown"    => self::flag( $raw["quiz_show_countdown"] ?? true ),
            "quiz_shuffle_questions" => self::flag( $raw["quiz_shuffle_questions"] ?? false ),
            "quiz_shuffle_options"   => self::flag( $raw["quiz_shuffle_options"] ?? false ),
        ];
    }

    /** Store the keys an editor sent, leaving every other setting alone. */
    public static function save( int $form_id, array $raw ): void {
        $sent = array_intersect_key( $raw, array_flip( self::KEYS ) );
        if ( ! $sent ) {
            return;
        }
        $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
        $meta = is_array( $meta ) ? $meta : [];
        if ( is_array( $meta["settings"] ?? null ) ) {
            $base = $meta["settings"];
        } else {
            $form = Form_Manager::get( $form_id );
            $base = $form ? $form->settings() : [];
        }
        $meta["settings"] = array_merge( $base, array_intersect_key( self::sanitize( $raw ), $sent ) );
        update_post_meta( $form_id, Form::SETTINGS_META, $meta );
    }

    /** Points taken off for a wrong answer, as a positive number (0 = none). */
    public static function wrong_points( array $settings ): float {
        return self::points( $settings["quiz_wrong_points"] ?? 0 );
    }

    /** Whether the answer key shows the correct answers (default yes). */
    public static function reveal_answers( array $settings ): bool {
        return ! array_key_exists( "quiz_reveal_answers", $settings ) || self::flag( $settings["quiz_reveal_answers"] );
    }

    /** Time limit in minutes, 0 = none. 180 is a 3-hour test. */
    public static function time_limit( array $settings ): int {
        return min( self::MAX_MINUTES, max( 0, (int) ( $settings["quiz_time_limit"] ?? 0 ) ) );
    }

    public static function show_countdown( array $settings ): bool {
        return ! array_key_exists( "quiz_show_countdown", $settings ) || self::flag( $settings["quiz_show_countdown"] );
    }

    /**
     * "0.25", "-0.25" and "0,25" all mean a quarter point off. Stored positive:
     * the sign is what the scorer adds, so a typed minus can't turn a penalty
     * into a bonus.
     *
     * @param mixed $value
     */
    public static function points( $value ): float {
        if ( ! is_scalar( $value ) ) {
            return 0.0;
        }
        $n = (float) str_replace( ",", ".", trim( (string) $value ) );
        return is_finite( $n ) ? round( min( 1000.0, abs( $n ) ), 4 ) : 0.0;
    }

    /** @param mixed $value */
    private static function flag( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        return ! empty( $value ) && ! in_array( strtolower( (string) $value ), [ "0", "false", "no", "off" ], true );
    }
}
