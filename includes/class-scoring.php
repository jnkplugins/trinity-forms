<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Quiz scoring. When a form's choice fields (radio/select) define a correct
 * answer + points, each submission is scored after it is stored and the score is
 * saved as `_quiz_score` / `_quiz_max` entry-meta. `{quiz_score}` / `{quiz_max}`
 * merge tags expose it in confirmations and notifications.
 */
class Scoring {

    const SCORE_META = "_quiz_score";
    const MAX_META   = "_quiz_max";

    public static function bootstrap(): void {
        add_action( "trinity_forms_entry_created", [ self::class, "score_entry" ], 10, 2 );
        add_filter( "trinity_forms_merge_tags", [ self::class, "merge_tags" ], 10, 2 );
    }

    /** Compute + persist the quiz score for a freshly-stored entry (no-op for non-quizzes). */
    public static function score_entry( int $entry_id, int $form_id ): void {
        $post = get_post( $form_id );
        if ( ! $post ) {
            return;
        }
        $form     = new Form( $post );
        $scorable = [];
        foreach ( $form->fields() as $field ) {
            $correct = (string) ( $field->config["correct_answer"] ?? "" );
            if ( $correct !== "" ) {
                $scorable[ $field->id ] = [ "correct" => $correct, "points" => (float) ( $field->config["points"] ?? 1 ) ];
            }
        }
        if ( ! $scorable ) {
            return; // Not a quiz.
        }

        global $wpdb;
        $meta_t = $wpdb->prefix . "trinity_forms_entry_meta";
        $score  = 0.0;
        $max    = 0.0;
        foreach ( $scorable as $fid => $def ) {
            $max += $def["points"];
            $val  = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT value FROM {$meta_t} WHERE entry_id = %d AND field_id = %s LIMIT 1",
                $entry_id, $fid
            ) );
            if ( self::matches( $val, $def["correct"] ) ) {
                $score += $def["points"];
            }
        }
        $wpdb->insert( $meta_t, [ "entry_id" => $entry_id, "field_id" => self::SCORE_META, "field_label" => "Quiz score", "field_type" => "quiz_score", "value" => self::fmt( $score ) ], [ "%d", "%s", "%s", "%s", "%s" ] );
        $wpdb->insert( $meta_t, [ "entry_id" => $entry_id, "field_id" => self::MAX_META,   "field_label" => "Quiz total", "field_type" => "quiz_max",   "value" => self::fmt( $max ) ],   [ "%d", "%s", "%s", "%s", "%s" ] );
    }

    /** Case-insensitive answer match (trimmed). */
    private static function matches( string $val, string $correct ): bool {
        return 0 === strcasecmp( trim( $val ), trim( $correct ) );
    }

    private static function fmt( float $n ): string {
        $s = number_format( $n, 2, ".", "" );
        return false !== strpos( $s, "." ) ? rtrim( rtrim( $s, "0" ), "." ) : $s;
    }

    /** Add {quiz_score} / {quiz_max} merge tags when the entry was scored. */
    public static function merge_tags( array $tags, $entry_id ): array {
        $entry_id = (int) $entry_id;
        if ( $entry_id <= 0 ) {
            return $tags;
        }
        global $wpdb;
        $meta_t = $wpdb->prefix . "trinity_forms_entry_meta";
        $score  = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta_t} WHERE entry_id = %d AND field_id = %s LIMIT 1", $entry_id, self::SCORE_META ) );
        if ( null !== $score ) {
            $max = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$meta_t} WHERE entry_id = %d AND field_id = %s LIMIT 1", $entry_id, self::MAX_META ) );
            // Keys carry their braces like every other tag in the strtr map; bare
            // words left "{1}/{1}" in the confirmation.
            $tags["{quiz_score}"] = (string) $score;
            $tags["{quiz_max}"]   = (string) $max;
        }
        return $tags;
    }
}
