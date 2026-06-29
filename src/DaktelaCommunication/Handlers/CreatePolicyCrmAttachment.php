<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;

/**
 * @internal
 */
final class CreatePolicyCrmAttachment
{
    public function __construct(private readonly DaktelaCommunicationService $communicationService)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(string $recordIdentifier, string $uploadedName, string $title): array
    {
        return $this->communicationService->postFormJson(
            '/api/v6/crmRecords/' . rawurlencode($recordIdentifier) . '/attachments.json',
            [
                'file' => [
                    'name' => $uploadedName,
                    'title' => $title,
                ],
            ],
            'daktela_policy_attachment_save_failed'
        );
    }
}
