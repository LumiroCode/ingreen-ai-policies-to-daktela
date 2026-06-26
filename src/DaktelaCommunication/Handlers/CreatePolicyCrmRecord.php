<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaPolicyCrmRecordMapper;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

/**
 * @internal
 */
final class CreatePolicyCrmRecord
{
    public function __construct(
        private readonly DaktelaCommunicationService $communicationService,
        private readonly DaktelaPolicyCrmRecordMapper $mapper = new DaktelaPolicyCrmRecordMapper()
    ) {
    }

    /**
     * @param array<string,mixed> $ticket
     * @return array<string,mixed>
     */
    public function execute(string $ticketId, ExtractedPolicyData $data, array $ticket): array
    {
        return $this->communicationService->postFormJson(
            '/api/v6/crmRecords.json',
            $this->mapper->toPolicyCrmPayload($ticketId, $data, $ticket),
            'daktela_policy_crm_save_failed'
        );
    }
}
