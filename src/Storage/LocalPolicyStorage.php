<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Storage;

use Ingreen\DaktelaPolicy\Attachment\AttachmentMetadata;
use Ingreen\DaktelaPolicy\Attachment\DownloadedFile;
use Ingreen\DaktelaPolicy\Support\AppException;

final class LocalPolicyStorage
{
    public function __construct(private readonly string $directory)
    {
    }

    public function targetPath(string $entityType, string $entityId, AttachmentMetadata $attachment): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $this->filename($entityType, $entityId, $attachment);
    }

    public function exists(string $entityType, string $entityId, AttachmentMetadata $attachment): ?StoredPolicy
    {
        $path = $this->targetPath($entityType, $entityId, $attachment);

        if (is_file($path)) {
            return new StoredPolicy('already_exists', $path, false);
        }

        return null;
    }

    public function store(string $entityType, string $entityId, AttachmentMetadata $attachment, DownloadedFile $file): StoredPolicy
    {
        $this->ensureDirectory();
        $path = $this->targetPath($entityType, $entityId, $attachment);

        if (is_file($path)) {
            return new StoredPolicy('already_exists', $path, false);
        }

        $tmpPath = $path . '.tmp.' . bin2hex(random_bytes(6));

        if (file_put_contents($tmpPath, $file->bytes, LOCK_EX) === false) {
            throw new AppException(500, 'storage_write_failed', 'Failed to write downloaded policy file.', [
                'path' => $path,
            ]);
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new AppException(500, 'storage_write_failed', 'Failed to move downloaded policy file into place.', [
                'path' => $path,
            ]);
        }

        return new StoredPolicy('downloaded', $path, true);
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new AppException(500, 'storage_directory_failed', 'Failed to create policy storage directory.', [
                'directory' => $this->directory,
            ]);
        }
    }

    private function filename(string $entityType, string $entityId, AttachmentMetadata $attachment): string
    {
        $base = $attachment->title !== null ? $attachment->title : substr(hash('sha256', $attachment->stableKey()), 0, 16);
        $base = preg_replace('/\.pdf$/i', '', $base) ?? $base;

        return $this->sanitize($entityType)
            . '_'
            . $this->sanitize($entityId)
            . '_'
            . $this->sanitize($base)
            . '.pdf';
    }

    private function sanitize(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value)) ?? '';
        $sanitized = trim($sanitized, '._-');

        return $sanitized === '' ? 'unknown' : substr($sanitized, 0, 120);
    }
}
