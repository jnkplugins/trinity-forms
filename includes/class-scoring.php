<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Quiz scoring. When a form's choice fields (radio/select) define a correct
 * answer + points, each submission is scored after it is stored.
 *
 * Per question: a correct answer earns its points, a wrong answer loses the
 * question's "wrong answer" points (negative marking, 2.29.0; the field's own
 * value or else the form's Quiz default), and a question left blank scores 0.
 * The total may go below zero: four wrong answers at −0.25 with the rest blank
 * is −1, as exam boards mark it.
 *
 * Stored as entry meta (all underscore ids, so they never show as answers):
 *   _quiz_score, _quiz_max                 totals
 *   _quiz_correct, _quiz_wrong, _quiz_blank counts
 *   _quiz_detail                            JSON, one row per question, read by
 *                                           the answer key and the entry screen
 * The timer (Pro) adds _quiz_seconds and _quiz_late.
 */
class Scoring {

    const SCORE_META   = "_quiz_score";
    const MAX_META     = "_quiz_max";
    const CORRECT_META = "_quiz_correct";
    const WRONG_META   = "_quiz_wrong";
    const BLANK_META   = "_quiz_blank";
    const DETAIL_META  = "_quiz_detail";
    const SECONDS_META = "_quiz_seconds";
    const LATE_META    = "_quiz_late";

    public static function bootstrap(): void {
        add_action( "trinity_forms_entry_created", [ self::class, "score_entry" ], 10, 2 );
        add_filter( "trinity_forms_merge_tags", [ self::class, "merge_tags" ], 10, 2 );
    }

    /**
     * The scored questions of a form, in form order.
     *
     * @return array<string,array{field:Field,correct:string,points:float,wrong:float}>
     */
    public static function questions( Form $form ): array {
        $default_wrong = Quiz_Settings::wrong_points( $form->settings() );
        $out           = [];
        foreach ( $form->fields() as $field ) {
            $correct = (string) ( $field->config["correct_answer"] ?? "" );
            if ( "" === $correct ) {
                continue;
            }
            $wrong = $field->config["wrong_points"] ?? null;
            $out[ $field->id ] = [
                "field"   => $field,
                "correct" => $correct,
                "points"  => (float) ( $field->config["points"] ?? 1 ),
                // Stored as a positive number of points to take away; a field value
                // ("" or missing = use the form's default) wins over the form's.
                "wrong"   => ( null === $wrong || "" === $wrong ) ? $default_wrong : abs( (float) $wrong ),
            ];
        }
        return $out;
    }

    /**
     * Score answers without storing anything.
     *
     * @param array<string,mixed> $answers field id => submitted value.
     * @return array{score:float,max:float,correct:int,wrong:int,blank:int,detail:array<int,array<string,mixed>>}
     */
    public static function score( Form $form, array $answers ): array {
        $res = [ "score" => 0.0, "max" => 0.0, "correct" => 0, "wrong" => 0, "blank" => 0, "detail" => [] ];
        foreach ( self::questions( $form ) as $fid => $q ) {
            $res["max"] += $q["points"];
            $given = $answers[ $fid ] ?? "";
            $given = is_scalar( $given ) ? trim( (string) $given ) : "";
            if ( "" === $given ) {
                $status = "blank";
                $earned = 0.0;
            } elseif ( self::matches( $given, $q["correct"] ) ) {
                $status = "correct";
                $earned = $q["points"];
            } else {
                $status = "wrong";
                $earned = -$q["wrong"];
            }
            $res[ $status ]++;
            $res["score"] += $earned;
            $res["detail"][] = [
                "id"            => $fid,
                "question"      => $q["field"]->label,
                "given"         => $given,
                "given_label"   => "" === $given ? "" : self::option_label( $q["field"], $given ),
                "correct"       => $q["correct"],
                "correct_label" => self::option_label( $q["field"], $q["correct"] ),
                "status"        => $status,
                "earned"        => round( $earned, 4 ),
            ];
        }
        $res["score"] = round( $res["score"], 4 );
        $res["max"]   = round( $res["max"], 4 );
        return $res;
    }

    /** Compute + persist the quiz score for a freshly-stored entry (no-op for non-quizzes). */
    public static function score_entry( int $entry_id, int $form_id ): void {
        $post = get_post( $form_id );
        if ( ! $post ) {
            return;
        }
        $form = new Form( $post );
        if ( ! self::is_quiz( $form ) ) {
            return;
        }

        global $wpdb;
        $meta_t  = $wpdb->prefix . "trinity_forms_entry_meta";
        $rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_id, value FROM {$meta_t} WHERE entry_id = %d", $entry_id ), ARRAY_A );
        $answers = array_column( $rows, "value", "field_id" );
        $res     = self::score( $form, $answers );

        $put = static function ( string $id, string $label, string $value ) use ( $wpdb, $meta_t, $entry_id ): void {
            $wpdb->insert( $meta_t, [ "entry_id" => $entry_id, "field_id" => $id, "field_label" => $label, "field_type" => ltrim( $id, "_" ), "value" => $value ], [ "%d", "%s", "%s", "%s", "%s" ] );
        };
        $put( self::SCORE_META, "Quiz score", self::fmt( $res["score"] ) );
        $put( self::MAX_META, "Quiz total", self::fmt( $res["max"] ) );
        $put( self::CORRECT_META, "Correct answers", (string) $res["correct"] );
        $put( self::WRONG_META, "Wrong answers", (string) $res["wrong"] );
        $put( self::BLANK_META, "Unanswered", (string) $res["blank"] );
        $put( self::DETAIL_META, "Quiz answers", (string) wp_json_encode( $res["detail"], JSON_UNESCAPED_UNICODE ) );

        /**
         * After a quiz entry was scored (the Pro timer stores its time here).
         *
         * @param int   $entry_id
         * @param Form  $form
         * @param array $res score(), see above.
         */
        do_action( "trinity_forms_quiz_scored", $entry_id, $form, $res );
    }

    /** Whether a form scores its entries (a field has a correct answer). */
    public static function is_quiz( ?Form $form ): bool {
        if ( ! $form ) {
            return false;
        }
        foreach ( $form->fields() as $field ) {
            if ( "" !== (string) ( $field->config["correct_answer"] ?? "" ) ) {
                return true;
            }
        }
        return false;
    }

    /** The quiz tags for a merge-tag picker. @return array<int,array{tag:string,label:string}> */
    public static function picker_tags(): array {
        return [
            [ "tag" => "{quiz_score}", "label" => __( "Quiz score", "trinity-forms" ) ],
            [ "tag" => "{quiz_max}",   "label" => __( "Highest possible quiz score", "trinity-forms" ) ],
            [ "tag" => "{quiz_percent}", "label" => __( "Quiz score as a percentage", "trinity-forms" ) ],
            [ "tag" => "{quiz_correct}", "label" => __( "Correct answers", "trinity-forms" ) ],
            [ "tag" => "{quiz_wrong}", "label" => __( "Wrong answers", "trinity-forms" ) ],
            [ "tag" => "{quiz_blank}", "label" => __( "Questions left unanswered", "trinity-forms" ) ],
            [ "tag" => "{quiz_time}", "label" => __( "Time taken (timed quizzes)", "trinity-forms" ) ],
            [ "tag" => "{quiz_answer_key}", "label" => __( "Answer key (each question, the answer given and the correct one)", "trinity-forms" ) ],
        ];
    }

    /**
     * An entry's score as "7 / 10", or "" when it was not scored.
     *
     * @param array<string,mixed> $values field id => stored value (Entries::values()).
     */
    public static function summary( array $values ): string {
        if ( ! isset( $values[ self::SCORE_META ] ) || ! is_scalar( $values[ self::SCORE_META ] ) ) {
            return "";
        }
        $score = self::num( (float) $values[ self::SCORE_META ] );
        if ( ! isset( $values[ self::MAX_META ] ) || ! is_scalar( $values[ self::MAX_META ] ) ) {
            return $score;
        }
        /* translators: 1: points scored, 2: highest possible score. */
        return sprintf( __( "%1\$s / %2\$s", "trinity-forms" ), $score, self::num( (float) $values[ self::MAX_META ] ) );
    }

    /** "12 correct · 3 wrong · 15 unanswered", or "" for an entry scored before 2.29. */
    public static function counts_line( array $values ): string {
        if ( ! isset( $values[ self::CORRECT_META ] ) ) {
            return "";
        }
        return sprintf(
            /* translators: 1: correct answers, 2: wrong answers, 3: unanswered questions. */
            __( "%1\$d correct · %2\$d wrong · %3\$d unanswered", "trinity-forms" ),
            (int) $values[ self::CORRECT_META ],
            (int) ( $values[ self::WRONG_META ] ?? 0 ),
            (int) ( $values[ self::BLANK_META ] ?? 0 )
        );
    }

    /** Score as a percentage of the highest possible score, e.g. "42.5%". */
    public static function percent( float $score, float $max ): string {
        if ( $max <= 0 ) {
            return "";
        }
        return self::num( round( $score / $max * 100, 1 ) ) . "%";
    }

    /** Seconds as h:mm:ss (a 3-hour test reads 2:59:59, not 179:59). */
    public static function hms( int $seconds ): string {
        $seconds = max( 0, $seconds );
        return sprintf( "%d:%02d:%02d", intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ), $seconds % 60 );
    }

    /**
     * The answer key as HTML: every question, the answer given (✓/✗), the correct
     * answer and the points earned. With $reveal false the correct answer is left
     * out, so a quiz that is re-taken keeps its answers secret.
     *
     * @param array<int,array<string,mixed>> $detail The _quiz_detail rows.
     */
    public static function answer_key_html( array $detail, bool $reveal = true ): string {
        if ( ! $detail ) {
            return "";
        }
        $marks = [
            "correct" => '<span style="color:#15803d;font-weight:700" aria-label="' . esc_attr__( "Correct", "trinity-forms" ) . '">✓</span>',
            "wrong"   => '<span style="color:#b91c1c;font-weight:700" aria-label="' . esc_attr__( "Wrong", "trinity-forms" ) . '">✗</span>',
            "blank"   => '<span style="color:#6b7280;font-weight:700" aria-label="' . esc_attr__( "Not answered", "trinity-forms" ) . '">–</span>',
        ];
        $html = '<ol class="trinity-forms-answer-key" style="padding-left:1.4em;margin:.5em 0">';
        foreach ( $detail as $row ) {
            $status = in_array( $row["status"] ?? "", [ "correct", "wrong", "blank" ], true ) ? $row["status"] : "blank";
            $given  = "blank" === $status
                ? '<em>' . esc_html__( "Not answered", "trinity-forms" ) . '</em>'
                : esc_html( (string) ( $row["given_label"] ?? $row["given"] ?? "" ) );
            $earned = (float) ( $row["earned"] ?? 0 );
            $html  .= '<li class="tf-key-' . esc_attr( $status ) . '" style="margin:0 0 .8em">'
                . '<strong>' . esc_html( (string) ( $row["question"] ?? "" ) ) . '</strong><br>'
                . $marks[ $status ] . ' ' . esc_html__( "Your answer:", "trinity-forms" ) . ' ' . $given;
            if ( $reveal && "correct" !== $status ) {
                $html .= '<br><span style="color:#15803d">' . esc_html__( "Correct answer:", "trinity-forms" ) . ' ' . esc_html( (string) ( $row["correct_label"] ?? $row["correct"] ?? "" ) ) . '</span>';
            }
            $html .= ' <span style="color:#6b7280">(' . esc_html( ( $earned > 0 ? "+" : "" ) . self::num( $earned ) ) . ')</span></li>';
        }
        return $html . "</ol>";
    }

    /** Case-insensitive answer match (trimmed). */
    private static function matches( string $val, string $correct ): bool {
        return 0 === strcasecmp( trim( $val ), trim( $correct ) );
    }

    /** The label a choice value is shown with (the value itself when none). */
    private static function option_label( Field $field, string $value ): string {
        foreach ( (array) ( $field->config["options"] ?? [] ) as $opt ) {
            if ( ! is_array( $opt ) ) {
                continue;
            }
            $v = (string) ( $opt["value"] ?? $opt["label"] ?? "" );
            if ( self::matches( $v, $value ) ) {
                return (string) ( $opt["label"] ?? $v );
            }
        }
        return $value;
    }

    private static function fmt( float $n ): string {
        $s = number_format( $n, 2, ".", "" );
        $s = false !== strpos( $s, "." ) ? rtrim( rtrim( $s, "0" ), "." ) : $s;
        return "-0" === $s ? "0" : $s;
    }

    /** A score for people: localised, up to 2 decimals, "−1" not "-1.00". */
    private static function num( float $n ): string {
        $out = number_format_i18n( $n, fmod( $n, 1.0 ) ? 2 : 0 );
        if ( false !== strpos( $out, "." ) || false !== strpos( $out, "," ) ) {
            $out = preg_replace( '/([.,]\d*?)0+$/', '$1', $out );
            $out = rtrim( (string) $out, ".," );
        }
        return "-0" === $out ? "0" : (string) $out;
    }

    /** Add the quiz merge tags when the entry was scored. */
    public static function merge_tags( array $tags, $entry_id ): array {
        $entry_id = (int) $entry_id;
        if ( $entry_id <= 0 ) {
            return $tags;
        }
        global $wpdb;
        $meta_t = $wpdb->prefix . "trinity_forms_entry_meta";
        $rows   = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT field_id, value FROM {$meta_t} WHERE entry_id = %d AND field_id LIKE %s",
            $entry_id,
            $wpdb->esc_like( "_quiz_" ) . "%"
        ), ARRAY_A );
        $v = array_column( $rows, "value", "field_id" );
        if ( ! isset( $v[ self::SCORE_META ] ) ) {
            return $tags;
        }
        // Keys carry their braces like every other tag in the strtr map; bare
        // words left "{1}/{1}" in the confirmation.
        $tags["{quiz_score}"]   = (string) $v[ self::SCORE_META ];
        $tags["{quiz_max}"]     = (string) ( $v[ self::MAX_META ] ?? "" );
        $tags["{quiz_percent}"] = self::percent( (float) $v[ self::SCORE_META ], (float) ( $v[ self::MAX_META ] ?? 0 ) );
        $tags["{quiz_correct}"] = (string) (int) ( $v[ self::CORRECT_META ] ?? 0 );
        $tags["{quiz_wrong}"]   = (string) (int) ( $v[ self::WRONG_META ] ?? 0 );
        $tags["{quiz_blank}"]   = (string) (int) ( $v[ self::BLANK_META ] ?? 0 );
        $tags["{quiz_time}"]    = isset( $v[ self::SECONDS_META ] ) ? self::hms( (int) $v[ self::SECONDS_META ] ) : "";

        $detail = json_decode( (string) ( $v[ self::DETAIL_META ] ?? "" ), true );
        $reveal = true;
        $form_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT form_id FROM {$wpdb->prefix}trinity_forms_entries WHERE id = %d", $entry_id ) );
        $form    = $form_id ? Form_Manager::get( $form_id ) : null;
        if ( $form ) {
            $reveal = Quiz_Settings::reveal_answers( $form->settings() );
        }
        $tags["{quiz_answer_key}"] = is_array( $detail ) ? self::answer_key_html( $detail, $reveal ) : "";
        return $tags;
    }
}
