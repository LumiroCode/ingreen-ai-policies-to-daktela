<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaVehicleCrmRecordMapper;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

/**
 * @internal
 */
final class CreateVehicleCrmRecord
{
    public function __construct(
        private readonly DaktelaCommunicationService $communicationService,
        private readonly DaktelaVehicleCrmRecordMapper $mapper = new DaktelaVehicleCrmRecordMapper()
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
            $this->mapper->toVehicleCrmPayload($ticketId, $data, $ticket),
            'daktela_vehicle_crm_save_failed'
        );
    }
}
