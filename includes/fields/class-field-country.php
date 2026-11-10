<?php
namespace TrinityForms\Fields;

defined( "ABSPATH" ) || exit;

/**
 * Country field — a select prefilled with the ISO 3166 country list. The stored
 * value is the country name (readable in entry detail, CSV and merge tags). The
 * list is filterable via `trinity_forms_countries`.
 */
class Field_Country extends Field_Base {

    public function render( array $config, $value = null ): string {
        $id       = esc_attr( $config["id"] ?? "" );
        $name     = esc_attr( $this->attr_name( $config ) );
        $required = ! empty( $config["required"] ) ? " required" : "";
        $current  = (string) ( is_array( $value ) ? "" : ( $value ?? "" ) );
        $ph       = (string) ( $config["placeholder"] ?? "" );
        if ( $ph === "" ) {
            $ph = __( "Select a country…", "trinity-forms" );
        }
        $options = '<option value="">' . esc_html( $ph ) . '</option>';
        // The value stays the English name (what entries, rules, integrations and
        // validate() have always used); the visitor reads it in their language.
        foreach ( self::labels() as $c => $label ) {
            $options .= '<option value="' . esc_attr( $c ) . '"' . selected( $current, $c, false ) . '>' . esc_html( $label ) . '</option>';
        }
        $control = '<select id="' . $id . '" name="' . $name . '"' . $required . ' autocomplete="country-name">' . $options . '</select>';
        return $this->wrap( $config, $control );
    }

    public function sanitize( $raw, array $config ) {
        return sanitize_text_field( (string) ( is_array( $raw ) ? "" : $raw ) );
    }

    public function validate( $value, array $config ): array {
        if ( $value === "" || $value === null ) {
            return [];
        }
        return in_array( (string) $value, self::countries(), true ) ? [] : [ __( "Please select a valid country.", "trinity-forms" ) ];
    }

    /**
     * Stored English name => ISO 3166-1 alpha-2 code.
     *
     * @return array<string,string>
     */
    public static function codes(): array {
        return [
            "Afghanistan" => "AF", "Albania" => "AL", "Algeria" => "DZ", "Andorra" => "AD", "Angola" => "AO", "Antigua and Barbuda" => "AG",
            "Argentina" => "AR", "Armenia" => "AM", "Australia" => "AU", "Austria" => "AT", "Azerbaijan" => "AZ", "Bahamas" => "BS",
            "Bahrain" => "BH", "Bangladesh" => "BD", "Barbados" => "BB", "Belarus" => "BY", "Belgium" => "BE", "Belize" => "BZ",
            "Benin" => "BJ", "Bhutan" => "BT", "Bolivia" => "BO", "Bosnia and Herzegovina" => "BA", "Botswana" => "BW", "Brazil" => "BR",
            "Brunei" => "BN", "Bulgaria" => "BG", "Burkina Faso" => "BF", "Burundi" => "BI", "Cabo Verde" => "CV", "Cambodia" => "KH",
            "Cameroon" => "CM", "Canada" => "CA", "Central African Republic" => "CF", "Chad" => "TD", "Chile" => "CL", "China" => "CN",
            "Colombia" => "CO", "Comoros" => "KM", "Congo" => "CG", "Congo (Democratic Republic)" => "CD", "Costa Rica" => "CR", "Côte d'Ivoire" => "CI",
            "Croatia" => "HR", "Cuba" => "CU", "Cyprus" => "CY", "Czechia" => "CZ", "Denmark" => "DK", "Djibouti" => "DJ",
            "Dominica" => "DM", "Dominican Republic" => "DO", "Ecuador" => "EC", "Egypt" => "EG", "El Salvador" => "SV", "Equatorial Guinea" => "GQ",
            "Eritrea" => "ER", "Estonia" => "EE", "Eswatini" => "SZ", "Ethiopia" => "ET", "Fiji" => "FJ", "Finland" => "FI",
            "France" => "FR", "Gabon" => "GA", "Gambia" => "GM", "Georgia" => "GE", "Germany" => "DE", "Ghana" => "GH",
            "Greece" => "GR", "Grenada" => "GD", "Guatemala" => "GT", "Guinea" => "GN", "Guinea-Bissau" => "GW", "Guyana" => "GY",
            "Haiti" => "HT", "Honduras" => "HN", "Hungary" => "HU", "Iceland" => "IS", "India" => "IN", "Indonesia" => "ID",
            "Iran" => "IR", "Iraq" => "IQ", "Ireland" => "IE", "Israel" => "IL", "Italy" => "IT", "Jamaica" => "JM",
            "Japan" => "JP", "Jordan" => "JO", "Kazakhstan" => "KZ", "Kenya" => "KE", "Kiribati" => "KI", "Kosovo" => "XK",
            "Kuwait" => "KW", "Kyrgyzstan" => "KG", "Laos" => "LA", "Latvia" => "LV", "Lebanon" => "LB", "Lesotho" => "LS",
            "Liberia" => "LR", "Libya" => "LY", "Liechtenstein" => "LI", "Lithuania" => "LT", "Luxembourg" => "LU", "Madagascar" => "MG",
            "Malawi" => "MW", "Malaysia" => "MY", "Maldives" => "MV", "Mali" => "ML", "Malta" => "MT", "Marshall Islands" => "MH",
            "Mauritania" => "MR", "Mauritius" => "MU", "Mexico" => "MX", "Micronesia" => "FM", "Moldova" => "MD", "Monaco" => "MC",
            "Mongolia" => "MN", "Montenegro" => "ME", "Morocco" => "MA", "Mozambique" => "MZ", "Myanmar" => "MM", "Namibia" => "NA",
            "Nauru" => "NR", "Nepal" => "NP", "Netherlands" => "NL", "New Zealand" => "NZ", "Nicaragua" => "NI", "Niger" => "NE",
            "Nigeria" => "NG", "North Korea" => "KP", "North Macedonia" => "MK", "Norway" => "NO", "Oman" => "OM", "Pakistan" => "PK",
            "Palau" => "PW", "Palestine" => "PS", "Panama" => "PA", "Papua New Guinea" => "PG", "Paraguay" => "PY", "Peru" => "PE",
            "Philippines" => "PH", "Poland" => "PL", "Portugal" => "PT", "Qatar" => "QA", "Romania" => "RO", "Russia" => "RU",
            "Rwanda" => "RW", "Saint Kitts and Nevis" => "KN", "Saint Lucia" => "LC", "Saint Vincent and the Grenadines" => "VC", "Samoa" => "WS", "San Marino" => "SM",
            "Sao Tome and Principe" => "ST", "Saudi Arabia" => "SA", "Senegal" => "SN", "Serbia" => "RS", "Seychelles" => "SC", "Sierra Leone" => "SL",
            "Singapore" => "SG", "Slovakia" => "SK", "Slovenia" => "SI", "Solomon Islands" => "SB", "Somalia" => "SO", "South Africa" => "ZA",
            "South Korea" => "KR", "South Sudan" => "SS", "Spain" => "ES", "Sri Lanka" => "LK", "Sudan" => "SD", "Suriname" => "SR",
            "Sweden" => "SE", "Switzerland" => "CH", "Syria" => "SY", "Taiwan" => "TW", "Tajikistan" => "TJ", "Tanzania" => "TZ",
            "Thailand" => "TH", "Timor-Leste" => "TL", "Togo" => "TG", "Tonga" => "TO", "Trinidad and Tobago" => "TT", "Tunisia" => "TN",
            "Turkey" => "TR", "Turkmenistan" => "TM", "Tuvalu" => "TV", "Uganda" => "UG", "Ukraine" => "UA", "United Arab Emirates" => "AE",
            "United Kingdom" => "GB", "United States" => "US", "Uruguay" => "UY", "Uzbekistan" => "UZ", "Vanuatu" => "VU", "Vatican City" => "VA",
            "Venezuela" => "VE", "Vietnam" => "VN", "Yemen" => "YE", "Zambia" => "ZM", "Zimbabwe" => "ZW",
        ];
    }

    /**
     * A stored country name in the reader's language.
     *
     * Uses ICU's region names (PHP intl) when the extension is there, so 195 names
     * need no hand translation; without intl, or for a name with no ISO code (one
     * added through the filter), the stored English name is shown.
     */
    public static function label( string $name, ?string $locale = null ): string {
        $locale = $locale ?? ( function_exists( "determine_locale" ) ? determine_locale() : get_locale() );
        $label  = $name;
        $code   = self::codes()[ $name ] ?? "";
        if ( "" !== $code && class_exists( \Locale::class ) && 0 !== strpos( $locale, "en" ) ) {
            $region = (string) \Locale::getDisplayRegion( "-" . $code, $locale );
            if ( "" !== $region && $region !== $code ) {
                $label = $region;
            }
        }
        return (string) apply_filters( "trinity_forms_country_label", $label, $name, $locale );
    }

    /**
     * Every country as stored name => label, sorted by the label the reader sees.
     *
     * @return array<string,string>
     */
    public static function labels( ?string $locale = null ): array {
        $locale = $locale ?? ( function_exists( "determine_locale" ) ? determine_locale() : get_locale() );
        $out    = [];
        foreach ( self::countries() as $c ) {
            $out[ $c ] = self::label( $c, $locale );
        }
        if ( 0 !== strpos( $locale, "en" ) ) {
            $collator = class_exists( \Collator::class ) ? new \Collator( $locale ) : null;
            if ( $collator ) {
                uasort( $out, static fn( $a, $b ) => (int) $collator->compare( $a, $b ) );
            } else {
                asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
            }
        }
        return $out;
    }

    /** @return string[] ISO 3166 country names, filterable. */
    public static function countries(): array {
        $list = [
            "Afghanistan","Albania","Algeria","Andorra","Angola","Antigua and Barbuda","Argentina","Armenia","Australia","Austria","Azerbaijan",
            "Bahamas","Bahrain","Bangladesh","Barbados","Belarus","Belgium","Belize","Benin","Bhutan","Bolivia","Bosnia and Herzegovina","Botswana","Brazil","Brunei","Bulgaria","Burkina Faso","Burundi",
            "Cabo Verde","Cambodia","Cameroon","Canada","Central African Republic","Chad","Chile","China","Colombia","Comoros","Congo","Congo (Democratic Republic)","Costa Rica","Côte d'Ivoire","Croatia","Cuba","Cyprus","Czechia",
            "Denmark","Djibouti","Dominica","Dominican Republic","Ecuador","Egypt","El Salvador","Equatorial Guinea","Eritrea","Estonia","Eswatini","Ethiopia",
            "Fiji","Finland","France","Gabon","Gambia","Georgia","Germany","Ghana","Greece","Grenada","Guatemala","Guinea","Guinea-Bissau","Guyana",
            "Haiti","Honduras","Hungary","Iceland","India","Indonesia","Iran","Iraq","Ireland","Israel","Italy","Jamaica","Japan","Jordan",
            "Kazakhstan","Kenya","Kiribati","Kosovo","Kuwait","Kyrgyzstan","Laos","Latvia","Lebanon","Lesotho","Liberia","Libya","Liechtenstein","Lithuania","Luxembourg",
            "Madagascar","Malawi","Malaysia","Maldives","Mali","Malta","Marshall Islands","Mauritania","Mauritius","Mexico","Micronesia","Moldova","Monaco","Mongolia","Montenegro","Morocco","Mozambique","Myanmar",
            "Namibia","Nauru","Nepal","Netherlands","New Zealand","Nicaragua","Niger","Nigeria","North Korea","North Macedonia","Norway","Oman",
            "Pakistan","Palau","Palestine","Panama","Papua New Guinea","Paraguay","Peru","Philippines","Poland","Portugal","Qatar","Romania","Russia","Rwanda",
            "Saint Kitts and Nevis","Saint Lucia","Saint Vincent and the Grenadines","Samoa","San Marino","Sao Tome and Principe","Saudi Arabia","Senegal","Serbia","Seychelles","Sierra Leone","Singapore","Slovakia","Slovenia","Solomon Islands","Somalia","South Africa","South Korea","South Sudan","Spain","Sri Lanka","Sudan","Suriname","Sweden","Switzerland","Syria",
            "Taiwan","Tajikistan","Tanzania","Thailand","Timor-Leste","Togo","Tonga","Trinidad and Tobago","Tunisia","Turkey","Turkmenistan","Tuvalu",
            "Uganda","Ukraine","United Arab Emirates","United Kingdom","United States","Uruguay","Uzbekistan","Vanuatu","Vatican City","Venezuela","Vietnam","Yemen","Zambia","Zimbabwe",
        ];
        return array_values( array_filter( array_map( "strval", (array) apply_filters( "trinity_forms_countries", $list ) ) ) );
    }
}
