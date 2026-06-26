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

    /**
     * @param array<string,mixed> $ticket
     * @return array<string,mixed>
     */
    public function toPolicyCrmPayload(string $ticketId, ExtractedPolicyData $data, array $ticket): array
    {
        $payload = [
            'title' => self::POLICY_CRM_TITLE,
            'type' => [
                'name' => self::POLICY_CRM_TYPE_NAME,
                'title' => self::POLICY_CRM_TITLE,
            ],
            'stage' => 'OPEN',
            'description' => '',
            'ticket' => ['name' => $ticketId],
            'customFields' => $this->customFields($data),
        ];

        foreach (['user', 'contact', 'account'] as $field) {
            $name = $this->linkedName($ticket[$field] ?? null);

            if ($name !== null) {
                $payload[$field] = ['name' => $name];
            }
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
            $value = $data->field($field);

            if ($value === null || trim($value) === '') {
                continue;
            }

            $customFields[$field] = trim($value);
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
}
