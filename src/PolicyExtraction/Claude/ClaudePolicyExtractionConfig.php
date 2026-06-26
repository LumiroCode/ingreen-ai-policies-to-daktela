<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction\Claude;

use Ingreen\DaktelaPolicy\Support\AppException;

final class ClaudePolicyExtractionConfig
{
    public const DEFAULT_PATH = __DIR__ . '/../../../config/claude-policy-extraction.php';

    /**
     * @param array<string, mixed> $responseJsonSchema
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $responseJsonSchema
    ) {
    }

    public static function fromFile(string $path = self::DEFAULT_PATH): self
    {
        if (!is_file($path)) {
            throw new AppException(500, 'missing_configuration', 'Claude policy extraction config file is missing.', [
                'path' => $path,
            ]);
        }

        $settings = require $path;

        if (!is_array($settings)) {
            throw new AppException(500, 'invalid_configuration', 'Claude policy extraction config must return an array.', [
                'path' => $path,
            ]);
        }

        $prompt = $settings['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '') {
            throw new AppException(500, 'missing_configuration', 'Claude policy extraction prompt is missing.', [
                'path' => $path,
                'name' => 'prompt',
            ]);
        }

        $responseJsonSchema = $settings['responseJsonSchema'] ?? null;
        if (!is_array($responseJsonSchema)) {
            throw new AppException(500, 'missing_configuration', 'Claude policy extraction response JSON schema is missing.', [
                'path' => $path,
                'name' => 'responseJsonSchema',
            ]);
        }

        return new self(trim($prompt), $responseJsonSchema);
    }
}
