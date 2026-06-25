<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Dto;

final class TicketUpdateInput
{
    /**
     * @param array<string, mixed> $customFields
     * @param array<string, mixed> $extraData
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $category = null,
        public readonly ?string $stage = null,
        public readonly ?string $priority = null,
        public readonly ?string $description = null,
        public readonly array $customFields = [],
        public readonly array $extraData = []
    ) {
    }
}
