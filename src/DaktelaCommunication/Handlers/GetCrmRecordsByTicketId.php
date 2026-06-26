<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;

/**
 * @internal
 */
final class GetCrmRecordsByTicketId
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly DaktelaCommunicationService $communicationService,
        private readonly ?AppLogger $logger = null
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(string $ticketId): array
    {
        $records = [];

        for ($page = 1; ; $page++) {
            $pageRecords = $this->recordPage($ticketId, $page);
            $records = array_merge($records, $pageRecords);

            if (count($pageRecords) < self::PAGE_SIZE) {
                return $records;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recordPage(string $ticketId, int $page): array
    {
        $payload = $this->communicationService->getJson('/api/v6/crmRecords', [
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'filter' => [
                'field' => 'ticket.name',
                'operator' => 'eq',
                'value' => $ticketId,
            ],
        ]);

        $records = $payload['result']['data'] ?? null;

        if (!is_array($records)) {
            throw new AppException(502, 'invalid_daktela_response', 'Daktela response did not contain a CRM record list.', [
                'path' => '/api/v6/crmRecords',
            ]);
        }

        $records = array_values(array_filter(
            $records,
            static fn (mixed $record): bool => is_array($record)
        ));

        $this->logger?->info('Daktela ticket CRM records lookup page fetched.', [
            'ticketId' => $ticketId,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'returnedCount' => count($records),
            'filter' => [
                'field' => 'ticket.name',
                'operator' => 'eq',
                'value' => $ticketId,
            ],
        ]);

        return $records;
    }
}
