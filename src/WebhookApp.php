<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Config\AppConfig;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyExtraction\ConfirmedPolicyDataWriter;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyConfirmationForm;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataCache;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\TicketPolicyDataWriter;
use Ingreen\DaktelaPolicy\PolicyExtraction\TicketPolicyValuesProvider;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdfMaterializer;
use Ingreen\DaktelaPolicy\Support\AppException;
use Throwable;

final class WebhookApp
{
    private readonly WebhookAccessGuard $accessGuard;
    private readonly PolicyDataCache $policyDataCache;

    public function __construct(
        private readonly AppConfig $config,
        UtilityTabSignatureVerifier $tabSignatureVerifier,
        private readonly TicketPdfAttachments $ticketPdfAttachments,
        private readonly PolicyPdfMaterializer $policyPdfMaterializer,
        private readonly PolicyDataExtractor $policyDataExtractor,
        private readonly AppLogger $logger,
        private readonly ?TicketPolicyValuesProvider $ticketPolicyValuesProvider = null,
        private readonly ?TicketPolicyDataWriter $ticketPolicyDataWriter = null,
        private readonly ?ConfirmedPolicyDataWriter $confirmedPolicyDataWriter = null
    ) {
        $this->accessGuard = new WebhookAccessGuard($config, $tabSignatureVerifier, $logger);
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
        ?string $tabDt = null,
        ?string $tabSig = null,
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
                $tabDt,
                $tabSig
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

                    return $this->confirmExtractedPolicyData($ticketId, $attachmentIndex, $confirmationForm, $requestId, $ticketTitle);
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
                        'dt' => $tabDt,
                        'sig' => $tabSig,
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
        $policyPdf = $this->policyPdfMaterializer->cachedOrDownload($attachment, $attachmentIndex);
        $body = $this->policyPdfMaterializer->contents($policyPdf);

        $this->logger->info('Policy attachment prepared for PDF preview.', [
            'requestId' => $requestId,
            'entityType' => 'ticket',
            'entityId' => $ticketId,
            'attachmentFile' => $attachment['file'],
            'storedPath' => $policyPdf->path,
        ]);

        return [
            'status' => 200,
            'headers' => $this->accessGuard->securityHeaders([
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . addcslashes($policyPdf->downloadFilename(), "\\\"") . '"',
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
                    $this->logger->info('Confirmed policy data loaded from cache.', [
                        'requestId' => $requestId,
                        'ticketId' => $ticketId,
                        'attachmentIndex' => $attachmentIndex,
                        'attachment' => $this->attachmentDiagnostics($attachment),
                        'policyData' => $this->policyDataDiagnostics($storedData),
                    ]);

                    return [
                        'status' => 200,
                        'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                        'body' => $this->renderPage(
                            $ticketId,
                            $attachments,
                            [
                                'type' => 'success',
                                'text' => 'Polisa została już kiedyś odczytana i zatwierdzona - wczytano zapisane dane.',
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
                    $this->logger->info('Pending policy data loaded from cache.', [
                        'requestId' => $requestId,
                        'ticketId' => $ticketId,
                        'attachmentIndex' => $attachmentIndex,
                        'attachment' => $this->attachmentDiagnostics($attachment),
                        'policyData' => $this->policyDataDiagnostics($pendingData),
                    ]);

                    return [
                        'status' => 200,
                        'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                        'body' => $this->renderPage(
                            $ticketId,
                            $attachments,
                            [
                                'type' => 'success',
                                'text' => 'Wczytano dane z poprzedniego odczytu polisy przez AI. Sprawdź wartości przed zapisaniem do systemu.',
                            ],
                            $pendingData,
                            $attachmentIndex,
                            ticketTitle: $ticketTitle
                        ),
                    ];
                }
            }

            $policyPdf = $this->policyPdfMaterializer->downloadFresh($attachment, $attachmentIndex);

            $this->logger->info('Policy attachment stored for Claude extraction.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'attachmentFile' => $attachment['file'],
                'storedPath' => $policyPdf->path,
            ]);

            $extractedData = $this->policyDataExtractor->extract($policyPdf->path);
            $extractedData = $confirmationForm?->applyLockedValues($extractedData) ?? $extractedData;
            $this->policyDataCache->savePending($ticketId, $attachment, $extractedData);

            $this->logger->info('Policy attachment processed with Claude extraction.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'attachmentFile' => $attachment['file'],
                'storedPath' => $policyPdf->path,
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
                'body' => $this->renderPolicyProcessingErrorPage(
                    $ticketId,
                    $attachments,
                    $exception,
                    $attachmentIndex,
                    $confirmationForm,
                    $ticketTitle
                ),
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
                'body' => $this->renderPolicyProcessingErrorPage(
                    $ticketId,
                    $attachments,
                    $exception,
                    $attachmentIndex,
                    $confirmationForm,
                    $ticketTitle
                ),
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
        string $requestId,
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

            $this->logger->info('Policy confirmation save started.', [
                'requestId' => $requestId,
                'ticketId' => $ticketId,
                'attachmentIndex' => $attachmentIndex,
                'attachment' => $this->attachmentDiagnostics($attachment),
                'lockedFieldCount' => count($confirmationForm->lockedFields()),
                'writer' => $this->confirmedPolicyDataWriter !== null
                    ? 'confirmedPolicyDataWriter'
                    : ($this->ticketPolicyDataWriter !== null ? 'ticketPolicyDataWriter' : 'cacheOnly'),
                'policyData' => $this->policyDataDiagnostics($extractedData),
            ]);

            if ($this->confirmedPolicyDataWriter !== null) {
                $policyPdf = $this->policyPdfMaterializer->cachedOrDownload($attachment, $attachmentIndex);
                $this->confirmedPolicyDataWriter->saveConfirmedPolicyData($ticketId, $extractedData, $policyPdf);
            } else {
                $this->ticketPolicyDataWriter?->updateTicketPolicyData($ticketId, $extractedData);
            }

            $this->logger->info('Policy confirmation data saved to Daktela writer.', [
                'requestId' => $requestId,
                'ticketId' => $ticketId,
                'writer' => $this->confirmedPolicyDataWriter !== null
                    ? 'confirmedPolicyDataWriter'
                    : ($this->ticketPolicyDataWriter !== null ? 'ticketPolicyDataWriter' : 'cacheOnly'),
            ]);

            $this->policyDataCache->saveConfirmed($ticketId, $attachment, $extractedData);
            $this->policyDataCache->deletePending($ticketId, $attachment);

            $this->logger->info('Policy confirmation cache updated.', [
                'requestId' => $requestId,
                'ticketId' => $ticketId,
                'attachmentIndex' => $attachmentIndex,
            ]);

            return [
                'status' => 200,
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPage(
                    $ticketId,
                    $attachments,
                    [
                        'type' => 'success',
                        'text' => $this->confirmedPolicyDataWriter !== null
                            ? 'Zaakceptowane wartości zostały zapisane do ticketa oraz rekordów CRM polisy i pojazdu w Daktela.'
                            : ($this->ticketPolicyDataWriter === null
                            ? 'Zaakceptowane wartości zostały zapisane do cache.'
                            : 'Zaakceptowane wartości zostały zapisane do ticketa w Daktela.'),
                    ],
                    $extractedData,
                    $attachmentIndex,
                    $confirmationForm->lockedFields(),
                    $ticketTitle
                ),
            ];
        } catch (AppException $exception) {
            $this->logger->warning('Policy confirmation save failed.', [
                'requestId' => $requestId,
                'ticketId' => $ticketId,
                'attachmentIndex' => $attachmentIndex,
                'errorCode' => $exception->errorCode(),
                'details' => $exception->details(),
            ]);

            return [
                'status' => $exception->statusCode(),
                'headers' => $this->accessGuard->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPolicyProcessingErrorPage(
                    $ticketId,
                    $attachments,
                    $exception,
                    $attachmentIndex,
                    $confirmationForm,
                    $ticketTitle
                ),
            ];
        }
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
     */
    private function renderPolicyProcessingErrorPage(
        string $ticketId,
        array $attachments,
        Throwable $exception,
        string $attachmentIndex,
        ?PolicyConfirmationForm $confirmationForm,
        ?string $ticketTitle
    ): string
    {
        return $this->renderPage(
            $ticketId,
            $attachments,
            [
                'type' => 'error',
                'text' => $this->policyProcessingErrorMessage($exception),
            ],
            $confirmationForm?->toPolicyData(),
            $attachmentIndex,
            $confirmationForm?->lockedFields() ?? [],
            $ticketTitle
        );
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

    private function policyProcessingErrorMessage(Throwable $exception): string
    {
        if (!$exception instanceof AppException) {
            return 'Wystąpił nieoczekiwany błąd podczas odczytu danych z polisy.';
        }

        return match ($exception->errorCode()) {
            'attachment_download_failed' => 'System źródłowy odmówił pobrania wybranego pliku polisy lub zwrócił błąd dla tego załącznika.',
            'upstream_http_error' => 'Nie udało się połączyć z systemem źródłowym podczas pobierania pliku polisy.',
            'daktela_auth_failed' => 'Daktela odrzuciła uwierzytelnienie API podczas pobierania pliku polisy.',
            'attachment_too_large' => 'Plik polisy jest większy niż dozwolony limit.',
            'attachment_is_not_pdf' => 'Wybrany załącznik nie jest poprawnym plikiem PDF.',
            'policy_temp_dir_failed', 'policy_temp_write_failed', 'policy_pdf_not_readable' => 'Nie udało się zapisać pliku polisy do odczytu.',
            'policy_data_storage_failed', 'policy_data_not_found' => 'Nie udało się zapisać potwierdzonych danych polisy.',
            'daktela_ticket_policy_update_failed' => 'Nie udało się zapisać danych polisy do ticketa w Daktela.',
            'invalid_policy_crm_lookup_arguments' => 'Dane dla rekordu CRM polisy nie zostały zapisane. Uzupełnij numer rejestracyjny pojazdu i VIN w formularzu, a następnie spróbuj ponownie.',
            'multiple_policy_crm_records_found' => 'Dane dla rekordu CRM polisy nie zostały zapisane. Znaleziono więcej niż jeden pasujący rekord CRM polisy dla numeru rejestracyjnego lub VIN. Usuń zbędne rekordy CRM polis tak, aby pozostał tylko jeden, a następnie spróbuj ponownie.',
            'daktela_policy_crm_save_failed' => 'Nie udało się zapisać danych do rekordu CRM polisy w Daktela.',
            'daktela_policy_attachment_upload_failed' => 'Nie udało się przesłać pliku polisy do Daktela.',
            'invalid_daktela_attachment_upload_response' => 'Daktela zwróciła nieprawidłową odpowiedź podczas przesyłania pliku polisy.',
            'daktela_policy_attachment_save_failed' => 'Nie udało się dołączyć pliku polisy do rekordu CRM polisy w Daktela.',
            'multiple_vehicle_crm_records_found' => 'Dane dla rekordu CRM pojazdu nie zostały zapisane. Znaleziono więcej niż jeden pasujący rekord CRM pojazdu dla numeru rejestracyjnego lub VIN. Usuń zbędne rekordy CRM pojazdów tak, aby pozostał tylko jeden, a następnie spróbuj ponownie.',
            'daktela_vehicle_crm_save_failed' => 'Nie udało się zapisać danych do rekordu CRM pojazdu w Daktela.',
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
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
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
        $ticketTitle = $this->displayTicketTitle($ticketId, $ticketTitle);
        $ticketPolicyValues = $extractedData instanceof ExtractedPolicyData
            ? $this->ticketPolicyValues($ticketId)
            : [];
        ob_start();
        require dirname(__DIR__) . '/templates/page.php';
        return (string) ob_get_clean();
    }

    /**
     * @return array<string,string>
     */
    private function ticketPolicyValues(string $ticketId): array
    {
        if ($this->ticketPolicyValuesProvider === null) {
            return [];
        }

        try {
            return $this->ticketPolicyValuesProvider->valuesForTicket($ticketId);
        } catch (Throwable $exception) {
            $this->logger->warning('Could not load existing ticket policy values.', [
                'ticketId' => $ticketId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
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
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     * @return array<string,mixed>
     */
    private function attachmentDiagnostics(array $attachment): array
    {
        return [
            'id' => $attachment['id'] ?? null,
            'name' => $attachment['name'] ?? null,
            'title' => $attachment['title'] ?? null,
            'type' => $attachment['type'] ?? null,
            'fileSha256' => hash('sha256', $attachment['file']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function policyDataDiagnostics(ExtractedPolicyData $data): array
    {
        $nonEmptyFields = [];

        foreach ($data->fields as $field => $value) {
            if ($value !== null && trim($value) !== '') {
                $nonEmptyFields[] = $field;
            }
        }

        $vin = trim((string) ($data->field('vin') ?? ''));

        return [
            'nonEmptyFieldCount' => count($nonEmptyFields),
            'nonEmptyFields' => $nonEmptyFields,
            'hasRegistrationNumber' => trim((string) ($data->field('nr_rejestracyjny') ?? '')) !== '',
            'hasVin' => $vin !== '',
            'vinLength' => strlen($vin),
            'vinSuffix' => $vin === '' ? '' : substr($vin, -6),
            'vinSha256' => $vin === '' ? null : hash('sha256', $vin),
        ];
    }
}
