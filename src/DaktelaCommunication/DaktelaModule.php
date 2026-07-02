<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\CreatePolicyCrmRecord;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\CreateVehicleCrmRecord;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\FindCrmRecordIdentifiersByTitle;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetCrmRecordsByTicketId;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetTicketByName;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\GetTicketAttachments;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\HasPolicyCrmAttachment;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\PreparePolicyCrmAttachment;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UpdatePolicyCrmRecord;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UpdateVehicleCrmRecord;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UpdateTicketPolicyData;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers\UploadPolicyPdf;
use Ingreen\DaktelaPolicy\DaktelaCommunication\Services\DaktelaCommunicationService;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyExtraction\ConfirmedPolicyDataWriter;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\TicketPolicyDataWriter;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdf;
use Ingreen\DaktelaPolicy\Support\AppException;
use Ingreen\DaktelaPolicy\TicketAttachmentProvider;

final class DaktelaModule implements TicketAttachmentProvider, TicketPolicyDataWriter, ConfirmedPolicyDataWriter
{
    private const POLICY_CRM_RECORD_TITLE = 'Polisy';
    private const VEHICLE_CRM_RECORD_TITLE = 'Pojazdy';

    private readonly DaktelaCommunicationService $service;
    private readonly ?AppLogger $logger;
    private readonly DaktelaNumericValueNormalizer $valueNormalizer;
    private readonly CreatePolicyCrmRecord $createPolicyCrmRecord;
    private readonly CreateVehicleCrmRecord $createVehicleCrmRecord;
    private readonly FindCrmRecordIdentifiersByTitle $findCrmRecordIdentifiersByTitle;
    private readonly GetCrmRecordsByTicketId $getCrmRecordsByTicketId;
    private readonly GetTicketByName $getTicketByName;
    private readonly GetTicketAttachments $getTicketAttachments;
    private readonly PreparePolicyCrmAttachment $preparePolicyCrmAttachment;
    private readonly UpdatePolicyCrmRecord $updatePolicyCrmRecord;
    private readonly UpdateVehicleCrmRecord $updateVehicleCrmRecord;
    private readonly UpdateTicketPolicyData $updateTicketPolicyData;

    /**
     * @param null|callable(string, string, array<string, string>, string|array<string,\CURLFile>|null): array{status:int,headers:array<string,string>,body:string} $requester
     */
    public function __construct(
        string $baseUrl,
        string $apiToken,
        mixed $requester = null,
        ?AppLogger $logger = null
    ) {
        $this->service = new DaktelaCommunicationService($baseUrl, $apiToken, $requester);
        $this->logger = $logger;
        $this->valueNormalizer = new DaktelaNumericValueNormalizer();
        $this->preparePolicyCrmAttachment = new PreparePolicyCrmAttachment(
            new HasPolicyCrmAttachment($this->service),
            new UploadPolicyPdf($this->service),
            $logger
        );
        $this->getCrmRecordsByTicketId = new GetCrmRecordsByTicketId($this->service, $logger);
        $this->createPolicyCrmRecord = new CreatePolicyCrmRecord($this->service);
        $this->createVehicleCrmRecord = new CreateVehicleCrmRecord($this->service);
        $this->findCrmRecordIdentifiersByTitle = new FindCrmRecordIdentifiersByTitle(
            $this->getCrmRecordsByTicketId,
            $this->valueNormalizer,
            $logger
        );
        $this->getTicketByName = new GetTicketByName($this->service);
        $this->getTicketAttachments = new GetTicketAttachments($this->service, $logger);
        $this->updatePolicyCrmRecord = new UpdatePolicyCrmRecord($this->service);
        $this->updateVehicleCrmRecord = new UpdateVehicleCrmRecord($this->service);
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
    public function saveConfirmedPolicyData(string $ticketId, ExtractedPolicyData $data, PolicyPdf $policyPdf): array
    {
        $this->logger?->info('Confirmed policy save started.', [
            'ticketId' => $ticketId,
        ]);

        $ticketUpdate = $this->updateTicketPolicyData($ticketId, $data);
        $this->logger?->info('Daktela ticket policy data updated before CRM save.', [
            'ticketId' => $ticketId,
            'resultName' => $this->resultName($ticketUpdate),
        ]);

        $ticket = $this->ticketForCrmPayload($ticketId, $ticketUpdate);
        $registrationNumber = $this->requiredCrmLookupField($data, 'nr_rejestracyjny', 'invalid_policy_crm_lookup_arguments');
        $vin = $this->requiredCrmLookupField($data, 'vin', 'invalid_policy_crm_lookup_arguments');
        $this->logger?->info('Confirmed policy CRM lookup values prepared.', [
            'ticketId' => $ticketId,
            'lookup' => $this->lookupDiagnostics($registrationNumber, $vin),
        ]);

        $policyCrmResponse = $this->savePolicyCrmRecord(
            $ticketId,
            $data,
            $ticket,
            $registrationNumber,
            $vin,
            $policyPdf
        );

        $this->saveVehicleCrmRecord($ticketId, $data, $ticket, $registrationNumber, $vin);

        $this->logger?->info('Confirmed policy save finished.', [
            'ticketId' => $ticketId,
            'policyCrmResultName' => $this->resultName($policyCrmResponse),
        ]);

        return $policyCrmResponse;
    }

    /**
     * @param array<string,mixed> $ticket
     * @return array<string,mixed>
     */
    private function savePolicyCrmRecord(
        string $ticketId,
        ExtractedPolicyData $data,
        array $ticket,
        string $registrationNumber,
        string $vin,
        PolicyPdf $policyPdf
    ): array {
        $recordIdentifiers = $this->findPolicyCrmRecordIdentifiers($ticketId, $registrationNumber, $vin);
        $this->logger?->info('Policy CRM record lookup finished.', [
            'ticketId' => $ticketId,
            'recordTitle' => self::POLICY_CRM_RECORD_TITLE,
            'matchedCount' => count($recordIdentifiers),
            'recordIdentifiers' => $recordIdentifiers,
        ]);

        if (count($recordIdentifiers) > 1) {
            $this->logger?->warning('Policy CRM save aborted because multiple matching records were found.', [
                'ticketId' => $ticketId,
                'recordIdentifiers' => $recordIdentifiers,
            ]);

            throw new AppException(409, 'multiple_policy_crm_records_found', 'More than one matching policy CRM record was found.', [
                'ticketId' => $ticketId,
                'recordIdentifiers' => $recordIdentifiers,
            ]);
        }

        if ($recordIdentifiers === []) {
            $attachment = $this->preparePolicyCrmAttachment->execute($ticketId, null, $policyPdf);
            $this->logger?->info('Creating policy CRM record.', [
                'ticketId' => $ticketId,
                'recordTitle' => self::POLICY_CRM_RECORD_TITLE,
            ]);

            $response = $this->createPolicyCrmRecord->execute($ticketId, $data, $ticket, $attachment);
            $this->logger?->info('Policy CRM record created.', [
                'ticketId' => $ticketId,
                'resultName' => $this->resultName($response),
            ]);

            return $response;
        }

        $this->logger?->info('Updating policy CRM record.', [
            'ticketId' => $ticketId,
            'recordIdentifier' => $recordIdentifiers[0],
        ]);

        $attachment = $this->preparePolicyCrmAttachment->execute($ticketId, $recordIdentifiers[0], $policyPdf);
        $response = $this->updatePolicyCrmRecord->execute(
            $recordIdentifiers[0],
            $ticketId,
            $data,
            $ticket,
            $attachment
        );
        $this->logger?->info('Policy CRM record updated.', [
            'ticketId' => $ticketId,
            'recordIdentifier' => $recordIdentifiers[0],
            'resultName' => $this->resultName($response),
        ]);

        return $response;
    }

    /**
     * @param array<string,mixed> $ticket
     * @return array<string,mixed>
     */
    private function saveVehicleCrmRecord(
        string $ticketId,
        ExtractedPolicyData $data,
        array $ticket,
        string $registrationNumber,
        string $vin
    ): array {
        $recordIdentifiers = $this->findVehicleCrmRecordIdentifiers($ticketId, $registrationNumber, $vin);
        $this->logger?->info('Vehicle CRM record lookup finished.', [
            'ticketId' => $ticketId,
            'recordTitle' => self::VEHICLE_CRM_RECORD_TITLE,
            'matchedCount' => count($recordIdentifiers),
            'recordIdentifiers' => $recordIdentifiers,
        ]);

        if (count($recordIdentifiers) > 1) {
            $this->logger?->warning('Vehicle CRM save aborted because multiple matching records were found.', [
                'ticketId' => $ticketId,
                'recordIdentifiers' => $recordIdentifiers,
            ]);

            throw new AppException(409, 'multiple_vehicle_crm_records_found', 'More than one matching vehicle CRM record was found.', [
                'ticketId' => $ticketId,
                'recordIdentifiers' => $recordIdentifiers,
            ]);
        }

        if ($recordIdentifiers === []) {
            $this->logger?->info('Creating vehicle CRM record.', [
                'ticketId' => $ticketId,
                'recordTitle' => self::VEHICLE_CRM_RECORD_TITLE,
            ]);

            $response = $this->createVehicleCrmRecord->execute($ticketId, $data, $ticket);
            $this->logger?->info('Vehicle CRM record created.', [
                'ticketId' => $ticketId,
                'resultName' => $this->resultName($response),
            ]);

            return $response;
        }

        $this->logger?->info('Updating vehicle CRM record.', [
            'ticketId' => $ticketId,
            'recordIdentifier' => $recordIdentifiers[0],
        ]);

        $response = $this->updateVehicleCrmRecord->execute($recordIdentifiers[0], $ticketId, $data, $ticket);
        $this->logger?->info('Vehicle CRM record updated.', [
            'ticketId' => $ticketId,
            'recordIdentifier' => $recordIdentifiers[0],
            'resultName' => $this->resultName($response),
        ]);

        return $response;
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

    private function requiredCrmLookupField(ExtractedPolicyData $data, string $field, string $errorCode): string
    {
        $value = $this->valueNormalizer->normalizeForField($field, $data->field($field));

        if ($value === null || $value === '') {
            throw new AppException(400, $errorCode, 'CRM lookup requires registration number and VIN.', [
                'field' => $field,
            ]);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $ticketUpdate
     * @return array<string,mixed>
     */
    private function ticketForCrmPayload(string $ticketId, array $ticketUpdate): array
    {
        $ticket = $this->resultObject($ticketUpdate);

        if ($this->hasRequiredCrmTicketContext($ticket)) {
            $this->logger?->info('Using ticket update response as CRM payload context.', [
                'ticketId' => $ticketId,
                'hasUser' => isset($ticket['user']),
                'hasContact' => isset($ticket['contact']),
                'hasAccount' => isset($ticket['account']),
            ]);

            return $ticket;
        }

        $this->logger?->info('Ticket update response lacks CRM context; fetching ticket before CRM save.', [
            'ticketId' => $ticketId,
            'hasUser' => isset($ticket['user']),
            'hasCustomFields' => is_array($ticket['customFields'] ?? null),
            'hasPolicyOrigin' => is_array($ticket['customFields'] ?? null)
                && array_key_exists('pochodzenie_polisy', $ticket['customFields']),
        ]);

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
    private function hasRequiredCrmTicketContext(array $ticket): bool
    {
        return isset($ticket['user'])
            && is_array($ticket['customFields'] ?? null)
            && array_key_exists('pochodzenie_polisy', $ticket['customFields']);
    }

    /**
     * @return array<string,mixed>
     */
    private function lookupDiagnostics(string $registrationNumber, string $vin): array
    {
        $registrationNumber = $this->valueNormalizer->normalizeForField('nr_rejestracyjny', $registrationNumber) ?? '';
        $vin = trim($vin);

        return [
            'registrationNumber' => $registrationNumber,
            'vinLength' => strlen($vin),
            'vinSuffix' => $vin === '' ? '' : substr($vin, -6),
            'vinSha256' => hash('sha256', $vin),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function resultName(array $payload): ?string
    {
        $result = $payload['result'] ?? $payload;

        if (!is_array($result)) {
            return null;
        }

        $name = $result['name'] ?? null;

        if (!is_string($name) && !is_int($name)) {
            return null;
        }

        $name = trim((string) $name);

        return $name !== '' ? $name : null;
    }
}
