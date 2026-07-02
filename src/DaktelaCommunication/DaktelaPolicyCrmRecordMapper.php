<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

final class DaktelaPolicyCrmRecordMapper
{
    private const POLICY_CRM_TYPE_NAME = 'type_68227cae0ac91290315441';
    private const POLICY_CRM_TITLE = 'Polisy';
    private const CUSTOM_FIELDS = [
        'marka',
        'model',
        'nr_rejestracyjny',
        'vin',
        'towarzystwo_ubezpieczeniowe',
        'nr_polisy',
        'cena_pakietu',
        'cena_wznowienia',
        'oc_cena',
        'ac_cena',
        'cena_nnw',
        'cena_assistance',
        'gap_cena',
        'cena_przedluzonej_gwarancji',
        'rodzaj_polisy',
        'data_konca_polisy',
        'data_sprzedazy_lubezpieczenia',
        'data_sprzedazy_wznowienia',
    ];

    private readonly DaktelaNumericValueNormalizer $valueNormalizer;

    public function __construct(?DaktelaNumericValueNormalizer $valueNormalizer = null)
    {
        $this->valueNormalizer = $valueNormalizer ?? new DaktelaNumericValueNormalizer();
    }

    /**
     * @param array<string,mixed> $ticket
     * @return array<string,mixed>
     */
    public function toPolicyCrmPayload(
        string $ticketId,
        ExtractedPolicyData $data,
        array $ticket,
        ?array $attachment = null
    ): array
    {
        $customFields = $this->customFields($data);
        $origin = $this->ticketCustomField($ticket, 'pochodzenie_polisy');

        if ($origin !== null) {
            $customFields['pochodzenie_polisy'] = $origin;
        }

        $payload = [
            'title' => self::POLICY_CRM_TITLE,
            'type' => [
                'name' => self::POLICY_CRM_TYPE_NAME,
                'title' => self::POLICY_CRM_TITLE,
            ],
            'stage' => 'OPEN',
            'description' => '',
            'ticket' => ['name' => $ticketId],
            'customFields' => $customFields,
        ];

        foreach (['user', 'contact', 'account'] as $field) {
            $name = $this->linkedName($ticket[$field] ?? null);

            if ($name !== null) {
                $payload[$field] = ['name' => $name];
            }
        }

        if ($attachment !== null) {
            $payload['add_files'] = [$attachment];
        }

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function customFields(ExtractedPolicyData $data): array
    {
        $customFields = [];

        foreach (self::CUSTOM_FIELDS as $field) {
            $value = $this->valueNormalizer->normalizeForField($field, $data->field($field));

            if ($value === null) {
                continue;
            }

            $customFields[$field] = $value;
        }

        return $customFields;
    }

    private function linkedName(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? null;
        }

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $ticket
     */
    private function ticketCustomField(array $ticket, string $field): ?string
    {
        $customFields = $ticket['customFields'] ?? null;

        if (!is_array($customFields)) {
            return null;
        }

        return $this->scalarValue($customFields[$field] ?? null);
    }

    private function scalarValue(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $normalized = $this->scalarValue($item);

                if ($normalized !== null) {
                    return $normalized;
                }
            }

            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
