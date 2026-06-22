<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Logging;

use DateTimeImmutable;

final class DailyLogPaths
{
    public function __construct(
        private readonly string $varDir,
        private readonly DateTimeImmutable $date
    ) {
    }

    public static function forToday(string $varDir): self
    {
        return new self($varDir, new DateTimeImmutable());
    }

    public function directory(): string
    {
        return rtrim($this->varDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->dateName();
    }

    public function logsFile(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $this->dateName() . '.log';
    }

    public function errorsFile(): string
    {
        return $this->directory() . DIRECTORY_SEPARATOR . $this->dateName() . '.errors.log';
    }

    private function dateName(): string
    {
        return $this->date->format('Y-m-d');
    }
}
