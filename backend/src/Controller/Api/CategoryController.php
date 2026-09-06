<?php

namespace App\Controller\Api;

use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    #[Route('/api/categories', name: 'app_api_categories_list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        $categories = $entityManager->getRepository(Category::class)->findBy(['is_active' => true]);

        $data = array_map(fn(Category $c) => $this->serializeCategory($c), $categories);

        return $this->json($data);
    }

    #[Route('/api/categories/{id}', name: 'app_api_categories_get', methods: ['GET'])]
    public function get(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $category = $entityManager->getRepository(Category::class)->find($id);

        if (!$category) {
            return $this->json(['error' => 'Category not found'], 404);
        }

        return $this->json($this->serializeCategory($category));
    }

    #[Route('/api/categories', name: 'app_api_categories_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['name'], $data['slug'])) {
            return $this->json(['error' => 'Missing required fields: name, slug'], 400);
        }

        $existing = $entityManager->getRepository(Category::class)->findOneBy(['slug' => $data['slug']]);
        if ($existing) {
            return $this->json(['error' => 'A category with this slug already exists'], 409);
        }

        $category = new Category();
        $category->setName($data['name']);
        $category->setSlug($data['slug']);
        $category->setDescription($data['description'] ?? null);
        $category->setIconUrl($data['icon_url'] ?? null);
        $category->setColorHex($data['color_hex'] ?? '#667eea');
        $category->setMetadata($data['metadata'] ?? []);
        $category->setIsActive($data['is_active'] ?? true);
        $category->setCreatedAt(new \DateTimeImmutable());
        $category->setUpdatedAt(new \DateTime());

        $entityManager->persist($category);
        $entityManager->flush();

        return $this->json($this->serializeCategory($category), 201);
    }

    #[Route('/api/categories/{id}', name: 'app_api_categories_update', methods: ['PUT'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $category = $entityManager->getRepository(Category::class)->find($id);

        if (!$category) {
            return $this->json(['error' => 'Category not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $category->setName($data['name']);
        }
        if (isset($data['slug']) && $data['slug'] !== $category->getSlug()) {
            $existing = $entityManager->getRepository(Category::class)->findOneBy(['slug' => $data['slug']]);
            if ($existing) {
                return $this->json(['error' => 'A category with this slug already exists'], 409);
            }
            $category->setSlug($data['slug']);
        }
        if (array_key_exists('description', $data)) {
            $category->setDescription($data['description']);
        }
        if (array_key_exists('icon_url', $data)) {
            $category->setIconUrl($data['icon_url']);
        }
        if (isset($data['color_hex'])) {
            $category->setColorHex($data['color_hex']);
        }
        if (isset($data['metadata'])) {
            $category->setMetadata($data['metadata']);
        }
        if (isset($data['is_active'])) {
            $category->setIsActive($data['is_active']);
        }

        $category->setUpdatedAt(new \DateTime());

        $entityManager->flush();

        return $this->json($this->serializeCategory($category));
    }

    #[Route('/api/categories/{id}', name: 'app_api_categories_delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $category = $entityManager->getRepository(Category::class)->find($id);

        if (!$category) {
            return $this->json(['error' => 'Category not found'], 404);
        }

        // Soft delete : on désactive plutôt que de supprimer (évite de casser les sessions existantes)
        $category->setIsActive(false);
        $category->setUpdatedAt(new \DateTime());

        $entityManager->flush();

        return $this->json(['message' => 'Category deactivated successfully']);
    }

    private function serializeCategory(Category $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'description' => $category->getDescription(),
            'icon_url' => $category->getIconUrl(),
            'color_hex' => $category->getColorHex(),
            'metadata' => $category->getMetadata(),
            'is_active' => $category->isActive(),
        ];
    }
}
