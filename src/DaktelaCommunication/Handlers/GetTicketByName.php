<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;

/**
 * @internal
 */
final class GetTicketByName
{
    public function __construct(private readonly DaktelaCommunicationService $communicationService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $name): array
    {
        return $this->communicationService->getJson('/api/v6/tickets/' . rawurlencode($name));
    }
}
