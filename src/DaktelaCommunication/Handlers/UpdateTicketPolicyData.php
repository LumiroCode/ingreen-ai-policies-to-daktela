<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\DaktelaTicketPolicyDataMapper;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

/**
 * @internal
 */
final class UpdateTicketPolicyData
{
    public function __construct(
        private readonly DaktelaCommunicationService $communicationService,
        private readonly DaktelaTicketPolicyDataMapper $mapper = new DaktelaTicketPolicyDataMapper()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $ticketId, ExtractedPolicyData $data): array
    {
        return $this->communicationService->putFormJson(
            '/api/v6/tickets/' . rawurlencode($ticketId) . '.json',
            $this->mapper->toTicketPayload($data),
            'daktela_ticket_policy_update_failed'
        );
    }
}
