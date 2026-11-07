<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Poll results for visitors (free, since 5 Nov 2026).
 *
 * The admin Results page (Results::aggregate(), free) already adds up every
 * choice and rating question. This shows the same numbers to the people who
 * voted:
 *
 *   {poll_results}            every chartable question, in a confirmation or email
 *   {poll_results:<field id>} one question
 *   [trinity_forms_poll_results id="12" field="" after_vote="1"]
 *   the "Trinity Forms Poll Results" block (same options)
 *
 * Page caches: the shortcode's markup is the same for everyone. With after_vote
 * it holds no numbers at all, only a note; the browser asks the REST route for
 * fresh results once this visitor has voted (remembered in localStorage) and
 * again right after a vote on the same page. The route answers only for a
 * form + question pair a published shortcode or block signed, so it can't be
 * used to read the answers of a form nobody chose to show.
 *
 * Markup is spans with inline styles, no block tags and no stylesheet: it has
 * to survive wp_kses_post(), keep the line breaks of a plain-text confirmation
 * (Notification_Engine::body_html() only adds <br> when it finds no block tags)
 * and look right inside an email.
 */
class Poll_Results {

    public const SHORTCODE = "trinity_forms_poll_results";
    public const ROUTE     = "/poll-results/(?P<form_id>\d+)";

    /** Field types Results::aggregate() tallies as choices. */
    private const CHOICE_TYPES = [ "radio", "select", "checkbox" ];

    public static function register(): void {
        add_filter( "trinity_forms_merge_template", [ __CLASS__, "merge_template" ], 10, 3 );
        add_filter( "trinity_forms_merge_tag_catalogue", [ __CLASS__, "catalogue" ], 20 );
        add_action( "trinity_forms_register_shortcodes", static function () {
            add_shortcode( self::SHORTCODE, [ __CLASS__, "shortcode" ] );
        } );
        add_action( "trinity_forms_register_rest_routes", static function ( $namespace ) {
            register_rest_route( (string) $namespace, self::ROUTE, [
                "methods"             => "GET",
                "callback"            => [ __CLASS__, "rest" ],
                "permission_callback" => "__return_true",
                "args"                => [
                    "field" => [ "type" => "string", "default" => "" ],
                    "sig"   => [ "type" => "string", "default" => "" ],
                ],
            ] );
        }, 12 );
        add_action( "wp_enqueue_scripts", [ __CLASS__, "register_assets" ] );
    }

    public static function register_assets(): void {
        wp_register_script(
            "trinity-forms-poll-results",
            \TrinityForms\Public_Front::asset_url( "public/assets/js/poll-results.js" ),
            [],
            TRINITY_FORMS_VERSION,
            true
        );
    }

    /* ------------------------------------------------------------- merge tags */

    /**
     * Replace {poll_results} / {poll_results:<id>} in a template. A template
     * without the tag costs nothing: the aggregate query only runs when needed.
     */
    public static function merge_template( $template, $entry_id = 0, $form_id = 0 ): string {
        $template = (string) $template;
        if ( false === strpos( $template, "{poll_results" ) || ! $form_id ) {
            return $template;
        }
        return (string) preg_replace_callback(
            '/\{poll_results(?::([a-z0-9_\-]+))?\}/i',
            static fn( $m ) => self::render( (int) $form_id, isset( $m[1] ) ? sanitize_key( $m[1] ) : "" ),
            $template
        );
    }

    /** @param array<int,array<string,mixed>> $groups */
    public static function catalogue( array $groups ): array {
        $groups[] = [
            "label" => __( "Poll results", "trinity-forms" ),
            "tags"  => [
                [ "tag" => "{poll_results}", "label" => __( "Results so far for every choice and rating question", "trinity-forms" ) ],
            ],
        ];
        return $groups;
    }

    /* -------------------------------------------------------------- rendering */

    /**
     * The results as kses-safe HTML.
     *
     * @param string $field_id One question, or "" for every chartable one.
     */
    public static function render( int $form_id, string $field_id = "" ): string {
        $form = Form_Manager::get( $form_id );
        if ( ! $form ) {
            return "";
        }
        $data   = Results::aggregate( $form_id );
        $labels = Form_Manager::choice_labels( $form_id );
        $by_id  = [];
        foreach ( $data["fields"] as $f ) {
            $by_id[ (string) $f["id"] ] = $f;
        }

        $out = "";
        foreach ( $form->fields() as $field ) {
            $id = (string) $field->id;
            if ( "" !== $field_id && $id !== $field_id ) {
                continue;
            }
            $agg = $by_id[ $id ] ?? null;
            if ( in_array( $field->type, self::CHOICE_TYPES, true ) && ! empty( $labels[ $id ] ) ) {
                $out .= self::choice_block( (string) ( $field->label ?: $id ), $labels[ $id ], $agg );
            } elseif ( $agg && ! empty( $agg["summary_html"] ) ) {
                // Survey fields (Likert, NPS, ranking) chart themselves: the trinity_forms_results_field filter.
                $out .= '<span class="tf-poll-question" style="display:block;margin:0 0 1.25em">'
                    . self::title( (string) ( $field->label ?: $id ), (int) ( $agg["answered"] ?? 0 ) )
                    . wp_kses_post( (string) $agg["summary_html"] ) . "</span>";
            } elseif ( $agg && isset( $agg["avg"] ) ) {
                $out .= self::number_block( (string) ( $field->label ?: $id ), $agg );
            } else {
                $extra = apply_filters( "trinity_forms_poll_results_field", "", $field, $agg, $form_id );
                $out  .= is_string( $extra ) ? $extra : "";
            }
        }
        if ( "" === $out ) {
            return "";
        }
        return '<span class="tf-poll-results-list" style="display:block;margin:1em 0">' . $out . "</span>";
    }

    /**
     * One choice question: every option in the form's order, with its share of
     * the people who answered (a checkbox question can add up to more than 100%).
     *
     * @param array<string,string>      $options value => label
     * @param array<string,mixed>|null  $agg     Results::aggregate() row
     */
    private static function choice_block( string $title, array $options, ?array $agg ): string {
        $counts   = (array) ( $agg["counts"] ?? [] );
        $answered = (int) ( $agg["answered"] ?? 0 );
        $rows     = [];
        foreach ( $options as $label ) {
            $rows[ $label ] = (int) ( $counts[ $label ] ?? 0 );
        }
        // Answers whose option was renamed or removed since: still counted.
        foreach ( $counts as $label => $n ) {
            if ( ! isset( $rows[ (string) $label ] ) ) {
                $rows[ (string) $label ] = (int) $n;
            }
        }
        $html = self::title( $title, $answered );
        foreach ( $rows as $label => $n ) {
            $pct   = $answered > 0 ? (int) round( $n / $answered * 100 ) : 0;
            $html .= self::bar( (string) $label, $pct, sprintf(
                /* translators: 1: percentage, 2: number of votes. */
                __( '%1$d%% (%2$s)', "trinity-forms" ),
                $pct,
                number_format_i18n( $n )
            ) );
        }
        return '<span class="tf-poll-question" style="display:block;margin:0 0 1.25em">' . $html . "</span>";
    }

    /** One rating, range or number question: its average. */
    public static function number_block( string $title, array $agg ): string {
        $avg  = (float) $agg["avg"];
        $html = self::title( $title, (int) ( $agg["answered"] ?? 0 ) );
        $html .= '<span class="tf-poll-average" style="display:block">' . esc_html( sprintf(
            /* translators: 1: average, 2: lowest answer, 3: highest answer. */
            __( 'Average %1$s (from %2$s to %3$s)', "trinity-forms" ),
            number_format_i18n( $avg, floor( $avg ) == $avg ? 0 : 1 ),
            number_format_i18n( (float) $agg["min"], floor( (float) $agg["min"] ) == (float) $agg["min"] ? 0 : 1 ),
            number_format_i18n( (float) $agg["max"], floor( (float) $agg["max"] ) == (float) $agg["max"] ? 0 : 1 )
        ) ) . "</span>";
        return '<span class="tf-poll-question" style="display:block;margin:0 0 1.25em">' . $html . "</span>";
    }

    /** A question's heading and vote count. Public so survey fields can reuse it. */
    public static function title( string $title, int $answered ): string {
        return '<span class="tf-poll-title" style="display:block;font-weight:600">' . esc_html( $title ) . "</span>"
            . '<span class="tf-poll-count" style="display:block;font-size:.875em;opacity:.75;margin:0 0 .4em">' . esc_html( sprintf(
                /* translators: %s: number of votes. */
                _n( "%s vote", "%s votes", $answered, "trinity-forms" ),
                number_format_i18n( $answered )
            ) ) . "</span>";
    }

    /** One labelled bar. $pct is 0-100. Public so survey fields can reuse it. */
    public static function bar( string $label, int $pct, string $figure ): string {
        $pct = max( 0, min( 100, $pct ) );
        return '<span class="tf-poll-row" style="display:block;margin:0 0 .5em">'
            . '<span class="tf-poll-label" style="display:block">' . esc_html( $label ) . ' <b class="tf-poll-figure">' . esc_html( $figure ) . "</b></span>"
            . '<span class="tf-poll-track" style="display:block;height:.6em;border-radius:999px;background-color:#7f7f7f33;overflow:hidden">'
            . '<span class="tf-poll-fill" style="display:block;height:100%;width:' . $pct . '%;border-radius:999px;background-color:var(--trinity-forms-accent,#2271b1)"></span>'
            . "</span></span>";
    }

    /* -------------------------------------------------- shortcode, block, REST */

    /** The signature that lets the REST route answer for this form + question. */
    public static function sig( int $form_id, string $field_id ): string {
        return substr( wp_hash( "tf_poll_results|" . $form_id . "|" . $field_id, "nonce" ), 0, 20 );
    }

    /** @param array<string,mixed>|string $atts */
    public static function shortcode( $atts ): string {
        Public_Front::need_assets();
        $atts     = shortcode_atts( [ "id" => 0, "field" => "", "after_vote" => "0" ], (array) $atts, self::SHORTCODE );
        $form_id  = (int) $atts["id"];
        $field_id = sanitize_key( (string) $atts["field"] );
        $after    = in_array( strtolower( (string) $atts["after_vote"] ), [ "1", "true", "yes", "on" ], true );
        $form     = $form_id ? Form_Manager::get_public( $form_id ) : null;
        if ( ! $form ) {
            return current_user_can( "edit_posts" )
                ? '<p class="tf-poll-results-notice"><em>' . esc_html__( "Poll results: choose a published form.", "trinity-forms" ) . "</em></p>"
                : "";
        }

        self::enqueue();
        $inner = $after
            ? '<p class="tf-poll-results-wait">' . esc_html__( "The results appear here after you vote.", "trinity-forms" ) . "</p>"
            : self::render( $form_id, $field_id );
        if ( "" === $inner ) {
            $inner = '<p class="tf-poll-results-empty">' . esc_html__( "No votes yet.", "trinity-forms" ) . "</p>";
        }
        return self::box( $form_id, $field_id, $after, $inner );
    }

    public static function enqueue(): void {
        if ( ! wp_script_is( "trinity-forms-poll-results", "registered" ) ) {
            self::register_assets();
        }
        wp_enqueue_script( "trinity-forms-poll-results" );
    }

    /**
     * The results box: the same markup for every visitor (a page cache can keep it),
     * with what the script needs to ask the signed REST route for the numbers.
     *
     * @param string $attrs Extra, already escaped attributes.
     */
    private static function box( int $form_id, string $field_id, bool $after, string $inner, string $attrs = "" ): string {
        return sprintf(
            '<div class="tf-poll-results" data-tf-poll="%1$d" data-field="%2$s" data-sig="%3$s" data-after-vote="%4$s" data-endpoint="%5$s"%7$s aria-live="polite">%6$s</div>',
            $form_id,
            esc_attr( $field_id ),
            esc_attr( self::sig( $form_id, $field_id ) ),
            $after ? "1" : "0",
            esc_url( rest_url( "trinity-forms/v1/poll-results/" . $form_id ) ),
            $inner,
            $attrs
        );
    }

    /* ------------------------------------------------------------- Poll mode */

    /** Question types the results can chart. */
    private const CHARTABLE = [ "radio", "select", "checkbox", "rating", "range", "likert", "nps", "ranking" ];

    /** Is this form a poll: the setting is on and there is a question to chart? */
    public static function is_poll( Form $form ): bool {
        if ( ! $form->get_setting( "poll_mode", false ) ) {
            return false;
        }
        foreach ( $form->fields() as $field ) {
            if ( in_array( (string) $field->type, self::CHARTABLE, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Poll mode around a form's markup. The server output is identical for every
     * visitor, so a full-page cache is fine: the form is there, and a hidden results
     * box next to it. A tiny inline script, running before the form is painted, marks
     * the wrapper when this browser already voted (localStorage, the same key the
     * script keeps), so a returning voter never sees the form flash up. Without
     * JavaScript nothing is marked and the form simply shows.
     */
    public static function wrap_form( Form $form, string $html ): string {
        self::enqueue();
        $id  = (int) $form->id;
        // The rule travels with every wrapper (about 100 bytes), so the markup is the
        // same wherever and however often the form is drawn.
        $out = '<div class="trinity-forms-poll" data-tf-poll-form="' . $id . '">'
            . '<style>.trinity-forms-poll.tf-poll-voted>.trinity-forms-form{display:none!important}</style>'
            . '<script>(function(s){try{if(window.localStorage.getItem("tf_voted_' . $id . '")){s.parentNode.className+=" tf-poll-voted"}}catch(e){}})(document.currentScript)</script>'
            . $html
            . self::box( $id, "", true, "", ' data-tf-poll-mode="1" hidden' )
            . "</div>";
        return $out;
    }

    /**
     * The results in place of the form, drawn on the server: for a logged-in visitor
     * a per-user entry limit has stopped (they have voted; that is not an error).
     */
    public static function results_instead_of_form( Form $form ): string {
        self::enqueue();
        $inner = self::render( (int) $form->id );
        if ( "" === $inner ) {
            $inner = '<p class="tf-poll-results-empty">' . esc_html__( "No votes yet.", "trinity-forms" ) . "</p>";
        }
        return '<div class="trinity-forms-poll tf-poll-voted" data-tf-poll-form="' . (int) $form->id . '">'
            . self::box( (int) $form->id, "", false, $inner ) . "</div>";
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function rest( \WP_REST_Request $request ) {
        $form_id  = (int) $request["form_id"];
        $field_id = sanitize_key( (string) $request->get_param( "field" ) );
        $sig      = (string) $request->get_param( "sig" );
        if ( ! hash_equals( self::sig( $form_id, $field_id ), $sig ) || ! Form_Manager::get_public( $form_id ) ) {
            return new \WP_Error( "trinity_forms_poll_results", __( "These results are not available.", "trinity-forms" ), [ "status" => 403 ] );
        }
        $html = self::render( $form_id, $field_id );
        $res  = new \WP_REST_Response( [
            "success" => true,
            "data"    => [ "html" => "" !== $html ? $html : '<p class="tf-poll-results-empty">' . esc_html__( "No votes yet.", "trinity-forms" ) . "</p>" ],
        ] );
        // Fresh numbers: neither a page cache nor the browser may keep them.
        $res->header( "Cache-Control", "no-store, max-age=0" );
        return $res;
    }
}
