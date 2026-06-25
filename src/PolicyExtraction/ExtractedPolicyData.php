<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

final class ExtractedPolicyData
{
    public const FIELDS = [
        'stan_pojazdu',
        'marka',
        'model',
        'wersja',
        'vin',
        'rocznik',
        'przebieg',
        'wartosc_pojazdu_brutto',
        'wartosc_pojazdu_netto',
        'kategoria_pojazdu',
        'sposob_korzystania',
        'typ_silnika',
        'pojemnosc_silnika',
        'data_nabycia',
        'data_pierwszej_rejestracji',
        'planowana_data_rejestracji',
        'pakiet_ubezpieczeniowy',
        'rodzaj_assistance',
        'towarzystwo_ubezpieczeniowe',
        'kategoria_tu',
        'data_konca_polisy',
        'cena_pakietu',
        'data_sprzedazy_lubezpieczenia',
    ];

    public const VEHICLE_FIELDS = [
        'stan_pojazdu',
        'marka',
        'model',
        'wersja',
        'vin',
        'rocznik',
        'przebieg',
        'wartosc_pojazdu_brutto',
        'wartosc_pojazdu_netto',
        'kategoria_pojazdu',
        'sposob_korzystania',
        'typ_silnika',
        'pojemnosc_silnika',
        'data_nabycia',
        'data_pierwszej_rejestracji',
        'planowana_data_rejestracji',
    ];

    public const POLICY_FIELDS = [
        'pakiet_ubezpieczeniowy',
        'rodzaj_assistance',
        'towarzystwo_ubezpieczeniowe',
        'kategoria_tu',
        'data_konca_polisy',
        'cena_pakietu',
        'data_sprzedazy_lubezpieczenia',
    ];

    public const LABELS = [
        'stan_pojazdu' => 'Stan pojazdu',
        'marka' => 'Marka',
        'model' => 'Model',
        'wersja' => 'Wersja',
        'vin' => 'VIN',
        'rocznik' => 'Rocznik',
        'przebieg' => 'Przebieg',
        'wartosc_pojazdu_brutto' => 'Wartość brutto',
        'wartosc_pojazdu_netto' => 'Wartość netto',
        'kategoria_pojazdu' => 'Kategoria pojazdu',
        'sposob_korzystania' => 'Sposób korzystania',
        'typ_silnika' => 'Typ silnika',
        'pojemnosc_silnika' => 'Pojemność silnika',
        'data_nabycia' => 'Data nabycia',
        'data_pierwszej_rejestracji' => 'Data pierwszej rejestracji',
        'planowana_data_rejestracji' => 'Planowana data rejestracji',
        'pakiet_ubezpieczeniowy' => 'Pakiet ubezpieczeniowy',
        'rodzaj_assistance' => 'Rodzaj assistance',
        'towarzystwo_ubezpieczeniowe' => 'Towarzystwo ubezpieczeniowe',
        'kategoria_tu' => 'Kategoria TU',
        'data_konca_polisy' => 'Data końca polisy',
        'cena_pakietu' => 'Cena pakietu pierwszy rok',
        'data_sprzedazy_lubezpieczenia' => 'Data sprzedaży ubezpieczenia pierwszy rok',
    ];

    /** @var array<string,string|null> */
    public readonly array $fields;

    public readonly ?string $carMake;
    public readonly ?string $carModel;
    public readonly ?string $value;

    /**
     * @param array<string,string|null>|null $fields
     */
    public function __construct(
        ?string $carMake,
        ?string $carModel,
        ?string $value,
        public readonly string $rawResponse,
        ?array $fields = null
    ) {
        $this->fields = self::normalizeFields($fields ?? [
            'marka' => $carMake,
            'model' => $carModel,
            'wartosc_pojazdu_brutto' => $value,
        ]);
        $this->carMake = $this->fields['marka'];
        $this->carModel = $this->fields['model'];
        $this->value = $this->fields['wartosc_pojazdu_brutto'];
    }

    /**
     * @param array<string,string|null> $fields
     */
    public static function fromFields(array $fields, string $rawResponse): self
    {
        return new self(
            $fields['marka'] ?? null,
            $fields['model'] ?? null,
            $fields['wartosc_pojazdu_brutto'] ?? null,
            $rawResponse,
            $fields
        );
    }

    public function field(string $field): ?string
    {
        return $this->fields[$field] ?? null;
    }

    /**
     * @param array<string,string|null> $fields
     * @return array<string,string|null>
     */
    private static function normalizeFields(array $fields): array
    {
        $normalized = [];

        foreach (self::FIELDS as $field) {
            $normalized[$field] = $fields[$field] ?? null;
        }

        return $normalized;
    }
}
