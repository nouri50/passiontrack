<?php

namespace App\Controller\Api;

use App\Entity\Analysis;
use App\Entity\Session;
use App\Entity\User;
use App\Service\AI\AIProviderInterface;
use App\Service\AI\PromptGenerator;
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
        PromptGenerator $promptGenerator
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($sessionId);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $existing = $entityManager->getRepository(Analysis::class)->findOneBy(['session' => $session]);
        if ($existing) {
            return $this->json(['error' => 'Analysis already exists for this session', 'analysis_id' => $existing->getId()], 409);
        }

        $prompt = $promptGenerator->generate($session);

        try {
            $result = $aiProvider->analyze($prompt);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'AI analysis failed: ' . $e->getMessage()], 502);
        }

        // Garde-fou déterministe : si le score de la session est au maximum,
        // on force 'weaknesses' à vide plutôt que de compter sur Mistral pour
        // respecter la consigne (on a observé qu'il ne le fait pas de façon fiable).
        if ($this->isMaxScore($session) && is_array($result['content'] ?? null)) {
            $result['content']['weaknesses'] = [];
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

        return $this->json($this->serializeAnalysis($analysis), 201);
    }

    #[Route('/api/analyses/{id}', name: 'app_api_analysis_get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $analysis = $entityManager->getRepository(Analysis::class)->find($id);

        if (!$analysis) {
            return $this->json(['error' => 'Analysis not found'], 404);
        }

        if ($analysis->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
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
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $analysis = $entityManager->getRepository(Analysis::class)->findOneBy(['session' => $session]);

        if (!$analysis) {
            return $this->json(['error' => 'No analysis found for this session'], 404);
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
