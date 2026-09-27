<?php

namespace App\Controller\Api;

use App\Entity\Analysis;
use App\Entity\Category;
use App\Entity\Session;
use App\Entity\User;
use App\Service\Notification\NotificationService;
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
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        $qb = $entityManager->getRepository(Session::class)->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.date_start', 'DESC');

        if ($categoryId = $request->query->get('category_id')) {
            $qb->andWhere('s.category = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        // Sans paramètre "page" : comportement historique inchangé, on renvoie
        // le tableau complet (Dashboard.jsx et Analytics.jsx en dépendent pour
        // calculer leurs statistiques globales, donc on ne casse rien ici).
        $page = $request->query->get('page');
        if ($page === null) {
            $sessions = $qb->getQuery()->getResult();
            $data = array_map(fn(Session $s) => $this->serializeSession($s), $sessions);

            return $this->json($data);
        }

        // Avec "page" : liste paginée + filtres supplémentaires, utilisée par
        // la page "Mes sessions" (Sessions.jsx) pour éviter de charger tout
        // l'historique d'un coup à mesure qu'il grandit.
        $page = max(1, (int) $page);
        $limitParam = $request->query->get('limit');
        $limit = $limitParam !== null ? min(100, max(1, (int) $limitParam)) : 20;

        // Liste des sous-catégories disponibles, calculée à part sur la même
        // portée utilisateur/catégorie mais SANS les filtres subcategory/search
        // ni la pagination : sinon les boutons de filtre changeraient de page
        // en page au lieu de montrer toutes les sous-catégories existantes.
        $subcatQb = $entityManager->getRepository(Session::class)->createQueryBuilder('sc')
            ->select('DISTINCT sc.subcategery')
            ->where('sc.user = :user')
            ->andWhere("sc.subcategery IS NOT NULL AND sc.subcategery != ''")
            ->setParameter('user', $user);

        if ($categoryId) {
            $subcatQb->andWhere('sc.category = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        $availableSubcategories = array_column($subcatQb->getQuery()->getScalarResult(), 'subcategery');

        if ($subcategory = $request->query->get('subcategory')) {
            $qb->andWhere('s.subcategery = :subcategory')
                ->setParameter('subcategory', $subcategory);
        }

        if ($search = $request->query->get('search')) {
            $qb->andWhere('LOWER(s.title) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $search . '%');
        }

        $sortMap = [
            'date_desc' => ['s.date_start', 'DESC'],
            'date_asc' => ['s.date_start', 'ASC'],
            'duration_desc' => ['s.duration', 'DESC'],
            'duration_asc' => ['s.duration', 'ASC'],
            'title_asc' => ['s.title', 'ASC'],
        ];
        $sortKey = $request->query->get('sort', 'date_desc');
        [$sortField, $sortDir] = $sortMap[$sortKey] ?? $sortMap['date_desc'];
        $qb->resetDQLPart('orderBy')->orderBy($sortField, $sortDir);

        $countQb = clone $qb;
        $total = (int) $countQb->resetDQLPart('orderBy')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);

        $sessions = $qb->getQuery()->getResult();
        $data = array_map(fn(Session $s) => $this->serializeSession($s), $sessions);

        return $this->json([
            'sessions' => $data,
            'available_subcategories' => $availableSubcategories,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
        ]);
    }

    #[Route('/api/sessions/{id}', name: 'app_api_sessions_get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
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
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['category_id'], $data['title'], $data['date_start'], $data['date_end'])) {
            return $this->json(['error' => 'SESSION_MISSING_FIELDS'], 400);
        }

        $category = $entityManager->getRepository(Category::class)->find($data['category_id']);
        if (!$category) {
            return $this->json(['error' => 'CATEGORY_NOT_FOUND'], 404);
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
        EntityManagerInterface $entityManager,
        NotificationService $notificationService
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
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
                return $this->json(['error' => 'CATEGORY_NOT_FOUND'], 404);
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

        // Un record personnel n'a de sens que si les métriques d'atterrissage
        // ont pu changer, donc uniquement quand 'data' fait partie de la
        // requête — pas sur un simple renommage de titre par exemple.
        if (isset($data['data'])) {
            $notificationService->checkLandingAchievements($session);
        }

        return $this->json($this->serializeSession($session));
    }

    #[Route('/api/sessions/{id}', name: 'app_api_sessions_delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        // Depuis l'ajout des contraintes FK (migration Version20260913133022),
        // il faut supprimer ce qui référence la session avant la session elle-même,
        // même logique que UserController::deleteAccount().
        $entityManager->createQuery('DELETE FROM App\Entity\Notification n WHERE n.related_session = :session')
            ->setParameter('session', $session)
            ->execute();

        $entityManager->createQuery('DELETE FROM App\Entity\Analysis a WHERE a.session = :session')
            ->setParameter('session', $session)
            ->execute();

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
