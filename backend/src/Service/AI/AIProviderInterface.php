<?php

namespace App\Service\AI;

interface AIProviderInterface
{
    /**
     * @return array{content: array, summary: string, tokens_used: ?int}
     */
    public function analyze(string $prompt): array;

    /**
     * Génère une réponse en texte libre, sans forcer de format JSON —
     * pour le mini chat IA, contrairement à analyze() qui est dédiée à
     * l'analyse structurée d'une session.
     */
    public function chat(string $prompt): string;

    public function getModelName(): string;
}
