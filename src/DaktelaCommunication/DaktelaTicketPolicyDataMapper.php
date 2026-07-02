<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

final class DaktelaTicketPolicyDataMapper
{
    private readonly DaktelaNumericValueNormalizer $valueNormalizer;

    public function __construct(?DaktelaNumericValueNormalizer $valueNormalizer = null)
    {
        $this->valueNormalizer = $valueNormalizer ?? new DaktelaNumericValueNormalizer();
    }

    /**
     * @return array{customFields:array<string,string>}
     */
    public function toTicketPayload(ExtractedPolicyData $data): array
    {
        $customFields = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            $value = $this->valueNormalizer->normalizeForField($field, $data->field($field));

            if ($value === null) {
                continue;
            }

            $customFields[$field] = $value;
        }

        return ['customFields' => $customFields];
    }
}
