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
        private readonly PolicyStore $store,
        private readonly AppLogger $logger
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $method, array $headers, string $rawBody): array
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            if (strtoupper($method) !== 'POST') {
                throw new AppException(405, 'method_not_allowed', 'Only POST is supported.');
            }

            if (!hash_equals($this->config->webhookSharedSecret, $this->header($headers, 'X-Webhook-Secret') ?? '')) {
                throw new AppException(401, 'unauthorized', 'Invalid webhook secret.');
            }

            $payload = json_decode($rawBody, true);

            if (!is_array($payload)) {
                throw new AppException(400, 'invalid_json', 'Request body must be a JSON object.');
            }

            $entityType = $this->requiredString($payload, 'entityType');
            $entityId = $this->requiredString($payload, 'entityId');

            if (strtolower($entityType) !== 'ticket') {
                throw new AppException(400, 'unsupported_entity_type', 'Unsupported entity type.', [
                    'entityType' => $entityType,
                    'supportedEntityTypes' => ['ticket'],
                ]);
            }

            $result = $this->downloadTicketPolicy($entityId, $requestId);

            return ['status' => 200, 'body' => ['requestId' => $requestId, 'result' => $result]];
        } catch (AppException $exception) {
            $this->logger->warning('Webhook request failed.', [
                'requestId' => $requestId,
                'errorCode' => $exception->errorCode(),
                'details' => $exception->details(),
            ]);

            return [
                'status' => $exception->statusCode(),
                'body' => [
                    'requestId' => $requestId,
                    'error' => [
                        'code' => $exception->errorCode(),
                        'message' => $exception->getMessage(),
                        'details' => $exception->details(),
                    ],
                ],
            ];
        } catch (Throwable $exception) {
            $this->logger->exception($exception, ['requestId' => $requestId]);

            return [
                'status' => 500,
                'body' => [
                    'requestId' => $requestId,
                    'error' => ['code' => 'internal_error', 'message' => 'Internal server error.'],
                ],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadTicketPolicy(string $ticketId, string $requestId): array
    {
        $ticket = $this->resultObject($this->daktela->getJson('/api/v6/tickets/' . rawurlencode($ticketId)));

        if (($ticket['has_attachment'] ?? null) === false) {
            throw new AppException(404, 'ticket_has_no_attachment', 'Ticket has no attachment.', ['entityId' => $ticketId]);
        }

        $attachments = array_merge(
            $this->collectAttachments($ticket, 'ticket'),
            $this->ticketActivityAttachments($ticketId)
        );
        $attachment = $this->selectPdf($this->uniqueAttachments($attachments));
        $existing = $this->store->existing('ticket', $ticketId, $attachment);

        if ($existing !== null) {
            $this->logger->info('Policy attachment already exists locally.', [
                'requestId' => $requestId,
                'entityType' => 'ticket',
                'entityId' => $ticketId,
                'path' => $existing['path'],
            ]);

            return $this->result($existing, $attachment);
        }

        $download = $this->daktela->download($attachment['file'], $this->config->maxDownloadBytes);

        if (!$this->looksLikePdf($download['body'], $download['contentType'], $attachment)) {
            throw new AppException(422, 'attachment_is_not_pdf', 'Downloaded attachment does not look like a PDF.', [
                'file' => $attachment['file'],
                'contentType' => $download['contentType'],
                'attachmentType' => $attachment['type'] ?? null,
            ]);
        }

        $stored = $this->store->save('ticket', $ticketId, $attachment, $download['body']);
        $this->logger->info('Policy attachment stored locally.', [
            'requestId' => $requestId,
            'entityType' => 'ticket',
            'entityId' => $ticketId,
            'path' => $stored['path'],
            'attachmentFile' => $attachment['file'],
        ]);

        return $this->result($stored, $attachment);
    }

    /**
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}>
     */
    private function ticketActivityAttachments(string $ticketId): array
    {
        try {
            $payload = $this->daktela->getJson('/api/v6/activities', [
                'pageSize' => 100,
                'sort' => [['field' => 'time', 'dir' => 'desc']],
            ]);
        } catch (AppException $exception) {
            $this->logger->warning('Failed to scan Daktela activities for ticket attachments.', [
                'ticketId' => $ticketId,
                'errorCode' => $exception->errorCode(),
            ]);

            return [];
        }

        $attachments = [];
        $activities = $payload['result']['data'] ?? [];

        foreach (is_array($activities) ? $activities : [] as $activity) {
            if (!is_array($activity) || !$this->activityBelongsToTicket($activity, $ticketId)) {
                continue;
            }

            $attachments = array_merge($attachments, $this->collectAttachments($activity, 'activity'));

            if (($activity['type'] ?? null) === 'EMAIL' && isset($activity['item'])) {
                $attachments = array_merge($attachments, $this->emailAttachments((string) $activity['item']));
            }
        }

        return $attachments;
    }

    /**
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}>
     */
    private function emailAttachments(string $itemId): array
    {
        try {
            return $this->collectAttachments(
                $this->resultObject($this->daktela->getJson('/api/v6/activitiesEmail/' . rawurlencode($itemId))),
                'email_activity'
            );
        } catch (AppException $exception) {
            $this->logger->warning('Failed to fetch Daktela email item while resolving attachments.', [
                'itemId' => $itemId,
                'errorCode' => $exception->errorCode(),
            ]);

            return [];
        }
    }

    /**
     * @param mixed $payload
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}>
     */
    private function collectAttachments(mixed $payload, string $source): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $attachments = [];
        $file = $payload['file'] ?? $payload['url'] ?? $payload['href'] ?? null;

        if (is_string($file) && trim($file) !== '') {
            $title = $payload['title'] ?? $payload['filename'] ?? $payload['name'] ?? null;
            $type = $payload['type'] ?? $payload['mime'] ?? $payload['contentType'] ?? null;
            $size = $payload['size'] ?? null;
            $attachments[] = [
                'file' => trim($file),
                'title' => is_string($title) && $title !== '' ? $title : null,
                'type' => is_string($type) && $type !== '' ? $type : null,
                'size' => is_int($size) ? $size : null,
                'source' => $source,
            ];
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $attachments = array_merge($attachments, $this->collectAttachments($value, $source));
            }
        }

        return $attachments;
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}> $attachments
     * @return array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}
     */
    private function selectPdf(array $attachments): array
    {
        $pdfs = array_values(array_filter($attachments, fn (array $item): bool => $this->isPdf($item)));

        if ($pdfs === []) {
            throw new AppException(404, 'policy_pdf_not_found', 'No PDF attachment was found for the ticket.');
        }

        usort($pdfs, function (array $left, array $right): int {
            $score = $this->pdfScore($right) <=> $this->pdfScore($left);
            return $score !== 0 ? $score : strcmp($this->attachmentKey($left), $this->attachmentKey($right));
        });

        return $pdfs[0];
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}> $attachments
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}>
     */
    private function uniqueAttachments(array $attachments): array
    {
        $seen = [];
        $unique = [];

        foreach ($attachments as $attachment) {
            $key = $this->attachmentKey($attachment);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $attachment;
            }
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resultObject(array $payload): array
    {
        $result = $payload['result'] ?? $payload;

        if (!is_array($result)) {
            throw new AppException(502, 'invalid_daktela_response', 'Daktela response did not contain an object result.');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function activityBelongsToTicket(array $activity, string $ticketId): bool
    {
        $ticket = $activity['ticket'] ?? null;
        return is_array($ticket) ? (string) ($ticket['name'] ?? '') === $ticketId : (string) $ticket === $ticketId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new AppException(400, 'invalid_request', 'Required string field is missing.', ['field' => $key]);
        }

        return trim($value);
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strtolower($header) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function isPdf(array $attachment): bool
    {
        return $this->hasPdfType($attachment) || $this->hasPdfExtension($attachment);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function pdfScore(array $attachment): int
    {
        return ($this->hasPdfType($attachment) ? 20 : 0)
            + ($this->hasPdfExtension($attachment) ? 10 : 0)
            + (isset($attachment['title']) && preg_match('/policy|pojist|smlouv|insurance/i', (string) $attachment['title']) === 1 ? 5 : 0);
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
    private function attachmentKey(array $attachment): string
    {
        return $attachment['file'] . '|' . ($attachment['title'] ?? '') . '|' . ($attachment['type'] ?? '');
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
     * @param array{status:string,path:string,downloaded:bool} $stored
     * @param array{file:string,title?:string|null,type?:string|null,source?:string|null} $attachment
     * @return array<string, mixed>
     */
    private function result(array $stored, array $attachment): array
    {
        return [
            'status' => $stored['status'],
            'path' => $stored['path'],
            'attachment' => [
                'file' => $attachment['file'],
                'title' => $attachment['title'] ?? null,
                'type' => $attachment['type'] ?? null,
                'source' => $attachment['source'] ?? null,
            ],
        ];
    }
}
