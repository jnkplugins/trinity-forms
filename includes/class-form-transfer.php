<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Export and import a form as JSON.
 *
 * The plain, boring feature that makes a plugin usable in a real workflow: build a
 * form on staging, move it to production; keep a template in version control; send
 * a client's form to support without a database dump.
 *
 * Entries are never included. A form definition is configuration and safe to email
 * around; entries are people's data and should not leave the site by accident.
 */
class Form_Transfer {

    private const VERSION = 1;

    /**
     * @return array<string,mixed>
     */
    public static function export( int $form_id ): array {
        $form = Form_Manager::get( $form_id );
        if ( ! $form ) {
            return [];
        }

        $meta = get_post_meta( $form_id, Form::SETTINGS_META, true );
        $meta = is_array( $meta ) ? $meta : [];

        // Credentials are per-site and belong to whoever runs that site. Shipping
        // them inside an "export my form" file is how an API key ends up in a
        // support ticket.
        //
        // Stripped from BOTH halves: Form::__construct() folds the meta sections
        // (payment, integrations) into the schema, so cleaning only $meta leaves a
        // second copy of every secret in the schema.
        return [
            "trinity_forms" => self::VERSION,
            "plugin"        => TRINITY_FORMS_VERSION,
            "exported"      => gmdate( "Y-m-d H:i:s" ),
            "title"         => (string) get_the_title( $form_id ),
            // The resolved schema, which already has the meta sections folded in.
            // Exporting post_content instead would miss every setting stored as
            // post meta.
            "schema"        => self::strip_secrets( $form->schema ),
            "meta"          => self::strip_secrets( $meta ),
        ];
    }

    public static function export_json( int $form_id ): string {
        $data = self::export( $form_id );
        return $data ? (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : "";
    }

    /**
     * @param string $json
     * @return array{form_id:int,title:string,error:string}
     */
    public static function import_json( string $json, string $title_override = "" ): array {
        $result = [ "form_id" => 0, "title" => "", "error" => "" ];

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            $result["error"] = __( "That file is not valid JSON.", "trinity-forms" );
            return $result;
        }
        if ( empty( $data["trinity_forms"] ) ) {
            $result["error"] = __( "That file was not exported from Trinity Forms.", "trinity-forms" );
            return $result;
        }
        if ( (int) $data["trinity_forms"] > self::VERSION ) {
            $result["error"] = __( "That file was made by a newer version of Trinity Forms. Update first.", "trinity-forms" );
            return $result;
        }

        $schema = $data["schema"] ?? null;
        if ( ! is_array( $schema ) || empty( $schema["steps"] ) ) {
            $result["error"] = __( "That file has no form in it.", "trinity-forms" );
            return $result;
        }

        $title = "" !== $title_override
            ? $title_override
            : (string) ( $data["title"] ?? __( "Imported form", "trinity-forms" ) );

        $form_id = Form_Manager::create( $title, $schema );
        if ( ! $form_id ) {
            $result["error"] = __( "The form could not be created.", "trinity-forms" );
            return $result;
        }

        $meta = $data["meta"] ?? [];
        if ( is_array( $meta ) && $meta ) {
            update_post_meta( $form_id, Form::SETTINGS_META, self::strip_secrets( $meta ) );
        }

        $result["form_id"] = $form_id;
        $result["title"]   = $title;

        do_action( "trinity_forms_form_imported", $form_id, $data );

        return $result;
    }

    /**
     * Remove anything that looks like a credential, at any depth.
     *
     * Matching on the key name rather than a fixed list, so a premium integration
     * added later is covered without this class knowing it exists.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function strip_secrets( array $data ): array {
        $suffixes = [ "_key", "_secret", "_token", "_password", "api_key", "webhook_secret" ];

        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $data[ $key ] = self::strip_secrets( $value );
                continue;
            }
            $key_string = (string) $key;
            foreach ( $suffixes as $suffix ) {
                if ( str_ends_with( $key_string, $suffix ) || $key_string === $suffix ) {
                    $data[ $key ] = "";
                    break;
                }
            }
        }
        return $data;
    }
}
