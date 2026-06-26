<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy;

interface UtilityTabSignatureVerifier
{
    public function makeTabSignature(string $dt, string $ticket): ?string;

    public function isValidTabTimestamp(string $dt): bool;

    public function isFreshTabTimestamp(string $dt): bool;
}
