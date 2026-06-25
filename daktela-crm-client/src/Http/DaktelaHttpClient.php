<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Ingreen\DaktelaCrmClient\Config\DaktelaClientConfig;
use Ingreen\DaktelaCrmClient\Exception\DaktelaCrmClientException;
use Ingreen\DaktelaCrmClient\Exception\DaktelaHttpException;

final class DaktelaHttpClient
{
    private readonly ClientInterface $client;

    public function __construct(
        private readonly DaktelaClientConfig $config,
        ?ClientInterface $client = null
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim($config->baseUrl, '/') . '/',
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>|list<mixed>|null
     */
    public function getCrmRecords(array $query = []): array|null
    {
        return $this->request('GET', 'api/v6/crmRecords', ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|list<mixed>|null
     */
    public function createCrmRecord(array $payload): array|null
    {
        return $this->request('POST', 'api/v6/crmRecords', ['json' => $payload]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|list<mixed>|null
     */
    public function updateCrmRecord(string $name, array $payload): array|null
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('CRM record name must be provided.');
        }

        return $this->request('PUT', 'api/v6/crmRecords/' . rawurlencode($name), ['json' => $payload]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|list<mixed>|null
     */
    public function updateTicket(string $ticketName, array $payload): array|null
    {
        if (trim($ticketName) === '') {
            throw new \InvalidArgumentException('Ticket name must be provided.');
        }

        return $this->request('PUT', 'api/v6/tickets/' . rawurlencode($ticketName), ['json' => $payload]);
    }

    /**
     * @param array{query?:array<string,mixed>,json?:array<string,mixed>} $options
     * @return array<string, mixed>|list<mixed>|null
     */
    private function request(string $method, string $path, array $options = []): array|null
    {
        $options[RequestOptions::HEADERS] = [
            'Accept' => 'application/json',
            'X-AUTH-TOKEN-OPENAPI' => $this->config->apiToken,
        ];

        if (isset($options['json'])) {
            $options[RequestOptions::HEADERS]['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->client->request($method, $path, $options);
        } catch (GuzzleException $exception) {
            throw new DaktelaCrmClientException('Daktela HTTP request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new DaktelaHttpException($statusCode, 'Daktela API request failed.', $body);
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new DaktelaCrmClientException('Daktela API returned invalid JSON.', 0, $exception);
        }

        if (!is_array($payload)) {
            throw new DaktelaCrmClientException('Daktela API returned an unexpected response payload.');
        }

        if (array_key_exists('error', $payload) && is_array($payload['error']) && $payload['error'] !== []) {
            throw new DaktelaCrmClientException('Daktela API returned an error response.');
        }

        return $payload['result'] ?? null;
    }
}
