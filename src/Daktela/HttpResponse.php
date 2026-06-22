<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Daktela;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public function header(string $name): ?string
    {
        $normalized = strtolower($name);

        foreach ($this->headers as $headerName => $value) {
            if (strtolower($headerName) === $normalized) {
                return $value;
            }
        }

        return null;
    }
}
