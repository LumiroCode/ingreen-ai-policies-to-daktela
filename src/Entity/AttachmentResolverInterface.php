<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Entity;

use Ingreen\DaktelaPolicy\Attachment\AttachmentMetadata;

interface AttachmentResolverInterface
{
    /**
     * @return list<AttachmentMetadata>
     */
    public function resolve(string $entityId): array;
}
