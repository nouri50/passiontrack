<?php

namespace App\Controller\Api;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController extends AbstractController
{
    #[Route('/api/notifications', name: 'app_api_notifications_list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $notifications = $entityManager->getRepository(Notification::class)->findBy(
            ['user' => $user],
            ['set_at' => 'DESC']
        );

        $data = array_map(fn(Notification $n) => $this->serializeNotification($n), $notifications);

        return $this->json($data);
    }

    #[Route('/api/notifications/{id}/read', name: 'app_api_notifications_read', methods: ['PUT'])]
    public function markAsRead(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $notification = $entityManager->getRepository(Notification::class)->find($id);

        if (!$notification) {
            return $this->json(['error' => 'Notification not found'], 404);
        }

        if ($notification->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $notification->setIsRead(true);
        $notification->setReadAt(new \DateTimeImmutable());

        $entityManager->flush();

        return $this->json($this->serializeNotification($notification));
    }

    #[Route('/api/notifications/{id}', name: 'app_api_notifications_delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $notification = $entityManager->getRepository(Notification::class)->find($id);

        if (!$notification) {
            return $this->json(['error' => 'Notification not found'], 404);
        }

        if ($notification->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $entityManager->remove($notification);
        $entityManager->flush();

        return $this->json(['message' => 'Notification deleted successfully']);
    }

    private function serializeNotification(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'is_read' => $notification->isRead(),
            'channel' => $notification->getChannel(),
            'sent_at' => $notification->getSetAt()->format('Y-m-d H:i:s'),
            'read_at' => $notification->getReadAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
