<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

use Ingreen\DaktelaPolicy\Support\AppException;

final class PolicyDataResponseParser
{
    /** @var array<string,string> */
    private const KEY_ALIASES = [
        'stan pojazdu' => 'stan_pojazdu',
        'stan_pojazdu' => 'stan_pojazdu',
        'marka' => 'marka',
        'marka pojazdu' => 'marka',
        'model' => 'model',
        'model pojazdu' => 'model',
        'wersja' => 'wersja',
        'wersja wyposazenia' => 'wersja',
        'wersja wyposażenia' => 'wersja',
        'vin' => 'vin',
        'numer vin' => 'vin',
        'numer vin pojazdu' => 'vin',
        'rocznik' => 'rocznik',
        'rok produkcji' => 'rocznik',
        'rok produkcji pojazdu' => 'rocznik',
        'przebieg' => 'przebieg',
        'przebieg pojazdu' => 'przebieg',
        'wartosc pojazdu brutto' => 'wartosc_pojazdu_brutto',
        'wartość pojazdu brutto' => 'wartosc_pojazdu_brutto',
        'wartosc brutto' => 'wartosc_pojazdu_brutto',
        'wartość brutto' => 'wartosc_pojazdu_brutto',
        'wartosc_pojazdu_brutto' => 'wartosc_pojazdu_brutto',
        'wartosc pojazdu netto' => 'wartosc_pojazdu_netto',
        'wartość pojazdu netto' => 'wartosc_pojazdu_netto',
        'wartosc netto' => 'wartosc_pojazdu_netto',
        'wartość netto' => 'wartosc_pojazdu_netto',
        'wartosc_pojazdu_netto' => 'wartosc_pojazdu_netto',
        'kategoria pojazdu' => 'kategoria_pojazdu',
        'kategoria_pojazdu' => 'kategoria_pojazdu',
        'sposob korzystania' => 'sposob_korzystania',
        'sposób korzystania' => 'sposob_korzystania',
        'sposob_korzystania' => 'sposob_korzystania',
        'typ silnika' => 'typ_silnika',
        'typ_silnika' => 'typ_silnika',
        'rodzaj silnika' => 'typ_silnika',
        'pojemnosc silnika' => 'pojemnosc_silnika',
        'pojemność silnika' => 'pojemnosc_silnika',
        'pojemnosc_silnika' => 'pojemnosc_silnika',
        'data nabycia' => 'data_nabycia',
        'data_nabycia' => 'data_nabycia',
        'data pierwszej rejestracji' => 'data_pierwszej_rejestracji',
        'data_pierwszej_rejestracji' => 'data_pierwszej_rejestracji',
        'planowana data rejestracji' => 'planowana_data_rejestracji',
        'planowana_data_rejestracji' => 'planowana_data_rejestracji',
        'pakiet ubezpieczeniowy' => 'pakiet_ubezpieczeniowy',
        'pakiet_ubezpieczeniowy' => 'pakiet_ubezpieczeniowy',
        'czy pakiet ubezpieczeniowy' => 'pakiet_ubezpieczeniowy',
        'rodzaj assistance' => 'rodzaj_assistance',
        'rodzaj_assistance' => 'rodzaj_assistance',
        'assistance' => 'rodzaj_assistance',
        'towarzystwo ubezpieczeniowe' => 'towarzystwo_ubezpieczeniowe',
        'towarzystwo_ubezpieczeniowe' => 'towarzystwo_ubezpieczeniowe',
        'ubezpieczyciel' => 'towarzystwo_ubezpieczeniowe',
        'kategoria tu' => 'kategoria_tu',
        'kategoria_tu' => 'kategoria_tu',
        'kategoria towarzystwa ubezpieczeniowego' => 'kategoria_tu',
        'data konca polisy' => 'data_konca_polisy',
        'data końca polisy' => 'data_konca_polisy',
        'data_konca_polisy' => 'data_konca_polisy',
        'cena pakietu' => 'cena_pakietu',
        'cena pakietu pierwszy rok' => 'cena_pakietu',
        'cena_pakietu' => 'cena_pakietu',
        'data sprzedazy ubezpieczenia' => 'data_sprzedazy_lubezpieczenia',
        'data sprzedaży ubezpieczenia' => 'data_sprzedazy_lubezpieczenia',
        'data sprzedazy ubezpieczenia pierwszy rok' => 'data_sprzedazy_lubezpieczenia',
        'data sprzedaży ubezpieczenia pierwszy rok' => 'data_sprzedazy_lubezpieczenia',
        'data_sprzedazy_lubezpieczenia' => 'data_sprzedazy_lubezpieczenia',
        'car_make' => 'marka',
        'car_model' => 'model',
        'value' => 'wartosc_pojazdu_brutto',
    ];

    public function parse(string $response): ExtractedPolicyData
    {
        $payload = $this->payloadFromJson($response) ?? $this->payloadFromKeyValueText($response);

        if (!is_array($payload)) {
            throw new AppException(502, 'policy_extraction_parse_failed', 'Claude did not return valid policy extraction JSON.');
        }

        return ExtractedPolicyData::fromFields($this->fieldsFromPayload($payload), $response);
    }

    private function jsonObject(string $response): string
    {
        $response = trim($response);

        if (str_starts_with($response, '{') && str_ends_with($response, '}')) {
            return $response;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches) === 1) {
            return trim($matches[1]);
        }

        $start = strpos($response, '{');
        $end = strrpos($response, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($response, $start, $end - $start + 1);
        }

        return $response;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function payloadFromJson(string $response): ?array
    {
        foreach ($this->jsonCandidates($response) as $candidate) {
            $payload = json_decode($candidate, true);

            if (is_array($payload) && $this->hasRecognizedKey($payload)) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function jsonCandidates(string $response): array
    {
        $candidates = [$this->jsonObject($response)];

        if (preg_match_all('/```(?:json)?\s*(.*?)\s*```/s', $response, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                $candidates[] = trim($block);
            }
        }

        if (preg_match_all('/\{(?:[^{}]|(?R))*\}/s', $response, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $candidates[] = trim($match);
            }
        }

        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')));
    }

    /**
     * @return array<string,string|null>|null
     */
    private function payloadFromKeyValueText(string $response): ?array
    {
        $payload = [];
        $parts = preg_split('/\R|[;,](?=\s*["\']?[\p{L}_][\p{L}\p{N}_ ]{1,80}["\']?\s*:)/u', $response) ?: [];

        foreach ($parts as $part) {
            if (preg_match('/^\s*(?:[-*•]\s*)?["\']?(.+?)["\']?\s*:\s*(.*?)\s*$/u', trim($part), $matches) !== 1) {
                continue;
            }

            $field = $this->fieldForKey($matches[1]);

            if ($field === null) {
                continue;
            }

            $payload[$field] = $this->nullableKeyValue($matches[2]);
        }

        return $payload !== [] ? $payload : null;
    }

    private function fieldForKey(string $key): ?string
    {
        $key = trim($key, " \t\n\r\0\x0B\"'");
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;
        $normalized = strtolower(strtr($key, [
            'Ą' => 'ą',
            'Ć' => 'ć',
            'Ę' => 'ę',
            'Ł' => 'ł',
            'Ń' => 'ń',
            'Ó' => 'ó',
            'Ś' => 'ś',
            'Ź' => 'ź',
            'Ż' => 'ż',
        ]));

        return self::KEY_ALIASES[$normalized] ?? null;
    }

    private function nullableKeyValue(string $value): ?string
    {
        $value = trim($value, " \t\n\r\0\x0B,\"'");

        if ($value === '' || preg_match('/^(null|brak|nie dotyczy|n\/a|-|\/)$/iu', $value) === 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $payload
     * @return array<string,string|null>
     */
    private function fieldsFromPayload(array $payload): array
    {
        $fields = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            $fields[$field] = null;
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $field = $this->fieldForKey($key);

            if ($field !== null) {
                $fields[$field] = $this->nullableString($value);
            }
        }

        return $fields;
    }

    /**
     * @param array<mixed> $payload
     */
    private function hasRecognizedKey(array $payload): bool
    {
        foreach (array_keys($payload) as $key) {
            if (is_string($key) && $this->fieldForKey($key) !== null) {
                return true;
            }
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(null|brak|nie dotyczy|n\/a|-|\/)$/iu', $value) === 1) {
            return null;
        }

        return $value !== '' ? $value : null;
    }
}
