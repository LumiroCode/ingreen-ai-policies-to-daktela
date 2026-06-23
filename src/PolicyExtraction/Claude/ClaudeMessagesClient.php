<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction\Claude;

interface ClaudeMessagesClient
{
    /**
     * @param list<array{role:string,content:list<object>}> $messages
     */
    public function createMessage(string $model, int $maxTokens, array $messages): string;
}
