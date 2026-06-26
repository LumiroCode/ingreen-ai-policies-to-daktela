<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

final class DaktelaTicketPolicyDataMapper
{
    /**
     * @return array{customFields:array<string,string>}
     */
    public function toTicketPayload(ExtractedPolicyData $data): array
    {
        $customFields = [];

        foreach (ExtractedPolicyData::FIELDS as $field) {
            $value = $data->field($field);

            if ($value === null || trim($value) === '') {
                continue;
            }

            $customFields[$field] = trim($value);
        }

        return ['customFields' => $customFields];
    }
}
