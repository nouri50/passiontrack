<?php

namespace App\Service\AI;

use App\Entity\Session;

class PromptGenerator
{
    public function generate(Session $session): string
    {
        $category = $session->getCategory()->getSlug();
        $data = json_encode($session->getData());
        $duration = $session->getDuration();
        $title = $session->getTitle();

        $basePrompt = match ($category) {
            'aviation' => "Tu es un instructeur de simulation de vol. Analyse cette session de vol : titre \"{$title}\", durée {$duration}s, données: {$data}.",
            'racing' => "Tu es un coach de course automobile. Analyse cette session de course : titre \"{$title}\", durée {$duration}s, données: {$data}.",
            'fitness' => "Tu es un coach sportif. Analyse cette séance d'entraînement : titre \"{$title}\", durée {$duration}s, données: {$data}.",
            'gaming' => "Tu es un coach esport. Analyse cette session de jeu : titre \"{$title}\", durée {$duration}s, données: {$data}.",
            default => "Analyse cette session d'activité : titre \"{$title}\", durée {$duration}s, données: {$data}.",
        };

        return $basePrompt . " Réponds UNIQUEMENT en JSON valide, ENTIÈREMENT EN FRANÇAIS (tous les textes doivent être en français, pas d'anglais), avec cette structure exacte : "
            . '{"strengths": ["point fort 1", "point fort 2"], "weaknesses": ["point faible 1"], '
            . '"tips": ["conseil 1", "conseil 2"], "predictions": ["prédiction 1"], "summary": "résumé court en une phrase"}';
    }
}
