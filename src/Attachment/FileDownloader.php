<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Attachment;

use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Support\AppException;

final class FileDownloader
{
    public function __construct(
        private readonly DaktelaClient $daktelaClient,
        private readonly int $maxDownloadBytes
    ) {
    }

    public function download(AttachmentMetadata $attachment): DownloadedFile
    {
        $response = $this->daktelaClient->download($attachment->file, $this->maxDownloadBytes);
        $contentType = $response->header('Content-Type');

        if (!$this->looksLikePdf($response->body, $contentType, $attachment)) {
            throw new AppException(422, 'attachment_is_not_pdf', 'Downloaded attachment does not look like a PDF.', [
                'file' => $attachment->file,
                'contentType' => $contentType,
                'attachmentType' => $attachment->type,
            ]);
        }

        return new DownloadedFile($response->body, $contentType);
    }

    private function looksLikePdf(string $body, ?string $contentType, AttachmentMetadata $attachment): bool
    {
        $hasPdfHeader = str_starts_with(ltrim(substr($body, 0, 1024)), '%PDF');
        $hasPdfType = $contentType !== null && str_contains(strtolower($contentType), 'pdf');
        $hasPdfMetadataType = $attachment->type !== null && str_contains(strtolower($attachment->type), 'pdf');
        $hasPdfExtension = preg_match('/\.pdf(?:$|[?#])/i', $attachment->file) === 1
            || ($attachment->title !== null && preg_match('/\.pdf$/i', $attachment->title) === 1);

        return $hasPdfHeader || ($hasPdfType && $hasPdfExtension) || ($hasPdfMetadataType && $hasPdfExtension);
    }
}
