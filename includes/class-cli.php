<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * WP-CLI commands.
 *
 * Registered only when WP-CLI is running, so nothing here loads on a web request.
 *
 * These exist because the things people script are the boring ones: moving a form
 * between environments, exporting entries for a report, clearing test data before a
 * launch. Every one of them is otherwise a click-through in wp-admin.
 *
 *     wp trinity form list
 *     wp trinity form export 12 > contact.json
 *     wp trinity form import contact.json --title="Contact (staging)"
 *     wp trinity entry list --form=12
 *     wp trinity entry export --form=12 > entries.csv
 *     wp trinity entry delete --form=12 --status=spam --yes
 *     wp trinity import-gf 3 --dry-run
 */
class CLI {

    public static function register(): void {
        if ( ! defined( "WP_CLI" ) || ! \WP_CLI ) {
            return;
        }
        \WP_CLI::add_command( "trinity form", [ self::class, "form" ] );
        \WP_CLI::add_command( "trinity entry", [ self::class, "entry" ] );
        \WP_CLI::add_command( "trinity import-gf", [ self::class, "import_gf" ] );
    }

    /**
     * Manage forms.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public static function form( array $args, array $assoc ): void {
        $sub = $args[0] ?? "list";

        switch ( $sub ) {
            case "list":
                $rows = [];
                foreach ( Form_Manager::all_titles() as $id => $title ) {
                    $form = Form_Manager::get( $id );
                    $rows[] = [
                        "id"      => $id,
                        "title"   => $title,
                        "fields"  => $form ? count( $form->fields() ) : 0,
                        "entries" => count( Entries::ids( [ "form_id" => $id, "view" => "all" ] ) ),
                    ];
                }
                \WP_CLI\Utils\format_items( $assoc["format"] ?? "table", $rows, [ "id", "title", "fields", "entries" ] );
                return;

            case "export":
                $form_id = (int) ( $args[1] ?? 0 );
                $json    = Form_Transfer::export_json( $form_id );
                if ( "" === $json ) {
                    \WP_CLI::error( "No such form." );
                }
                // Printed rather than written to a file so it can be piped or
                // redirected, which is how anyone actually uses this.
                \WP_CLI::line( $json );
                return;

            case "import":
                $path = (string) ( $args[1] ?? "" );
                if ( ! is_readable( $path ) ) {
                    \WP_CLI::error( "Cannot read {$path}" );
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file named on the command line; WP_Filesystem is for the web request context.
                $result = Form_Transfer::import_json( (string) file_get_contents( $path ), (string) ( $assoc["title"] ?? "" ) );
                if ( "" !== $result["error"] ) {
                    \WP_CLI::error( $result["error"] );
                }
                \WP_CLI::success( "Imported \"{$result['title']}\" as form {$result['form_id']}." );
                return;

            case "delete":
                $form_id = (int) ( $args[1] ?? 0 );
                if ( ! Form_Manager::get( $form_id ) ) {
                    \WP_CLI::error( "No such form." );
                }
                \WP_CLI::confirm( "Delete form {$form_id} and every entry on it?", $assoc );
                $deleted = Entries::delete( Entries::ids( [ "form_id" => $form_id, "view" => "all" ] ) );
                wp_delete_post( $form_id, true );
                \WP_CLI::success( "Deleted the form and {$deleted} entries." );
                return;
        }

        \WP_CLI::error( "Unknown subcommand. Try: list, export, import, delete." );
    }

    /**
     * Manage entries.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public static function entry( array $args, array $assoc ): void {
        $sub  = $args[0] ?? "list";
        // "wp trinity entry export 12" used to ignore the 12 and act on every
        // form's entries (delete too). Only --form picks a form.
        if ( count( $args ) > 1 ) {
            \WP_CLI::error( sprintf( "Unexpected argument \"%s\". To pick a form, use --form=%s.", $args[1], $args[1] ) );
        }
        $args_for_query = [
            "form_id" => (int) ( $assoc["form"] ?? 0 ),
            "view"    => (string) ( $assoc["status"] ?? "all" ),
            "search"  => (string) ( $assoc["search"] ?? "" ),
        ];

        switch ( $sub ) {
            case "list":
                $result = Entries::query( array_merge( $args_for_query, [
                    "per_page" => (int) ( $assoc["limit"] ?? 100 ),
                ] ) );
                $senders = Entries::senders( array_map( static fn( $r ) => (int) $r->id, $result["rows"] ) );

                $rows = [];
                foreach ( $result["rows"] as $r ) {
                    $id = (int) $r->id;
                    $rows[] = [
                        "id"        => $id,
                        "form"      => (int) $r->form_id,
                        "from"      => $senders[ $id ]["email"] ?? ( $senders[ $id ]["name"] ?? "" ),
                        "status"    => (string) $r->status,
                        "submitted" => Dates::to_site( $r->submitted_at ),
                    ];
                }
                \WP_CLI\Utils\format_items( $assoc["format"] ?? "table", $rows, [ "id", "form", "from", "status", "submitted" ] );
                \WP_CLI::line( "" );
                \WP_CLI::line( "Total: {$result['total']}" );
                return;

            case "export":
                $ids = Entries::ids( $args_for_query );
                if ( ! $ids ) {
                    \WP_CLI::warning( "No entries matched." );
                    return;
                }
                \WP_CLI::line( Entries::to_csv( $ids ) );
                return;

            case "delete":
                $ids = Entries::ids( $args_for_query );
                if ( ! $ids ) {
                    \WP_CLI::warning( "No entries matched." );
                    return;
                }
                \WP_CLI::confirm( "Permanently delete " . count( $ids ) . " entries?", $assoc );
                $deleted = Entries::delete( $ids );
                \WP_CLI::success( "Deleted {$deleted} entries." );
                return;
        }

        \WP_CLI::error( "Unknown subcommand. Try: list, export, delete." );
    }

    /**
     * Import from Gravity Forms.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public static function import_gf( array $args, array $assoc ): void {
        if ( ! GF_Importer::is_available() ) {
            \WP_CLI::error( "No Gravity Forms tables found on this site." );
        }

        $available = GF_Importer::available_forms();

        if ( ! isset( $args[0] ) ) {
            $rows = [];
            foreach ( $available as $id => $form ) {
                $rows[] = [ "id" => $id, "title" => $form["title"], "entries" => $form["entries"] ];
            }
            \WP_CLI\Utils\format_items( "table", $rows, [ "id", "title", "entries" ] );
            \WP_CLI::line( "" );
            \WP_CLI::line( "Import one with: wp trinity import-gf <id>" );
            return;
        }

        $gf_form_id = (int) $args[0];
        if ( ! isset( $available[ $gf_form_id ] ) ) {
            \WP_CLI::error( "No such Gravity Forms form." );
        }

        $dry_run      = isset( $assoc["dry-run"] );
        $with_entries = ! isset( $assoc["no-entries"] );

        $report = GF_Importer::import_form( $gf_form_id, $with_entries, $dry_run );

        foreach ( (array) $report["skipped"] as $skipped ) {
            \WP_CLI::warning( "Not imported: {$skipped}" );
        }

        if ( $dry_run ) {
            \WP_CLI::success( "Dry run: would import {$report['fields']} fields and {$report['entries']} entries from \"{$report['title']}\". Nothing was changed." );
            return;
        }

        \WP_CLI::success( "Imported \"{$report['title']}\" as form {$report['form_id']} — {$report['fields']} fields, {$report['entries']} entries." );
    }
}
