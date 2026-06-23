<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

use Ingreen\DaktelaPolicy\Support\AppException;

final class PolicyDataResponseParser
{
    public function parse(string $response): ExtractedPolicyData
    {
        $payload = json_decode($this->jsonObject($response), true);

        if (!is_array($payload)) {
            throw new AppException(502, 'policy_extraction_parse_failed', 'Claude did not return valid policy extraction JSON.');
        }

        return new ExtractedPolicyData(
            $this->nullableString($payload['car_make'] ?? null),
            $this->nullableString($payload['car_model'] ?? null),
            $this->nullableString($payload['value'] ?? null),
            $response
        );
    }

    private function jsonObject(string $response): string
    {
        $response = trim($response);

        if (str_starts_with($response, '{') && str_ends_with($response, '}')) {
            return $response;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches) === 1) {
            return trim($matches[1]);
        }

        $start = strpos($response, '{');
        $end = strrpos($response, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($response, $start, $end - $start + 1);
        }

        return $response;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
