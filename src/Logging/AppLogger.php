<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Logging;

use Throwable;

class AppLogger
{
    public function __construct(private readonly ?string $logFile = null)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function exception(Throwable $exception, array $context = []): void
    {
        $this->error($exception->getMessage(), $context + [
            'exceptionClass' => $exception::class,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'time' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($this->logFile === null) {
            error_log($line);
            return;
        }

        $directory = dirname($this->logFile);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log($line);
            return;
        }

        if (file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    }
}
