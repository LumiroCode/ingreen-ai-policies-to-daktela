<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Attachment;

final class AttachmentMetadata
{
    public function __construct(
        public readonly string $file,
        public readonly ?string $title = null,
        public readonly ?string $type = null,
        public readonly ?int $size = null,
        public readonly ?string $source = null
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, ?string $source = null): ?self
    {
        $file = $payload['file'] ?? $payload['url'] ?? $payload['href'] ?? null;

        if (!is_string($file) || trim($file) === '') {
            return null;
        }

        $title = $payload['title'] ?? $payload['filename'] ?? $payload['name'] ?? null;
        $type = $payload['type'] ?? $payload['mime'] ?? $payload['contentType'] ?? null;
        $size = $payload['size'] ?? null;

        return new self(
            trim($file),
            is_string($title) && $title !== '' ? $title : null,
            is_string($type) && $type !== '' ? $type : null,
            is_int($size) ? $size : null,
            $source
        );
    }

    public function stableKey(): string
    {
        return $this->file . '|' . ($this->title ?? '') . '|' . ($this->type ?? '');
    }
}
