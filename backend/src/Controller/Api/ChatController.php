<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Chat\ChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController extends AbstractController
{
    #[Route('/api/chat', name: 'app_api_chat', methods: ['POST'])]
    public function chat(Request $request, ChatService $chatService): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $message = trim((string) ($data['message'] ?? ''));

        if (!$chatService->isMessageValid($message)) {
            return $this->json(['error' => 'CHAT_MESSAGE_INVALID'], 400);
        }

        // Historique fourni par le frontend (mémoire de la fenêtre de chat
        // ouverte, rien de stocké côté serveur) — on ne fait confiance qu'à
        // sa forme minimale (role + content), le reste est ignoré.
        $rawHistory = is_array($data['history'] ?? null) ? $data['history'] : [];
        $history = array_values(array_filter(array_map(
            static function ($turn): ?array {
                if (!is_array($turn) || !isset($turn['role'], $turn['content'])) {
                    return null;
                }

                return [
                    'role' => (string) $turn['role'],
                    'content' => (string) $turn['content'],
                ];
            },
            $rawHistory
        )));

        try {
            $reply = $chatService->ask($user, $message, $history);
        } catch (\Throwable $e) {
            error_log('Chat failed: ' . $e->getMessage());

            return $this->json(['error' => 'CHAT_FAILED'], 502);
        }

        // Garde-fou déterministe : le chat confond parfois un Landing
        // Rate/Gforce élevé avec un "décrochage" (stall) — une erreur
        // factuelle grave, pas une simple imprécision de style, déjà
        // explicitement interdite dans la consigne de ChatService ("ne
        // transforme JAMAIS un Landing Rate élevé en décrochage") et
        // pourtant observée en pratique. La réponse du chat étant un texte
        // libre (pas de champs structurés comme pour l'analyse IA), on
        // retire la ou les phrases contenant le terme fautif plutôt que de
        // tenter une réécriture automatique qui risquerait de casser la
        // grammaire du reste du message.
        $reply = $this->stripStallConfusion($reply);

        // Garde-fou déterministe : le chat affirme parfois que la page
        // "Sessions" aide à trouver/découvrir de nouvelles simulations ou
        // défis — déjà explicitement interdit dans la consigne de
        // ChatService ("cette fonctionnalité n'existe pas"), mais observé
        // malgré tout en pratique. Même principe que stripStallConfusion :
        // on retire la phrase fautive plutôt que de tenter une réécriture.
        $reply = $this->stripSessionsPageMisclaim($reply);

        return $this->json(['reply' => $reply]);
    }

    /**
     * Retire toute phrase mentionnant un "décrochage"/"stall" de la réponse
     * du chat. Découpe sur la ponctuation de fin de phrase (. ! ?), filtre
     * les phrases fautives, puis rejoint le reste. Si la confusion occupait
     * tout le message, retombe sur une réponse sûre plutôt qu'une chaîne
     * vide.
     */
    private function stripStallConfusion(string $reply): string
    {
        // Couvre aussi le verbe (« plutôt que de décrocher rapidement »).
        $stallPattern = '/\b(d[ée]croch(?:age|er|e|es|ent|ons|ez|[ée]e?s?|ant)|stall)\b/iu';

        if (!preg_match($stallPattern, $reply)) {
            return $reply;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($reply), -1, PREG_SPLIT_NO_EMPTY);

        $cleaned = array_values(array_filter(
            $sentences,
            static fn(string $sentence) => !preg_match($stallPattern, $sentence)
        ));

        $result = implode(' ', $cleaned);

        return $result !== ''
            ? $result
            : "Je n'ai pas de point précis fiable à te donner pour l'instant — regarde le détail de ta dernière analyse de session pour plus de précision.";
    }

    /**
     * Retire toute phrase affirmant que la page "Sessions" aide à trouver,
     * découvrir ou suggérer de nouvelles simulations/défis — cette page
     * n'affiche QUE les sessions déjà créées par l'utilisateur, ce n'est pas
     * un catalogue (voir consigne ChatService). Détecte "page"/"Sessions"
     * suivi (ou précédé) d'un verbe de découverte dans un rayon raisonnable,
     * dans les deux sens, pour couvrir les deux formulations possibles.
     */
    private function stripSessionsPageMisclaim(string $reply): string
    {
        $discoveryWords = '(trouver|d[ée]couvrir|suggestions?|nouveaux?\s+d[ée]fis?|nouvelles?\s+simulations?)';
        $misclaimPattern = '/(?:page.{0,30}sessions.{0,150}' . $discoveryWords
            . '|' . $discoveryWords . '.{0,150}page.{0,30}sessions)/iu';

        if (!preg_match($misclaimPattern, $reply)) {
            return $reply;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($reply), -1, PREG_SPLIT_NO_EMPTY);

        $cleaned = array_values(array_filter(
            $sentences,
            static fn(string $sentence) => !preg_match($misclaimPattern, $sentence)
        ));

        $result = implode(' ', $cleaned);

        return $result !== ''
            ? $result
            : "Je n'ai pas de point précis fiable à te donner pour l'instant — regarde le détail de ta dernière analyse de session pour plus de précision.";
    }
}
