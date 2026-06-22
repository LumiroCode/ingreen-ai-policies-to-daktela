<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Support\AppException;

final class PolicyStore
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     * @return array{status:string,path:string,downloaded:bool}
     */
    public function existing(string $entityType, string $entityId, array $attachment): ?array
    {
        $path = $this->path($entityType, $entityId, $attachment);

        return is_file($path) ? ['status' => 'already_exists', 'path' => $path, 'downloaded' => false] : null;
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     * @return array{status:string,path:string,downloaded:bool}
     */
    public function save(string $entityType, string $entityId, array $attachment, string $bytes): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new AppException(500, 'storage_directory_failed', 'Failed to create policy storage directory.', [
                'directory' => $this->directory,
            ]);
        }

        $path = $this->path($entityType, $entityId, $attachment);

        if (is_file($path)) {
            return ['status' => 'already_exists', 'path' => $path, 'downloaded' => false];
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));

        if (file_put_contents($tmp, $bytes, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new AppException(500, 'storage_write_failed', 'Failed to write downloaded policy file.', [
                'path' => $path,
            ]);
        }

        return ['status' => 'downloaded', 'path' => $path, 'downloaded' => true];
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function path(string $entityType, string $entityId, array $attachment): string
    {
        $base = $attachment['title'] ?? null;
        $base = is_string($base) && $base !== ''
            ? preg_replace('/\.pdf$/i', '', $base)
            : substr(hash('sha256', $attachment['file'] . '|' . ($attachment['type'] ?? '')), 0, 16);

        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $this->clean($entityType)
            . '_'
            . $this->clean($entityId)
            . '_'
            . $this->clean((string) $base)
            . '.pdf';
    }

    private function clean(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value)) ?? '';
        $clean = trim($clean, '._-');

        return $clean === '' ? 'unknown' : substr($clean, 0, 120);
    }
}
