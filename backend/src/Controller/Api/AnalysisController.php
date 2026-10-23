<?php

namespace App\Controller\Api;

use App\Entity\Analysis;
use App\Entity\Notification;
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

        // Garde-fou déterministe (3) : les verdicts Landing Rate/Gforce
        // dégradés ("ferme mais acceptable", "dur", "notable", "important")
        // sont parfois malgré tout classés par Mistral dans 'strengths' au
        // lieu de 'weaknesses', même après plusieurs renforcements successifs
        // et très explicites de la consigne dans PromptGenerator (observé à
        // plusieurs reprises en test réel — la piste du prompt engineering
        // seul est épuisée pour ce cas aussi). On corrige après coup plutôt
        // que de continuer à retoucher le texte du prompt : toute entrée de
        // 'strengths' reprenant le motif "verdict officiel : ..." que
        // PromptGenerator impose à l'IA de recopier mot pour mot pour un
        // verdict dégradé est déplacée vers 'weaknesses'. Le motif ne dépend
        // volontairement PAS de la ponctuation exacte autour de lui
        // (parenthèses, flèche "→", ou rien) — l'IA formule cette annotation
        // de façon inconsistante selon les sessions (observé en pratique), et
        // une détection trop stricte sur un seul style de ponctuation laissait
        // passer les autres variantes. Fiable malgré l'élargissement car
        // "verdict officiel" reste une expression verrouillée par le prompt,
        // qui n'a aucune raison d'apparaître ailleurs.
        if (is_array($result['content'] ?? null)) {
            $result['content'] = $this->reclassifyDegradedVerdicts($result['content']);
            if (isset($result['content']['summary'])) {
                $result['summary'] = $result['content']['summary'];
            }
        }

        // Garde-fou déterministe (4) : s'assurer qu'un verdict Landing
        // Rate/Gforce dégradé n'est pas totalement absent de la réponse de
        // l'IA (ni en point fort, ni en point faible, ni en conseil, ni dans
        // le résumé) — observé sur une session avec beaucoup de contexte
        // concurrent (notes détaillées, gestion de situation, alignement
        // manqué) où le Landing Gforce dégradé a été complètement ignoré
        // sans explication, alors que le Landing Rate, lui, était bien
        // mentionné. On ne peut pas reformuler un conseil complet à la
        // place de l'IA de façon fiable, donc on se contente d'ajouter une
        // entrée factuelle minimale dans 'weaknesses' si le verdict est
        // totalement absent du texte généré, plutôt que de laisser la
        // session sans aucune trace d'un paramètre pourtant dégradé.
        // Complément observé ensuite : quand le verdict dégradé est absent,
        // c'est souvent parce que l'IA a affirmé à tort "bon" pour ce même
        // paramètre ailleurs (points forts) — une contradiction visible dans
        // l'analyse finale. On retire cette affirmation contradictoire dans
        // le même mouvement que l'ajout du vrai verdict.
        if (is_array($result['content'] ?? null)) {
            $verdict = $promptGenerator->computeLandingVerdictForSession($session);
            $result['content'] = $this->ensureDegradedVerdictsMentioned($result['content'], $verdict);
        }

        // Garde-fou déterministe (5) : voir stripSpeculativeIncidentWeakness()
        // ci-dessous — retire un point faible spéculatif attribué à un
        // incident technique quand l'atterrissage est en réalité bon des
        // deux côtés (Landing Rate ET Landing Gforce).
        if (is_array($result['content'] ?? null)) {
            $verdict = $promptGenerator->computeLandingVerdictForSession($session);
            $result['content'] = $this->stripSpeculativeIncidentWeakness(
                $result['content'],
                $this->hasTechnicalIncident($session),
                $verdict
            );
        }

        // Garde-fou déterministe (6) : voir neutralizeFreezeContent() — sur un
        // incident "Freeze simulateur", retire le freeze des points faibles,
        // corrige la traduction "micro-gel" et remplace un conseil de
        // "maintenance" absurde par un conseil sur la stabilité du simulateur.
        if ($this->isSimulatorFreezeIncident($session)) {
            if (is_array($result['content'] ?? null)) {
                $result['content'] = $this->neutralizeFreezeContent($result['content']);
            }
            if (is_string($result['summary'] ?? null)) {
                $result['summary'] = $this->fixFreezeWording($result['summary']);
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

        // Email uniquement pour la toute première analyse de cette session :
        // au-delà (relance après "Réinitialiser l'analyse", typiquement pour
        // tester un correctif), on ne veut plus encombrer la boîte mail à
        // chaque itération. On se base sur l'historique des notifications
        // 'ai_insight' déjà envoyées pour cette session plutôt que sur
        // l'existence d'une Analysis précédente, car le reset d'analyse la
        // supprime mais ne touche pas aux notifications déjà émises — donc
        // cet historique survit à un reset, contrairement à l'Analysis.
        // La notification en base (cloche), elle, est toujours créée.
        $alreadyNotified = $entityManager->getRepository(Notification::class)->findOneBy([
            'related_session' => $session,
            'type' => 'ai_insight',
        ]);
        $channel = $alreadyNotified ? 'in_app' : 'both';

        $notificationService->notify(
            $user,
            'ai_insight',
            'Analyse IA disponible — ' . $session->getTitle(),
            $result['summary'],
            $channel,
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

    #[Route('/api/sessions/{sessionId}/analysis', name: 'app_api_analysis_delete', methods: ['DELETE'])]
    public function deleteBySession(int $sessionId, EntityManagerInterface $entityManager): JsonResponse
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

        $entityManager->remove($analysis);
        $entityManager->flush();

        return $this->json(['message' => 'Analysis deleted successfully']);
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
     * Détecte si un "Incident technique" réel est déclaré sur la session
     * (champ présent, non vide, différent de "Aucun"/"None"/"NA") — même
     * logique que PromptGenerator::getTechnicalIncident(), dupliquée ici
     * volontairement (style déjà suivi par isAtcRunwayReassignmentIncident
     * ci-dessus) pour que ce contrôleur n'ait pas besoin d'exposer le texte
     * de l'incident lui-même, juste sa présence.
     */
    private function hasTechnicalIncident(Session $session): bool
    {
        $normalize = static fn(string $s): string => strtolower(str_replace([' ', "'", '_', '-'], '', $s));

        foreach ($session->getData() as $key => $value) {
            if ($normalize((string) $key) === $normalize('Incident technique')) {
                if ($value === null || $value === '') {
                    return false;
                }

                return !in_array($normalize((string) $value), ['aucun', 'none', 'na'], true);
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

    /**
     * Déplace de 'strengths' vers 'weaknesses' toute entrée mentionnant
     * explicitement un verdict Landing Rate/Gforce dégradé. S'appuie sur
     * l'expression "verdict officiel : ..." que PromptGenerator impose à
     * l'IA de reprendre mot pour mot pour chaque verdict — fiable ici
     * précisément parce que cette expression est verrouillée en amont par le
     * prompt, contrairement à une détection par mots-clés libres qui
     * risquerait des faux positifs (le mot "important" par exemple est trop
     * courant en dehors de ce contexte précis pour être détecté seul).
     * Volontairement PAS ancré sur une ponctuation précise autour de
     * l'expression (parenthèses, flèche...) : l'IA varie ce détail d'une
     * session à l'autre, donc seule la présence du motif "verdict officiel"
     * suivi du mot de verdict dégradé compte ici.
     */
    private function reclassifyDegradedVerdicts(array $content): array
    {
        if (!isset($content['strengths']) || !is_array($content['strengths'])) {
            return $content;
        }

        $degradedPattern = '/verdict officiel\s*:?\s*(ferme mais acceptable|dur|notable|important)\b/iu';

        $misclassified = [];
        $content['strengths'] = array_values(array_filter(
            $content['strengths'],
            function ($item) use ($degradedPattern, &$misclassified) {
                if (is_string($item) && preg_match($degradedPattern, $item)) {
                    $misclassified[] = $item;
                    return false;
                }
                return true;
            }
        ));

        if (!empty($misclassified)) {
            $weaknesses = is_array($content['weaknesses'] ?? null) ? $content['weaknesses'] : [];
            $content['weaknesses'] = array_values(array_merge($weaknesses, $misclassified));
        }

        return $content;
    }

    /**
     * Concatène tous les champs textuels de la réponse IA (strengths,
     * weaknesses, tips, predictions, summary) en une seule chaîne, pour
     * pouvoir chercher si un motif donné apparaît n'importe où dans la
     * réponse, peu importe le champ où l'IA a choisi de le placer.
     */
    private function flattenContentToText(array $content): string
    {
        $parts = [];

        foreach (['strengths', 'weaknesses', 'tips', 'predictions'] as $field) {
            if (isset($content[$field]) && is_array($content[$field])) {
                foreach ($content[$field] as $item) {
                    if (is_string($item)) {
                        $parts[] = $item;
                    }
                }
            }
        }

        if (isset($content['summary']) && is_string($content['summary'])) {
            $parts[] = $content['summary'];
        }

        return implode(' | ', $parts);
    }

    /**
     * S'assure qu'un verdict Landing Rate/Gforce dégradé n'est pas totalement
     * absent de la réponse de l'IA (observé en pratique sur une session avec
     * beaucoup de contexte concurrent). Cherche le verdict dans TOUS les
     * champs de texte (pas seulement 'strengths' comme reclassifyDegradedVerdicts
     * ci-dessus), via un motif élargi qui reconnaît le verdict même sans
     * l'annotation "verdict officiel" complète, puisque l'IA ne la recopie
     * pas toujours mot pour mot. Si le verdict reste introuvable malgré
     * tout, on ajoute une entrée factuelle minimale dans 'weaknesses' plutôt
     * que de laisser le paramètre complètement absent de l'analyse — on ne
     * tente pas de reformuler un conseil complet à la place de l'IA, ce ne
     * serait pas fiable.
     *
     * Complément : quand le verdict dégradé est absent du texte, c'est
     * souvent parce que l'IA a de nouveau affirmé à tort "bon" pour ce même
     * paramètre ailleurs (typiquement dans 'strengths') — la même confusion
     * de catégorie de verdict que le prompt essaie déjà d'empêcher, mais qui
     * réapparaît parfois malgré tout. Dans ce cas précis, on retire aussi
     * cette affirmation contradictoire de 'strengths', pour ne pas laisser
     * cohabiter deux verdicts opposés ("bon" et "ferme mais acceptable" par
     * exemple) pour le même paramètre dans la même analyse.
     */
    private function ensureDegradedVerdictsMentioned(array $content, ?array $verdict): array
    {
        if ($verdict === null) {
            return $content;
        }

        $haystack = $this->flattenContentToText($content);
        $weaknesses = is_array($content['weaknesses'] ?? null) ? $content['weaknesses'] : [];
        $strengths = is_array($content['strengths'] ?? null) ? $content['strengths'] : [];

        $rateDegraded = $verdict['rate_verdict'] !== 'bon (atterrissage doux)';
        if ($rateDegraded) {
            $rateMentioned = (bool) preg_match(
                '/ferme mais acceptable|(landing rate|atterrissage|vitesse verticale).{0,80}\bdur\b/iu',
                $haystack
            );
            if (!$rateMentioned) {
                $weaknesses[] = sprintf(
                    'Landing Rate : %.0f fpm (verdict officiel : %s)',
                    $verdict['rate'],
                    $verdict['rate_verdict']
                );

                $strengths = array_values(array_filter(
                    $strengths,
                    static fn($item) => !is_string($item) || !preg_match('/landing rate.{0,40}\bbon\b/iu', $item)
                ));
            }
        }

        $gforceDegraded = $verdict['gforce_verdict'] !== null && $verdict['gforce_verdict'] !== 'bon (impact vertical doux)';
        if ($gforceDegraded) {
            $gforceMentioned = (bool) preg_match(
                '/(landing gforce|g-?force|facteur de charge|impact vertical).{0,80}\b(notable|important)\b/iu',
                $haystack
            );
            if (!$gforceMentioned) {
                $weaknesses[] = sprintf(
                    'Landing Gforce : %.2f G (verdict officiel : %s)',
                    $verdict['gforce'],
                    $verdict['gforce_verdict']
                );

                $strengths = array_values(array_filter(
                    $strengths,
                    static fn($item) => !is_string($item) || !preg_match('/(landing gforce|g-?force|facteur de charge).{0,40}\bbon\b/iu', $item)
                ));
            }
        }

        $content['weaknesses'] = array_values($weaknesses);
        $content['strengths'] = array_values($strengths);

        return $content;
    }

    /**
     * Garde-fou déterministe (5) : retire de 'weaknesses' toute affirmation
     * spéculative du type « l'incident technique a pu avoir un impact sur la
     * performance de la session » quand les verdicts Landing Rate/Gforce sont
     * TOUS LES DEUX "bon" — observé en pratique sur un incident "Freeze
     * simulateur" (déconnexion autopilote, reprise en main) où l'atterrissage
     * final restait pourtant bon des deux côtés : l'IA transformait quand
     * même l'incident en point faible générique, sans dégradation réelle à
     * l'appui. Un incident technique doit EXPLIQUER une dégradation mesurée
     * (voir consigne PromptGenerator), pas en devenir une par défaut dès
     * lors qu'il est déclaré — si rien n'est réellement dégradé, il n'y a
     * rien à reprocher à la performance. Ne s'applique QUE si un verdict est
     * réellement dégradé n'existe pas : dès qu'un seul des deux paramètres
     * est dégradé, on laisse l'IA s'exprimer librement sur l'incident, ce
     * n'est alors plus spéculatif.
     */
    private function stripSpeculativeIncidentWeakness(array $content, bool $hasTechnicalIncident, ?array $verdict): array
    {
        if (!$hasTechnicalIncident || !isset($content['weaknesses']) || !is_array($content['weaknesses'])) {
            return $content;
        }

        $rateOk = $verdict === null || $verdict['rate_verdict'] === 'bon (atterrissage doux)';
        $gforceOk = $verdict === null
            || $verdict['gforce_verdict'] === null
            || $verdict['gforce_verdict'] === 'bon (impact vertical doux)';

        if (!$rateOk || !$gforceOk) {
            return $content;
        }

        $speculativePattern = '/incident.{0,100}(?:a pu|aurait pu|pourrait|peut).{0,30}(?:impact|affect)[a-zéû]*.{0,60}performance/iu';

        $content['weaknesses'] = array_values(array_filter(
            $content['weaknesses'],
            static fn($item) => !is_string($item) || !preg_match($speculativePattern, $item)
        ));

        return $content;
    }

    /**
     * Détecte l'incident "Freeze simulateur" (valeur de la liste déroulante
     * "Incident technique") — même normalisation que hasTechnicalIncident().
     */
    private function isSimulatorFreezeIncident(Session $session): bool
    {
        $normalize = static fn(string $s): string => strtolower(str_replace([' ', "'", '_', '-'], '', $s));

        foreach ($session->getData() as $key => $value) {
            if ($normalize((string) $key) === $normalize('Incident technique')) {
                return str_contains($normalize((string) $value), 'freeze');
            }
        }

        return false;
    }

    /**
     * Corrige la mauvaise traduction de "freeze" par le modèle ("micro-gel",
     * "gel du simulateur").
     */
    private function fixFreezeWording(string $text): string
    {
        $text = preg_replace('/\bmicro[- ]?gels?\b/iu', 'micro-freeze', $text) ?? $text;

        return preg_replace('/\bgel (?:du|de) (?:simulateur|sim)\b/iu', 'freeze du simulateur', $text) ?? $text;
    }

    /**
     * Garde-fou déterministe (6) pour l'incident "Freeze simulateur" :
     * - retire de 'weaknesses' toute phrase qui fait du freeze lui-même un
     *   point faible (ce n'est pas une erreur de pilotage), SAUF si elle
     *   parle aussi de l'atterrissage (Landing Rate/G-force) — on garde alors
     *   la phrase plutôt que de perdre un vrai verdict dégradé ;
     * - corrige "micro-gel" → "micro-freeze" partout ;
     * - remplace un conseil de "maintenance"/"réparation" (sans objet pour
     *   un freeze du simulateur) par un conseil sur la stabilité du sim.
     */
    private function neutralizeFreezeContent(array $content): array
    {
        $freezePattern = '/freeze|\bmicro[- ]?gels?\b|\bgel (?:du|de) (?:simulateur|sim)\b/iu';
        // On ne garde une phrase mêlant freeze et atterrissage que si elle porte
        // un vrai verdict chiffré (Landing Rate, G-force, fpm) — le simple mot
        // « atterrissage » ne suffit pas (ex. « Atterrissage avec freeze »).
        $landingPattern = '/landing|g-?force|\bfpm\b/iu';

        foreach (['summary', 'strengths', 'weaknesses', 'tips'] as $field) {
            if (!isset($content[$field])) {
                continue;
            }

            $content[$field] = is_array($content[$field])
                ? array_map(fn($item) => is_string($item) ? $this->fixFreezeWording($item) : $item, $content[$field])
                : (is_string($content[$field]) ? $this->fixFreezeWording($content[$field]) : $content[$field]);
        }

        if (isset($content['weaknesses']) && is_array($content['weaknesses'])) {
            $content['weaknesses'] = array_values(array_filter(
                $content['weaknesses'],
                static fn($item) => !is_string($item)
                    || !preg_match($freezePattern, $item)
                    || preg_match($landingPattern, $item)
            ));
        }

        if (isset($content['tips']) && is_array($content['tips'])) {
            $replacement = "Si les freezes se reproduisent, vérifie la charge de ton ordinateur pendant le vol (mémoire, applications ouvertes en arrière-plan, réglages graphiques).";
            $replaced = false;
            $tips = [];

            foreach ($content['tips'] as $tip) {
                // Conseil vide pour un freeze de simulateur : maintenance,
                // réparation, ou renvoi vers la documentation/le support.
                if (is_string($tip) && (
                    preg_match('/maintenance|r[ée]paration/iu', $tip)
                    || (preg_match($freezePattern, $tip) && preg_match('/documentation|support|contacter|consulter/iu', $tip))
                )) {
                    if (!$replaced) {
                        $tips[] = $replacement;
                        $replaced = true;
                    }
                    continue;
                }
                $tips[] = $tip;
            }

            $content['tips'] = $tips;
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
