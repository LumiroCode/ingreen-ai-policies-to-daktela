<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $body,
        public readonly array $headers = ['Content-Type' => 'application/json']
    ) {
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
