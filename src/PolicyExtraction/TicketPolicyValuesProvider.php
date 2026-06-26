<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

interface TicketPolicyValuesProvider
{
    /**
     * @return array<string,string>
     */
    public function valuesForTicket(string $ticketId): array;
}
