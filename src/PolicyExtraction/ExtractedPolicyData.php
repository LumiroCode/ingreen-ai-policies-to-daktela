<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

use DateTimeImmutable;

final class ExtractedPolicyData
{
    public const FIELDS = [
        'stan_pojazdu',
        'nr_rejestracyjny',
        'marka',
        'model',
        'wersja',
        'vin',
        'forma_wlasnosci',
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
        'wspolposiadacz',
        'imie_wspolposiadacza',
        'nazwisko_wspolposiadacza',
        'pesel_wspolposiadacza',
        'adres_wspolposiadacza',
        'pakiet_ubezpieczeniowy',
        'rodzaj_assistance',
        'towarzystwo_ubezpieczeniowe',
        'nr_polisy',
        'data_konca_polisy',
        'cena_pakietu',
        'cena_wznowienia',
        'oc_cena',
        'ac_cena',
        'cena_nnw',
        'cena_assistance',
        'gap_cena',
        'cena_przedluzonej_gwarancji',
        'rodzaj_polisy',
        'data_sprzedazy_lubezpieczenia',
        'data_sprzedazy_wznowienia',
    ];

    public const DATE_FIELDS = [
        'data_nabycia',
        'data_pierwszej_rejestracji',
        'planowana_data_rejestracji',
        'data_konca_polisy',
        'data_sprzedazy_lubezpieczenia',
        'data_sprzedazy_wznowienia',
    ];

    public const VEHICLE_FIELDS = [
        'stan_pojazdu',
        'nr_rejestracyjny',
        'marka',
        'model',
        'wersja',
        'vin',
        'forma_wlasnosci',
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
        'wspolposiadacz',
        'imie_wspolposiadacza',
        'nazwisko_wspolposiadacza',
        'pesel_wspolposiadacza',
        'adres_wspolposiadacza',
    ];

    public const POLICY_FIELDS = [
        'pakiet_ubezpieczeniowy',
        'rodzaj_assistance',
        'towarzystwo_ubezpieczeniowe',
        'nr_polisy',
        'data_konca_polisy',
        'cena_pakietu',
        'cena_wznowienia',
        'oc_cena',
        'ac_cena',
        'cena_nnw',
        'cena_assistance',
        'gap_cena',
        'cena_przedluzonej_gwarancji',
        'rodzaj_polisy',
        'data_sprzedazy_lubezpieczenia',
        'data_sprzedazy_wznowienia',
    ];

    public const LABELS = [
        'stan_pojazdu' => 'Stan pojazdu',
        'nr_rejestracyjny' => 'Nr rejestracyjny',
        'marka' => 'Marka',
        'model' => 'Model',
        'wersja' => 'Wersja',
        'vin' => 'VIN',
        'forma_wlasnosci' => 'Forma własności',
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
        'wspolposiadacz' => 'Współposiadacz',
        'imie_wspolposiadacza' => 'Imię współposiadacza',
        'nazwisko_wspolposiadacza' => 'Nazwisko współposiadacza',
        'pesel_wspolposiadacza' => 'PESEL współposiadacza',
        'adres_wspolposiadacza' => 'Adres współposiadacza',
        'pakiet_ubezpieczeniowy' => 'Pakiet ubezpieczeniowy',
        'rodzaj_assistance' => 'Rodzaj assistance',
        'towarzystwo_ubezpieczeniowe' => 'Towarzystwo ubezpieczeniowe',
        'nr_polisy' => 'Nr polisy',
        'data_konca_polisy' => 'Data końca polisy',
        'cena_pakietu' => 'Cena pakietu pierwszy rok',
        'cena_wznowienia' => 'Cena wznowienia',
        'oc_cena' => 'OC cena',
        'ac_cena' => 'AC cena',
        'cena_nnw' => 'Cena NNW',
        'cena_assistance' => 'Cena assistance',
        'gap_cena' => 'GAP cena',
        'cena_przedluzonej_gwarancji' => 'Cena przedłużonej gwarancji',
        'rodzaj_polisy' => 'Rodzaj polisy',
        'data_sprzedazy_lubezpieczenia' => 'Data sprzedaży ubezpieczenia pierwszy rok',
        'data_sprzedazy_wznowienia' => 'Data sprzedaży wznowienia',
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

    public static function isDateField(string $field): bool
    {
        return in_array($field, self::DATE_FIELDS, true);
    }

    public static function fieldInputType(string $field): string
    {
        return self::isDateField($field) ? 'date' : 'text';
    }

    public static function fieldInputValue(string $field, ?string $value): string
    {
        $value = self::normalizedScalarValue($value);

        if ($value === null) {
            return '';
        }

        if (!self::isDateField($field)) {
            return $value;
        }

        $date = self::dateValue($value);

        return $date?->format('Y-m-d') ?? '';
    }

    public static function fieldDisplayValue(string $field, ?string $value): string
    {
        $value = self::normalizedScalarValue($value);

        if ($value === null) {
            return '';
        }

        if (!self::isDateField($field)) {
            return $value;
        }

        $date = self::dateValue($value);

        return $date?->format('d.m.Y') ?? $value;
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

    private static function normalizedScalarValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function dateValue(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        $value = preg_split('/[T\s]/', $value, 2)[0] ?? $value;

        if (preg_match('/^(?<year>\d{4})[-\/.](?<month>\d{1,2})[-\/.](?<day>\d{1,2})$/', $value, $matches) === 1) {
            $year = (int) $matches['year'];
            $month = (int) $matches['month'];
            $day = (int) $matches['day'];

            if (checkdate($month, $day, $year)) {
                return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            }
        }

        if (preg_match('/^(?<day>\d{1,2})[-\/.](?<month>\d{1,2})[-\/.](?<year>\d{4})$/', $value, $matches) === 1) {
            $year = (int) $matches['year'];
            $month = (int) $matches['month'];
            $day = (int) $matches['day'];

            if (checkdate($month, $day, $year)) {
                return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            }
        }

        return null;
    }
}
