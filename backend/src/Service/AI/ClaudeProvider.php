<?php

namespace App\Service\AI;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClaudeProvider implements AIProviderInterface
{
    private const CLAUDE_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-sonnet-4-5';

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey
    ) {}

    public function analyze(string $prompt): array
    {
        $response = $this->client->request('POST', self::CLAUDE_URL, [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => self::MODEL,
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
        ]);

        $data = $response->toArray();
        $rawText = $data['content'][0]['text'] ?? '';

        $parsed = json_decode($rawText, true);

        if (!is_array($parsed)) {
            $parsed = ['tips' => [$rawText]];
        }

        return [
            'content' => $parsed,
            'summary' => $parsed['summary'] ?? substr($rawText, 0, 255),
            'tokens_used' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
        ];
    }

    public function getModelName(): string
    {
        return self::MODEL;
    }
}
