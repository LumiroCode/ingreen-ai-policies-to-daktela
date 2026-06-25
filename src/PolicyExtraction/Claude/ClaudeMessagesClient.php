<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction\Claude;

interface ClaudeMessagesClient
{
    /**
     * @param list<array{role:string,content:list<object>}> $messages
     * @param array<string,mixed>|null $thinking
     * @param array<string,mixed>|null $outputConfig
     */
    public function createMessage(
        string $model,
        int $maxTokens,
        array $messages,
        ?array $thinking = null,
        ?array $outputConfig = null
    ): string;
}
