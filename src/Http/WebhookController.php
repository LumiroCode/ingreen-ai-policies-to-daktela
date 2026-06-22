<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\Http;

use Ingreen\DaktelaPolicy\Application\PolicyDownloadService;
use Ingreen\DaktelaPolicy\Logging\AppLogger;
use Ingreen\DaktelaPolicy\Support\AppException;
use Throwable;

final class WebhookController
{
    public function __construct(
        private readonly string $sharedSecret,
        private readonly PolicyDownloadService $policyDownloadService,
        private readonly AppLogger $logger
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function handle(string $method, array $headers, string $body): Response
    {
        $requestId = bin2hex(random_bytes(8));

        try {
            if (strtoupper($method) !== 'POST') {
                throw new AppException(405, 'method_not_allowed', 'Only POST is supported.');
            }

            $this->assertAuthorized($headers);
            $payload = $this->decodeJson($body);
            $entityType = $this->requiredString($payload, 'entityType');
            $entityId = $this->requiredString($payload, 'entityId');

            $result = $this->policyDownloadService->download($entityType, $entityId, $requestId);

            return new Response(200, [
                'requestId' => $requestId,
                'result' => $result,
            ]);
        } catch (AppException $exception) {
            $this->logger->warning('Webhook request failed.', [
                'requestId' => $requestId,
                'errorCode' => $exception->errorCode(),
                'details' => $exception->details(),
            ]);

            return new Response($exception->statusCode(), [
                'requestId' => $requestId,
                'error' => [
                    'code' => $exception->errorCode(),
                    'message' => $exception->getMessage(),
                    'details' => $exception->details(),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->logger->exception($exception, ['requestId' => $requestId]);

            return new Response(500, [
                'requestId' => $requestId,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Internal server error.',
                ],
            ]);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertAuthorized(array $headers): void
    {
        $actual = $this->header($headers, 'X-Webhook-Secret');

        if ($actual === null || !hash_equals($this->sharedSecret, $actual)) {
            throw new AppException(401, 'unauthorized', 'Invalid webhook secret.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            throw new AppException(400, 'invalid_json', 'Request body must be a JSON object.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new AppException(400, 'invalid_request', 'Required string field is missing.', [
                'field' => $key,
            ]);
        }

        return trim($value);
    }

    /**
     * @param array<string, string> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }
}
