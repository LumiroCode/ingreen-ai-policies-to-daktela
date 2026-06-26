<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction\Claude;

use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\TextBlockParam;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataResponseParser;
use Ingreen\DaktelaPolicy\Support\AppException;

final class ClaudePolicyDataExtractor implements PolicyDataExtractor
{
    private readonly ClaudePolicyExtractionConfig $extractionConfig;

    public function __construct(
        private readonly ClaudeMessagesClient $client,
        private readonly PolicyDataResponseParser $parser = new PolicyDataResponseParser(),
        private readonly string $model = 'claude-sonnet-4-6',
        private readonly int $maxTokens = 4096,
        ?ClaudePolicyExtractionConfig $extractionConfig = null
    ) {
        $this->extractionConfig = $extractionConfig ?? ClaudePolicyExtractionConfig::fromFile();
    }

    public function extract(string $pdfPath): ExtractedPolicyData
    {
        $pdfData = $this->readPdf($pdfPath);

        try {
            $response = $this->client->createMessage(
                $this->model,
                $this->maxTokens,
                [[
                    'role' => 'user',
                    'content' => [
                        DocumentBlockParam::with(source: Base64PDFSource::with(data: base64_encode($pdfData))),
                        TextBlockParam::with(text: $this->extractionConfig->prompt),
                    ],
                ]],
                // ['type' => 'adaptive']
                null,
                [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => $this->extractionConfig->responseJsonSchema,
                    ],
                ],
            );
        } catch (APIException $exception) {
            throw new AppException(502, 'claude_policy_extraction_failed', 'Claude policy extraction request failed.', [
                'message' => $exception->getMessage(),
            ]);
        }

        return $this->parser->parse($response);
    }

    private function readPdf(string $pdfPath): string
    {
        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new AppException(400, 'policy_pdf_not_readable', 'Policy PDF file is not readable.', [
                'path' => $pdfPath,
            ]);
        }

        $data = file_get_contents($pdfPath);

        if ($data === false || $data === '') {
            throw new AppException(400, 'policy_pdf_not_readable', 'Policy PDF file is not readable.', [
                'path' => $pdfPath,
            ]);
        }

        return $data;
    }
}
