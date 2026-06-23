<?php

declare(strict_types=1);

namespace Ingreen\DaktelaPolicy\PolicyExtraction\Claude;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;

final class AnthropicClaudeMessagesClient implements ClaudeMessagesClient
{
    public function __construct(private readonly Client $client)
    {
    }

    public static function fromApiKey(?string $apiKey = null): self
    {
        return new self(new Client(apiKey: $apiKey));
    }

    public function createMessage(string $model, int $maxTokens, array $messages): string
    {
        $message = $this->client->messages->create(
            model: $model,
            maxTokens: $maxTokens,
            messages: $messages
        );

        $text = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return trim($text);
    }
}
