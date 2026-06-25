<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

final class PolicyConfirmationForm
{
    /**
     * @param array<string,string> $values
     * @param array<string,bool> $lockedFields
     */
    private function __construct(
        private readonly bool $hasData,
        private readonly array $values,
        private readonly array $lockedFields
    ) {
    }

    /**
     * @param array<string,string>|null $policyData
     * @param array<string,string>|null $policyLocked
     */
    public static function fromRequest(?array $policyData, ?array $policyLocked): self
    {
        $values = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            $values[$field] = self::nullableValue($policyData[$field] ?? null);
        }

        $lockedFields = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            if (is_array($policyLocked) && array_key_exists($field, $policyLocked)) {
                $lockedFields[$field] = true;
            }
        }

        return new self($policyData !== null, $values, $lockedFields);
    }

    /**
     * @return array<string,bool>
     */
    public static function allLockedFields(): array
    {
        return array_fill_keys(ExtractedPolicyData::FIELDS, true);
    }

    /**
     * @return array<string,bool>
     */
    public function lockedFields(): array
    {
        return $this->lockedFields;
    }

    public function validationMessage(?string $confirmation): ?string
    {
        $allLocked = count($this->lockedFields) === count(ExtractedPolicyData::FIELDS);

        if ($confirmation === 'yes' && !$allLocked) {
            return 'Aby potwierdzić poprawność danych, zaznacz wszystkie pola jako poprawne.';
        }

        if ($confirmation === 'no' && $allLocked) {
            return 'Nie można zgłosić niepoprawnych danych, gdy wszystkie pola są oznaczone jako poprawne.';
        }

        return null;
    }

    public function toPolicyData(): ?ExtractedPolicyData
    {
        if (!$this->hasData) {
            return null;
        }

        return $this->policyDataFromValues($this->values);
    }

    public function applyLockedValues(ExtractedPolicyData $extractedData): ExtractedPolicyData
    {
        if ($this->lockedFields === []) {
            return $extractedData;
        }

        $values = $extractedData->fields;

        foreach (ExtractedPolicyData::FIELDS as $field) {
            if (isset($this->lockedFields[$field])) {
                $values[$field] = $this->values[$field];
            }
        }

        return $this->policyDataFromValues($values);
    }

    /**
     * @param array<string,string|null> $values
     */
    private function policyDataFromValues(array $values): ExtractedPolicyData
    {
        $payload = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            $payload[$field] = $values[$field] ?? null;
        }

        return ExtractedPolicyData::fromFields(
            $payload,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    private static function nullableValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
