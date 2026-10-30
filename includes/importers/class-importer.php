<?php
namespace TrinityForms\Importers;

defined( "ABSPATH" ) || exit;

/**
 * One form builder Trinity Forms can import from (Gravity Forms, Contact Form 7, ...).
 *
 * A provider only READS the other plugin's data, never changes it, and should work
 * while that plugin is deactivated (people import, then switch it off). Register a
 * provider with the `trinity_forms_importers` filter (slug => instance); the Import
 * screen, the empty-state notice on the forms list and the report all come from here.
 *
 * Most providers extend Base_Importer and implement only the first five methods plus
 * convert(); import() then creates the form the same way for every builder.
 */
interface Importer {

    /** Stable machine name, e.g. "cf7". Used in form values and the import hook. */
    public function slug(): string;

    /** The builder's product name, e.g. "Contact Form 7". Not translated. */
    public function label(): string;

    /** True when this site holds forms of this builder (plugin active or not). */
    public function is_available(): bool;

    /**
     * The forms that can be imported, keyed by the builder's own form id.
     *
     * "entries" is the number of stored submissions, or null when the builder keeps
     * none (Contact Form 7, WPForms Lite).
     *
     * @return array<int|string,array{title:string,entries:?int}>
     */
    public function forms(): array;

    /** Whether import() can bring stored submissions across. */
    public function supports_entries(): bool;

    /**
     * One form converted to Trinity Forms, without writing anything.
     *
     * fields:        Trinity field configs (id, type, label, required, placeholder,
     *                options[{label,value}], help, default_value, css_class, ...)
     * settings:      form settings to merge over Form_Manager::default_schema()
     *                (submit_button_text, confirmation_message, redirect_url, ...)
     * notifications: Trinity notification configs (to, subject, body, from_name,
     *                from_email, reply_to, cc, bcc, enabled, event, attach_files)
     * skipped:       plain-language list of what had no equivalent, shown to the user
     *
     * @param int|string $source_id
     * @return array{title:string,fields:array,settings:array,notifications:array,skipped:array<int,string>}|null
     */
    public function convert( $source_id ): ?array;

    /**
     * Create the Trinity form (or, on a dry run, only report).
     *
     * @param int|string $source_id
     * @param array{with_entries?:bool,dry_run?:bool} $options
     * @return array{form_id:int,title:string,fields:int,entries:int,skipped:array<int,string>,notifications:int}
     */
    public function import( $source_id, array $options = [] ): array;
}
