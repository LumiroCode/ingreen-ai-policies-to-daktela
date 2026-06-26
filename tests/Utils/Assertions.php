<?php

declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue(bool $value, string $message = ''): void
{
    if (!$value) {
        throw new RuntimeException($message !== '' ? $message : 'Expected true.');
    }
}

function assertArrayMissingKey(string $key, array $array, string $message = ''): void
{
    if (array_key_exists($key, $array)) {
        throw new RuntimeException($message !== '' ? $message : 'Expected missing key ' . $key . '.');
    }
}
