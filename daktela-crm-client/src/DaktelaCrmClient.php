<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient;

use Ingreen\DaktelaCrmClient\Dto\CarRecordInput;
use Ingreen\DaktelaCrmClient\Dto\CrmRecord;
use Ingreen\DaktelaCrmClient\Dto\PolicyRecordInput;
use Ingreen\DaktelaCrmClient\Dto\TicketRecord;
use Ingreen\DaktelaCrmClient\Dto\TicketUpdateInput;
use Ingreen\DaktelaCrmClient\Exception\NotImplementedException;
use Ingreen\DaktelaCrmClient\Http\DaktelaHttpClient;

final class DaktelaCrmClient implements DaktelaCrmClientInterface
{
    public function __construct(private readonly DaktelaHttpClient $httpClient)
    {
    }

    public function findCarRecordByVinOrNumber(?string $vin, ?string $carNumber): ?CrmRecord
    {
        if ($this->blank($vin) && $this->blank($carNumber)) {
            throw new \InvalidArgumentException('Either VIN or car number must be provided.');
        }

        throw new NotImplementedException('findCarRecordByVinOrNumber is not implemented yet.');
    }

    public function upsertCarRecord(CarRecordInput $input): CrmRecord
    {
        throw new NotImplementedException('upsertCarRecord is not implemented yet.');
    }

    public function findPolicyRecordByCarNumber(string $carNumber): ?CrmRecord
    {
        if ($this->blank($carNumber)) {
            throw new \InvalidArgumentException('Car number must be provided.');
        }

        throw new NotImplementedException('findPolicyRecordByCarNumber is not implemented yet.');
    }

    public function upsertPolicyRecord(PolicyRecordInput $input): CrmRecord
    {
        throw new NotImplementedException('upsertPolicyRecord is not implemented yet.');
    }

    public function updateTicketData(string $ticketName, TicketUpdateInput $input): TicketRecord
    {
        if ($this->blank($ticketName)) {
            throw new \InvalidArgumentException('Ticket name must be provided.');
        }

        throw new NotImplementedException('updateTicketData is not implemented yet.');
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
