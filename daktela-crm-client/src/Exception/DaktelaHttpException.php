<?php

declare(strict_types=1);

namespace Ingreen\DaktelaCrmClient\Exception;

final class DaktelaHttpException extends DaktelaCrmClientException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly ?string $responseBody = null
    ) {
        parent::__construct($message, $statusCode);
    }
}
