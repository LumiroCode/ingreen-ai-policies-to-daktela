<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;

/**
 * @internal
 */
final class GetTicketAttachments
{
    public function __construct(
        private readonly DaktelaCommunicationService $communicationService,
        private readonly ?AppLogger $logger = null
    ) {
    }

    /**
     * @return array{
     *     title:?string,
     *     hasAttachment:mixed,
     *     attachments:list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>
     * }
     */
    public function execute(string $ticketId): array
    {
        $ticket = $this->resultObject($this->communicationService->getJson('/api/v6/tickets/' . rawurlencode($ticketId)));
        $candidates = $this->attachmentCandidates($ticket, 'ticket');
        $activities = $this->ticketActivities($ticketId);

        foreach ($activities as $activity) {
            $candidates = array_merge(
                $candidates,
                $this->activityAttachmentCandidates($activity, 'attachments', 'activity.attachments', $activity),
                is_array($activity['item'] ?? null) ? $this->activityAttachmentCandidates($activity['item'], 'attachments', 'activity.item.attachments', $activity) : [],
                is_array($activity['item'] ?? null) ? $this->activityAttachmentCandidates($activity['item'], 'inlineAttachments', 'activity.item.inlineAttachments', $activity) : []
            );
        }

        return [
            'title' => $this->ticketTitle($ticket),
            'hasAttachment' => $ticket['has_attachment'] ?? null,
            'attachments' => $this->pdfAttachments($this->uniqueAttachments($this->normalizedAttachments($candidates))),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ticketActivities(string $ticketId): array
    {
        try {
            $payload = $this->communicationService->getJson('/api/v6/tickets/' . rawurlencode($ticketId) . '/activities', [
                'pageSize' => 100,
                'sort' => [['field' => 'time', 'dir' => 'desc']],
            ]);
        } catch (AppException $exception) {
            $this->logger?->warning('Failed to fetch Daktela ticket activities for attachments.', [
                'ticketId' => $ticketId,
                'errorCode' => $exception->errorCode(),
            ]);

            return [];
        }

        $activities = $payload['result']['data'] ?? [];

        return array_values(array_filter(
            is_array($activities) ? $activities : [],
            static fn (mixed $activity): bool => is_array($activity)
        ));
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $activity
     * @return list<array{payload:array<string,mixed>,source:string,activity:?array<string,mixed>}>
     */
    private function activityAttachmentCandidates(array $payload, string $field, string $source, ?array $activity = null): array
    {
        $attachments = $payload[$field] ?? [];

        return is_array($attachments) ? $this->attachmentCandidates($attachments, $source, $activity) : [];
    }

    /**
     * @param mixed $payload
     * @param array<string,mixed>|null $activity
     * @return list<array{payload:array<string,mixed>,source:string,activity:?array<string,mixed>}>
     */
    private function attachmentCandidates(mixed $payload, string $source, ?array $activity = null): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $candidates = [];

        if (isset($payload['file']) || isset($payload['url']) || isset($payload['href'])) {
            $candidates[] = [
                'payload' => $payload,
                'source' => $source,
                'activity' => $activity,
            ];
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $candidates = array_merge($candidates, $this->attachmentCandidates($value, $source, $activity));
            }
        }

        return $candidates;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
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
     * @param array<string,mixed> $ticket
     */
    private function ticketTitle(array $ticket): ?string
    {
        $title = $this->stringValue($ticket['title'] ?? null);

        if ($title !== null && trim($title) !== '') {
            return trim($title);
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * @param list<array{payload:array<string,mixed>,source:string,activity:?array<string,mixed>}> $candidates
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>
     */
    private function normalizedAttachments(array $candidates): array
    {
        $attachments = [];

        foreach ($candidates as $candidate) {
            $payload = $candidate['payload'];
            $file = $this->stringValue($payload['file'] ?? $payload['url'] ?? $payload['href'] ?? null);

            if ($file === null || trim($file) === '') {
                continue;
            }

            $title = $payload['title'] ?? $payload['filename'] ?? $payload['name'] ?? null;
            $type = $payload['type'] ?? $payload['mime'] ?? $payload['contentType'] ?? null;
            $size = $payload['size'] ?? null;
            $model = $this->normalizeModel($this->stringValue($payload['_sys']['model'] ?? null));
            $fileId = trim($file);
            $id = $this->stringValue($payload['id'] ?? null) ?? $fileId;
            $mapper = $this->resolveAttachmentMapper($candidate['activity'], $model, $candidate['source']);
            $downloadPath = $mapper !== null
                ? $this->downloadPath($mapper, $fileId, is_string($title) ? $title : '')
                : $fileId;

            $attachments[] = [
                'file' => $downloadPath,
                'title' => is_string($title) && $title !== '' ? $title : null,
                'type' => is_string($type) && $type !== '' ? $type : null,
                'size' => is_int($size) ? $size : null,
                'source' => $candidate['source'],
                'id' => trim($id),
                'name' => $this->stringValue($payload['name'] ?? null),
                'previewUrl' => $this->previewUrl($downloadPath),
            ];
        }

        return $attachments;
    }

    /**
     * @param array<string,mixed>|null $activity
     */
    private function resolveAttachmentMapper(?array $activity, ?string $attachmentModel, string $sourcePath): ?string
    {
        $directModelMappers = [
            'activitiesEmailFiles' => 'activitiesEmailFiles',
            'activitiesWebFiles' => 'activitiesWebFiles',
            'activitiesFbmFiles' => 'activitiesFbmFiles',
            'activitiesIgdmFiles' => 'activitiesIgdmFiles',
            'activitiesWapFiles' => 'activitiesWapFiles',
            'activitiesVbrFiles' => 'activitiesVbrFiles',
        ];

        if ($attachmentModel !== null && isset($directModelMappers[$attachmentModel])) {
            return $directModelMappers[$attachmentModel];
        }

        if ($sourcePath === 'activity.attachments' || $attachmentModel === 'activitiesAttachments') {
            return 'activitiesComment';
        }

        if ($activity === null) {
            return null;
        }

        return match ($this->normalizeModel($this->stringValue($activity['item']['_sys']['model'] ?? null))) {
            'activitiesEmail' => 'activitiesEmailFiles',
            'activitiesWeb' => 'activitiesWebFiles',
            'activitiesFbm' => 'activitiesFbmFiles',
            'activitiesIgdm' => 'activitiesIgdmFiles',
            'activitiesWap' => 'activitiesWapFiles',
            'activitiesVbr' => 'activitiesVbrFiles',
            default => null,
        };
    }

    private function downloadPath(string $mapper, string $fileId, string $title): string
    {
        return '/file/download.php?' . http_build_query([
            'mapper' => $mapper,
            'name' => $fileId,
            'iconHash' => $title,
            'download' => 1,
        ]);
    }

    private function previewUrl(string $file): string
    {
        $file = $this->communicationService->publicUrl($file);
        $parts = parse_url($file);

        if ($parts === false) {
            return $file;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['download'] = '0';
        $queryString = http_build_query($query);

        $url = '';

        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $url .= $parts['user'];

            if (isset($parts['pass'])) {
                $url .= ':' . $parts['pass'];
            }

            $url .= '@';
        }

        if (isset($parts['host'])) {
            $url .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $url .= $parts['path'] ?? '';
        $url .= $queryString !== '' ? '?' . $queryString : '';
        $url .= isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $url;
    }

    private function normalizeModel(?string $model): string
    {
        return str_replace('\\', '', (string) $model);
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>
     */
    private function uniqueAttachments(array $attachments): array
    {
        $seen = [];
        $unique = [];

        foreach ($attachments as $attachment) {
            $key = $attachment['file'] . '|' . ($attachment['title'] ?? '') . '|' . ($attachment['type'] ?? '');

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $attachment;
            }
        }

        return $unique;
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>
     */
    private function pdfAttachments(array $attachments): array
    {
        return array_values(array_filter($attachments, fn (array $item): bool => $this->isPdf($item)));
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
}
