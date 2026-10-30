<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;

/**
 * Every importer, and what they found on this site.
 */
class Registry {

    /** @var array<string,Importer>|null */
    private static ?array $all = null;

    /** @return array<string,Importer> slug => provider */
    public static function all(): array {
        if ( null === self::$all ) {
            $built = [
                "gravity-forms" => new Gravity_Forms(),
                "cf7"           => new Contact_Form_7(),
                "wpforms"       => new WPForms(),
                "fluent-forms"  => new Fluent_Forms(),
                "ninja-forms"   => new Ninja_Forms(),
                "formidable"    => new Formidable(),
                "forminator"    => new Forminator(),
                "everest-forms" => new Everest_Forms(),
                "sureforms"     => new SureForms(),
                "metform"       => new MetForm(),
            ];
            /**
             * Add or replace form-builder importers.
             *
             * @param array<string,Importer> $importers slug => provider
             */
            $filtered  = apply_filters( "trinity_forms_importers", $built );
            self::$all = [];
            foreach ( (array) $filtered as $importer ) {
                if ( $importer instanceof Importer ) {
                    self::$all[ $importer->slug() ] = $importer;
                }
            }
        }
        return self::$all;
    }

    public static function get( string $slug ): ?Importer {
        return self::all()[ $slug ] ?? null;
    }

    /** Tests only: build the list again (after changing the filter). */
    public static function reset(): void {
        self::$all = null;
    }

    /**
     * Builders with forms on this site, with how many.
     *
     * @return array<string,array{label:string,count:int}>
     */
    public static function found(): array {
        $out = [];
        foreach ( self::all() as $slug => $importer ) {
            if ( ! $importer->is_available() ) {
                continue;
            }
            $count = count( $importer->forms() );
            if ( $count > 0 ) {
                $out[ $slug ] = [ "label" => $importer->label(), "count" => $count ];
            }
        }
        return $out;
    }

    /** "1 Contact Form 7 form, 2 Gravity Forms forms", or "" when nothing was found. */
    public static function summary(): string {
        $parts = [];
        foreach ( self::found() as $row ) {
            $parts[] = sprintf(
                /* translators: 1: number of forms, 2: form plugin name, e.g. "Contact Form 7". */
                _n( "%1\$d %2\$s form", "%1\$d %2\$s forms", $row["count"], "trinity-forms" ),
                $row["count"],
                $row["label"]
            );
        }
        return implode( wp_get_list_item_separator(), $parts );
    }
}
