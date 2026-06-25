<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Dto;

final class TicketRecord
{
    /**
     * @param array<string, mixed>|list<mixed>|null $customFields
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly int|string $name,
        public readonly ?string $title,
        public readonly ?string $category,
        public readonly ?string $stage,
        public readonly ?string $priority,
        public readonly array|null $customFields,
        public readonly array $raw
    ) {
        if ((string) $name === '') {
            throw new \InvalidArgumentException('name must not be blank.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromDaktelaPayload(array $payload): self
    {
        $category = is_array($payload['category'] ?? null)
            ? (string) ($payload['category']['name'] ?? '')
            : ($payload['category'] ?? null);

        return new self(
            $payload['name'] ?? '',
            isset($payload['title']) ? (string) $payload['title'] : null,
            is_string($category) && $category !== '' ? $category : null,
            isset($payload['stage']) ? (string) $payload['stage'] : null,
            isset($payload['priority']) ? (string) $payload['priority'] : null,
            is_array($payload['customFields'] ?? null) ? $payload['customFields'] : null,
            $payload
        );
    }
}
