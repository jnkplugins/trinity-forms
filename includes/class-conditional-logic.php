<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Show/hide rules, shared by fields, notifications and confirmations.
 *
 * All three call is_visible() with a Field — a real one for fields, a throwaway one
 * wrapping the rules for the other two. That is why an operator added here works in
 * every place rules can be written, with no second implementation to keep in step.
 */
class Conditional_Logic {

    /**
     * Every operator, and whether it needs a value typed next to it.
     *
     * Exposed so the editors can build their dropdowns from the same list the
     * evaluator uses; before this the UI hard-coded eight operators and adding a
     * ninth meant remembering three files.
     *
     * @return array<string,array{label:string,needs_value:bool}>
     */
    public static function operators(): array {
        return [
            "equals"          => [ "label" => __( "is", "trinity-forms" ),                  "needs_value" => true ],
            "not_equals"      => [ "label" => __( "is not", "trinity-forms" ),              "needs_value" => true ],
            "contains"        => [ "label" => __( "contains", "trinity-forms" ),            "needs_value" => true ],
            "not_contains"    => [ "label" => __( "does not contain", "trinity-forms" ),    "needs_value" => true ],
            "starts_with"     => [ "label" => __( "starts with", "trinity-forms" ),         "needs_value" => true ],
            "ends_with"       => [ "label" => __( "ends with", "trinity-forms" ),           "needs_value" => true ],
            "greater_than"    => [ "label" => __( "is greater than", "trinity-forms" ),     "needs_value" => true ],
            "less_than"       => [ "label" => __( "is less than", "trinity-forms" ),        "needs_value" => true ],
            "is_before"       => [ "label" => __( "is before", "trinity-forms" ),           "needs_value" => true ],
            "is_after"        => [ "label" => __( "is after", "trinity-forms" ),            "needs_value" => true ],
            "in_list"         => [ "label" => __( "is one of", "trinity-forms" ),           "needs_value" => true ],
            "not_in_list"     => [ "label" => __( "is none of", "trinity-forms" ),          "needs_value" => true ],
            "matches_pattern" => [ "label" => __( "matches pattern", "trinity-forms" ),     "needs_value" => true ],
            "empty"           => [ "label" => __( "is empty", "trinity-forms" ),            "needs_value" => false ],
            "not_empty"       => [ "label" => __( "is not empty", "trinity-forms" ),        "needs_value" => false ],
        ];
    }

    /**
     * Clean a rule set coming from an editor, keeping nested groups intact.
     *
     * The lenient counterpart to Builder_REST's version: that one answers an API
     * client and says what was wrong, this one is for form posts where the right
     * response to a malformed row is to drop it.
     *
     * Both read the operator list from operators() rather than restating it.
     * Restating it is how "starts with" quietly became "is" on save.
     *
     * @param mixed $raw
     * @return array<string,mixed> Empty when there is nothing worth storing.
     */
    public static function sanitize_rules( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $group = self::sanitize_group( $raw, 0 );
        if ( ! $group ) {
            return [];
        }

        // The action belongs to the outermost group only; nested ones are just
        // and/or containers.
        //
        // Prepended rather than appended so the serialized key order stays
        // action/match/conditions. Block attributes are JSON, so order is
        // semantically irrelevant — but the builder compares its output against
        // what is stored to decide whether a form actually changed, and reordering
        // keys would make every save rewrite every block.
        return array_merge(
            [
                "action" => in_array( $raw["action"] ?? "", [ "show", "hide" ], true )
                    ? (string) $raw["action"]
                    : "show",
            ],
            $group
        );
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,mixed> Empty when the group holds nothing answerable.
     */
    private static function sanitize_group( array $group, int $depth ): array {
        if ( $depth > 10 || empty( $group["conditions"] ) || ! is_array( $group["conditions"] ) ) {
            return [];
        }

        $operators  = array_keys( self::operators() );
        $conditions = [];

        foreach ( $group["conditions"] as $cond ) {
            if ( ! is_array( $cond ) ) {
                continue;
            }

            if ( isset( $cond["conditions"] ) ) {
                $nested = self::sanitize_group( $cond, $depth + 1 );
                // An empty group evaluates to "true" forever, which reads as a rule
                // that does nothing — drop it rather than store it.
                if ( $nested ) {
                    $conditions[] = $nested;
                }
                continue;
            }

            $field = sanitize_key( (string) ( $cond["field"] ?? "" ) );
            if ( "" === $field ) {
                continue;
            }

            $value = $cond["value"] ?? "";
            $conditions[] = [
                "field"    => $field,
                "operator" => in_array( $cond["operator"] ?? "", $operators, true )
                    ? (string) $cond["operator"]
                    : "equals",
                "value"    => is_array( $value )
                    ? array_map( "sanitize_text_field", array_map( "strval", $value ) )
                    : sanitize_text_field( (string) $value ),
            ];
        }

        if ( ! $conditions ) {
            return [];
        }

        return [
            "match"      => in_array( $group["match"] ?? "", [ "all", "any" ], true ) ? (string) $group["match"] : "all",
            "conditions" => $conditions,
        ];
    }

    /**
     * Field config can include:
     *
     *   "conditional" => [
     *       "action"     => "show" | "hide",
     *       "match"      => "all"  | "any",
     *       "conditions" => [
     *           [ "field" => "f_country", "operator" => "equals", "value" => "US" ],
     *           // …or a nested group, for "A and (B or C)":
     *           [ "match" => "any", "conditions" => [ … ] ],
     *       ],
     *   ]
     *
     * Returns whether the field should be visible given the current payload.
     */
    public static function is_visible( Field $field, array $payload ): bool {
        $rules = $field->config["conditional"] ?? null;
        if ( ! $rules || empty( $rules["conditions"] ) ) {
            return true;
        }

        $action = $rules["action"] ?? "show";
        $passed = self::evaluate_group( (array) $rules, $payload );

        return "hide" === $action ? ! $passed : $passed;
    }

    /**
     * Evaluate one group of conditions, recursing into nested groups.
     *
     * A group is anything with a "conditions" key; a leaf is anything with a
     * "field" key. That is what lets "A and (B or C)" be written without a second
     * rule format.
     *
     * @param array<string,mixed> $group
     * @param array<string,mixed> $payload
     */
    private static function evaluate_group( array $group, array $payload, int $depth = 0 ): bool {
        // A malformed or hand-edited rule set could nest forever; the limit is far
        // above anything the editors can produce.
        if ( $depth > 10 ) {
            return true;
        }

        $matcher = ( $group["match"] ?? "all" ) === "any" ? "any" : "all";
        $results = [];

        foreach ( (array) ( $group["conditions"] ?? [] ) as $cond ) {
            if ( ! is_array( $cond ) ) {
                continue;
            }
            if ( isset( $cond["conditions"] ) ) {
                $results[] = self::evaluate_group( $cond, $payload, $depth + 1 );
                continue;
            }
            $other     = $payload[ $cond["field"] ?? "" ] ?? null;
            $results[] = self::compare( $other, (string) ( $cond["operator"] ?? "equals" ), $cond["value"] ?? "" );
        }

        if ( ! $results ) {
            return true;
        }

        return "any" === $matcher
            ? in_array( true, $results, true )
            : ! in_array( false, $results, true );
    }

    private static function compare( $left, string $op, $right ): bool {
        $l = is_array( $left )  ? array_map( "strval", $left )  : (string) ( $left  ?? "" );
        $r = is_array( $right ) ? implode( ",", array_map( "strval", $right ) ) : (string) ( $right ?? "" );

        switch ( $op ) {
            case "equals":       return is_array( $l ) ? in_array( $r, $l, true ) : $l === $r;
            case "not_equals":   return is_array( $l ) ? ! in_array( $r, $l, true ) : $l !== $r;
            case "contains":     return is_array( $l ) ? in_array( $r, $l, true ) : str_contains( $l, $r );
            case "not_contains": return is_array( $l ) ? ! in_array( $r, $l, true ) : ! str_contains( $l, $r );
            case "empty":        return "" === $l || [] === $l;
            case "not_empty":    return "" !== $l && [] !== $l;
            case "greater_than": return is_numeric( $l ) && is_numeric( $r ) && (float) $l > (float) $r;
            case "less_than":    return is_numeric( $l ) && is_numeric( $r ) && (float) $l < (float) $r;

            case "starts_with":
                return ! is_array( $l ) && "" !== $r && str_starts_with( $l, $r );
            case "ends_with":
                return ! is_array( $l ) && "" !== $r && str_ends_with( $l, $r );

            // Dates rather than numbers, so "2026-03-01" compares correctly against
            // "2026-11-01" — which greater_than would get wrong as a string and
            // refuse as a non-number.
            case "is_before":
            case "is_after":
                if ( is_array( $l ) || "" === $l || "" === $r ) {
                    return false;
                }
                $lt = strtotime( $l );
                $rt = strtotime( $r );
                if ( false === $lt || false === $rt ) {
                    return false;
                }
                return "is_before" === $op ? $lt < $rt : $lt > $rt;

            // A comma-separated list, which is how someone writes "one of these
            // three countries" without three separate OR rows.
            case "in_list":
            case "not_in_list":
                $list = array_filter( array_map( "trim", explode( ",", $r ) ), static fn( $v ) => "" !== $v );
                $hit  = is_array( $l )
                    ? (bool) array_intersect( $l, $list )
                    : in_array( $l, $list, true );
                return "in_list" === $op ? $hit : ! $hit;

            case "matches_pattern":
                if ( is_array( $l ) || "" === $r ) {
                    return false;
                }
                // Delimited here rather than trusting the author to supply delimiters,
                // and errors suppressed so a bad pattern hides the field instead of
                // throwing a warning onto the page.
                $pattern = "/" . str_replace( "/", "\\/", $r ) . "/u";
                return 1 === @preg_match( $pattern, $l );
        }

        return false;
    }
}
