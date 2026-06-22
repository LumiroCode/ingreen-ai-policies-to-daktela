<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Attachment;

final class DownloadedFile
{
    public function __construct(
        public readonly string $bytes,
        public readonly ?string $contentType = null
    ) {
    }
}
