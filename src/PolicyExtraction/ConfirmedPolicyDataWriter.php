<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

use Ingreen\DaktelaPolicy\PolicyFiles\PolicyPdf;

interface ConfirmedPolicyDataWriter
{
    /**
     * @return array<string, mixed>
     */
    public function saveConfirmedPolicyData(string $ticketId, ExtractedPolicyData $data, PolicyPdf $policyPdf): array;
}
