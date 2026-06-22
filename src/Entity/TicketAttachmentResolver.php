<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Entity;

use Ingreen\DaktelaPolicy\Attachment\AttachmentMetadata;
use Ingreen\DaktelaPolicy\Daktela\DaktelaClient;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;

final class TicketAttachmentResolver implements AttachmentResolverInterface
{
    private const ACTIVITY_SCAN_PAGE_SIZE = 100;

    public function __construct(
        private readonly DaktelaClient $daktelaClient,
        private readonly AppLogger $logger
    ) {
    }

    /**
     * @return list<AttachmentMetadata>
     */
    public function resolve(string $entityId): array
    {
        $ticketPayload = $this->daktelaClient->getJson('/api/v6/tickets/' . rawurlencode($entityId));
        $ticket = $this->unwrapResult($ticketPayload);

        if (($ticket['has_attachment'] ?? null) === false) {
            throw new AppException(404, 'ticket_has_no_attachment', 'Ticket has no attachment.', [
                'entityId' => $entityId,
            ]);
        }

        $attachments = $this->collectAttachments($ticket, 'ticket');
        $attachments = array_merge($attachments, $this->attachmentsFromActivities($entityId));
        $attachments = $this->deduplicate($attachments);

        if ($attachments === []) {
            throw new AppException(404, 'policy_pdf_not_found', 'No attachment metadata was found for the ticket.', [
                'entityId' => $entityId,
            ]);
        }

        return $attachments;
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrapResult(array $payload): array
    {
        $result = $payload['result'] ?? $payload;

        if (!is_array($result)) {
            throw new AppException(502, 'invalid_daktela_response', 'Daktela response did not contain an object result.');
        }

        return $result;
    }

    /**
     * @return list<AttachmentMetadata>
     */
    private function attachmentsFromActivities(string $ticketId): array
    {
        try {
            $payload = $this->daktelaClient->getJson('/api/v6/activities', [
                'pageSize' => self::ACTIVITY_SCAN_PAGE_SIZE,
                'sort' => [
                    ['field' => 'time', 'dir' => 'desc'],
                ],
            ]);
        } catch (AppException $exception) {
            $this->logger->warning('Failed to scan Daktela activities for ticket attachments.', [
                'ticketId' => $ticketId,
                'errorCode' => $exception->errorCode(),
            ]);

            return [];
        }

        $activities = $payload['result']['data'] ?? [];

        if (!is_array($activities)) {
            return [];
        }

        $attachments = [];

        foreach ($activities as $activity) {
            if (!is_array($activity) || !$this->activityBelongsToTicket($activity, $ticketId)) {
                continue;
            }

            $attachments = array_merge($attachments, $this->collectAttachments($activity, 'activity'));

            if (($activity['type'] ?? null) === 'EMAIL' && isset($activity['item'])) {
                $attachments = array_merge($attachments, $this->attachmentsFromEmailItem((string) $activity['item']));
            }
        }

        return $attachments;
    }

    /**
     * @return list<AttachmentMetadata>
     */
    private function attachmentsFromEmailItem(string $itemId): array
    {
        try {
            $payload = $this->daktelaClient->getJson('/api/v6/activitiesEmail/' . rawurlencode($itemId));
        } catch (AppException $exception) {
            $this->logger->warning('Failed to fetch Daktela email item while resolving attachments.', [
                'itemId' => $itemId,
                'errorCode' => $exception->errorCode(),
            ]);

            return [];
        }

        return $this->collectAttachments($this->unwrapResult($payload), 'email_activity');
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function activityBelongsToTicket(array $activity, string $ticketId): bool
    {
        $ticket = $activity['ticket'] ?? null;

        if (is_array($ticket)) {
            return (string) ($ticket['name'] ?? '') === $ticketId;
        }

        return (string) $ticket === $ticketId;
    }

    /**
     * @param mixed $payload
     * @return list<AttachmentMetadata>
     */
    private function collectAttachments(mixed $payload, string $source): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $attachments = [];

        if (isset($payload['file']) || isset($payload['url']) || isset($payload['href'])) {
            $attachment = AttachmentMetadata::fromArray($payload, $source);

            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        foreach ($payload as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (is_string($key) && strtolower($key) === 'attachments') {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $attachment = AttachmentMetadata::fromArray($item, $source);

                        if ($attachment !== null) {
                            $attachments[] = $attachment;
                        }
                    }
                }
            }

            $attachments = array_merge($attachments, $this->collectAttachments($value, $source));
        }

        return $attachments;
    }

    /**
     * @param list<AttachmentMetadata> $attachments
     * @return list<AttachmentMetadata>
     */
    private function deduplicate(array $attachments): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($attachments as $attachment) {
            $key = $attachment->stableKey();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $attachment;
        }

        return $deduplicated;
    }
}
