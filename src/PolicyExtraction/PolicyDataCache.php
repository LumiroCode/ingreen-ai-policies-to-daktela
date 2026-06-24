<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

use Ingreen\DaktelaPolicy\Support\AppException;

final class PolicyDataCache
{
    public function __construct(private readonly string $varDir)
    {
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    public function confirmed(string $ticketId, array $attachment): ?ExtractedPolicyData
    {
        return $this->read($this->path('confirmed', $ticketId, $attachment));
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    public function pending(string $ticketId, array $attachment): ?ExtractedPolicyData
    {
        return $this->read($this->path('pending', $ticketId, $attachment));
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    public function savePending(string $ticketId, array $attachment, ExtractedPolicyData $data): void
    {
        $this->write($this->path('pending', $ticketId, $attachment), $data);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    public function saveConfirmed(string $ticketId, array $attachment, ExtractedPolicyData $data): void
    {
        $this->write($this->path('confirmed', $ticketId, $attachment), $data);
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    public function deletePending(string $ticketId, array $attachment): void
    {
        $path = $this->path('pending', $ticketId, $attachment);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function read(string $path): ?ExtractedPolicyData
    {
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (!is_array($payload)) {
            return null;
        }

        return new ExtractedPolicyData(
            isset($payload['car_make']) && is_string($payload['car_make']) ? $payload['car_make'] : null,
            isset($payload['car_model']) && is_string($payload['car_model']) ? $payload['car_model'] : null,
            isset($payload['value']) && is_string($payload['value']) ? $payload['value'] : null,
            isset($payload['raw_response']) && is_string($payload['raw_response']) ? $payload['raw_response'] : ''
        );
    }

    private function write(string $path, ExtractedPolicyData $data): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AppException(500, 'policy_data_storage_failed', 'Could not create policy data directory.', [
                'directory' => $directory,
            ]);
        }

        $payload = json_encode([
            'car_make' => $data->carMake,
            'car_model' => $data->carModel,
            'value' => $data->value,
            'raw_response' => $data->rawResponse,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new AppException(500, 'policy_data_storage_failed', 'Could not write policy data file.', [
                'path' => $path,
            ]);
        }
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function path(string $state, string $ticketId, array $attachment): string
    {
        $key = hash('sha256', $ticketId . '|' . $this->attachmentIdentifier($attachment));

        return rtrim($this->varDir, '/\\') . '/policy-data/' . $state . '/' . $key . '.json';
    }

    /**
     * @param array{file:string,title?:string|null,type?:string|null,id?:string|null,name?:string|null,dataModel?:string|null,mapper?:string|null} $attachment
     */
    private function attachmentIdentifier(array $attachment): string
    {
        return (string) ($attachment['id']
            ?? $attachment['name']
            ?? $attachment['file']);
    }
}
