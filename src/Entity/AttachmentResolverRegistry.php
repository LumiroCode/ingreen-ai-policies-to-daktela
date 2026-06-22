<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Entity;

use Ingreen\DaktelaPolicy\Support\AppException;

final class AttachmentResolverRegistry
{
    /**
     * @param array<string, AttachmentResolverInterface> $resolvers
     */
    public function __construct(private readonly array $resolvers)
    {
    }

    public function get(string $entityType): AttachmentResolverInterface
    {
        $key = strtolower(trim($entityType));

        if (!isset($this->resolvers[$key])) {
            throw new AppException(400, 'unsupported_entity_type', 'Unsupported entity type.', [
                'entityType' => $entityType,
                'supportedEntityTypes' => array_keys($this->resolvers),
            ]);
        }

        return $this->resolvers[$key];
    }
}
