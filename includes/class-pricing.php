<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * Works out what an order form comes to.
 *
 * The whole point of this class is that the browser is never asked. The page shows
 * a running total for the visitor's benefit, but every amount stored, emailed or
 * charged is recomputed here from the form's saved configuration and the *keys*
 * the visitor chose. Trusting a posted total is how "$0.00" order forms happen.
 *
 * Free on purpose: pricing a form is arithmetic, and gating arithmetic is a poor
 * line to draw. Taking the money stays premium.
 */
class Pricing {

    /**
     * Does this form have anything priced on it?
     */
    public static function has_pricing( Form $form ): bool {
        foreach ( $form->fields() as $field ) {
            if ( in_array( $field->type, [ "product", "option", "shipping", "total" ], true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The order total, and the line items that make it up.
     *
     * Quantity binds to a product through the quantity field's `product_field`
     * setting; a quantity with no binding multiplies nothing, which is the safe
     * reading of an incompletely configured form.
     *
     * @param array<string,mixed> $values field id => sanitized value
     * @return array{total:float,lines:array<int,array{label:string,amount:float,quantity:int}>}
     */
    public static function calculate( Form $form, array $values ): array {
        $fields = [];
        foreach ( $form->fields() as $field ) {
            $fields[ $field->id ] = $field;
        }

        // Quantities first: a product needs to know its multiplier before it prices.
        $quantities = [];
        foreach ( $fields as $id => $field ) {
            if ( "quantity" !== $field->type ) {
                continue;
            }
            $bound = (string) ( $field->config["product_field"] ?? "" );
            if ( "" !== $bound ) {
                $quantities[ $bound ] = max( 0, (int) ( $values[ $id ] ?? 0 ) );
            }
        }

        $lines = [];
        $total = 0.0;

        foreach ( $fields as $id => $field ) {
            if ( ! array_key_exists( $id, $values ) ) {
                continue;
            }
            $value = $values[ $id ];

            switch ( $field->type ) {
                case "product":
                    $unit = Fields\Field_Product::resolve_price( $field->config, $value );
                    if ( $unit <= 0 ) {
                        break;
                    }
                    // A product with a bound quantity field defaults to 1 only when
                    // that field was left empty, not when it was deliberately 0.
                    $qty    = array_key_exists( $id, $quantities ) ? $quantities[ $id ] : 1;
                    $amount = $unit * $qty;
                    $total += $amount;
                    $lines[] = [
                        "label"    => $field->display_label(),
                        "amount"   => $amount,
                        "quantity" => $qty,
                    ];
                    break;

                case "option":
                    $amount = Fields\Field_Option::resolve_price( $field->config, $value );
                    if ( 0.0 === $amount ) {
                        break;
                    }
                    // An option rides on its product's quantity: two shirts with gift
                    // wrap is two lots of gift wrap.
                    $bound  = (string) ( $field->config["product_field"] ?? "" );
                    $qty    = ( "" !== $bound && isset( $quantities[ $bound ] ) ) ? $quantities[ $bound ] : 1;
                    $amount = $amount * $qty;
                    $total += $amount;
                    $lines[] = [
                        "label"    => $field->display_label(),
                        "amount"   => $amount,
                        "quantity" => $qty,
                    ];
                    break;

                case "shipping":
                    $amount = Fields\Field_Shipping::resolve_price( $field->config, $value );
                    if ( 0.0 === $amount ) {
                        break;
                    }
                    $total  += $amount;
                    $lines[] = [
                        "label"    => $field->display_label(),
                        "amount"   => $amount,
                        "quantity" => 1,
                    ];
                    break;
            }
        }

        /**
         * Adjust a computed order total — coupons, tax, member discounts.
         *
         * @param float               $total
         * @param array<int,array>    $lines
         * @param Form                $form
         * @param array<string,mixed> $values
         */
        $total = (float) apply_filters( "trinity_forms_order_total", $total, $lines, $form, $values );

        return [ "total" => round( max( 0, $total ), 2 ), "lines" => $lines ];
    }
}
