<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyFiles;

use Ingreen\DaktelaPolicy\Support\AppException;

final class PolicyPdf
{
    private function __construct(
        public readonly string $path,
        public readonly string $title,
        public readonly string $mimeType,
        public readonly int $size
    ) {
    }

    public static function fromFile(string $path, string $title, string $mimeType = 'application/pdf'): self
    {
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;

        if ($size === false || $size <= 0) {
            throw new AppException(500, 'policy_pdf_not_readable', 'Policy PDF file is not readable.', [
                'path' => $path,
            ]);
        }

        return new self($path, self::normalizeTitle($title), self::normalizeMimeType($mimeType), $size);
    }

    public function downloadFilename(): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->title) ?: 'attachment.pdf';
    }

    private static function normalizeTitle(string $title): string
    {
        $title = basename(str_replace('\\', '/', trim($title)));
        $title = preg_replace('/[\x00-\x1F\x7F]+/u', '_', $title) ?? '';
        $title = trim($title);

        if ($title === '') {
            return 'policy.pdf';
        }

        return str_ends_with(strtolower($title), '.pdf') ? $title : $title . '.pdf';
    }

    private static function normalizeMimeType(string $mimeType): string
    {
        $mimeType = trim($mimeType);

        return $mimeType !== '' ? $mimeType : 'application/pdf';
    }
}
