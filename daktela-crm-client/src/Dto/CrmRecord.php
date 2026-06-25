<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Dto;

final class CrmRecord
{
    /**
     * @param array<string, mixed>|list<mixed>|null $customFields
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly ?string $type,
        public readonly ?string $stage,
        public readonly array|null $customFields,
        public readonly array $raw
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('name must not be blank.');
        }

        if (trim($title) === '') {
            throw new \InvalidArgumentException('title must not be blank.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromDaktelaPayload(array $payload): self
    {
        $type = is_array($payload['type'] ?? null)
            ? (string) ($payload['type']['name'] ?? '')
            : ($payload['type'] ?? null);

        return new self(
            (string) ($payload['name'] ?? ''),
            (string) ($payload['title'] ?? ''),
            is_string($type) && $type !== '' ? $type : null,
            isset($payload['stage']) ? (string) $payload['stage'] : null,
            is_array($payload['customFields'] ?? null) ? $payload['customFields'] : null,
            $payload
        );
    }
}
