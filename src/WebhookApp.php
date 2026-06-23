<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;
use Throwable;

final class WebhookApp
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly DaktelaClient $daktela,
        private readonly TicketPdfAttachments $ticketPdfAttachments,
        private readonly AppLogger $logger
    ) {
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function handle(?string $ticketId, ?string $attachmentIndex): array
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            $ticketId = $this->requiredTicketId($ticketId);

            if ($attachmentIndex !== null && trim($attachmentIndex) !== '') {
                return $this->downloadSelectedTicketPdf($ticketId, $attachmentIndex, $requestId);
            }

            return $this->ticketPdfListResponse($ticketId);
        } catch (AppException $exception) {
            $this->logger->warning('Ticket request failed.', [
                'requestId' => $requestId,
                'errorCode' => $exception->errorCode(),
                'details' => $exception->details(),
            ]);

            return [
                'status' => $exception->statusCode(),
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'requestId' => $requestId,
                    'error' => [
                        'code' => $exception->errorCode(),
                        'message' => $exception->getMessage(),
                        'details' => $exception->details(),
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        } catch (Throwable $exception) {
            $this->logger->exception($exception, ['requestId' => $requestId]);

            return [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode([
                    'requestId' => $requestId,
                    'error' => ['code' => 'internal_error', 'message' => 'Internal server error.'],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        }
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function ticketPdfListResponse(string $ticketId): array
    {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
            'body' => $this->renderPdfAttachmentsTable($ticketId, $this->ticketPdfAttachments->forTicket($ticketId)),
        ];
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function downloadSelectedTicketPdf(string $ticketId, string $attachmentIndex, string $requestId): array
    {
        $attachments = $this->ticketPdfAttachments->forTicket($ticketId);
        $attachment = $this->ticketPdfAttachments->byIndex($attachments, $attachmentIndex);
        $download = $this->daktela->download($attachment['file'], $this->config->maxDownloadBytes);

        if (!$this->looksLikePdf($download['body'], $download['contentType'], $attachment)) {
            throw new AppException(422, 'attachment_is_not_pdf', 'Downloaded attachment does not look like a PDF.', [
                'file' => $attachment['file'],
                'contentType' => $download['contentType'],
                'attachmentType' => $attachment['type'] ?? null,
            ]);
        }

        $this->logger->info('Policy attachment downloaded for reading.', [
            'requestId' => $requestId,
            'entityType' => 'ticket',
            'entityId' => $ticketId,
            'attachmentFile' => $attachment['file'],
        ]);

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $this->downloadFilename($attachment) . '"',
            ],
            'body' => $download['body'],
        ];
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}> $attachments
     */
    private function renderPdfAttachmentsTable(string $ticketId, array $attachments): string
    {
        ob_start();
        require dirname(__DIR__) . '/templates/pdf-attachments-table.php';
        return (string) ob_get_clean();
    }

    private function requiredTicketId(?string $ticketId): string
    {
        if ($ticketId === null || trim($ticketId) === '') {
            throw new AppException(400, 'invalid_request', 'Required ticket query parameter is missing.', ['field' => 'ticket']);
        }

        return trim($ticketId);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function looksLikePdf(string $body, ?string $contentType, array $attachment): bool
    {
        return str_starts_with(ltrim(substr($body, 0, 1024)), '%PDF')
            || ($contentType !== null && str_contains(strtolower($contentType), 'pdf') && $this->hasPdfExtension($attachment))
            || ($this->hasPdfType($attachment) && $this->hasPdfExtension($attachment));
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function hasPdfType(array $attachment): bool
    {
        return isset($attachment['type']) && is_string($attachment['type']) && str_contains(strtolower($attachment['type']), 'pdf');
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function hasPdfExtension(array $attachment): bool
    {
        return preg_match('/\.pdf(?:$|[?#])/i', $attachment['file']) === 1
            || (isset($attachment['title']) && preg_match('/\.pdf$/i', (string) $attachment['title']) === 1);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function downloadFilename(array $attachment): string
    {
        $filename = $attachment['title'] ?? basename(parse_url($attachment['file'], PHP_URL_PATH) ?: 'attachment.pdf');
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'attachment.pdf';

        return str_ends_with(strtolower($filename), '.pdf') ? $filename : $filename . '.pdf';
    }
}
