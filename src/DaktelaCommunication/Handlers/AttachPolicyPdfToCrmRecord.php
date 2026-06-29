<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Handlers;

use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdf;

/**
 * @internal
 */
final class AttachPolicyPdfToCrmRecord
{
    public function __construct(
        private readonly HasPolicyCrmAttachment $hasPolicyCrmAttachment,
        private readonly UploadPolicyPdf $uploadPolicyPdf,
        private readonly CreatePolicyCrmAttachment $createPolicyCrmAttachment,
        private readonly ?AppLogger $logger = null
    ) {
    }

    public function execute(string $ticketId, string $recordIdentifier, PolicyPdf $policyPdf): void
    {
        $alreadyAttached = $this->hasPolicyCrmAttachment->execute($recordIdentifier, $policyPdf);
        $this->logger?->info('Policy CRM attachment lookup finished.', [
            'ticketId' => $ticketId,
            'recordIdentifier' => $recordIdentifier,
            'title' => $policyPdf->title,
            'size' => $policyPdf->size,
            'alreadyAttached' => $alreadyAttached,
        ]);

        if ($alreadyAttached) {
            return;
        }

        $uploadedName = $this->uploadPolicyPdf->execute($policyPdf);
        $this->createPolicyCrmAttachment->execute($recordIdentifier, $uploadedName, $policyPdf->title);

        $this->logger?->info('Policy PDF attached to policy CRM record.', [
            'ticketId' => $ticketId,
            'recordIdentifier' => $recordIdentifier,
            'title' => $policyPdf->title,
            'size' => $policyPdf->size,
        ]);
    }
}
