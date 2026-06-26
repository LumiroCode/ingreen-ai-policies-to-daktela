<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\Logging\AppLogger;

final class NullLogger extends AppLogger
{
    /** @var list<array{message:string,context:array<string,mixed>}> */
    public array $warnings = [];

    public function info(string $message, array $context = []): void
    {
    }

    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = ['message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
    }
}
