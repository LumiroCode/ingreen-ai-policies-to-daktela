<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient;

use Ingreen\DaktelaCrmClient\Dto\CarRecordInput;
use Ingreen\DaktelaCrmClient\Dto\CrmRecord;
use Ingreen\DaktelaCrmClient\Dto\PolicyRecordInput;
use Ingreen\DaktelaCrmClient\Dto\TicketRecord;
use Ingreen\DaktelaCrmClient\Dto\TicketUpdateInput;

interface DaktelaCrmClientInterface
{
    public function findCarRecordByVinOrNumber(?string $vin, ?string $carNumber): ?CrmRecord;

    public function upsertCarRecord(CarRecordInput $input): CrmRecord;

    public function findPolicyRecordByCarNumber(string $carNumber): ?CrmRecord;

    public function upsertPolicyRecord(PolicyRecordInput $input): CrmRecord;

    public function updateTicketData(string $ticketName, TicketUpdateInput $input): TicketRecord;
}
