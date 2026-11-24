<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads Quiz And Survey Master's own tables.

/**
 * Quiz And Survey Master (QSM) → Trinity Forms quiz (2.29.0).
 *
 * Reads QSM's tables directly ({prefix}mlw_quizzes, {prefix}mlw_questions), so it
 * works with QSM deactivated and with old installs (8.x) as well as current ones
 * (11.x), and never changes QSM's data.
 *
 * Where QSM keeps things:
 *  - Quiz options: in 7.x+ inside quiz_settings["quiz_options"] (a serialized
 *    array inside the serialized quiz_settings). The old columns of the same name
 *    (timer_limit, quiz_system, randomness_order) stop being updated, so they are
 *    only the fallback.
 *  - Pages: quiz_settings["pages"], arrays of question ids. Each page after the
 *    first becomes a Page Break.
 *  - Answers: question.answer_array, a serialized list of [text, points, correct].
 *  - "Required": question_settings["required"], where 0 means required.
 *  - Results page: quiz_settings["results_pages"] (11.x) or message_after (old).
 *
 * Multiple choice (vertical/horizontal) becomes a Radio question, Drop Down a
 * Dropdown question, each with its correct answer, points and the points a wrong
 * answer costs (QSM's negative answer points). Other question types come across
 * as plain fields without marks, or are listed in the report as skipped.
 * QSM's results are not brought over.
 */
class QSM extends Base_Importer {

    /** QSM question types (question_type_new). */
    private const MC       = [ "0", "1" ];
    private const DROPDOWN = "2";

    public function slug(): string {
        return "qsm";
    }

    public function label(): string {
        return "Quiz And Survey Master";
    }

    private static function quizzes_table(): string {
        global $wpdb;
        return $wpdb->prefix . "mlw_quizzes";
    }

    private static function questions_table(): string {
        global $wpdb;
        return $wpdb->prefix . "mlw_questions";
    }

    public function is_available(): bool {
        if ( ! self::table_readable( self::quizzes_table() ) ) {
            return false;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::quizzes_table() . " WHERE deleted = 0" ) > 0;
    }

    public function forms(): array {
        if ( ! self::table_readable( self::quizzes_table() ) ) {
            return [];
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
        $rows = (array) $wpdb->get_results( "SELECT quiz_id, quiz_name FROM " . self::quizzes_table() . " WHERE deleted = 0 ORDER BY quiz_id ASC", ARRAY_A );
        $out  = [];
        foreach ( $rows as $r ) {
            // QSM's results are not imported, so no count is offered for them.
            $out[ (int) $r["quiz_id"] ] = [ "title" => self::text( (string) $r["quiz_name"] ), "entries" => null ];
        }
        return $out;
    }

    /** QSM stores text through htmlspecialchars(); people should see the characters. */
    private static function text( string $s ): string {
        return trim( html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, "UTF-8" ) );
    }

    /** The quiz's options, newest storage first. @return array<string,mixed> */
    private static function options( array $quiz ): array {
        $settings = self::unserialize_safe( $quiz["quiz_settings"] ?? "" );
        $settings = is_array( $settings ) ? $settings : [];
        $opts     = self::unserialize_safe( $settings["quiz_options"] ?? "" );
        $opts     = is_array( $opts ) ? $opts : [];
        foreach ( [ "timer_limit" => "timer_limit", "system" => "quiz_system", "randomness_order" => "randomness_order", "submit_button_text" => "submit_button_text" ] as $key => $column ) {
            if ( ! array_key_exists( $key, $opts ) && array_key_exists( $column, $quiz ) ) {
                $opts[ $key ] = $quiz[ $column ];
            }
        }
        return $opts + [ "_settings" => $settings ];
    }

    /** "questions" / "answers" / "pages" from either storage of the randomize setting. @return string[] */
    private static function random_modes( $value ): array {
        $arr = is_array( $value ) ? $value : self::unserialize_safe( $value );
        if ( is_array( $arr ) ) {
            return array_values( array_intersect( array_map( "strval", $arr ), [ "questions", "answers", "pages" ] ) );
        }
        switch ( (int) $value ) {
            case 1:
                return [ "questions" ];
            case 2:
                return [ "questions", "answers", "pages" ];
            case 3:
                return [ "answers" ];
        }
        return [];
    }

    /** @return array<int,array<string,mixed>> question rows in the quiz's page order, with "_page" set. */
    private static function questions( array $quiz, array $settings ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            "SELECT * FROM " . self::questions_table() . " WHERE quiz_id = %d AND deleted = 0 ORDER BY question_order ASC, question_id ASC",
            (int) $quiz["quiz_id"]
        ), ARRAY_A );
        $by_id = [];
        foreach ( $rows as $r ) {
            $by_id[ (int) $r["question_id"] ] = $r;
        }
        $pages = self::unserialize_safe( $settings["pages"] ?? "" );
        $out   = [];
        if ( is_array( $pages ) && $pages ) {
            foreach ( array_values( $pages ) as $p => $ids ) {
                foreach ( (array) $ids as $qid ) {
                    if ( isset( $by_id[ (int) $qid ] ) ) {
                        $out[] = $by_id[ (int) $qid ] + [ "_page" => $p ];
                        unset( $by_id[ (int) $qid ] );
                    }
                }
            }
        }
        // Questions no page lists (a quiz never opened in the newer editor) keep their order.
        foreach ( $by_id as $r ) {
            $out[] = $r + [ "_page" => 0 ];
        }
        return $out;
    }

    public function convert( $source_id ): ?array {
        global $wpdb;
        if ( ! self::table_readable( self::quizzes_table() ) ) {
            return null;
        }
        $quiz = $wpdb->get_row( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            "SELECT * FROM " . self::quizzes_table() . " WHERE quiz_id = %d AND deleted = 0",
            (int) $source_id
        ), ARRAY_A );
        if ( ! $quiz ) {
            return null;
        }

        $opts      = self::options( $quiz );
        $settings  = $opts["_settings"];
        $skipped   = [];
        $fields    = [];
        $used      = [];
        $penalties = [];
        $page      = 0;
        $count     = 0;
        $tags      = [];

        // Contact fields first (QSM shows them before the questions by default).
        $contact = self::unserialize_safe( $settings["contact_form"] ?? "" );
        foreach ( is_array( $contact ) ? $contact : [] as $c ) {
            if ( ! is_array( $c ) || ( isset( $c["enable"] ) && ! in_array( (string) $c["enable"], [ "true", "1" ], true ) ) ) {
                continue;
            }
            $use   = (string) ( $c["use"] ?? "" );
            $type  = in_array( (string) ( $c["type"] ?? "" ), [ "email", "url", "number", "date" ], true ) ? (string) $c["type"] : "text";
            $type  = "phone" === $use ? "phone" : ( "url" === $type ? "website" : $type );
            $label = self::text( (string) ( $c["label"] ?? ucfirst( $use ) ) );
            $id    = self::unique_id( "" !== $use ? $use : $label, "contact", $used );
            $used[] = $id;
            $fields[] = [ "id" => $id, "type" => $type, "label" => $label, "required" => in_array( (string) ( $c["required"] ?? "" ), [ "true", "1" ], true ) ];
            if ( "name" === $use ) {
                $tags["%USER_NAME%"] = "{" . $id . "}";
            } elseif ( "email" === $use ) {
                $tags["%USER_EMAIL%"] = "{" . $id . "}";
            } elseif ( "phone" === $use ) {
                $tags["%USER_PHONE%"] = "{" . $id . "}";
            } elseif ( "comp" === $use ) {
                $tags["%USER_BUSINESS%"] = "{" . $id . "}";
            }
        }

        foreach ( self::questions( $quiz, $settings ) as $q ) {
            if ( (int) $q["_page"] > $page ) {
                $page     = (int) $q["_page"];
                $fields[] = [ "id" => self::unique_id( "page_" . ( $page + 1 ), "page", $used ), "type" => "page_break" ];
                $used[]   = end( $fields )["id"];
            }
            $qs    = self::unserialize_safe( $q["question_settings"] ?? "" );
            $qs    = is_array( $qs ) ? $qs : [];
            $type  = (string) ( $q["question_type_new"] ?? "0" );
            $title = self::text( wp_strip_all_tags( (string) ( $qs["question_title"] ?? "" ) ) );
            $desc  = self::text( wp_strip_all_tags( (string) ( $q["question_name"] ?? "" ) ) );
            $label = "" !== $title ? $title : $desc;
            $help  = "" !== $title && $desc !== $title ? $desc : "";
            $label = "" !== $label ? $label : sprintf( "Question %d", $count + 1 );
            $label = str_replace( "%BLANK%", "____", $label );
            $req   = isset( $qs["required"] ) && 0 === (int) $qs["required"];
            $id    = self::unique_id( "q" . (int) $q["question_id"], "question", $used );
            $used[] = $id;
            $answers = self::unserialize_safe( $q["answer_array"] ?? "" );
            $answers = is_array( $answers ) ? array_values( array_filter( $answers, "is_array" ) ) : [];

            $field = [ "id" => $id, "label" => $label, "required" => $req ];
            if ( "" !== $help ) {
                $field["help"] = $help;
            }

            if ( in_array( $type, self::MC, true ) || self::DROPDOWN === $type ) {
                $field["type"]    = self::DROPDOWN === $type ? "select" : "radio";
                $field["options"] = [];
                $correct          = null;
                $best             = null;
                foreach ( $answers as $i => $a ) {
                    $text = self::text( (string) ( $a[0] ?? "" ) );
                    if ( "" === $text ) {
                        continue;
                    }
                    $field["options"][] = [ "label" => $text, "value" => $text ];
                    $pts = (float) ( $a[1] ?? 0 );
                    if ( null === $correct && ! empty( $a[2] ) ) {
                        $correct = [ $text, $pts ];
                    }
                    if ( $pts > 0 && ( null === $best || $pts > $best[1] ) ) {
                        $best = [ $text, $pts ];
                    }
                }
                // A points-only quiz may leave "correct" unticked: the answer worth most is the right one.
                $correct = $correct ?? $best;
                if ( null !== $correct ) {
                    $field["correct_answer"] = $correct[0];
                    $field["points"]         = $correct[1] > 0 ? $correct[1] : 1.0;
                    $wrong = [];
                    foreach ( $answers as $a ) {
                        if ( self::text( (string) ( $a[0] ?? "" ) ) !== $correct[0] && (float) ( $a[1] ?? 0 ) < 0 ) {
                            $wrong[] = abs( (float) $a[1] );
                        }
                    }
                    $pen = $wrong ? max( $wrong ) : 0.0;
                    if ( $wrong && count( array_unique( $wrong ) ) > 1 ) {
                        /* translators: %s: question text. */
                        $skipped[] = sprintf( __( "%s: its wrong answers cost different points; every wrong answer now costs %s.", "trinity-forms" ), $label, (string) $pen );
                    }
                    $penalties[ $id ] = $pen;
                    $count++;
                } else {
                    /* translators: %s: question text. */
                    $skipped[] = sprintf( __( "%s: no correct answer is set, so it is not marked.", "trinity-forms" ), $label );
                }
                $fields[] = $field;
                continue;
            }

            // Not a marked question in Trinity: kept as a plain field where there is one.
            $plain = [
                "3"  => "text",      // Short answer
                "5"  => "textarea",  // Paragraph
                "7"  => "number",
                "12" => "date",
                "14" => "text",      // Fill in the blank
                "4"  => "checkbox",  // Multiple response
                "10" => "checkbox",
                "8"  => "consent",   // Opt-in
                "11" => "file",
                "6"  => "html",      // Text/HTML section
            ];
            if ( ! isset( $plain[ $type ] ) ) {
                $skipped[] = self::field_skipped( $label, self::type_name( $type ) );
                continue;
            }
            $field["type"] = $plain[ $type ];
            if ( "html" === $field["type"] ) {
                $field = [ "id" => $id, "type" => "html", "html" => wp_kses_post( html_entity_decode( (string) ( $q["question_name"] ?? "" ), ENT_QUOTES, "UTF-8" ) ) ];
            } elseif ( "checkbox" === $field["type"] ) {
                $field["options"] = [];
                foreach ( $answers as $a ) {
                    $t = self::text( (string) ( $a[0] ?? "" ) );
                    if ( "" !== $t ) {
                        $field["options"][] = [ "label" => $t, "value" => $t ];
                    }
                }
            } elseif ( "consent" === $field["type"] ) {
                $field["consent_text"] = esc_html( isset( $answers[0][0] ) ? self::text( (string) $answers[0][0] ) : $label );
            }
            if ( "html" !== $field["type"] && in_array( $type, [ "3", "4", "7", "10", "14" ], true ) ) {
                /* translators: 1: question text, 2: QSM question type. */
                $skipped[] = sprintf( __( "%1\$s (%2\$s): imported as a plain field. Trinity marks only Radio and Dropdown questions, so its answer is not scored.", "trinity-forms" ), $label, self::type_name( $type ) );
            }
            $fields[] = $field;
        }

        // One penalty for the whole quiz goes in the Quiz settings; mixed ones stay per question.
        $form_settings = [];
        $distinct      = array_values( array_unique( array_map( "strval", $penalties ) ) );
        if ( 1 === count( $distinct ) ) {
            $form_settings["quiz_wrong_points"] = (float) $distinct[0];
        } else {
            foreach ( $fields as $i => $f ) {
                if ( isset( $penalties[ $f["id"] ?? "" ] ) && $penalties[ $f["id"] ] > 0 ) {
                    $fields[ $i ]["wrong_points"] = $penalties[ $f["id"] ];
                }
            }
        }

        $minutes = (int) ( $opts["timer_limit"] ?? 0 );
        if ( $minutes > 0 ) {
            $form_settings["quiz_time_limit"] = $minutes;
        }
        $modes = self::random_modes( $opts["randomness_order"] ?? 0 );
        if ( in_array( "questions", $modes, true ) || in_array( "pages", $modes, true ) ) {
            $form_settings["quiz_shuffle_questions"] = true;
        }
        $shuffle_any = in_array( "answers", $modes, true );
        foreach ( self::questions( $quiz, $settings ) as $q ) {
            $qs = self::unserialize_safe( $q["question_settings"] ?? "" );
            if ( is_array( $qs ) && "random" === ( $qs["matchAnswer"] ?? "" ) ) {
                $shuffle_any = true;
            }
        }
        if ( $shuffle_any ) {
            $form_settings["quiz_shuffle_options"] = true;
        }
        if ( ( $minutes > 0 || ! empty( $form_settings["quiz_shuffle_questions"] ) || $shuffle_any ) && ! \TrinityForms\License::is_pro() ) {
            $skipped[] = __( "Time limit and shuffling are saved with the quiz and work with Trinity Forms Pro.", "trinity-forms" );
        }
        $button = self::text( (string) ( $opts["submit_button_text"] ?? $quiz["submit_button_text"] ?? "" ) );
        if ( "" !== $button ) {
            $form_settings["submit_button_text"] = $button;
        }

        // The results page becomes the confirmation, QSM's %TAGS% swapped for ours.
        $tags += [
            "%CORRECT_SCORE%%"      => "{quiz_percent}",
            "%CORRECT_SCORE%"       => "{quiz_percent}",
            "%POINT_SCORE%"         => "{quiz_score}",
            "%MAXIMUM_POINTS%"      => "{quiz_max}",
            "%AMOUNT_CORRECT%"      => "{quiz_correct}",
            "%CORRECT%"             => "{quiz_correct}",
            "%AMOUNT_INCORRECT%"    => "{quiz_wrong}",
            "%TOTAL_QUESTIONS%"     => (string) $count,
            "%QUESTIONS_ANSWERS%"   => "{quiz_answer_key}",
            "%QUESTIONS_ANSWERS_EMAIL%" => "{quiz_answer_key}",
            "%QUIZ_NAME%"           => "{form_title}",
            "%TIMER%"               => "{quiz_time}",
            "%TIMER_MINUTES%"       => "{quiz_time}",
            "%CURRENT_DATE%"        => "{date}",
            "%CONTACT_ALL%"         => "{all_fields}",
        ];
        $page_html = self::results_page( $quiz, $settings );
        if ( "" !== $page_html ) {
            $page_html = strtr( $page_html, $tags );
            if ( preg_match_all( '/%[A-Z_]+%/', $page_html, $m ) ) {
                $left = array_values( array_unique( $m[0] ) );
                $page_html = str_replace( $left, "", $page_html );
                /* translators: %s: list of QSM template variables. */
                $skipped[] = sprintf( __( "Results page: %s had no equivalent and were left out.", "trinity-forms" ), implode( ", ", $left ) );
            }
            $form_settings["confirmation_message"] = $page_html;
        } else {
            $form_settings["confirmation_message"] = __( "Your score: {quiz_score} / {quiz_max}", "trinity-forms" ) . "<br>{quiz_answer_key}";
        }

        return [
            "title"         => self::text( (string) $quiz["quiz_name"] ),
            "fields"        => $fields,
            "settings"      => $form_settings,
            "notifications" => [],
            "skipped"       => $skipped,
        ];
    }

    /** The first results page's HTML (the default one when it has conditions). */
    private static function results_page( array $quiz, array $settings ): string {
        $pages = self::unserialize_safe( $settings["results_pages"] ?? "" );
        if ( is_array( $pages ) && $pages ) {
            $chosen = null;
            foreach ( $pages as $p ) {
                if ( is_array( $p ) && empty( $p["conditions"] ) ) {
                    $chosen = $p;
                    break;
                }
            }
            $chosen = $chosen ?? ( is_array( reset( $pages ) ) ? reset( $pages ) : [] );
            $html   = (string) ( $chosen["page"] ?? "" );
        } else {
            $legacy = self::unserialize_safe( $quiz["message_after"] ?? "" );
            $html   = is_array( $legacy ) ? (string) ( $legacy[0][2] ?? $legacy[0]["page"] ?? "" ) : (string) ( $quiz["message_after"] ?? "" );
        }
        return wp_kses_post( html_entity_decode( $html, ENT_QUOTES, "UTF-8" ) );
    }

    private static function type_name( string $type ): string {
        $names = [
            "0" => "Multiple Choice", "1" => "Multiple Choice (Horizontal)", "2" => "Drop Down", "3" => "Short Answer",
            "4" => "Multiple Response", "5" => "Paragraph", "6" => "Text/HTML Section", "7" => "Number", "8" => "Opt-in",
            "9" => "Captcha", "10" => "Multiple Response (Horizontal)", "11" => "File Upload", "12" => "Date", "13" => "Polar",
            "14" => "Fill In The Blank", "15" => "Matching Pairs", "16" => "Radio Grid", "17" => "Checkbox Grid",
        ];
        return $names[ $type ] ?? "type " . $type;
    }
}
