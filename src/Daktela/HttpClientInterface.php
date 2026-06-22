<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Daktela;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
