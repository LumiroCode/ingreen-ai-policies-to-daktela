<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Application;

use Ingreen\DaktelaPolicy\Attachment\AttachmentSelector;
use Ingreen\DaktelaPolicy\Attachment\FileDownloader;
use Ingreen\DaktelaPolicy\Entity\AttachmentResolverRegistry;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Storage\LocalPolicyStorage;

final class PolicyDownloadService
{
    public function __construct(
        private readonly AttachmentResolverRegistry $resolverRegistry,
        private readonly AttachmentSelector $attachmentSelector,
        private readonly FileDownloader $fileDownloader,
        private readonly LocalPolicyStorage $storage,
        private readonly AppLogger $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function download(string $entityType, string $entityId, string $requestId): array
    {
        $resolver = $this->resolverRegistry->get($entityType);
        $attachments = $resolver->resolve($entityId);
        $selected = $this->attachmentSelector->selectPolicyPdf($attachments);
        $existing = $this->storage->exists($entityType, $entityId, $selected);

        if ($existing !== null) {
            $this->logger->info('Policy attachment already exists locally.', [
                'requestId' => $requestId,
                'entityType' => $entityType,
                'entityId' => $entityId,
                'path' => $existing->path,
            ]);

            return $this->payload($existing->status, $existing->path, $selected);
        }

        $downloaded = $this->fileDownloader->download($selected);
        $stored = $this->storage->store($entityType, $entityId, $selected, $downloaded);

        $this->logger->info('Policy attachment stored locally.', [
            'requestId' => $requestId,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'path' => $stored->path,
            'attachmentFile' => $selected->file,
        ]);

        return $this->payload($stored->status, $stored->path, $selected);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $status, string $path, object $attachment): array
    {
        return [
            'status' => $status,
            'path' => $path,
            'attachment' => [
                'file' => $attachment->file,
                'title' => $attachment->title,
                'type' => $attachment->type,
                'source' => $attachment->source,
            ],
        ];
    }
}
