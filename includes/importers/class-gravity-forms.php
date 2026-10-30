<?php
namespace TrinityForms\Importers;

use TrinityForms\GF_Importer;

defined( "ABSPATH" ) || exit;

/**
 * Gravity Forms, on the shared interface. The work stays in GF_Importer (its own
 * table reads, entry copy and hooks), so its behaviour does not change.
 */
class Gravity_Forms extends Base_Importer {

    public function slug(): string {
        return "gravity-forms";
    }

    public function label(): string {
        return "Gravity Forms";
    }

    public function is_available(): bool {
        return GF_Importer::is_available();
    }

    public function forms(): array {
        $out = [];
        foreach ( GF_Importer::available_forms() as $id => $form ) {
            $out[ $id ] = [ "title" => $form["title"], "entries" => (int) $form["entries"] ];
        }
        return $out;
    }

    public function supports_entries(): bool {
        return true;
    }

    public function convert( $source_id ): ?array {
        $c = GF_Importer::convert_form( (int) $source_id );
        if ( null === $c || null === $c["fields"] ) {
            return null;
        }
        unset( $c["source"] );
        return $c;
    }

    protected function count_entries( $source_id ): int {
        return GF_Importer::entry_count( (int) $source_id );
    }

    public function import( $source_id, array $options = [] ): array {
        $report = GF_Importer::import_form( (int) $source_id, ! empty( $options["with_entries"] ), ! empty( $options["dry_run"] ) );
        if ( ! empty( $report["form_id"] ) ) {
            do_action( "trinity_forms_form_imported", (int) $report["form_id"], $this->slug(), $source_id, $report );
        }
        return $report;
    }
}
