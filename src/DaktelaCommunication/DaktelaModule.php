<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\CreatePolicyCrmRecord;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\FindCrmRecordIdentifiersByTitle;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetCrmRecordsByTicketId;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetTicketByName;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetTicketAttachments;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UpdatePolicyCrmRecord;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UpdateTicketPolicyData;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyExtraction\ConfirmedPolicyDataWriter;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\TicketPolicyDataWriter;
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\TicketAttachmentProvider;

final class DaktelaModule implements TicketAttachmentProvider, TicketPolicyDataWriter, ConfirmedPolicyDataWriter
{
    private const POLICY_CRM_RECORD_TITLE = 'Polisy';
    private const VEHICLE_CRM_RECORD_TITLE = 'Pojazdy';

    private readonly DaktelaCommunicationService $service;
    private readonly CreatePolicyCrmRecord $createPolicyCrmRecord;
    private readonly FindCrmRecordIdentifiersByTitle $findCrmRecordIdentifiersByTitle;
    private readonly GetCrmRecordsByTicketId $getCrmRecordsByTicketId;
    private readonly GetTicketByName $getTicketByName;
    private readonly GetTicketAttachments $getTicketAttachments;
    private readonly UpdatePolicyCrmRecord $updatePolicyCrmRecord;
    private readonly UpdateTicketPolicyData $updateTicketPolicyData;

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
        $this->createPolicyCrmRecord = new CreatePolicyCrmRecord($this->service);
        $this->findCrmRecordIdentifiersByTitle = new FindCrmRecordIdentifiersByTitle($this->getCrmRecordsByTicketId);
        $this->getTicketByName = new GetTicketByName($this->service);
        $this->getTicketAttachments = new GetTicketAttachments($this->service, $logger);
        $this->updatePolicyCrmRecord = new UpdatePolicyCrmRecord($this->service);
        $this->updateTicketPolicyData = new UpdateTicketPolicyData($this->service);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTicketByName(string $name): array
    {
        return $this->getTicketByName->execute($name);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateTicketPolicyData(string $ticketId, ExtractedPolicyData $data): array
    {
        return $this->updateTicketPolicyData->execute($ticketId, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function saveConfirmedPolicyData(string $ticketId, ExtractedPolicyData $data): array
    {
        $ticketUpdate = $this->updateTicketPolicyData($ticketId, $data);
        $ticket = $this->ticketForPolicyCrmPayload($ticketId, $ticketUpdate);
        $recordIdentifiers = $this->findPolicyCrmRecordIdentifiers(
            $ticketId,
            $this->requiredPolicyLookupField($data, 'nr_rejestracyjny'),
            $this->requiredPolicyLookupField($data, 'vin')
        );

        if (count($recordIdentifiers) > 1) {
            throw new AppException(409, 'multiple_policy_crm_records_found', 'More than one matching policy CRM record was found.', [
                'ticketId' => $ticketId,
                'recordIdentifiers' => $recordIdentifiers,
            ]);
        }

        if ($recordIdentifiers === []) {
            return $this->createPolicyCrmRecord->execute($ticketId, $data, $ticket);
        }

        return $this->updatePolicyCrmRecord->execute($recordIdentifiers[0], $ticketId, $data, $ticket);
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

    private function requiredPolicyLookupField(ExtractedPolicyData $data, string $field): string
    {
        $value = $data->field($field);

        if ($value === null || trim($value) === '') {
            throw new AppException(400, 'invalid_policy_crm_lookup_arguments', 'Policy CRM lookup requires registration number and VIN.', [
                'field' => $field,
            ]);
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $ticketUpdate
     * @return array<string,mixed>
     */
    private function ticketForPolicyCrmPayload(string $ticketId, array $ticketUpdate): array
    {
        $ticket = $this->resultObject($ticketUpdate);

        if ($this->hasAnyTicketReference($ticket)) {
            return $ticket;
        }

        return $this->resultObject($this->getTicketByName($ticketId));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resultObject(array $payload): array
    {
        $result = $payload['result'] ?? $payload;

        if (!is_array($result)) {
            throw new AppException(502, 'invalid_daktela_response', 'Daktela response did not contain an object result.');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $ticket
     */
    private function hasAnyTicketReference(array $ticket): bool
    {
        foreach (['user', 'contact', 'account'] as $field) {
            if (isset($ticket[$field])) {
                return true;
            }
        }

        return false;
    }
}
