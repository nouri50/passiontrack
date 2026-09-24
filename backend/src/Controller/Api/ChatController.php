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

        return $this->json(['reply' => $reply]);
    }
}
