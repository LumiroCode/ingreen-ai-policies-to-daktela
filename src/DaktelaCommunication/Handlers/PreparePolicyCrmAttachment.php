<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdf;

/**
 * @internal
 */
final class PreparePolicyCrmAttachment
{
    public function __construct(
        private readonly HasPolicyCrmAttachment $hasPolicyCrmAttachment,
        private readonly UploadPolicyPdf $uploadPolicyPdf,
        private readonly ?AppLogger $logger = null
    ) {
    }

    /**
     * @return array{name:string,title:string,type:string,size:int}|null
     */
    public function execute(string $ticketId, ?string $recordIdentifier, PolicyPdf $policyPdf): ?array
    {
        if ($recordIdentifier !== null) {
            $alreadyAttached = $this->hasPolicyCrmAttachment->execute($recordIdentifier, $policyPdf);
            $this->logger?->info('Policy CRM attachment lookup finished.', [
                'ticketId' => $ticketId,
                'recordIdentifier' => $recordIdentifier,
                'title' => $policyPdf->title,
                'size' => $policyPdf->size,
                'alreadyAttached' => $alreadyAttached,
            ]);

            if ($alreadyAttached) {
                return null;
            }
        }

        $uploadedName = $this->uploadPolicyPdf->execute($policyPdf);
        $this->logger?->info('Policy PDF uploaded for policy CRM record save.', [
            'ticketId' => $ticketId,
            'recordIdentifier' => $recordIdentifier,
            'title' => $policyPdf->title,
            'size' => $policyPdf->size,
        ]);

        return [
            'name' => $uploadedName,
            'title' => $policyPdf->title,
            'type' => $policyPdf->mimeType,
            'size' => $policyPdf->size,
        ];
    }
}
