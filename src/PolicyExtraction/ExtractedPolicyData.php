<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

final class ExtractedPolicyData
{
    public function __construct(
        public readonly ?string $carMake,
        public readonly ?string $carModel,
        public readonly ?string $value,
        public readonly string $rawResponse
    ) {
    }
}
