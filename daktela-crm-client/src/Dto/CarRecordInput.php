<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Dto;

final class CarRecordInput
{
    /**
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        public readonly ?string $name,
        public readonly string $title,
        public readonly ?string $vin,
        public readonly ?string $carNumber,
        public readonly ?string $description = null,
        public readonly array $customFields = []
    ) {
        $this->assertNotBlank($title, 'title');

        if ($this->blank($vin) && $this->blank($carNumber)) {
            throw new \InvalidArgumentException('Either VIN or car number must be provided.');
        }
    }

    private function assertNotBlank(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($name . ' must not be blank.');
        }
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
