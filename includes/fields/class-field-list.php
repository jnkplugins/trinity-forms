<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * A repeating list of rows — "add another" for simple tabular answers.
 *
 * Deliberately the cheap version of repetition: one or more plain text columns, no
 * per-cell field types, no conditional logic inside a row. The premium Repeater
 * field is the one that repeats a whole group of real fields; this covers the far
 * commoner "list your team members" case without needing it.
 *
 * Stored as a list of rows, each row a map of column => value, so a two-column list
 * round-trips through JSON without losing which value was in which column.
 */
class Field_List extends Field_Base {

    /** @return array<int,string> */
    private function columns( array $config ): array {
        $columns = array_values( array_filter( array_map(
            static fn( $c ) => trim( (string) ( is_array( $c ) ? ( $c["label"] ?? "" ) : $c ) ),
            (array) ( $config["columns"] ?? [] )
        ) ) );

        // A list with no configured columns is still a list — one unnamed column.
        return $columns ?: [ "" ];
    }

    public function render( array $config, $value = null ): string {
        $id      = esc_attr( $config["id"] ?? "" );
        $name    = esc_attr( $this->attr_name( $config ) );
        $columns = $this->columns( $config );

        $rows = (array) ( $value ?? [] );
        if ( ! $rows ) {
            $rows = [ [] ];
        }

        $head = "";
        if ( "" !== implode( "", $columns ) ) {
            $head = "<thead><tr>";
            foreach ( $columns as $col ) {
                $head .= "<th>" . esc_html( $col ) . "</th>";
            }
            $head .= '<th class="trinity-forms-list-actions"><span class="screen-reader-text">'
                . esc_html__( "Actions", "trinity-forms" ) . "</span></th></tr></thead>";
        }

        $body = "";
        foreach ( $rows as $r => $row ) {
            $body .= "<tr>";
            foreach ( $columns as $c => $col ) {
                $cell_value = is_array( $row ) ? (string) ( $row[ $col ] ?? $row[ $c ] ?? "" ) : "";
                $body .= "<td>"
                    . '<label class="screen-reader-text" for="' . $id . "_" . (int) $r . "_" . (int) $c . '">'
                    . esc_html( $col ?: ( $config["label"] ?? "" ) ) . "</label>"
                    . '<input type="text" id="' . $id . "_" . (int) $r . "_" . (int) $c . '"'
                    . ' name="' . $name . "[" . (int) $r . "][" . esc_attr( $col ) . ']"'
                    . ' value="' . esc_attr( $cell_value ) . '" />'
                    . "</td>";
            }
            $body .= '<td class="trinity-forms-list-actions">'
                . '<button type="button" class="trinity-forms-list-remove" aria-label="'
                . esc_attr__( "Remove row", "trinity-forms" ) . '">&times;</button></td>';
            $body .= "</tr>";
        }

        $control = '<table class="trinity-forms-list" data-field="' . $id . '">'
            . $head . "<tbody>" . $body . "</tbody></table>"
            . '<button type="button" class="trinity-forms-list-add">'
            . esc_html__( "+ Add row", "trinity-forms" ) . "</button>";

        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $columns = $this->columns( $config );
        $out     = [];

        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean = [];
            foreach ( $columns as $col ) {
                $clean[ $col ] = sanitize_text_field( (string) ( $row[ $col ] ?? "" ) );
            }
            // Drop rows the visitor left entirely blank rather than storing a row of
            // empty strings for every unused "add another" click.
            if ( "" !== trim( implode( "", $clean ) ) ) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    public function validate( $value, array $config ): array {
        $rows = (array) $value;

        $max = isset( $config["max_rows"] ) ? (int) $config["max_rows"] : 0;
        if ( $max > 0 && count( $rows ) > $max ) {
            return [ sprintf(
                /* translators: %d: maximum number of rows. */
                __( "Please add no more than %d rows.", "trinity-forms" ),
                $max
            ) ];
        }

        return [];
    }

    public function to_csv_value( $stored ): string {
        if ( ! is_array( $stored ) ) {
            return (string) $stored;
        }
        // One row per line, cells separated — readable in a spreadsheet cell without
        // needing the reader to parse JSON.
        $lines = [];
        foreach ( $stored as $row ) {
            $lines[] = is_array( $row ) ? implode( " | ", array_map( "strval", $row ) ) : (string) $row;
        }
        return implode( "\n", $lines );
    }
}
