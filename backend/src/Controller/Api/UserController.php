<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UserController extends AbstractController
{
    #[Route('/api/user', name: 'app_api_user_get', methods: ['GET'])]
    public function getProfile(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsernameField(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'language' => $user->getLanguage(),
            'theme_preference' => $user->getThemePreference(),
        ]);
    }

    #[Route('/api/user', name: 'app_api_user_update', methods: ['PUT'])]
    public function updateProfile(
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

        if (isset($data['first_name'])) {
            $user->setFirstName($data['first_name']);
        }

        if (isset($data['last_name'])) {
            $user->setLastName($data['last_name']);
        }

        if (isset($data['language'])) {
            $user->setLanguage($data['language']);
        }

        if (isset($data['theme_preference'])) {
            $user->setThemePreference($data['theme_preference']);
        }

        if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingUser) {
                return $this->json(['error' => 'Email already in use'], 409);
            }
            $user->setEmail($data['email']);
        }

        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['errors' => $errorMessages], 400);
        }

        $user->setUpdatedAt(new \DateTime());
        $entityManager->flush();

        return $this->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsernameField(),
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'language' => $user->getLanguage(),
                'theme_preference' => $user->getThemePreference(),
            ]
        ]);
    }

    #[Route('/api/user/password', name: 'app_api_user_password', methods: ['PUT'])]
    public function updatePassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['current_password'], $data['new_password'])) {
            return $this->json(['error' => 'Missing required fields: current_password, new_password'], 400);
        }

        if (!$passwordHasher->isPasswordValid($user, $data['current_password'])) {
            return $this->json(['error' => 'Current password is incorrect'], 400);
        }

        if (strlen($data['new_password']) < 8) {
            return $this->json(['error' => 'New password must be at least 8 characters'], 400);
        }

        $hashedPassword = $passwordHasher->hashPassword($user, $data['new_password']);
        $user->setPasswordHash($hashedPassword);
        $user->setUpdatedAt(new \DateTime());

        $entityManager->flush();

        return $this->json(['message' => 'Password updated successfully']);
    }

    #[Route('/api/user', name: 'app_api_user_delete', methods: ['DELETE'])]
    public function deleteAccount(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['password'])) {
            return $this->json(['error' => 'Password is required to confirm account deletion'], 400);
        }

        if (!$passwordHasher->isPasswordValid($user, $data['password'])) {
            return $this->json(['error' => 'Incorrect password'], 400);
        }

        $userId = $user->getId();
        $userIdentifier = $user->getUserIdentifier();

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Ordre important : on supprime d'abord ce qui référence Session/Analysis,
            // puis Session/Analysis eux-mêmes, avant de pouvoir supprimer l'utilisateur.
            $entityManager->createQuery('DELETE FROM App\Entity\Notification n WHERE n.user = :userId')
                ->setParameter('userId', $userId)
                ->execute();

            $entityManager->createQuery('DELETE FROM App\Entity\Analysis a WHERE a.user = :userId')
                ->setParameter('userId', $userId)
                ->execute();

            $entityManager->createQuery('DELETE FROM App\Entity\Session s WHERE s.user = :userId')
                ->setParameter('userId', $userId)
                ->execute();

            $entityManager->createQuery('DELETE FROM App\Entity\Integration i WHERE i.user = :userId')
                ->setParameter('userId', $userId)
                ->execute();

            $entityManager->createQuery('DELETE FROM App\Entity\RefreshToken rt WHERE rt.username = :username')
                ->setParameter('username', $userIdentifier)
                ->execute();

            $entityManager->remove($user);
            $entityManager->flush();

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            return $this->json(['error' => 'Failed to delete account: ' . $e->getMessage()], 500);
        }

        return $this->json(['message' => 'Account deleted successfully']);
    }
}
