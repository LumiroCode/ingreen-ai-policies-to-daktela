<?php

declare(strict_types=1);

use Ingreen\DaktelaPolicy\PolicyExtraction\Claude\ClaudeMessagesClient;

final class FakeClaudeMessagesClient implements ClaudeMessagesClient
{
    /** @var list<array{model:string,maxTokens:int,messages:list<array{role:string,content:list<object>}>,thinking:array<string,mixed>|null,outputConfig:array<string,mixed>|null}> */
    public array $requests = [];

    public function __construct(private readonly string $response)
    {
    }

    public function createMessage(
        string $model,
        int $maxTokens,
        array $messages,
        ?array $thinking = null,
        ?array $outputConfig = null
    ): string {
        $this->requests[] = [
            'model' => $model,
            'maxTokens' => $maxTokens,
            'messages' => $messages,
            'thinking' => $thinking,
            'outputConfig' => $outputConfig,
        ];

        return $this->response;
    }
}
