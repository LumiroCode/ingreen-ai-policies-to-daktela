<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Storage;

final class StoredPolicy
{
    public function __construct(
        public readonly string $status,
        public readonly string $path,
        public readonly bool $downloaded
    ) {
    }
}
