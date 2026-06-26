<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\FindCrmRecordIdentifiersByTitle;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetCrmRecordsByTicketId;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetTicketByName;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetTicketAttachments;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\TicketAttachmentProvider;

final class DaktelaModule implements TicketAttachmentProvider
{
    private const POLICY_CRM_RECORD_TITLE = 'Polisy';
    private const VEHICLE_CRM_RECORD_TITLE = 'Pojazdy';

    private readonly DaktelaCommunicationService $service;
    private readonly FindCrmRecordIdentifiersByTitle $findCrmRecordIdentifiersByTitle;
    private readonly GetCrmRecordsByTicketId $getCrmRecordsByTicketId;
    private readonly GetTicketByName $getTicketByName;
    private readonly GetTicketAttachments $getTicketAttachments;

    /**
     * @param null|callable(string, string, array<string, string>, ?string): array{status:int,headers:array<string,string>,body:string} $requester
     */
    public function __construct(
        string $baseUrl,
        string $apiToken,
        mixed $requester = null,
        ?AppLogger $logger = null
    ) {
        $this->service = new DaktelaCommunicationService($baseUrl, $apiToken, $requester);
        $this->getCrmRecordsByTicketId = new GetCrmRecordsByTicketId($this->service);
        $this->findCrmRecordIdentifiersByTitle = new FindCrmRecordIdentifiersByTitle($this->getCrmRecordsByTicketId);
        $this->getTicketByName = new GetTicketByName($this->service);
        $this->getTicketAttachments = new GetTicketAttachments($this->service, $logger);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTicketByName(string $name): array
    {
        return $this->getTicketByName->execute($name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCrmRecordsByTicketId(string $ticketId): array
    {
        return $this->getCrmRecordsByTicketId->execute($ticketId);
    }

    /**
     * Returns CRM record identifiers suitable for /api/v6/crmRecords/{name} writes.
     *
     * @return list<string> Daktela CRM record identifiers from record.name.
     */
    public function findPolicyCrmRecordIdentifiers(string $ticketId, string $registrationNumber, string $vin): array
    {
        return $this->findCrmRecordIdentifiersByTitle->execute(
            $ticketId,
            self::POLICY_CRM_RECORD_TITLE,
            $registrationNumber,
            $vin,
            'invalid_policy_crm_lookup_arguments',
            'Policy CRM lookup requires registration number and VIN.'
        );
    }

    /**
     * Returns CRM record identifiers suitable for /api/v6/crmRecords/{name} writes.
     *
     * @return list<string> Daktela CRM record identifiers from record.name.
     */
    public function findVehicleCrmRecordIdentifiers(string $ticketId, string $registrationNumber, string $vin): array
    {
        return $this->findCrmRecordIdentifiersByTitle->execute(
            $ticketId,
            self::VEHICLE_CRM_RECORD_TITLE,
            $registrationNumber,
            $vin,
            'invalid_vehicle_crm_lookup_arguments',
            'Vehicle CRM lookup requires registration number and VIN.'
        );
    }

    /**
     * @return array{
     *     title:?string,
     *     hasAttachment:mixed,
     *     attachments:list<array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null}>
     * }
     */
    public function getTicketPdfAttachments(string $ticketId): array
    {
        return $this->getTicketAttachments->execute($ticketId);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,size?:int|null,source?:string|null,id?:string|null,name?:string|null,previewUrl?:string|null} $attachment
     * @return array{body:string,contentType:?string}
     */
    public function downloadTicketAttachment(array $attachment, int $maxBytes): array
    {
        return $this->service->download($attachment['file'], $maxBytes);
    }

    /**
     * @return array{body:string,contentType:?string}
     */
    public function download(string $file, int $maxBytes): array
    {
        return $this->service->download($file, $maxBytes);
    }
}
