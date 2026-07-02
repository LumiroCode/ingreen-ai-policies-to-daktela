<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

final class DaktelaNumericValueNormalizer
{
    /**
     * Numeric fields that must be serialized as plain numbers before they are sent to Daktela.
     *
     * @var list<string>
     */
    private const NUMERIC_FIELDS = [
        'rocznik',
        'przebieg',
        'pojemnosc_silnika',
        'wartosc_pojazdu_brutto',
        'wartosc_pojazdu_netto',
        'cena_pakietu',
        'cena_wznowienia',
        'oc_cena',
        'ac_cena',
        'cena_nnw',
        'cena_assistance',
        'gap_cena',
        'cena_przedluzonej_gwarancji',
    ];

    public function normalizeForField(string $field, mixed $value): ?string
    {
        if (!in_array($field, self::NUMERIC_FIELDS, true)) {
            return $this->trimScalarValue($value);
        }

        return $this->normalizeNumericValue($value);
    }

    private function normalizeNumericValue(mixed $value): ?string
    {
        $value = $this->trimScalarValue($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/[-+]?\d(?:[\d\s.,\x{00A0}]*\d)?/u', $value, $matches) !== 1) {
            return null;
        }

        $number = preg_replace('/[\s\x{00A0}]+/u', '', $matches[0]);

        if (!is_string($number) || $number === '') {
            return null;
        }

        $negative = str_starts_with($number, '-');
        $number = ltrim($number, '+-');

        $commaPosition = strrpos($number, ',');
        $dotPosition = strrpos($number, '.');
        $decimalSeparator = null;

        if ($commaPosition !== false && $dotPosition !== false) {
            $decimalSeparator = $commaPosition > $dotPosition ? ',' : '.';
        } elseif ($commaPosition !== false && strlen($number) - $commaPosition <= 3) {
            $decimalSeparator = ',';
        } elseif ($dotPosition !== false && strlen($number) - $dotPosition <= 3) {
            $decimalSeparator = '.';
        }

        if ($decimalSeparator !== null) {
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $number = str_replace($thousandsSeparator, '', $number);
            $number = str_replace($decimalSeparator, '.', $number);
        } else {
            $number = str_replace([',', '.'], '', $number);
        }

        $parts = explode('.', $number, 2);
        $integerPart = ltrim($parts[0], '0');
        $integerPart = $integerPart !== '' ? $integerPart : '0';
        $normalized = $integerPart;

        if (array_key_exists(1, $parts) && $parts[1] !== '') {
            $fractionPart = rtrim($parts[1], '0');

            if ($fractionPart !== '') {
                $normalized .= '.' . $fractionPart;
            }
        }

        if ($negative && $normalized !== '0') {
            $normalized = '-' . $normalized;
        }

        return $normalized;
    }

    private function trimScalarValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
