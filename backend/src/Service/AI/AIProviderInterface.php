<?php

namespace App\Service\AI;

interface AIProviderInterface
{
    /**
     * @return array{content: array, summary: string, tokens_used: ?int}
     */
    public function analyze(string $prompt): array;

    public function getModelName(): string;
}
