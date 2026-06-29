<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdf;
use Ingreen\DaktelaPolicy\Support\AppException;

/**
 * @internal
 */
final class HasPolicyCrmAttachment
{
    private const PAGE_SIZE = 100;

    public function __construct(private readonly DaktelaCommunicationService $communicationService)
    {
    }

    public function execute(string $recordIdentifier, PolicyPdf $policyPdf): bool
    {
        for ($page = 1; ; $page++) {
            $payload = $this->communicationService->getJson(
                '/api/v6/crmRecords/' . rawurlencode($recordIdentifier) . '/attachments.json',
                ['page' => $page, 'pageSize' => self::PAGE_SIZE]
            );
            $attachments = $payload['result']['data'] ?? null;

            if (!is_array($attachments)) {
                throw new AppException(502, 'invalid_daktela_response', 'Daktela attachment response did not contain a data collection.');
            }

            foreach ($attachments as $attachment) {
                if (is_array($attachment)
                    && ($attachment['title'] ?? null) === $policyPdf->title
                    && ($attachment['size'] ?? null) === $policyPdf->size
                ) {
                    return true;
                }
            }

            if (count($attachments) < self::PAGE_SIZE) {
                return false;
            }
        }
    }
}
