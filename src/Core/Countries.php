<?php

declare(strict_types=1);

namespace Luster\Core;

final class Countries
{
    /**
     * Returns all UN-recognised countries (195 as of 2025), alphabetically
     * sorted, keyed and valued by common English name.
     *
     * A blank first entry is included for use as an unselected <select> prompt.
     *
     * @return array<string,string>
     */
    /**
     * Builds a fully-escaped <select> element for the country field.
     *
     * Pass the result wrapped in a ViewRaw instance so the View does not
     * double-escape the HTML.
     *
     * @param string $selected The currently selected country value (empty = none).
     */
    public static function buildSelect(string $selected = ''): string
    {
        $html = "<select id=\"country\" name=\"country\" required>\n";

        foreach (self::list() as $value => $label) {
            $eValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $eLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $sel    = ($value === $selected) ? ' selected' : '';
            $html  .= "  <option value=\"{$eValue}\"{$sel}>{$eLabel}</option>\n";
        }

        $html .= '</select>';

        return $html;
    }

    /**
     * Returns all UN-recognised countries (195 as of 2025), alphabetically
     * sorted, keyed and valued by common English name.
     *
     * A blank first entry is included for use as an unselected <select> prompt.
     *
     * @return array<string,string>
     */
    public static function list(): array
    {
        return [
            ''                                   => 'Select your country…',

            // A
            'Afghanistan'                        => 'Afghanistan',
            'Albania'                            => 'Albania',
            'Algeria'                            => 'Algeria',
            'Andorra'                            => 'Andorra',
            'Angola'                             => 'Angola',
            'Antigua and Barbuda'                => 'Antigua and Barbuda',
            'Argentina'                          => 'Argentina',
            'Armenia'                            => 'Armenia',
            'Australia'                          => 'Australia',
            'Austria'                            => 'Austria',
            'Azerbaijan'                         => 'Azerbaijan',

            // B
            'Bahamas'                            => 'Bahamas',
            'Bahrain'                            => 'Bahrain',
            'Bangladesh'                         => 'Bangladesh',
            'Barbados'                           => 'Barbados',
            'Belarus'                            => 'Belarus',
            'Belgium'                            => 'Belgium',
            'Belize'                             => 'Belize',
            'Benin'                              => 'Benin',
            'Bhutan'                             => 'Bhutan',
            'Bolivia'                            => 'Bolivia',
            'Bosnia and Herzegovina'             => 'Bosnia and Herzegovina',
            'Botswana'                           => 'Botswana',
            'Brazil'                             => 'Brazil',
            'Brunei'                             => 'Brunei',
            'Bulgaria'                           => 'Bulgaria',
            'Burkina Faso'                       => 'Burkina Faso',
            'Burundi'                            => 'Burundi',

            // C
            'Cabo Verde'                         => 'Cabo Verde',
            'Cambodia'                           => 'Cambodia',
            'Cameroon'                           => 'Cameroon',
            'Canada'                             => 'Canada',
            'Central African Republic'           => 'Central African Republic',
            'Chad'                               => 'Chad',
            'Chile'                              => 'Chile',
            'China'                              => 'China',
            'Colombia'                           => 'Colombia',
            'Comoros'                            => 'Comoros',
            'Congo, Democratic Republic of the'  => 'Congo, Democratic Republic of the',
            'Congo, Republic of the'             => 'Congo, Republic of the',
            'Costa Rica'                         => 'Costa Rica',
            'Côte d\'Ivoire'                     => 'Côte d\'Ivoire',
            'Croatia'                            => 'Croatia',
            'Cuba'                               => 'Cuba',
            'Cyprus'                             => 'Cyprus',
            'Czechia'                            => 'Czechia',

            // D
            'Denmark'                            => 'Denmark',
            'Djibouti'                           => 'Djibouti',
            'Dominica'                           => 'Dominica',
            'Dominican Republic'                 => 'Dominican Republic',

            // E
            'Ecuador'                            => 'Ecuador',
            'Egypt'                              => 'Egypt',
            'El Salvador'                        => 'El Salvador',
            'Equatorial Guinea'                  => 'Equatorial Guinea',
            'Eritrea'                            => 'Eritrea',
            'Estonia'                            => 'Estonia',
            'Eswatini'                           => 'Eswatini',
            'Ethiopia'                           => 'Ethiopia',

            // F
            'Fiji'                               => 'Fiji',
            'Finland'                            => 'Finland',
            'France'                             => 'France',

            // G
            'Gabon'                              => 'Gabon',
            'Gambia'                             => 'Gambia',
            'Georgia'                            => 'Georgia',
            'Germany'                            => 'Germany',
            'Ghana'                              => 'Ghana',
            'Greece'                             => 'Greece',
            'Grenada'                            => 'Grenada',
            'Guatemala'                          => 'Guatemala',
            'Guinea'                             => 'Guinea',
            'Guinea-Bissau'                      => 'Guinea-Bissau',
            'Guyana'                             => 'Guyana',

            // H
            'Haiti'                              => 'Haiti',
            'Holy See'                           => 'Holy See',
            'Honduras'                           => 'Honduras',
            'Hungary'                            => 'Hungary',

            // I
            'Iceland'                            => 'Iceland',
            'India'                              => 'India',
            'Indonesia'                          => 'Indonesia',
            'Iran'                               => 'Iran',
            'Iraq'                               => 'Iraq',
            'Ireland'                            => 'Ireland',
            'Israel'                             => 'Israel',
            'Italy'                              => 'Italy',

            // J
            'Jamaica'                            => 'Jamaica',
            'Japan'                              => 'Japan',
            'Jordan'                             => 'Jordan',

            // K
            'Kazakhstan'                         => 'Kazakhstan',
            'Kenya'                              => 'Kenya',
            'Kiribati'                           => 'Kiribati',
            'Kuwait'                             => 'Kuwait',
            'Kyrgyzstan'                         => 'Kyrgyzstan',

            // L
            'Laos'                               => 'Laos',
            'Latvia'                             => 'Latvia',
            'Lebanon'                            => 'Lebanon',
            'Lesotho'                            => 'Lesotho',
            'Liberia'                            => 'Liberia',
            'Libya'                              => 'Libya',
            'Liechtenstein'                      => 'Liechtenstein',
            'Lithuania'                          => 'Lithuania',
            'Luxembourg'                         => 'Luxembourg',

            // M
            'Madagascar'                         => 'Madagascar',
            'Malawi'                             => 'Malawi',
            'Malaysia'                           => 'Malaysia',
            'Maldives'                           => 'Maldives',
            'Mali'                               => 'Mali',
            'Malta'                              => 'Malta',
            'Marshall Islands'                   => 'Marshall Islands',
            'Mauritania'                         => 'Mauritania',
            'Mauritius'                          => 'Mauritius',
            'Mexico'                             => 'Mexico',
            'Micronesia'                         => 'Micronesia',
            'Moldova'                            => 'Moldova',
            'Monaco'                             => 'Monaco',
            'Mongolia'                           => 'Mongolia',
            'Montenegro'                         => 'Montenegro',
            'Morocco'                            => 'Morocco',
            'Mozambique'                         => 'Mozambique',
            'Myanmar'                            => 'Myanmar',

            // N
            'Namibia'                            => 'Namibia',
            'Nauru'                              => 'Nauru',
            'Nepal'                              => 'Nepal',
            'Netherlands'                        => 'Netherlands',
            'New Zealand'                        => 'New Zealand',
            'Nicaragua'                          => 'Nicaragua',
            'Niger'                              => 'Niger',
            'Nigeria'                            => 'Nigeria',
            'North Korea'                        => 'North Korea',
            'North Macedonia'                    => 'North Macedonia',
            'Norway'                             => 'Norway',

            // O
            'Oman'                               => 'Oman',

            // P
            'Pakistan'                           => 'Pakistan',
            'Palau'                              => 'Palau',
            'Palestine'                          => 'Palestine',
            'Panama'                             => 'Panama',
            'Papua New Guinea'                   => 'Papua New Guinea',
            'Paraguay'                           => 'Paraguay',
            'Peru'                               => 'Peru',
            'Philippines'                        => 'Philippines',
            'Poland'                             => 'Poland',
            'Portugal'                           => 'Portugal',

            // Q
            'Qatar'                              => 'Qatar',

            // R
            'Romania'                            => 'Romania',
            'Russia'                             => 'Russia',
            'Rwanda'                             => 'Rwanda',

            // S
            'Saint Kitts and Nevis'              => 'Saint Kitts and Nevis',
            'Saint Lucia'                        => 'Saint Lucia',
            'Saint Vincent and the Grenadines'   => 'Saint Vincent and the Grenadines',
            'Samoa'                              => 'Samoa',
            'San Marino'                         => 'San Marino',
            'São Tomé and Príncipe'              => 'São Tomé and Príncipe',
            'Saudi Arabia'                       => 'Saudi Arabia',
            'Senegal'                            => 'Senegal',
            'Serbia'                             => 'Serbia',
            'Seychelles'                         => 'Seychelles',
            'Sierra Leone'                       => 'Sierra Leone',
            'Singapore'                          => 'Singapore',
            'Slovakia'                           => 'Slovakia',
            'Slovenia'                           => 'Slovenia',
            'Solomon Islands'                    => 'Solomon Islands',
            'Somalia'                            => 'Somalia',
            'South Africa'                       => 'South Africa',
            'South Korea'                        => 'South Korea',
            'South Sudan'                        => 'South Sudan',
            'Spain'                              => 'Spain',
            'Sri Lanka'                          => 'Sri Lanka',
            'Sudan'                              => 'Sudan',
            'Suriname'                           => 'Suriname',
            'Sweden'                             => 'Sweden',
            'Switzerland'                        => 'Switzerland',
            'Syria'                              => 'Syria',

            // T
            'Tajikistan'                         => 'Tajikistan',
            'Tanzania'                           => 'Tanzania',
            'Thailand'                           => 'Thailand',
            'Timor-Leste'                        => 'Timor-Leste',
            'Togo'                               => 'Togo',
            'Tonga'                              => 'Tonga',
            'Trinidad and Tobago'                => 'Trinidad and Tobago',
            'Tunisia'                            => 'Tunisia',
            'Türkiye'                            => 'Türkiye',
            'Turkmenistan'                       => 'Turkmenistan',
            'Tuvalu'                             => 'Tuvalu',

            // U
            'Uganda'                             => 'Uganda',
            'Ukraine'                            => 'Ukraine',
            'United Arab Emirates'               => 'United Arab Emirates',
            'United Kingdom'                     => 'United Kingdom',
            'United States'                      => 'United States',
            'Uruguay'                            => 'Uruguay',
            'Uzbekistan'                         => 'Uzbekistan',

            // V
            'Vanuatu'                            => 'Vanuatu',
            'Venezuela'                          => 'Venezuela',
            'Vietnam'                            => 'Vietnam',

            // Y
            'Yemen'                              => 'Yemen',

            // Z
            'Zambia'                             => 'Zambia',
            'Zimbabwe'                           => 'Zimbabwe',
        ];
    }
}
