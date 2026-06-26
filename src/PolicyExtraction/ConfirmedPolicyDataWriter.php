<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

interface ConfirmedPolicyDataWriter
{
    /**
     * @return array<string, mixed>
     */
    public function saveConfirmedPolicyData(string $ticketId, ExtractedPolicyData $data): array;
}
