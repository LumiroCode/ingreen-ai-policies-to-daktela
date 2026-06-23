<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;

final class TicketPdfAttachments
{
    public function __construct(
        private readonly DaktelaClient $daktela,
        private readonly AppLogger $logger
    ) {
    }

    /**
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}>
     */
    public function forTicket(string $ticketId): array
    {
        $ticket = $this->resultObject($this->daktela->getJson('/api/v6/tickets/' . rawurlencode($ticketId)));

        if (($ticket['has_attachment'] ?? null) === false) {
            throw new AppException(404, 'ticket_has_no_attachment', 'Ticket has no attachment.', ['entityId' => $ticketId]);
        }

        return $this->pdfAttachments($this->uniqueAttachments(array_merge(
            $this->collectAttachments($ticket, 'ticket'),
            $this->ticketActivityAttachments($ticketId)
        )));
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}> $attachments
     * @return array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}
     */
    public function byIndex(array $attachments, string $attachmentIndex): array
    {
        if (!ctype_digit($attachmentIndex)) {
            throw new AppException(400, 'invalid_attachment', 'Attachment index must be a non-negative integer.', [
                'attachment' => $attachmentIndex,
            ]);
        }

        $index = (int) $attachmentIndex;

        if (!isset($attachments[$index])) {
            throw new AppException(404, 'attachment_not_found', 'Selected attachment was not found.', [
                'attachment' => $attachmentIndex,
            ]);
        }

        return $attachments[$index];
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
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}> $attachments
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null}>
     */
    private function pdfAttachments(array $attachments): array
    {
        return array_values(array_filter($attachments, fn (array $item): bool => $this->isPdf($item)));
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
     * @param array{file:string,title?:string|null,type?:string|null} $attachment
     */
    private function isPdf(array $attachment): bool
    {
        return $this->hasPdfType($attachment) || $this->hasPdfExtension($attachment);
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
}
