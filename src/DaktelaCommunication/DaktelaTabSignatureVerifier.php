<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication;

use Ingreen\DaktelaPolicy\UtilityTabSignatureVerifier;

final class DaktelaTabSignatureVerifier implements UtilityTabSignatureVerifier
{
    private const TAB_ALLOWED_SKEW_SECONDS = 5;

    public function makeTabSignature(string $dt, string $ticket): ?string
    {
        if (!$this->isValidTabTimestamp($dt)) {
            return null;
        }

        if (!preg_match('/^\d+$/', $ticket)) {
            return null;
        }

        $t = (int) $dt;
        $n = (int) $ticket;

        $p1 = 10000 + (((($n + 6123) * ($t + 5659)) + 2482) % 90000);
        $p2 = 10000 + (((($n + 5994) * ($t + 3437)) + 6426) % 90000);
        $p3 = 10000 + (((($n + 9154) ** 2) + (($t + 5083) * 4022)) % 90000);

        return sprintf('%d-%d-%d', $p1, $p2, $p3);
    }

    public function isValidTabTimestamp(string $dt): bool
    {
        if (!preg_match('/^\d{10,}$/', $dt)) {
            return false;
        }

        return strlen($dt) < strlen((string) PHP_INT_MAX)
            || (strlen($dt) === strlen((string) PHP_INT_MAX) && strcmp($dt, (string) PHP_INT_MAX) <= 0);
    }

    public function isFreshTabTimestamp(string $dt): bool
    {
        if (!$this->isValidTabTimestamp($dt)) {
            return false;
        }

        $age = abs(time() - (int) $dt);

        return $age <= self::TAB_ALLOWED_SKEW_SECONDS;
    }
}
