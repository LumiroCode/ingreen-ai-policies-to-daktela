<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\TicketPolicyValuesProvider;

final class DaktelaTicketPolicyValuesProvider implements TicketPolicyValuesProvider
{
    public function __construct(
        private readonly DaktelaModule $daktela,
        private readonly ?AppLogger $logger = null
    )
    {
    }

    /**
     * @return array<string,string>
     */
    public function valuesForTicket(string $ticketId): array
    {
        $payload = $this->daktela->getTicketByName($ticketId);
        $ticket = $this->resultObject($payload);
        $customFields = $ticket['customFields'] ?? null;

        $this->logger?->info('Daktela ticket policy values provider received ticket data.', [
            'ticketId' => $ticketId,
            'payloadKeys' => array_keys($payload),
            'ticketKeys' => array_keys($ticket),
            'customFieldsType' => get_debug_type($customFields),
            'customFieldKeys' => is_array($customFields) ? array_keys($customFields) : [],
        ]);

        if (!is_array($customFields)) {
            $this->logger?->info('Daktela ticket policy values provider found no custom fields.', [
                'ticketId' => $ticketId,
            ]);

            return [];
        }

        $values = [];
        $rawPolicyValues = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            $rawValue = $customFields[$field] ?? null;
            $value = $this->fieldValue($rawValue);
            $rawPolicyValues[$field] = $rawValue;

            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        $this->logger?->info('Daktela ticket policy values provider normalized policy values.', [
            'ticketId' => $ticketId,
            'rawPolicyValues' => $rawPolicyValues,
            'normalizedValues' => $values,
            'normalizedFieldCount' => count($values),
        ]);

        return $values;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resultObject(array $payload): array
    {
        $result = $payload['result'] ?? $payload;

        return is_array($result) ? $result : [];
    }

    private function fieldValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $items = [];

            foreach ($value as $item) {
                $itemValue = $this->scalarValue($item);

                if ($itemValue !== null) {
                    $items[] = $itemValue;
                }
            }

            return $items !== [] ? implode(', ', $items) : null;
        }

        return $this->scalarValue($value);
    }

    private function scalarValue(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
