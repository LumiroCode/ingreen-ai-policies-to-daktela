<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;

final class TicketPdfAttachments
{
    private const ATTACHMENT_CACHE_TTL_SECONDS = 86400;

    /** @var array<string,string> */
    private array $ticketTitles = [];

    public function __construct(
        private readonly TicketAttachmentProvider $provider,
        private readonly AppLogger $logger,
        private readonly ?string $cacheDir = null
    ) {
    }

    /**
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>
     */
    public function forTicket(string $ticketId, bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readAttachmentCache($ticketId);

            if ($cached !== null) {
                if ($cached['title'] !== null) {
                    $this->ticketTitles[$ticketId] = $cached['title'];
                }

                return $cached['attachments'];
            }
        }

        $attachments = $this->fetchForTicket($ticketId);
        $this->writeAttachmentCache($ticketId, $attachments, $this->ticketTitles[$ticketId] ?? null);

        return $attachments;
    }

    /**
     * @return list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>
     */
    private function fetchForTicket(string $ticketId): array
    {
        $ticketAttachments = $this->provider->getTicketPdfAttachments($ticketId);
        $ticketTitle = $ticketAttachments['title'];

        if ($ticketTitle !== null) {
            $this->ticketTitles[$ticketId] = $ticketTitle;
        }

        if ($ticketAttachments['hasAttachment'] === false && $ticketAttachments['attachments'] === []) {
            throw new AppException(404, 'ticket_has_no_attachment', 'Ticket has no attachment.', ['entityId' => $ticketId]);
        }

        return $ticketAttachments['attachments'];
    }

    public function cachedTitleForTicket(string $ticketId): ?string
    {
        return $this->ticketTitles[$ticketId] ?? null;
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
     * @return array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}
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
     * @param array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     * @return array{body:string,contentType:?string}
     */
    public function download(array $attachment, int $maxBytes): array
    {
        return $this->provider->downloadTicketAttachment($attachment, $maxBytes);
    }

    /**
     * @return array{attachments:list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>,title:?string}|null
     */
    private function readAttachmentCache(string $ticketId): ?array
    {
        $path = $this->attachmentCachePath($ticketId);

        if ($path === null || !is_file($path)) {
            return null;
        }

        $modifiedAt = filemtime($path);

        if ($modifiedAt === false || time() - $modifiedAt >= self::ATTACHMENT_CACHE_TTL_SECONDS) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (!is_array($payload) || !is_array($payload['attachments'] ?? null)) {
            return null;
        }

        $attachments = [];

        foreach ($payload['attachments'] as $attachment) {
            if (is_array($attachment) && isset($attachment['file']) && is_string($attachment['file'])) {
                $attachments[] = $this->normalizeCachedAttachment($attachment);
            }
        }

        return [
            'attachments' => $attachments,
            'title' => isset($payload['title']) && is_string($payload['title']) ? $payload['title'] : null,
        ];
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     * @return array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}
     */
    private function normalizeCachedAttachment(array $attachment): array
    {
        $attachment['file'] = $this->normalizeDaktelaDownloadPath($attachment['file']);

        if (isset($attachment['previewUrl']) && is_string($attachment['previewUrl'])) {
            $attachment['previewUrl'] = $this->normalizeDaktelaDownloadPath($attachment['previewUrl']);
        }

        return $attachment;
    }

    private function normalizeDaktelaDownloadPath(string $value): string
    {
        $parts = parse_url($value);

        if ($parts === false) {
            return $value;
        }

        $path = $parts['path'] ?? '';
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        $pathChanged = false;
        $queryChanged = false;

        if (str_ends_with($path, '/file/download')) {
            $path .= '.php';
            $pathChanged = true;
        }

        if (isset($query['mapper']) && is_string($query['mapper'])) {
            $mapper = $this->normalizeDaktelaDownloadMapper($query['mapper']);

            if ($mapper !== $query['mapper']) {
                $query['mapper'] = $mapper;
                $queryChanged = true;
            }
        }

        if (!$pathChanged && !$queryChanged) {
            return $value;
        }

        $normalized = '';

        if (isset($parts['scheme'])) {
            $normalized .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $normalized .= $parts['user'];

            if (isset($parts['pass'])) {
                $normalized .= ':' . $parts['pass'];
            }

            $normalized .= '@';
        }

        if (isset($parts['host'])) {
            $normalized .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        $normalized .= $path;
        $queryString = http_build_query($query);
        $normalized .= $queryString !== '' ? '?' . $queryString : '';
        $normalized .= isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $normalized;
    }

    private function normalizeDaktelaDownloadMapper(string $mapper): string
    {
        return [
            'activitiesEmailFiles' => 'activitiesEmail',
            'activitiesWebFiles' => 'activitiesWeb',
            'activitiesFbmFiles' => 'activitiesFbm',
            'activitiesIgdmFiles' => 'activitiesIgdm',
            'activitiesWapFiles' => 'activitiesWap',
            'activitiesVbrFiles' => 'activitiesVbr',
        ][$mapper] ?? $mapper;
    }

    /**
     * @param list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}> $attachments
     */
    private function writeAttachmentCache(string $ticketId, array $attachments, ?string $title): void
    {
        $path = $this->attachmentCachePath($ticketId);

        if ($path === null) {
            return;
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            $this->logger->warning('Could not create ticket attachment cache directory.', [
                'ticketId' => $ticketId,
                'directory' => $directory,
            ]);

            return;
        }

        $payload = json_encode([
            'title' => $title,
            'attachments' => $attachments,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($payload)) {
            $this->logger->warning('Could not encode ticket attachment cache payload.', [
                'ticketId' => $ticketId,
            ]);

            return;
        }

        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            $this->logger->warning('Could not write ticket attachment cache file.', [
                'ticketId' => $ticketId,
                'path' => $path,
            ]);
        }
    }

    private function attachmentCachePath(string $ticketId): ?string
    {
        if ($this->cacheDir === null || trim($this->cacheDir) === '') {
            return null;
        }

        return rtrim($this->cacheDir, '/\\') . '/ticket-attachments/' . hash('sha256', $ticketId) . '.json';
    }
}
