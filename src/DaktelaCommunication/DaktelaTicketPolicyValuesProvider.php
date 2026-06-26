<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\TicketPolicyValuesProvider;

final class DaktelaTicketPolicyValuesProvider implements TicketPolicyValuesProvider
{
    public function __construct(private readonly DaktelaModule $daktela)
    {
    }

    /**
     * @return array<string,string>
     */
    public function valuesForTicket(string $ticketId): array
    {
        $ticket = $this->resultObject($this->daktela->getTicketByName($ticketId));
        $customFields = $ticket['customFields'] ?? null;

        if (!is_array($customFields)) {
            return [];
        }

        $values = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            $value = $this->fieldValue($customFields[$field] ?? null);

            if ($value !== null) {
                $values[$field] = $value;
            }
        }

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
