<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataCache;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\Support\AppException;
use Throwable;

final class WebhookApp
{
    private readonly WebhookAccessGuard $accessGuard;
    private readonly PolicyDataCache $policyDataCache;

    public function __construct(
        private readonly AppConfig $config,
        private readonly DaktelaClient $daktela,
        private readonly TicketPdfAttachments $ticketPdfAttachments,
        private readonly PolicyDataExtractor $policyDataExtractor,
        private readonly AppLogger $logger
    ) {
        $this->accessGuard = new WebhookAccessGuard($config, $logger);
        $this->policyDataCache = new PolicyDataCache($config->varDir);
    }

    /**
     * @param array<string,string> $requestHeaders
     * @param array<string,string>|null $policyData
     * @param array<string,string>|null $policyLocked
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function handle(
        ?string $ticketId,
        ?string $attachmentIndex,
        ?string $accessToken = null,
        ?string $referrer = null,
        array $requestHeaders = [],
        ?string $daktelaTabDt = null,
        ?string $daktelaTabSig = null,
        ?string $confirmation = null,
        ?array $policyData = null,
        ?array $policyLocked = null,
        ?string $ticketTitle = null,
        bool $forceAttachmentRefresh = false,
        bool $servePolicyPdf = false
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

            if ($servePolicyPdf) {
                return $this->selectedPolicyPdfResponse($ticketId, $attachmentIndex, $requestId);
            }

            if ($attachmentIndex !== null && trim($attachmentIndex) !== '') {
                $confirmation = $confirmation !== null ? trim($confirmation) : null;
                $confirmationForm = PolicyConfirmationForm::fromRequest($policyData, $policyLocked);

                if ($confirmation === 'yes') {
                    $validationMessage = $confirmationForm->validationMessage($confirmation);

                    if ($validationMessage !== null) {
                        return $this->invalidPolicyConfirmationResponse($ticketId, $attachmentIndex, $confirmationForm, $validationMessage, $ticketTitle);
                    }

                    return $this->confirmExtractedPolicyData($ticketId, $attachmentIndex, $confirmationForm, $ticketTitle);
                }

                if ($confirmation === 'no') {
                    $validationMessage = $confirmationForm->validationMessage($confirmation);

                    if ($validationMessage !== null) {
                        return $this->invalidPolicyConfirmationResponse($ticketId, $attachmentIndex, $confirmationForm, $validationMessage, $ticketTitle);
                    }
                }

                return $this->downloadSelectedTicketPdf(
                    $ticketId,
                    $attachmentIndex,
                    $requestId,
                    $confirmation === 'no',
                    $confirmationForm,
                    $ticketTitle
                );
            }

            return $this->ticketPdfListResponse($ticketId, $ticketTitle, $forceAttachmentRefresh);
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
    private function selectedPolicyPdfResponse(string $ticketId, ?string $attachmentIndex, string $requestId): array
    {
        if ($attachmentIndex === null || trim($attachmentIndex) === '') {
            throw new AppException(400, 'invalid_request', 'Required attachment query parameter is missing.', ['field' => 'attachment']);
        }

        $attachments = $this->ticketPdfAttachments->forTicket($ticketId);
        $attachment = $this->ticketPdfAttachments->byIndex($attachments, $attachmentIndex);
        $path = $this->policyFilePath($attachment, $attachmentIndex);

        if (!is_file($path)) {
            $download = $this->daktela->download($attachment['file'], $this->config->maxDownloadBytes);

            if (!$this->looksLikePdf($download['body'], $download['contentType'], $attachment)) {
                throw new AppException(422, 'attachment_is_not_pdf', 'Downloaded attachment does not look like a PDF.', [
                    'file' => $attachment['file'],
                    'contentType' => $download['contentType'],
                    'attachmentType' => $attachment['type'] ?? null,
                ]);
            }

            $path = $this->storePolicyFile($attachment, $attachmentIndex, $download['body']);

            $this->logger->info('Policy attachment stored for PDF preview.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'attachmentFile' => $attachment['file'],
                'storedPath' => $path,
            ]);
        }

        $body = file_get_contents($path);

        if ($body === false) {
            throw new AppException(500, 'policy_pdf_not_readable', 'Policy PDF file is not readable.', ['path' => $path]);
        }

        return [
            'status' => 200,
            'headers' => $this->accessGuard->securityHeaders([
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . addcslashes($this->downloadFilename($attachment), "\\\"") . '"',
            ]),
            'body' => $body,
        ];
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function ticketPdfListResponse(string $ticketId, ?string $ticketTitle, bool $forceAttachmentRefresh): array
    {
        $attachments = $this->ticketPdfAttachments->forTicket($ticketId, $forceAttachmentRefresh);

        return [
            'status' => 200,
            'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
            'body' => $this->renderPage($ticketId, $attachments, ticketTitle: $ticketTitle),
        ];
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function downloadSelectedTicketPdf(
        string $ticketId,
        string $attachmentIndex,
        string $requestId,
        bool $forceExtraction = false,
        ?PolicyConfirmationForm $confirmationForm = null,
        ?string $ticketTitle = null
    ): array
    {
        $attachments = $this->ticketPdfAttachments->forTicket($ticketId);

        try {
            $attachment = $this->ticketPdfAttachments->byIndex($attachments, $attachmentIndex);

            if (!$forceExtraction) {
                $storedData = $this->policyDataCache->confirmed($ticketId, $attachment);

                if ($storedData !== null) {
                    return [
                        'status' => 200,
                        'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                        'body' => $this->renderPage(
                            $ticketId,
                            $attachments,
                            [
                                'type' => 'success',
                                'text' => 'Polisa została już kiedyś odczytana - wczytano zapisane dane.',
                            ],
                            $storedData,
                            $attachmentIndex,
                            PolicyConfirmationForm::allLockedFields(),
                            $ticketTitle
                        ),
                    ];
                }

                $pendingData = $this->policyDataCache->pending($ticketId, $attachment);

                if ($pendingData !== null) {
                    return [
                        'status' => 200,
                        'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                        'body' => $this->renderPage(
                            $ticketId,
                            $attachments,
                            [
                                'type' => 'success',
                                'text' => 'Wczytano dane z poprzedniego odczytu polisy. Sprawdź wartości przed zapisaniem do systemu.',
                            ],
                            $pendingData,
                            $attachmentIndex,
                            ticketTitle: $ticketTitle
                        ),
                    ];
                }
            }

            $download = $this->daktela->download($attachment['file'], $this->config->maxDownloadBytes);

            if (!$this->looksLikePdf($download['body'], $download['contentType'], $attachment)) {
                throw new AppException(422, 'attachment_is_not_pdf', 'Downloaded attachment does not look like a PDF.', [
                    'file' => $attachment['file'],
                    'contentType' => $download['contentType'],
                    'attachmentType' => $attachment['type'] ?? null,
                ]);
            }

            $path = $this->storePolicyFile($attachment, $attachmentIndex, $download['body']);

            $this->logger->info('Policy attachment stored for Claude extraction.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'attachmentFile' => $attachment['file'],
                'storedPath' => $path,
            ]);

            $extractedData = $this->policyDataExtractor->extract($path);
            $extractedData = $confirmationForm?->applyLockedValues($extractedData) ?? $extractedData;
            $this->policyDataCache->savePending($ticketId, $attachment, $extractedData);

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
                'body' => $this->renderPage(
                    $ticketId,
                    $attachments,
                    [
                        'type' => 'success',
                        'text' => 'Dane polisy zostały odczytane przez AI. Sprawdź wartości przed zapisaniem do systemu.',
                    ],
                    $extractedData,
                    $attachmentIndex,
                    $confirmationForm?->lockedFields() ?? [],
                    $ticketTitle
                ),
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
                ], ticketTitle: $ticketTitle),
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
                ], ticketTitle: $ticketTitle),
            ];
        }
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function confirmExtractedPolicyData(
        string $ticketId,
        string $attachmentIndex,
        PolicyConfirmationForm $confirmationForm,
        ?string $ticketTitle = null
    ): array
    {
        $attachments = $this->ticketPdfAttachments->forTicket($ticketId);

        try {
            $attachment = $this->ticketPdfAttachments->byIndex($attachments, $attachmentIndex);
            $extractedData = $confirmationForm->toPolicyData();

            if ($extractedData === null) {
                throw new AppException(404, 'policy_data_not_found', 'Extracted policy data was not found for confirmation.', [
                    'ticket' => $ticketId,
                    'attachment' => $attachmentIndex,
                ]);
            }

            $this->policyDataCache->saveConfirmed($ticketId, $attachment, $extractedData);
            $this->policyDataCache->deletePending($ticketId, $attachment);

            return [
                'status' => 200,
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPage(
                    $ticketId,
                    $attachments,
                    [
                        'type' => 'success',
                        'text' => 'Zaakceptowane wartości zostały zapisane do cache.',
                    ],
                    $extractedData,
                    $attachmentIndex,
                    $confirmationForm->lockedFields(),
                    $ticketTitle
                ),
            ];
        } catch (AppException $exception) {
            return [
                'status' => $exception->statusCode(),
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPage($ticketId, $attachments, [
                    'type' => 'error',
                    'text' => $this->policyProcessingErrorMessage($exception),
                ], ticketTitle: $ticketTitle),
            ];
        }
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function invalidPolicyConfirmationResponse(
        string $ticketId,
        string $attachmentIndex,
        PolicyConfirmationForm $confirmationForm,
        string $message,
        ?string $ticketTitle = null
    ): array
    {
        $attachments = $this->ticketPdfAttachments->forTicket($ticketId);
        $extractedData = $confirmationForm->toPolicyData();

        return [
            'status' => 422,
            'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
            'body' => $this->renderPage(
                $ticketId,
                $attachments,
                [
                    'type' => 'error',
                    'text' => $message,
                ],
                $extractedData,
                $attachmentIndex,
                $confirmationForm->lockedFields(),
                $ticketTitle
            ),
        ];
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function storePolicyFile(array $attachment, string $attachmentIndex, string $body): string
    {
        $directory = rtrim($this->config->varDir, '/\\') . '/policies';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AppException(500, 'policy_temp_dir_failed', 'Could not create temporary policy directory.', [
                'directory' => $directory,
            ]);
        }

        $path = $this->policyFilePath($attachment, $attachmentIndex);

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
    private function policyFilePath(array $attachment, string $attachmentIndex): string
    {
        return rtrim($this->config->varDir, '/\\') . '/policies/' . $this->temporaryPolicyFilename($attachment, $attachmentIndex);
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
            'policy_data_storage_failed', 'policy_data_not_found' => 'Nie udało się zapisać potwierdzonych danych polisy.',
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
    private function renderPage(
        string $ticketId,
        array $attachments,
        ?array $message = null,
        ?ExtractedPolicyData $extractedData = null,
        ?string $selectedAttachmentIndex = null,
        array $selectedLockedFields = [],
        ?string $ticketTitle = null
    ): string
    {
        $accessToken = $this->accessGuard->accessTokenForTicket($ticketId);
        $daktelaBaseUrl = $this->config->daktelaBaseUrl;
        $ticketTitle = $this->displayTicketTitle($ticketId, $ticketTitle);
        ob_start();
        require dirname(__DIR__) . '/templates/page.php';
        return (string) ob_get_clean();
    }

    private function displayTicketTitle(string $ticketId, ?string $ticketTitle): string
    {
        if ($ticketTitle !== null && trim($ticketTitle) !== '') {
            return trim($ticketTitle);
        }

        return $this->ticketPdfAttachments->cachedTitleForTicket($ticketId) ?? 'Ticket bez tytułu';
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
