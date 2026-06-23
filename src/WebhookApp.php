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
    private const ACCESS_TOKEN_TTL_SECONDS = 900;

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
    public function handle(
        ?string $ticketId,
        ?string $attachmentIndex,
        ?string $accessToken = null,
        ?string $utilityKey = null,
        ?string $referrer = null
    ): array
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            $ticketId = $this->requiredTicketId($ticketId);
            $this->assertAccessAllowed($ticketId, $accessToken, $utilityKey, $referrer);

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
                'headers' => $this->securityHeaders(['Content-Type' => 'application/json']),
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
                'headers' => $this->securityHeaders(['Content-Type' => 'application/json']),
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
            'headers' => $this->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
            'body' => $this->renderPdfAttachmentsTable($ticketId, $this->ticketPdfAttachments->forTicket($ticketId)),
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

            return [
                'status' => 200,
                'headers' => $this->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPdfAttachmentsTable($ticketId, $attachments, [
                    'type' => 'success',
                    'text' => 'Plik polisy został zapisany tymczasowo.',
                ]),
            ];
        } catch (AppException $exception) {
            $this->logger->warning('Policy attachment could not be stored.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'errorCode' => $exception->errorCode(),
                'details' => $exception->details(),
            ]);

            return [
                'status' => $exception->statusCode(),
                'headers' => $this->securityHeaders(['Content-Type' => 'text/html; charset=UTF-8']),
                'body' => $this->renderPdfAttachmentsTable($ticketId, $attachments, [
                    'type' => 'error',
                    'text' => 'Nie udało się zapisać pliku polisy. Spróbuj ponownie.',
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

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
     */
    private function renderPdfAttachmentsTable(string $ticketId, array $attachments, ?array $message = null): string
    {
        $accessToken = $this->accessTokenForTicket($ticketId);
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

    private function assertAccessAllowed(string $ticketId, ?string $accessToken, ?string $utilityKey, ?string $referrer): void
    {
        if ($this->config->allowedUtilityOrigin === null && $this->config->utilitySecretKey === null) {
            return;
        }

        if ($accessToken !== null && $this->isValidAccessToken($ticketId, $accessToken)) {
            return;
        }

        if ($this->isValidEntryRequest($utilityKey, $referrer)) {
            return;
        }

        throw new AppException(403, 'forbidden_utility_access', 'This utility requires a valid Daktela origin and utility key.', [
            'allowedOrigin' => $this->config->allowedUtilityOrigin,
            'requiresUtilityKey' => $this->config->utilitySecretKey !== null,
        ]);
    }

    private function isValidEntryRequest(?string $utilityKey, ?string $referrer): bool
    {
        if ($this->config->utilitySecretKey !== null && !$this->isValidUtilityKey($utilityKey)) {
            return false;
        }

        return $this->config->allowedUtilityOrigin === null
            || ($referrer !== null && $this->isAllowedReferrer($referrer));
    }

    private function isValidUtilityKey(?string $utilityKey): bool
    {
        return $utilityKey !== null
            && $this->config->utilitySecretKey !== null
            && hash_equals($this->config->utilitySecretKey, $utilityKey);
    }

    private function isAllowedReferrer(string $referrer): bool
    {
        $allowed = parse_url($this->config->allowedUtilityOrigin ?? '');
        $actual = parse_url($referrer);

        if (!is_array($allowed) || !is_array($actual)) {
            return false;
        }

        return strtolower((string) ($actual['scheme'] ?? '')) === strtolower((string) ($allowed['scheme'] ?? ''))
            && strtolower((string) ($actual['host'] ?? '')) === strtolower((string) ($allowed['host'] ?? ''))
            && (int) ($actual['port'] ?? self::defaultPort((string) ($actual['scheme'] ?? ''))) === (int) ($allowed['port'] ?? self::defaultPort((string) ($allowed['scheme'] ?? '')));
    }

    private static function defaultPort(string $scheme): int
    {
        return strtolower($scheme) === 'http' ? 80 : 443;
    }

    private function accessTokenForTicket(string $ticketId): string
    {
        $payload = $this->base64UrlEncode(json_encode([
            'ticket' => $ticketId,
            'expires' => time() + self::ACCESS_TOKEN_TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . $this->accessTokenSignature($payload);
    }

    private function isValidAccessToken(string $ticketId, string $token): bool
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || !hash_equals($this->accessTokenSignature($parts[0]), $parts[1])) {
            return false;
        }

        $payload = json_decode($this->base64UrlDecode($parts[0]), true);

        return is_array($payload)
            && ($payload['ticket'] ?? null) === $ticketId
            && is_int($payload['expires'] ?? null)
            && $payload['expires'] >= time();
    }

    private function accessTokenSignature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->config->daktelaApiToken);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }

    /**
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private function securityHeaders(array $headers): array
    {
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['Referrer-Policy'] = 'same-origin';

        if ($this->config->allowedUtilityOrigin !== null) {
            $headers['Content-Security-Policy'] = "frame-ancestors " . $this->config->allowedUtilityOrigin;
        }

        return $headers;
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
