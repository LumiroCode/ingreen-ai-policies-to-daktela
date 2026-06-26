<?php

declare(strict_types=1);

function test(string $name, callable $test): void
{
    try {
        $test();
        echo ".";
    } catch (Throwable $exception) {
        echo "\nFAIL: {$name}\n";
        echo $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
        exit(1);
    }
}
