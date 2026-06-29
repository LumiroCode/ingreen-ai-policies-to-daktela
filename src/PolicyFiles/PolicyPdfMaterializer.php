<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyFiles;

use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\TicketPdfAttachments;

final class PolicyPdfMaterializer
{
    public function __construct(
        private readonly TicketPdfAttachments $ticketPdfAttachments,
        private readonly string $varDir,
        private readonly int $maxDownloadBytes
    ) {
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    public function cachedOrDownload(array $attachment, string $attachmentIndex): PolicyPdf
    {
        $path = $this->path($attachment, $attachmentIndex);

        return is_file($path)
            ? PolicyPdf::fromFile($path, $this->title($attachment))
            : $this->downloadFresh($attachment, $attachmentIndex);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    public function downloadFresh(array $attachment, string $attachmentIndex): PolicyPdf
    {
        $download = $this->ticketPdfAttachments->download($attachment, $this->maxDownloadBytes);

        if (!$this->looksLikePdf($download['body'], $download['contentType'], $attachment)) {
            throw new AppException(422, 'attachment_is_not_pdf', 'Downloaded attachment does not look like a PDF.', [
                'file' => $attachment['file'],
                'contentType' => $download['contentType'],
                'attachmentType' => $attachment['type'] ?? null,
            ]);
        }

        $path = $this->store($attachment, $attachmentIndex, $download['body']);

        return PolicyPdf::fromFile($path, $this->title($attachment));
    }

    public function contents(PolicyPdf $pdf): string
    {
        $contents = file_get_contents($pdf->path);

        if ($contents === false) {
            throw new AppException(500, 'policy_pdf_not_readable', 'Policy PDF file is not readable.', [
                'path' => $pdf->path,
            ]);
        }

        return $contents;
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    private function store(array $attachment, string $attachmentIndex, string $body): string
    {
        $directory = rtrim($this->varDir, '/\\') . '/policies';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AppException(500, 'policy_temp_dir_failed', 'Could not create temporary policy directory.', [
                'directory' => $directory,
            ]);
        }

        $path = $this->path($attachment, $attachmentIndex);

        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new AppException(500, 'policy_temp_write_failed', 'Could not write temporary policy file.', [
                'path' => $path,
            ]);
        }

        return $path;
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    private function path(array $attachment, string $attachmentIndex): string
    {
        return rtrim($this->varDir, '/\\') . '/policies/' . $this->temporaryFilename($attachment, $attachmentIndex);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    private function temporaryFilename(array $attachment, string $attachmentIndex): string
    {
        $id = $attachment['id'] ?? $attachment['name'] ?? null;

        if ($id === null && ctype_digit($attachment['file'])) {
            $id = $attachment['file'];
        }

        $id = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($id ?? 'attachment-' . $attachmentIndex));
        $id = trim((string) $id, '._-');
        $filename = $id !== '' ? $id : 'attachment-' . $attachmentIndex;

        return str_ends_with(strtolower($filename), '.pdf') ? $filename : $filename . '.pdf';
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    private function title(array $attachment): string
    {
        if (isset($attachment['title']) && is_string($attachment['title']) && trim($attachment['title']) !== '') {
            return $attachment['title'];
        }

        return basename(parse_url($attachment['file'], PHP_URL_PATH) ?: 'policy.pdf');
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    private function looksLikePdf(string $body, ?string $contentType, array $attachment): bool
    {
        return str_starts_with(ltrim(substr($body, 0, 1024)), '%PDF')
            || ($contentType !== null && str_contains(strtolower($contentType), 'pdf') && $this->hasPdfExtension($attachment))
            || ($this->hasPdfType($attachment) && $this->hasPdfExtension($attachment));
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    private function hasPdfType(array $attachment): bool
    {
        return isset($attachment['type']) && is_string($attachment['type']) && str_contains(strtolower($attachment['type']), 'pdf');
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     */
    private function hasPdfExtension(array $attachment): bool
    {
        return preg_match('/\.pdf(?:$|[?#])/i', $attachment['file']) === 1
            || (isset($attachment['title']) && preg_match('/\.pdf$/i', (string) $attachment['title']) === 1);
    }
}
