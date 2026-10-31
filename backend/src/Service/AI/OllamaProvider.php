<?php

namespace App\Service\AI;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OllamaProvider implements AIProviderInterface
{
    private const MODEL = 'qwen2.5:7b';

    // URL lue depuis la variable d'environnement OLLAMA_URL (valeur par défaut
    // dans .env, surchargée dans docker-compose.yml pour joindre l'Ollama de
    // la machine hôte depuis le conteneur).
    public function __construct(
        private HttpClientInterface $client,
        #[Autowire(env: 'OLLAMA_URL')] private string $ollamaUrl,
    ) {}

    public function analyze(string $prompt): array
    {
        $response = $this->client->request('POST', $this->ollamaUrl, [
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
        $response = $this->client->request('POST', $this->ollamaUrl, [
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
