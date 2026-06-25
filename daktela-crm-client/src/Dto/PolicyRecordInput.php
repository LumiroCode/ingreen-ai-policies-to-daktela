<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Dto;

final class PolicyRecordInput
{
    /**
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        public readonly ?string $name,
        public readonly string $title,
        public readonly string $carNumber,
        public readonly ?string $policyNumber = null,
        public readonly ?string $description = null,
        public readonly array $customFields = []
    ) {
        $this->assertNotBlank($title, 'title');
        $this->assertNotBlank($carNumber, 'carNumber');
    }

    private function assertNotBlank(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($name . ' must not be blank.');
        }
    }
}
