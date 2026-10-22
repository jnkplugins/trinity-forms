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
        foreach ( self::countries() as $c ) {
            $options .= '<option value="' . esc_attr( $c ) . '"' . selected( $current, $c, false ) . '>' . esc_html( $c ) . '</option>';
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
