<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * The base class a third-party add-on extends.
 *
 * The honest reason this exists: the single most cited reason people stay on
 * Gravity Forms is "I can extend it to do whatever I need". You cannot match that
 * with features — you match it by making the extension path documented, small, and
 * stable.
 *
 * An add-on declares a slug, some global settings, and what to do with an entry.
 * Everything else — where the settings render, when the feed runs, how failures are
 * recorded — is handled here, so an add-on author writes the part only they can
 * write.
 *
 * Minimal example:
 *
 *     class My_Addon extends \TrinityForms\Addon {
 *         protected string $slug  = "my_addon";
 *         protected string $label = "My Service";
 *
 *         public function settings_fields(): array {
 *             return [ "api_key" => [ "label" => "API key", "type" => "password" ] ];
 *         }
 *
 *         public function process( int $entry_id, array $values, Form $form ): bool {
 *             return (bool) wp_remote_post( "https://example.com/hook", [ "body" => $values ] );
 *         }
 *     }
 *     My_Addon::register();
 */
abstract class Addon {

    /** Unique, lowercase, underscore-separated. Used as the settings key. */
    protected string $slug = "";

    /** Human name, shown in the settings UI. */
    protected string $label = "";

    /** @var array<string,static> */
    private static array $registered = [];

    final public static function register(): void {
        $instance = new static();
        if ( "" === $instance->slug ) {
            return;
        }
        self::$registered[ $instance->slug ] = $instance;

        // Feeds run on the same async queue as the built-in integrations, so a slow
        // third-party API can never hold up somebody's submission.
        add_action( "trinity_forms_run_integrations", [ $instance, "maybe_process" ], 10, 1 );
    }

    /** @return array<string,static> */
    final public static function all(): array {
        return self::$registered;
    }

    final public function slug(): string {
        return $this->slug;
    }

    final public function label(): string {
        return "" !== $this->label ? $this->label : $this->slug;
    }

    /**
     * Global settings this add-on needs.
     *
     * Keys ending in `_key`, `_secret` or `_token` are encrypted at rest by the
     * core settings sanitizer, so an API key does not need special handling.
     *
     * @return array<string,array{label:string,type?:string,help?:string}>
     */
    public function settings_fields(): array {
        return [];
    }

    /** @return mixed */
    final public function setting( string $key, $default = null ) {
        $settings = get_option( "trinity_forms_settings", [] );
        return $settings[ $this->slug ][ $key ] ?? $default;
    }

    /**
     * Is this add-on configured enough to run?
     *
     * Default: every declared setting has a value. Override when only some are
     * required.
     */
    public function is_configured(): bool {
        foreach ( array_keys( $this->settings_fields() ) as $key ) {
            if ( "" === (string) $this->setting( $key, "" ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Should this entry be sent at all?
     *
     * Override to add per-form opt-in or conditional logic. The default is "yes,
     * whenever the add-on is configured", which is the behaviour a simple webhook
     * wants.
     */
    public function should_process( int $entry_id, Form $form ): bool {
        return true;
    }

    /**
     * Do the work. Return false to have the failure logged against the entry.
     *
     * @param array<string,mixed> $values field id => value
     */
    abstract public function process( int $entry_id, array $values, Form $form ): bool;

    /**
     * The queue callback.
     *
     * Wrapped so an add-on that throws cannot take down the rest of the queue, and
     * so every outcome lands on the entry where somebody can see it.
     */
    final public function maybe_process( int $entry_id ): void {
        if ( ! $this->is_configured() ) {
            return;
        }

        $entry = Entries::get( $entry_id );
        $form  = $entry ? Form_Manager::get( (int) $entry["form_id"] ) : null;
        if ( ! $form || ! $this->should_process( $entry_id, $form ) ) {
            return;
        }

        try {
            $ok = $this->process( $entry_id, Entries::values( $entry_id ), $form );
        } catch ( \Throwable $e ) {
            $ok = false;
            Logger::warn( $this->slug, $e->getMessage(), $entry_id );
        }

        if ( ! $ok ) {
            Entry_Notes::log( $entry_id, sprintf(
                /* translators: %s: the add-on's name. */
                __( "%s could not process this entry.", "trinity-forms" ),
                $this->label()
            ) );
        }

        do_action( "trinity_forms_addon_processed", $this->slug, $entry_id, $ok );
    }
}
