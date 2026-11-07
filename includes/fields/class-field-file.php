<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

class Field_File extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $accept   = esc_attr( $config["accept"] ?? "" );
        $multiple = ! empty( $config["multiple"] ) ? " multiple" : "";
        $required = ! empty( $config["required"] ) ? " required" : "";
        $bracket  = $multiple ? "[]" : "";
        // The real limit, so the page script can say "too big" before sending a
        // file the server would throw away.
        $max      = self::max_bytes( $config );
        $limits   = ' data-max-bytes="' . esc_attr( (string) $max ) . '" data-max-label="' . esc_attr( size_format( $max ) ) . '"';
        $control  = '<input type="file" id="' . $id . '" name="' . $name . $bracket . '" accept="' . $accept . '"' . $multiple . $required . $limits . ' />';
        return $this->wrap( $config, $control );
    }

    /**
     * Uploads stored during this request, by path. The only values sanitize() lets
     * through.
     *
     * @var array<string,array<string,mixed>>
     */
    private static array $issued = [];

    /**
     * Register a file the server itself just stored (Submission_Pipeline does this
     * for every upload). Returns the same item, for chaining.
     *
     * @param array<string,mixed> $item filename, url, type, size, path.
     * @return array<string,mixed>
     */
    public static function issue( array $item ): array {
        $path = (string) ( $item["path"] ?? "" );
        if ( "" !== $path ) {
            self::$issued[ $path ] = $item;
        }
        return $item;
    }

    public function sanitize( $raw, array $config ) {
        // A file value never comes from the request: the upload arrives in $_FILES
        // and the pipeline builds the metadata (filename, path, size). Anything
        // else, e.g. a posted {"path": "/any/file/on/the/server"}, is dropped; it
        // used to be stored, mailed as an attachment and deleted with the entry.
        if ( ! is_array( $raw ) ) {
            return null;
        }
        $single = isset( $raw["path"] ) || isset( $raw["filename"] );
        $out    = [];
        foreach ( $single ? [ $raw ] : $raw as $item ) {
            $path = is_array( $item ) && is_string( $item["path"] ?? null ) ? $item["path"] : "";
            if ( "" !== $path && isset( self::$issued[ $path ] ) ) {
                // Our own copy, not the caller's: nothing in it can be edited.
                $out[] = self::$issued[ $path ];
            }
        }
        if ( ! $out ) {
            return null;
        }
        return $single ? $out[0] : $out;
    }

    public function validate( $value, array $config ): array {
        $errors = [];
        if ( ! empty( $config["required"] ) && empty( $value ) ) {
            $errors[] = __( "This field is required.", "trinity-forms" );
        }
        if ( empty( $value ) ) {
            return $errors;
        }
        $items     = isset( $value[0] ) ? $value : [ $value ];
        $accept    = self::parse_accept( (string) ( $config["accept"] ?? "" ) );
        $max_bytes = (int) ( $config["max_size"] ?? 0 ) * 1024 * 1024;

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) { continue; }
            if ( $max_bytes > 0 && (int) ( $item["size"] ?? 0 ) > $max_bytes ) {
                $errors[] = self::too_big_message( (string) ( $item["filename"] ?? "" ), self::max_bytes( $config ) );
            }
            if ( $accept ) {
                $ext = strtolower( pathinfo( (string) ( $item["filename"] ?? "" ), PATHINFO_EXTENSION ) );
                if ( $ext === "" || ! in_array( "." . $ext, $accept, true ) ) {
                    $errors[] = sprintf( __( "File %s has a disallowed type.", "trinity-forms" ), $item["filename"] ?? "" );
                }
            }
        }
        return $errors;
    }

    /**
     * The largest file this field takes: its own "Max size" capped by what the
     * server accepts. A 5 MB field on a host with PHP's default 2 MB limit used to
     * fail a 3 MB photo with "Upload error" and no reason.
     */
    public static function max_bytes( array $config ): int {
        $server = (int) wp_max_upload_size();
        $field  = (int) ( $config["max_size"] ?? 0 ) * 1024 * 1024;
        if ( $field > 0 && ( $server <= 0 || $field < $server ) ) {
            return $field;
        }
        return $server;
    }

    public static function too_big_message( string $name, int $max ): string {
        /* translators: 1: file name, 2: the largest size accepted, e.g. "2 MB". */
        return sprintf( __( "%1\$s is too big. Files can be up to %2\$s.", "trinity-forms" ), $name, size_format( $max ) );
    }

    private static function parse_accept( string $raw ): array {
        $out = [];
        foreach ( array_filter( array_map( "trim", explode( ",", $raw ) ) ) as $token ) {
            if ( $token[0] === "." ) {
                $out[] = strtolower( $token );
            }
        }
        return $out;
    }
}
