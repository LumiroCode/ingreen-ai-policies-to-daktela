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
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}>
     */
    public function forTicket(string $ticketId): array
    {
        $ticket = $this->resultObject($this->daktela->getJson('/api/v6/tickets/' . rawurlencode($ticketId)));
        $activityAttachments = $this->ticketActivityAttachments($ticketId);

        if (($ticket['has_attachment'] ?? null) === false && $activityAttachments === []) {
            throw new AppException(404, 'ticket_has_no_attachment', 'Ticket has no attachment.', ['entityId' => $ticketId]);
        }

        return $this->pdfAttachments($this->uniqueAttachments(array_merge(
            $this->collectAttachments($ticket, 'ticket'),
            $activityAttachments
        )));
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
     * @return array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}
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
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}>
     */
    private function ticketActivityAttachments(string $ticketId): array
    {
        try {
            $payload = $this->daktela->getJson('/api/v6/tickets/' . rawurlencode($ticketId) . '/activities', [
                'pageSize' => 100,
                'sort' => [['field' => 'time', 'dir' => 'desc']],
            ]);
        } catch (AppException $exception) {
            $this->logger->warning('Failed to fetch Daktela ticket activities for attachments.', [
                'ticketId' => $ticketId,
                'errorCode' => $exception->errorCode(),
            ]);

            return [];
        }

        $attachments = [];
        $activities = $payload['result']['data'] ?? [];

        foreach (is_array($activities) ? $activities : [] as $activity) {
            if (!is_array($activity)) {
                continue;
            }

            $attachments = array_merge(
                $attachments,
                $this->attachmentsFromField($activity, 'activity.attachments', $activity),
                is_array($activity['item'] ?? null) ? $this->attachmentsFromField($activity['item'], 'activity.item.attachments', $activity) : [],
                is_array($activity['item'] ?? null) ? $this->attachmentsFromField($activity['item'], 'activity.item.inlineAttachments', $activity, 'inlineAttachments') : []
            );
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $activity
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}>
     */
    private function attachmentsFromField(array $payload, string $source, ?array $activity = null, string $field = 'attachments'): array
    {
        $attachments = $payload[$field] ?? [];

        return is_array($attachments) ? $this->collectAttachments($attachments, $source, $activity) : [];
    }

    /**
     * @param mixed $payload
     * @param array<string, mixed>|null $activity
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}>
     */
    private function collectAttachments(mixed $payload, string $source, ?array $activity = null): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $attachments = [];
        $file = $this->stringValue($payload['file'] ?? $payload['url'] ?? $payload['href'] ?? null);

        if ($file !== null && trim($file) !== '') {
            $title = $payload['title'] ?? $payload['filename'] ?? $payload['name'] ?? null;
            $type = $payload['type'] ?? $payload['mime'] ?? $payload['contentType'] ?? null;
            $size = $payload['size'] ?? null;
            $dataModel = $this->normalizeModel($this->stringValue($payload['_sys']['model'] ?? null));
            $fileId = trim($file);
            $id = $this->stringValue($payload['id'] ?? null) ?? $fileId;
            $mapper = $this->resolveDaktelaAttachmentMapper($activity, $dataModel, $source);
            $attachments[] = [
                'file' => $mapper !== null ? $this->daktelaDownloadPath($mapper, $fileId, is_string($title) ? $title : '') : $fileId,
                'title' => is_string($title) && $title !== '' ? $title : null,
                'type' => is_string($type) && $type !== '' ? $type : null,
                'size' => is_int($size) ? $size : null,
                'source' => $source,
                'id' => trim($id),
                'name' => $this->stringValue($payload['name'] ?? null),
                'dataModel' => $dataModel !== '' ? $dataModel : null,
                'mapper' => $mapper,
            ];
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $attachments = array_merge($attachments, $this->collectAttachments($value, $source, $activity));
            }
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed>|null $activity
     */
    private function resolveDaktelaAttachmentMapper(?array $activity, ?string $attachmentModel, string $sourcePath): ?string
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

    private function daktelaDownloadPath(string $mapper, string $fileId, string $title): string
    {
        return '/file/download.php?' . http_build_query([
            'mapper' => $mapper,
            'name' => $fileId,
            'iconHash' => $title,
            'download' => 1,
        ]);
    }

    private function normalizeModel(?string $model): string
    {
        return str_replace('\\', '', (string) $model);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}>
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
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}> $attachments
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null}>
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
