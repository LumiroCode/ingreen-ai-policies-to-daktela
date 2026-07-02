<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaNumericValueNormalizer;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;

/**
 * @internal
 */
final class FindCrmRecordIdentifiersByTitle
{
    public function __construct(
        private readonly GetCrmRecordsByTicketId $getCrmRecordsByTicketId,
        private readonly DaktelaNumericValueNormalizer $valueNormalizer = new DaktelaNumericValueNormalizer(),
        private readonly ?AppLogger $logger = null
    ) {
    }

    /**
     * Returns CRM record identifiers suitable for /api/v6/crmRecords/{name} writes.
     *
     * @return list<string> Daktela CRM record identifiers from record.name.
     */
    public function execute(
        string $ticketId,
        string $recordTitle,
        string $registrationNumber,
        string $vin,
        string $invalidLookupErrorCode,
        string $invalidLookupMessage
    ): array {
        $registrationNumber = $this->requiredLookupIdentifier(
            $registrationNumber,
            'nr_rejestracyjny',
            'registrationNumber',
            $invalidLookupErrorCode,
            $invalidLookupMessage
        );
        $vin = $this->requiredLookupIdentifier($vin, 'vin', 'vin', $invalidLookupErrorCode, $invalidLookupMessage);

        $recordIdentifiers = [];

        foreach ($this->getCrmRecordsByTicketId->execute($ticketId) as $record) {
            if (!$this->isMatchingRecord($record, $recordTitle, $registrationNumber, $vin)) {
                continue;
            }

            $recordIdentifiers[] = $this->recordIdentifier($record, $ticketId, $recordTitle);
        }

        return $recordIdentifiers;
    }

    private function requiredLookupIdentifier(
        string $value,
        string $fieldName,
        string $detailsField,
        string $errorCode,
        string $message
    ): string {
        $value = $this->normalizeLookupIdentifier($fieldName, $value);

        if ($value === null || $value === '') {
            throw new AppException(400, $errorCode, $message, [
                'field' => $detailsField,
            ]);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function isMatchingRecord(array $record, string $recordTitle, string $registrationNumber, string $vin): bool
    {
        if (!$this->recordTitleMatches($record, $recordTitle)) {
            $this->logger?->info('Daktela CRM record skipped because type title did not match lookup title.', [
                'recordName' => $this->scalarString($record['name'] ?? null),
                'expectedTitle' => $recordTitle,
                'recordTitle' => $this->scalarString($record['title'] ?? null),
                'recordTypeTitle' => $this->recordTypeTitle($record),
            ]);

            return false;
        }

        $customFields = $record['customFields'] ?? null;

        if (!is_array($customFields) || array_is_list($customFields)) {
            $this->logger?->info('Daktela CRM record skipped because custom fields were not a keyed object.', [
                'recordName' => $this->scalarString($record['name'] ?? null),
                'recordTitle' => $recordTitle,
                'customFieldsType' => get_debug_type($customFields),
            ]);

            return false;
        }

        return $this->fieldMatches($customFields['nr_rejestracyjny'] ?? null, $registrationNumber, 'nr_rejestracyjny')
            || $this->fieldMatches($customFields['vin'] ?? null, $vin, 'vin');
    }

    /**
     * @param array<string,mixed> $record
     */
    private function recordTitleMatches(array $record, string $recordTitle): bool
    {
        return $this->scalarString($record['title'] ?? null) === $recordTitle
            || $this->recordTypeTitle($record) === $recordTitle;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function recordTypeTitle(array $record): ?string
    {
        $type = $record['type'] ?? null;

        if (!is_array($type)) {
            return null;
        }

        return $this->scalarString($type['title'] ?? null);
    }

    private function fieldMatches(mixed $recordValue, string $lookupValue, string $fieldName): bool
    {
        $recordValue = $this->scalarString($recordValue);

        if ($recordValue === null) {
            return false;
        }

        $recordValue = $this->normalizeLookupIdentifier($fieldName, $recordValue);
        $lookupValue = $this->normalizeLookupIdentifier($fieldName, $lookupValue);

        return $recordValue !== null && $lookupValue !== null && $recordValue === $lookupValue;
    }

    private function scalarString(mixed $value): ?string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                return null;
            }

            foreach ($value as $item) {
                $scalar = $this->scalarPrimitiveString($item);

                if ($scalar !== null) {
                    return $scalar;
                }
            }

            return null;
        }

        return $this->scalarPrimitiveString($value);
    }

    private function scalarPrimitiveString(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeLookupIdentifier(string $fieldName, mixed $value): ?string
    {
        $value = $this->valueNormalizer->normalizeForField($fieldName, $value);

        if ($value === null) {
            return null;
        }

        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    /**
     * @param array<string,mixed> $record
     */
    private function recordIdentifier(array $record, string $ticketId, string $recordTitle): string
    {
        $recordIdentifier = $this->scalarString($record['name'] ?? null);

        if ($recordIdentifier === null) {
            throw new AppException(502, 'invalid_daktela_response', 'Matched Daktela CRM record has no writable identifier.', [
                'path' => '/api/v6/crmRecords',
                'ticketId' => $ticketId,
                'recordTitle' => $recordTitle,
            ]);
        }

        return $recordIdentifier;
    }
}
