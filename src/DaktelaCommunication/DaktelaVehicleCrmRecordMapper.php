<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

final class DaktelaVehicleCrmRecordMapper
{
    private const VEHICLE_CRM_TYPE_NAME = 'type_68227f433880e473202607';
    private const VEHICLE_CRM_TITLE = 'Pojazdy';
    private const CUSTOM_FIELDS = [
        'nr_rejestracyjny',
        'marka',
        'model',
        'wersja',
        'vin',
        'forma_wlasnosci',
        'rocznik',
        'przebieg',
        'data_pierwszej_rejestracji',
        'wartosc_pojazdu_brutto',
        'wspolposiadacz',
        'imie_wspolposiadacza',
        'nazwisko_wspolposiadacza',
        'pesel_wspolposiadacza',
        'adres_wspolposiadacza',
    ];

    /**
     * @param array<string,mixed> $ticket
     * @return array<string,mixed>
     */
    public function toVehicleCrmPayload(string $ticketId, ExtractedPolicyData $data, array $ticket): array
    {
        $payload = [
            'title' => self::VEHICLE_CRM_TITLE,
            'type' => [
                'name' => self::VEHICLE_CRM_TYPE_NAME,
                'title' => self::VEHICLE_CRM_TITLE,
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
