<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * Shared behaviour for the pricing fields (product, option, quantity, shipping,
 * total).
 *
 * These are free on purpose. A form can price an order, show a running total and
 * store what was ordered without paying anything; taking the money — Stripe,
 * PayPal, Mollie — is what Pro is for. "Build the order form free, pay to get paid"
 * is a cleaner line than gating arithmetic.
 *
 * Every amount is recomputed server-side from the stored configuration at
 * submission; the numbers the browser posts are only ever used to know *which*
 * product and *how many*, never how much it costs.
 */
abstract class Field_Pricing_Base extends Field_Base {

    /** Currency symbol for display, from the site's Trinity Forms settings. */
    protected function currency_symbol(): string {
        $settings = get_option( "trinity_forms_settings", [] );
        $code     = strtoupper( (string) ( $settings["currency"] ?? "USD" ) );

        $symbols = [
            "USD" => "$", "EUR" => "€", "GBP" => "£", "INR" => "₹",
            "AUD" => "A$", "CAD" => "C$", "JPY" => "¥",
        ];
        return $symbols[ $code ] ?? ( $code . " " );
    }

    /** Format an amount for display next to a label. */
    protected function format_price( float $amount ): string {
        return $this->currency_symbol() . number_format_i18n( $amount, 2 );
    }

    /** Read a configured price, tolerating "12", "12.50" and "$12.50". */
    protected function price_of( array $config, string $key = "price" ): float {
        $raw = (string) ( $config[ $key ] ?? "0" );
        return (float) preg_replace( '/[^0-9.\-]/', "", $raw );
    }

    public function sanitize( $raw, array $config ) {
        return sanitize_text_field( (string) $raw );
    }

    public function validate( $value, array $config ): array {
        return [];
    }
}
