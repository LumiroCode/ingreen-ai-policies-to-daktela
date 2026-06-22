<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Daktela;

use Ingreen\DaktelaPolicy\Support\AppException;

final class DaktelaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        $response = $this->httpClient->request('GET', $url, $this->authHeaders(['Accept' => 'application/json']));

        if ($response->statusCode === 401 || $response->statusCode === 403) {
            throw new AppException(502, 'daktela_auth_failed', 'Daktela rejected API authentication.', [
                'statusCode' => $response->statusCode,
            ]);
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new AppException(502, 'daktela_request_failed', 'Daktela API request failed.', [
                'statusCode' => $response->statusCode,
                'path' => $path,
            ]);
        }

        $payload = json_decode($response->body, true);

        if (!is_array($payload)) {
            throw new AppException(502, 'invalid_daktela_response', 'Daktela returned invalid JSON.', [
                'path' => $path,
            ]);
        }

        return $payload;
    }

    public function download(string $file, int $maxBytes): HttpResponse
    {
        $url = $this->isAbsoluteUrl($file) ? $file : $this->buildUrl($file);
        $response = $this->httpClient->request('GET', $url, $this->authHeaders(['Accept' => 'application/pdf,*/*']));

        if ($response->statusCode === 401 || $response->statusCode === 403) {
            throw new AppException(502, 'daktela_auth_failed', 'Daktela rejected API authentication.', [
                'statusCode' => $response->statusCode,
            ]);
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new AppException(502, 'attachment_download_failed', 'Attachment download failed.', [
                'statusCode' => $response->statusCode,
            ]);
        }

        if (strlen($response->body) > $maxBytes) {
            throw new AppException(413, 'attachment_too_large', 'Downloaded attachment exceeds configured size limit.', [
                'maxBytes' => $maxBytes,
            ]);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildUrl(string $path, array $query = []): string
    {
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if ($query === []) {
            return $url;
        }

        return $url . '?' . http_build_query($query);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function authHeaders(array $headers): array
    {
        return $headers + ['X-AUTH-TOKEN-OPENAPI' => $this->apiToken];
    }

    private function isAbsoluteUrl(string $file): bool
    {
        return str_starts_with($file, 'http://') || str_starts_with($file, 'https://');
    }
}
