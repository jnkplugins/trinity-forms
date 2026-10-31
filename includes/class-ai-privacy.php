<?php
namespace TrinityForms;

defined( 'ABSPATH' ) || exit;

/**
 * What an AI assistant may see of an entry.
 *
 * Entries hold other people's personal data, so by default an assistant gets
 * contact details masked: emails keep only the first letter and the domain,
 * phone numbers their last two digits, names their initials, and addresses and
 * IP addresses are left out. Fields that should never leave the site (files,
 * passwords, card numbers, signatures) are always left out, whatever the
 * setting. Forms the owner ticked under "Never show to assistants" are left out
 * entirely: their entries are not listed and cannot be opened.
 *
 * Masking is about what the assistant reads. It is not access control: the
 * Abilities API still checks the signed-in user's own rights on every call.
 */
class AI_Privacy {

    /** Field types masked when contact details are hidden. */
    private const MASK_TYPES = [ 'email', 'phone', 'tel', 'name', 'first_name', 'last_name', 'address', 'ip' ];

    /** Field types never shown to an assistant. */
    private const NEVER_TYPES = [ 'file', 'upload', 'password', 'signature', 'credit_card', 'card', 'payment' ];

    /** Labels that mean "sensitive" even when the type says text. */
    private const NEVER_LABELS = '/password|passwort|contraseña|비밀번호|पासवर्ड|كلمة المرور|card number|cvv|cvc|iban|ssn|social security|passport|national id/iu';

    private const NAME_LABELS    = '/\b(name|nombre|nom|이름|성명|नाम)\b|الاسم/iu';
    private const PHONE_LABELS   = '/phone|mobile|tel\b|teléfono|telefono|전화|휴대폰|फ़ोन|फोन|मोबाइल|هاتف|جوال/iu';
    private const ADDRESS_LABELS = '/address|street|dirección|direccion|주소|पता|العنوان/iu';

    public static function settings(): array {
        return (array) get_option( 'trinity_forms_settings', [] );
    }

    /** True unless the owner chose to show contact details to assistants. */
    public static function masking(): bool {
        return empty( self::settings()['ai_show_contact'] );
    }

    /** Ids of the forms the owner hid from assistants. */
    public static function hidden_forms(): array {
        return array_values( array_filter( array_map( 'intval', (array) ( self::settings()['ai_hidden_forms'] ?? [] ) ) ) );
    }

    public static function form_hidden( int $form_id ): bool {
        return in_array( $form_id, self::hidden_forms(), true );
    }

    /** What kind of field this is for privacy: "never", "email", "phone", "name", "address" or "". */
    public static function field_kind( string $type, string $label ): string {
        // Form plugins spell types their own way: Fluent Forms says input_email
        // and input_name, others telephone, address_1, ip_address.
        $type = (string) preg_replace( '/^(input|field)_/', '', strtolower( $type ) );
        if ( in_array( $type, self::NEVER_TYPES, true ) || preg_match( '/file|upload|password|signature|credit|card/', $type ) || preg_match( self::NEVER_LABELS, $label ) ) {
            return 'never';
        }
        if ( false !== strpos( $type, 'email' ) ) {
            return 'email';
        }
        if ( preg_match( '/phone|^tel/', $type ) || preg_match( self::PHONE_LABELS, $label ) ) {
            return 'phone';
        }
        if ( preg_match( '/name$|^name/', $type ) || preg_match( self::NAME_LABELS, $label ) ) {
            return 'name';
        }
        if ( preg_match( '/address|^ip/', $type ) || preg_match( self::ADDRESS_LABELS, $label ) ) {
            return 'address';
        }
        return '';
    }

    public static function mask_email( string $email ): string {
        $email = trim( $email );
        $at    = strrpos( $email, '@' );
        if ( false === $at || 0 === $at ) {
            return '' === $email ? '' : '***';
        }
        return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
    }

    public static function mask_phone( string $phone ): string {
        $digits = preg_replace( '/\D+/', '', $phone );
        return strlen( (string) $digits ) < 3 ? '***' : '***' . substr( (string) $digits, -2 );
    }

    public static function mask_name( string $name ): string {
        $parts = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
        return implode( ' ', array_map( static fn( $p ) => mb_substr( $p, 0, 1 ) . '.', (array) $parts ) );
    }

    /**
     * Mask what a contact field holds, by kind. Arrays (name parts, checkbox
     * lists) are joined first.
     */
    public static function mask_value( string $kind, string $value ): string {
        switch ( $kind ) {
            case 'email':
                return self::mask_email( $value );
            case 'phone':
                return self::mask_phone( $value );
            case 'name':
                return self::mask_name( $value );
            case 'address':
                return '[' . __( 'hidden', 'trinity-forms' ) . ']';
        }
        return $value;
    }

    /**
     * Take email addresses and phone numbers out of free text (a message, a
     * note, a title), after the exact contact values of the entry last passed
     * to prepare() (so "Jane Smith" in a message becomes "J. S.").
     */
    public static function scrub( string $text ): string {
        if ( ! self::masking() || '' === $text ) {
            return $text;
        }
        foreach ( self::$known_pairs as $raw => $masked ) {
            if ( mb_strlen( (string) $raw ) >= 3 ) {
                $text = str_ireplace( (string) $raw, $masked, $text );
            }
        }
        $text = (string) preg_replace_callback( '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u', static fn( $m ) => self::mask_email( $m[0] ), $text );
        $text = (string) preg_replace_callback( '/(?<![\p{L}\p{N}])\+?\d[\d\s().-]{6,}\d(?![\p{L}\p{N}])/u', static fn( $m ) => self::mask_phone( $m[0] ), $text );
        return $text;
    }

    /** raw contact value => masked form, for the entry being prepared. */
    private static array $known_pairs = [];

    /**
     * An entry's answers as an assistant may see them, labelled from the form.
     *
     * @param array<string,mixed> $values field id => stored value (Entries::values())
     * @return array{fields:array<int,array<string,string>>,left_out:int}
     */
    public static function prepare( Form $form, array $values ): array {
        $mask              = self::masking();
        self::$known_pairs = [];
        $rows              = [];
        foreach ( $form->fields() as $field ) {
            $schema = (array) $field->config;
            $id     = (string) ( $schema['id'] ?? '' );
            if ( '' === $id || ! array_key_exists( $id, $values ) ) {
                continue;
            }
            $type = (string) ( $schema['type'] ?? '' );
            $map  = [];
            foreach ( (array) ( $schema['options'] ?? [] ) as $o ) {
                if ( is_array( $o ) && isset( $o['value'] ) ) {
                    $map[ (string) $o['value'] ] = (string) ( $o['label'] ?? $o['value'] );
                }
            }
            $rows[] = [
                'label' => (string) ( $schema['label'] ?? $id ),
                'type'  => $type,
                'value' => wp_strip_all_tags( Entries::display_value( $type, $values[ $id ], $map ) ),
            ];
        }
        // First pass: learn the contact values so free text can be scrubbed.
        foreach ( $rows as $r ) {
            $kind = self::field_kind( $r['type'], $r['label'] );
            if ( $mask && in_array( $kind, [ 'email', 'phone', 'name', 'address' ], true ) && '' !== trim( $r['value'] ) ) {
                self::$known_pairs[ trim( $r['value'] ) ] = self::mask_value( $kind, $r['value'] );
                if ( 'name' === $kind ) {
                    foreach ( preg_split( '/\s+/u', trim( $r['value'] ), -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
                        if ( mb_strlen( $part ) >= 3 ) {
                            self::$known_pairs[ $part ] = mb_substr( $part, 0, 1 ) . '.';
                        }
                    }
                }
            }
        }
        uksort( self::$known_pairs, static fn( $a, $b ) => mb_strlen( (string) $b ) <=> mb_strlen( (string) $a ) );

        $out      = [];
        $left_out = 0;
        foreach ( $rows as $r ) {
            $kind = self::field_kind( $r['type'], $r['label'] );
            // Payment, product and hidden fields carry amounts and tracking data an
            // assistant has no need for.
            if ( 'never' === $kind || in_array( $r['type'], [ 'payment', 'total', 'product', 'hidden', 'shipping', 'quantity' ], true ) ) {
                $left_out++;
                continue;
            }
            $value = $r['value'];
            if ( $mask ) {
                $value = '' !== $kind ? self::mask_value( $kind, $value ) : self::scrub( $value );
            }
            $out[] = [ 'label' => $r['label'], 'type' => $r['type'], 'value' => $value ];
        }
        return [ 'fields' => $out, 'left_out' => $left_out ];
    }
}
