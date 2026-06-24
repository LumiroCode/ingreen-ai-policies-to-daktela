<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

final class PolicyConfirmationForm
{
    private const FIELDS = ['car_make', 'car_model', 'value'];

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

        foreach (self::FIELDS as $field) {
            $values[$field] = self::nullableValue($policyData[$field] ?? null);
        }

        $lockedFields = [];

        foreach (self::FIELDS as $field) {
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
        return array_fill_keys(self::FIELDS, true);
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
        $allLocked = count($this->lockedFields) === count(self::FIELDS);

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

        $values = [
            'car_make' => $extractedData->carMake,
            'car_model' => $extractedData->carModel,
            'value' => $extractedData->value,
        ];

        foreach (self::FIELDS as $field) {
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
        $payload = [
            'car_make' => $values['car_make'] ?? null,
            'car_model' => $values['car_model'] ?? null,
            'value' => $values['value'] ?? null,
        ];

        return new ExtractedPolicyData(
            $payload['car_make'],
            $payload['car_model'],
            $payload['value'],
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
