<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Config;

final class DaktelaClientConfig
{
    /**
     * @param array<string, string> $ticketCustomFieldCodes
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $apiToken,
        public readonly string $carRecordTypeName,
        public readonly string $policyRecordTypeName,
        public readonly string $carVinFieldCode,
        public readonly string $carNumberFieldCode,
        public readonly string $policyCarNumberFieldCode,
        public readonly array $ticketCustomFieldCodes = []
    ) {
        $this->assertNotBlank($baseUrl, 'baseUrl');
        $this->assertNotBlank($apiToken, 'apiToken');
        $this->assertNotBlank($carRecordTypeName, 'carRecordTypeName');
        $this->assertNotBlank($policyRecordTypeName, 'policyRecordTypeName');
        $this->assertNotBlank($carVinFieldCode, 'carVinFieldCode');
        $this->assertNotBlank($carNumberFieldCode, 'carNumberFieldCode');
        $this->assertNotBlank($policyCarNumberFieldCode, 'policyCarNumberFieldCode');
    }

    private function assertNotBlank(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($name . ' must not be blank.');
        }
    }
}
