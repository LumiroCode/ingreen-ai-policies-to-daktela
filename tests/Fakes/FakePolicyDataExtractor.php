<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\PolicyExtraction\PolicyDataExtractor;
use Ingreen\DaktelaPolicy\PolicyExtraction\ExtractedPolicyData;

final class FakePolicyDataExtractor implements PolicyDataExtractor
{
    /** @var list<string> */
    public array $paths = [];

    public function __construct(
        private readonly ?ExtractedPolicyData $response = null,
        private readonly ?Throwable $exception = null
    ) {
    }

    public function extract(string $pdfPath): ExtractedPolicyData
    {
        $this->paths[] = $pdfPath;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response ?? new ExtractedPolicyData('Skoda', 'Octavia', '50 000 CZK', '{"car_make":"Skoda","car_model":"Octavia","value":"50 000 CZK"}');
    }
}
