<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\Support\AppException;
use Throwable;

final class WebhookApp
{
    private readonly WebhookAccessGuard $accessGuard;

    public function __construct(
        private readonly AppConfig $config,
        private readonly DaktelaClient $daktela,
        private readonly TicketPdfAttachments $ticketPdfAttachments,
        private readonly PolicyDataExtractor $policyDataExtractor,
        private readonly AppLogger $logger
    ) {
        $this->accessGuard = new WebhookAccessGuard($config, $logger);
    }

    /**
     * @param array<string,string> $requestHeaders
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function handle(
        ?string $ticketId,
        ?string $attachmentIndex,
        ?string $accessToken = null,
        ?string $referrer = null,
        array $requestHeaders = [],
        ?string $daktelaTabDt = null,
        ?string $daktelaTabSig = null
    ): array
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            $ticketId = $this->requiredTicketId($ticketId);
            $this->accessGuard->assertAccessAllowed(
                $ticketId,
                $accessToken,
                $referrer,
                $requestHeaders,
                $daktelaTabDt,
                $daktelaTabSig
            );

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
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'application/json']),
                'body' => json_encode([
                    'requestId' => $requestId,
                    'error' => [
                        'code' => $exception->errorCode(),
                        'message' => $exception->getMessage(),
                        'dt' => $daktelaTabDt,
                        'sig' => $daktelaTabSig,
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
        } catch (Throwable $exception) {
            $this->logger->exception($exception, ['requestId' => $requestId]);

            return [
                'status' => 500,
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'application/json']),
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
            'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
            'body' => $this->renderPage($ticketId, $this->ticketPdfAttachments->forTicket($ticketId)),
        ];
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function downloadSelectedTicketPdf(string $ticketId, string $attachmentIndex, string $requestId): array
    {
        $attachments = $this->ticketPdfAttachments->forTicket($ticketId);

        try {
            $attachment = $this->ticketPdfAttachments->byIndex($attachments, $attachmentIndex);
            $download = $this->daktela->download($attachment['file'], $this->config->maxDownloadBytes);

            if (!$this->looksLikePdf($download['body'], $download['contentType'], $attachment)) {
                throw new AppException(422, 'attachment_is_not_pdf', 'Downloaded attachment does not look like a PDF.', [
                    'file' => $attachment['file'],
                    'contentType' => $download['contentType'],
                    'attachmentType' => $attachment['type'] ?? null,
                ]);
            }

            $path = $this->storeTemporaryPolicyFile($attachment, $attachmentIndex, $download['body']);

            $this->logger->info('Policy attachment stored for Claude extraction.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'attachmentFile' => $attachment['file'],
                'storedPath' => $path,
            ]);

            $extractedData = $this->policyDataExtractor->extract($path);

            $this->logger->info('Policy attachment processed with Claude extraction.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'attachmentFile' => $attachment['file'],
                'storedPath' => $path,
            ]);

            return [
                'status' => 200,
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPage($ticketId, $attachments, [
                    'type' => 'success',
                    'text' => $this->extractionResultJson($extractedData),
                ]),
            ];
        } catch (AppException $exception) {
            $this->logger->warning('Policy attachment could not be stored or processed.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'errorCode' => $exception->errorCode(),
                'details' => $exception->details(),
            ]);

            return [
                'status' => $exception->statusCode(),
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPage($ticketId, $attachments, [
                    'type' => 'error',
                    'text' => $this->policyProcessingErrorMessage($exception),
                ]),
            ];
        } catch (Throwable $exception) {
            $this->logger->exception($exception, [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
            ]);

            return [
                'status' => 500,
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPage($ticketId, $attachments, [
                    'type' => 'error',
                    'text' => $this->policyProcessingErrorMessage($exception),
                ]),
            ];
        }
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function storeTemporaryPolicyFile(array $attachment, string $attachmentIndex, string $body): string
    {
        $directory = rtrim($this->config->varDir, '/\\') . '/tmp/policies';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AppException(500, 'policy_temp_dir_failed', 'Could not create temporary policy directory.', [
                'directory' => $directory,
            ]);
        }

        $path = $directory . '/' . $this->temporaryPolicyFilename($attachment, $attachmentIndex);

        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new AppException(500, 'policy_temp_write_failed', 'Could not write temporary policy file.', [
                'path' => $path,
            ]);
        }

        return $path;
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function temporaryPolicyFilename(array $attachment, string $attachmentIndex): string
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

    private function extractionResultJson(ExtractedPolicyData $data): string
    {
        return json_encode([
            'car_make' => $data->carMake,
            'car_model' => $data->carModel,
            'value' => $data->value,
            'raw_response' => $data->rawResponse,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function policyProcessingErrorMessage(Throwable $exception): string
    {
        if (!$exception instanceof AppException) {
            return 'Wystąpił nieoczekiwany błąd podczas odczytu danych z polisy.';
        }

        return match ($exception->errorCode()) {
            'attachment_download_failed', 'upstream_http_error', 'daktela_auth_failed' => 'Nie udało się pobrać pliku polisy z Dakteli.',
            'attachment_too_large' => 'Plik polisy jest większy niż dozwolony limit.',
            'attachment_is_not_pdf' => 'Wybrany załącznik nie jest poprawnym plikiem PDF.',
            'policy_temp_dir_failed', 'policy_temp_write_failed', 'policy_pdf_not_readable' => 'Nie udało się zapisać pliku polisy do odczytu.',
            'claude_policy_extraction_failed' => $this->claudePolicyExtractionErrorMessage($exception),
            'policy_extraction_parse_failed' => 'Claude zwrócił odpowiedź w nieoczekiwanym formacie.',
            default => 'Nie udało się przetworzyć pliku polisy.',
        };
    }

    private function claudePolicyExtractionErrorMessage(AppException $exception): string
    {
        $anthropicMessage = $this->anthropicErrorMessage($exception);

        if ($anthropicMessage !== null) {
            return $anthropicMessage;
        }

        return 'Nie udało się odczytać danych z polisy przez Claude.';
    }

    private function anthropicErrorMessage(AppException $exception): ?string
    {
        $message = $exception->details()['message'] ?? null;

        if (!is_string($message) || trim($message) === '') {
            return null;
        }

        $jsonStart = strpos($message, '{');
        $json = $jsonStart === false ? $message : substr($message, $jsonStart);
        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return null;
        }

        $anthropicMessage = $payload['body']['error']['message'] ?? null;

        if (!is_string($anthropicMessage) || trim($anthropicMessage) === '') {
            return null;
        }

        return trim($anthropicMessage);
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
     */
    private function renderPage(string $ticketId, array $attachments, ?array $message = null): string
    {
        $accessToken = $this->accessGuard->accessTokenForTicket($ticketId);
        ob_start();
        require dirname(__DIR__) . '/templates/page.php';
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
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function looksLikePdf(string $body, ?string $contentType, array $attachment): bool
    {
        return str_starts_with(ltrim(substr($body, 0, 1024)), '%PDF')
            || ($contentType !== null && str_contains(strtolower($contentType), 'pdf') && $this->hasPdfExtension($attachment))
            || ($this->hasPdfType($attachment) && $this->hasPdfExtension($attachment));
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function hasPdfType(array $attachment): bool
    {
        return isset($attachment['type']) && is_string($attachment['type']) && str_contains(strtolower($attachment['type']), 'pdf');
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function hasPdfExtension(array $attachment): bool
    {
        return preg_match('/\.pdf(?:$|[?#])/i', $attachment['file']) === 1
            || (isset($attachment['title']) && preg_match('/\.pdf$/i', (string) $attachment['title']) === 1);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function downloadFilename(array $attachment): string
    {
        $filename = $attachment['title'] ?? basename(parse_url($attachment['file'], PHP_URL_PATH) ?: 'attachment.pdf');
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'attachment.pdf';

        return str_ends_with(strtolower($filename), '.pdf') ? $filename : $filename . '.pdf';
    }
}
