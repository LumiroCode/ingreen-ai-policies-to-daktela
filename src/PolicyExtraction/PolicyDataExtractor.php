<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction;

interface PolicyDataExtractor
{
    public function extract(string $pdfPath): ExtractedPolicyData;
}
