<?php

namespace App\Controller\Api;

use App\Entity\Analysis;
use App\Entity\Category;
use App\Entity\Session;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SessionController extends AbstractController
{
    #[Route('/api/sessions', name: 'app_api_sessions_list', methods: ['GET'])]
    public function list(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $qb = $entityManager->getRepository(Session::class)->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.date_start', 'DESC');

        if ($categoryId = $request->query->get('category_id')) {
            $qb->andWhere('s.category = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        $sessions = $qb->getQuery()->getResult();

        $data = array_map(fn(Session $s) => $this->serializeSession($s), $sessions);

        return $this->json($data);
    }

    #[Route('/api/sessions/{id}', name: 'app_api_sessions_get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        return $this->json($this->serializeSession($session));
    }

    #[Route('/api/sessions', name: 'app_api_sessions_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['category_id'], $data['title'], $data['date_start'], $data['date_end'])) {
            return $this->json(['error' => 'Missing required fields: category_id, title, date_start, date_end'], 400);
        }

        $category = $entityManager->getRepository(Category::class)->find($data['category_id']);
        if (!$category) {
            return $this->json(['error' => 'Category not found'], 404);
        }

        $session = new Session();
        $session->setUser($user);
        $session->setCategory($category);
        $session->setTitle($data['title']);
        $session->setSubcategery($data['subcategory'] ?? null);
        $session->setDescription($data['description'] ?? null);
        $session->setDateStart(new \DateTime($data['date_start']));
        $session->setDateEnd(new \DateTime($data['date_end']));
        $session->setDuration($data['duration'] ?? null);
        $session->setData($data['data'] ?? []);
        $session->setNotes($data['notes'] ?? null);
        $session->setAttachmentUrl($data['attachment_url'] ?? null);
        $session->setIsPublic($data['is_public'] ?? false);
        $session->setCreatedAt(new \DateTimeImmutable());
        $session->setUpdatedAt(new \DateTimeImmutable());

        $errors = $validator->validate($session);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], 400);
        }

        $entityManager->persist($session);
        $entityManager->flush();

        return $this->json($this->serializeSession($session), 201);
    }

    #[Route('/api/sessions/{id}', name: 'app_api_sessions_update', methods: ['PUT'])]
    public function update(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $data = json_decode($request->getContent(), true);

        // Champs qui influencent la pertinence de l'analyse IA existante
        $analysisRelevantFields = ['duration', 'data', 'date_start', 'date_end', 'category_id'];
        $shouldInvalidateAnalysis = false;
        foreach ($analysisRelevantFields as $field) {
            if (array_key_exists($field, $data)) {
                $shouldInvalidateAnalysis = true;
                break;
            }
        }

        if (isset($data['category_id'])) {
            $category = $entityManager->getRepository(Category::class)->find($data['category_id']);
            if (!$category) {
                return $this->json(['error' => 'Category not found'], 404);
            }
            $session->setCategory($category);
        }

        if (isset($data['title'])) {
            $session->setTitle($data['title']);
        }
        if (array_key_exists('subcategory', $data)) {
            $session->setSubcategery($data['subcategory']);
        }
        if (isset($data['description'])) {
            $session->setDescription($data['description']);
        }
        if (isset($data['date_start'])) {
            $session->setDateStart(new \DateTime($data['date_start']));
        }
        if (isset($data['date_end'])) {
            $session->setDateEnd(new \DateTime($data['date_end']));
        }
        if (isset($data['duration'])) {
            $session->setDuration($data['duration']);
        }
        if (isset($data['data'])) {
            $session->setData($data['data']);
        }
        if (isset($data['notes'])) {
            $session->setNotes($data['notes']);
        }
        if (isset($data['attachment_url'])) {
            $session->setAttachmentUrl($data['attachment_url']);
        }
        if (isset($data['is_public'])) {
            $session->setIsPublic($data['is_public']);
        }

        $session->setUpdatedAt(new \DateTimeImmutable());

        if ($shouldInvalidateAnalysis) {
            $existingAnalysis = $entityManager->getRepository(Analysis::class)->findOneBy(['session' => $session]);
            if ($existingAnalysis) {
                $entityManager->remove($existingAnalysis);
            }
        }

        $entityManager->flush();

        return $this->json($this->serializeSession($session));
    }

    #[Route('/api/sessions/{id}', name: 'app_api_sessions_delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'Access denied'], 403);
        }

        $entityManager->remove($session);
        $entityManager->flush();

        return $this->json(['message' => 'Session deleted successfully']);
    }

    private function serializeSession(Session $session): array
    {
        return [
            'id' => $session->getId(),
            'category_id' => $session->getCategory()->getId(),
            'category_name' => $session->getCategory()->getName(),
            'subcategory' => $session->getSubcategery(),
            'title' => $session->getTitle(),
            'description' => $session->getDescription(),
            'date_start' => $session->getDateStart()->format('Y-m-d H:i:s'),
            'date_end' => $session->getDateEnd()->format('Y-m-d H:i:s'),
            'duration' => $session->getDuration(),
            'data' => $session->getData(),
            'notes' => $session->getNotes(),
            'attachment_url' => $session->getAttachmentUrl(),
            'is_public' => $session->isPublic(),
            'created_at' => $session->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $session->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
