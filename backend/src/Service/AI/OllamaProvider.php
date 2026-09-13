<?php

namespace App\Service\AI;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OllamaProvider implements AIProviderInterface
{
    private const OLLAMA_URL = 'http://localhost:11434/api/generate';
    private const MODEL = 'mistral';

    public function __construct(private HttpClientInterface $client) {}

    public function analyze(string $prompt): array
    {
        $response = $this->client->request('POST', self::OLLAMA_URL, [
            'json' => [
                'model' => self::MODEL,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    // Sans ce paramètre, Ollama utilise sa valeur par défaut (souvent
                    // 2048 tokens), largement insuffisante pour nos prompts (~1800-2500
                    // tokens rien que pour l'entrée, avant la réponse à générer).
                    // Mistral 7B supporte nativement au moins 8192 tokens.
                    'num_ctx' => 8192,
                ],
            ],
            'timeout' => 60,
        ]);

        $data = $response->toArray();
        $rawText = $data['response'] ?? '';

        $parsed = json_decode($rawText, true);

        if (!is_array($parsed)) {
            $parsed = [
                'strengths' => [],
                'weaknesses' => [],
                'tips' => [$rawText],
                'predictions' => [],
            ];
        }

        return [
            'content' => $parsed,
            'summary' => $parsed['summary'] ?? substr($rawText, 0, 255),
            'tokens_used' => $data['eval_count'] ?? null,
        ];
    }

    public function getModelName(): string
    {
        return self::MODEL;
    }
}
