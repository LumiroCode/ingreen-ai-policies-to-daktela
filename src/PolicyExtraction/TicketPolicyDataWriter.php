<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

interface TicketPolicyDataWriter
{
    /**
     * @return array<string, mixed>
     */
    public function updateTicketPolicyData(string $ticketId, ExtractedPolicyData $data): array;
}
