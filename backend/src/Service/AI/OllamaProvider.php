<?php

namespace App\Service\AI;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class OllamaProvider implements AIProviderInterface
{
    private const OLLAMA_URL = 'http://127.0.0.1:11434/api/generate';
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
                    'num_ctx' => 8192,
                ],
            ],
            // Seule l'option timeout globale est nécessaire (en secondes)
            'timeout' => 300,
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

    /**
     * Pas de 'format' => 'json' ici, volontairement : une réponse de
     * chat est du texte libre à afficher tel quel, pas une structure
     * à parser comme pour analyze().
     */
    public function chat(string $prompt): string
    {
        $response = $this->client->request('POST', self::OLLAMA_URL, [
            'json' => [
                'model' => self::MODEL,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'num_ctx' => 8192,
                ],
            ],
            'timeout' => 300,
        ]);

        $data = $response->toArray();

        return trim($data['response'] ?? '');
    }

    public function getModelName(): string
    {
        return self::MODEL;
    }
}
