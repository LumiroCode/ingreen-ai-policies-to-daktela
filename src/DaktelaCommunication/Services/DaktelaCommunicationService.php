<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\DaktelaCommunication\Services;

use Ingreen\DaktelaPolicy\Support\AppException;

/**
 * @internal
 */
final class DaktelaCommunicationService
{
    /**
     * @param null|callable(string, string, array<string, string>, ?string): array{status:int,headers:array<string,string>,body:string} $requester
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly mixed $requester = null
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $response = $this->request('GET', $this->buildUrl($path, $query), $this->authHeaders([
            'Accept' => 'application/json',
        ]));

        $this->assertSuccessfulResponse($response, $path, 'daktela_request_failed');

        $payload = json_decode($response['body'], true);

        if (!is_array($payload)) {
            throw new AppException(502, 'invalid_daktela_response', 'Daktela returned invalid JSON.', [
                'path' => $path,
            ]);
        }

        return $payload;
    }

    /**
     * @return array{body:string,contentType:?string}
     */
    public function download(string $file, int $maxBytes): array
    {
        $url = $this->isAbsoluteUrl($file) ? $file : $this->buildUrl($file);
        $response = $this->request('GET', $url, $this->authHeaders([
            'Accept' => 'application/pdf,*/*',
        ]));

        $this->assertSuccessfulResponse($response, $file, 'attachment_download_failed', [
            'contentType' => $this->header($response['headers'], 'Content-Type'),
            'bodyPreview' => substr($response['body'], 0, 500),
        ]);

        if (strlen($response['body']) > $maxBytes) {
            throw new AppException(413, 'attachment_too_large', 'Downloaded attachment exceeds configured size limit.', [
                'maxBytes' => $maxBytes,
            ]);
        }

        return [
            'body' => $response['body'],
            'contentType' => $this->header($response['headers'], 'Content-Type'),
        ];
    }

    public function publicUrl(string $path): string
    {
        return $this->buildUrl($path);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($this->requester !== null) {
            return ($this->requester)($method, $url, $headers, $body);
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new AppException(500, 'http_client_error', 'Failed to initialize HTTP client.');
        }

        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $length = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);

                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return $length;
            },
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value,
                array_keys($headers),
                $headers
            ),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new AppException(502, 'upstream_http_error', 'Daktela HTTP request failed.', ['error' => $error]);
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $statusCode, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    /**
     * @param array{status:int,headers:array<string,string>,body:string} $response
     */
    private function assertSuccessfulResponse(array $response, string $path, string $errorCode, array $details = []): void
    {
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new AppException(502, 'daktela_auth_failed', 'Daktela rejected API authentication.', $details + [
                'statusCode' => $response['status'],
                'path' => $path,
            ]);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new AppException(502, $errorCode, 'Daktela API request failed.', $details + [
                'statusCode' => $response['status'],
                'path' => $path,
            ]);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strtolower($header) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildUrl(string $path, array $query = []): string
    {
        $url = $this->isAbsoluteUrl($path)
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

    private function isAbsoluteUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }
}
