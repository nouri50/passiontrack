<?php

namespace App\Controller\Api;

use App\Entity\Analysis;
use App\Entity\Session;
use App\Entity\User;
use App\Service\AI\AIProviderInterface;
use App\Service\AI\PromptGenerator;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AnalysisController extends AbstractController
{
    #[Route('/api/sessions/{sessionId}/analyze', name: 'app_api_analysis_trigger', methods: ['POST'])]
    public function trigger(
        int $sessionId,
        EntityManagerInterface $entityManager,
        AIProviderInterface $aiProvider,
        PromptGenerator $promptGenerator,
        NotificationService $notificationService
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($sessionId);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        $existing = $entityManager->getRepository(Analysis::class)->findOneBy(['session' => $session]);
        if ($existing) {
            return $this->json(['error' => 'ANALYSIS_ALREADY_EXISTS', 'analysis_id' => $existing->getId()], 409);
        }

        $prompt = $promptGenerator->generate($session);

        file_put_contents(__DIR__ . '/../../../var/last_ai_prompt.txt', $prompt);

        try {
            $result = $aiProvider->analyze($prompt);
        } catch (\Throwable $e) {
            error_log('AI analysis failed: ' . $e->getMessage());
            return $this->json(['error' => 'AI_ANALYSIS_FAILED'], 502);
        }

        // Garde-fou déterministe : si le score de la session est au maximum,
        // on force 'weaknesses' à vide plutôt que de compter sur Mistral pour
        // respecter la consigne (on a observé qu'il ne le fait pas de façon fiable).
        if ($this->isMaxScore($session) && is_array($result['content'] ?? null)) {
            $result['content']['weaknesses'] = [];
        }

        // Garde-fou déterministe (2) : sur l'incident "Réassignation de piste
        // par l'ATC", le prompt seul ne suffit pas de façon fiable à empêcher
        // Mistral d'affirmer que le pilote a suivi l'ATC (observé à plusieurs
        // reprises en test réel, y compris après renforcement répété de la
        // consigne — la piste initiale du prompt engineering est épuisée pour
        // ce cas précis). On nettoie la réponse après coup plutôt que de
        // continuer à retoucher le texte du prompt.
        if ($this->isAtcRunwayReassignmentIncident($session) && is_array($result['content'] ?? null)) {
            $result['content'] = $this->stripFalseAtcCompliance($result['content']);
            // 'summary' existe en copie séparée au niveau racine de $result
            // (voir ClaudeProvider/OllamaProvider) — la resynchroniser avec
            // la version potentiellement nettoyée dans 'content', sinon
            // Analysis::summary garderait l'ancienne version fausse alors
            // que le contenu structuré, lui, serait corrigé.
            if (isset($result['content']['summary'])) {
                $result['summary'] = $result['content']['summary'];
            }
        }

        $analysis = new Analysis();
        $analysis->setSession($session);
        $analysis->setUser($user);
        $analysis->setContent($result['content']);
        $analysis->setSummary($result['summary']);
        $analysis->setAiModel($aiProvider->getModelName());
        $analysis->setTokenUsed($result['tokens_used']);
        $analysis->setGeneratedAt(new \DateTimeImmutable());
        $analysis->setUpdatedAt(new \DateTimeImmutable());

        $entityManager->persist($analysis);
        $entityManager->flush();

        $notificationService->notify(
            $user,
            'ai_insight',
            'Analyse IA disponible — ' . $session->getTitle(),
            $result['summary'],
            'both',
            $session,
            $analysis,
        );

        return $this->json($this->serializeAnalysis($analysis), 201);
    }

    #[Route('/api/analyses/{id}', name: 'app_api_analysis_get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $analysis = $entityManager->getRepository(Analysis::class)->find($id);

        if (!$analysis) {
            return $this->json(['error' => 'ANALYSIS_NOT_FOUND'], 404);
        }

        if ($analysis->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        return $this->json($this->serializeAnalysis($analysis));
    }

    #[Route('/api/sessions/{sessionId}/analysis', name: 'app_api_analysis_by_session', methods: ['GET'])]
    public function getBySession(int $sessionId, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($sessionId);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        $analysis = $entityManager->getRepository(Analysis::class)->findOneBy(['session' => $session]);

        if (!$analysis) {
            return $this->json(['error' => 'NO_ANALYSIS_FOUND'], 404);
        }

        return $this->json($this->serializeAnalysis($analysis));
    }

    /**
     * Vérifie si un champ de données commençant par "score" (une fois normalisé)
     * vaut 100. Utilise "commence par" plutôt qu'une égalité stricte car le nom
     * exact du champ vu dans /categories est "Score (/100)", pas juste "Score" —
     * une comparaison stricte risquerait de ne jamais matcher.
     */
    private function isMaxScore(Session $session): bool
    {
        foreach ($session->getData() as $key => $value) {
            $normalizedKey = strtolower(str_replace([' ', "'", '_', '-'], '', (string) $key));
            if (str_starts_with($normalizedKey, 'score') && is_numeric($value)) {
                return (float) $value >= 100;
            }
        }

        return false;
    }

    /**
     * Détection stricte de la valeur exacte du champ "Incident technique"
     * (liste déroulante), même normalisation que PromptGenerator::normalizeKey()
     * pour rester cohérent avec la logique de prompt.
     */
    private function isAtcRunwayReassignmentIncident(Session $session): bool
    {
        $normalize = static fn(string $s): string => strtolower(str_replace([' ', "'", '_', '-'], '', $s));

        foreach ($session->getData() as $key => $value) {
            if ($normalize((string) $key) === $normalize('Incident technique')) {
                return $normalize((string) $value) === $normalize("Réassignation de piste par l'ATC");
            }
        }

        return false;
    }

    /**
     * Retire toute mention affirmant que le pilote a suivi l'ATC — le prompt
     * a déjà échoué plusieurs fois à empêcher cette affirmation de façon
     * fiable pour l'incident "Réassignation de piste par l'ATC" (voir
     * PromptGenerator). Les éléments de tableau ('strengths', 'tips',
     * 'predictions') contenant le motif sont retirés purement et simplement ;
     * le résumé, s'il est concerné, est remplacé par une version sûre et
     * générique plutôt que d'être édité en place (risque de casser la
     * grammaire d'une phrase partiellement retirée).
     */
    private function stripFalseAtcCompliance(array $content): array
    {
        $atcCompliancePattern = '/(atc|tour|contr[ôo]leur).{0,40}(suivi|suivie|respect|conforme)|(suivi|suivie|respect).{0,40}(atc|tour)/iu';

        foreach (['strengths', 'tips', 'predictions'] as $field) {
            if (!isset($content[$field]) || !is_array($content[$field])) {
                continue;
            }

            $content[$field] = array_values(array_filter(
                $content[$field],
                static fn($item) => !is_string($item) || !preg_match($atcCompliancePattern, $item)
            ));
        }

        if (isset($content['summary']) && is_string($content['summary']) && preg_match($atcCompliancePattern, $content['summary'])) {
            $content['summary'] = "Vol réalisé avec une réassignation de piste par l'ATC à prendre en compte (incident connu du mode carrière, sans lien avec le pilotage).";
        }

        return $content;
    }

    private function serializeAnalysis(Analysis $analysis): array
    {
        return [
            'id' => $analysis->getId(),
            'session_id' => $analysis->getSession()->getId(),
            'content' => $analysis->getContent(),
            'summary' => $analysis->getSummary(),
            'ai_model' => $analysis->getAiModel(),
            'tokens_used' => $analysis->getTokenUsed(),
            'generated_at' => $analysis->getGeneratedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $analysis->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
