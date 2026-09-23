<?php

namespace App\Controller\Api;

use App\Entity\Avatar;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UserController extends AbstractController
{
    private const MAX_AVATAR_SIZE = 3 * 1024 * 1024; // 3 Mo
    private const ALLOWED_AVATAR_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    #[Route('/api/user', name: 'app_api_user_get', methods: ['GET'])]
    public function getProfile(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsernameField(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'language' => $user->getLanguage(),
            'theme_preference' => $user->getThemePreference(),
            'fshub_connected' => $user->getFshubToken() !== null,
            'avatar_url' => $user->getAvatar()?->getImageUrl(),
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
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
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

        // fshub_token : chaîne vide = déconnexion (on efface). Volontairement
        // jamais renvoyé en clair dans une réponse JSON (voir getProfile) —
        // seul un statut connecté/non connecté est exposé.
        if (array_key_exists('fshub_token', $data)) {
            $user->setFshubToken($data['fshub_token'] === '' ? null : $data['fshub_token']);
        }

        if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingUser) {
                return $this->json(['error' => 'EMAIL_ALREADY_IN_USE'], 409);
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
                'fshub_connected' => $user->getFshubToken() !== null,
                'avatar_url' => $user->getAvatar()?->getImageUrl(),
            ]
        ]);
    }

    #[Route('/api/user/avatar', name: 'app_api_user_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(
        Request $request,
        EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')] string $projectDir
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        $file = $request->files->get('file');

        if (!$file) {
            return $this->json(['error' => 'AVATAR_MISSING_FILE'], 400);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_AVATAR_EXTENSIONS, true)) {
            return $this->json(['error' => 'AVATAR_INVALID_FORMAT'], 400);
        }

        if ($file->getSize() > self::MAX_AVATAR_SIZE) {
            return $this->json(['error' => 'AVATAR_TOO_LARGE'], 400);
        }

        // Stocké dans public/ (contrairement aux rapports de vol SimBit,
        // volontairement privés) car un avatar doit être directement
        // affichable via une simple balise <img>, sans passer par un
        // endpoint authentifié.
        $uploadDir = $projectDir . '/public/uploads/avatars';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            error_log('Avatar upload: failed to create directory ' . $uploadDir);
            return $this->json(['error' => 'AVATAR_SAVE_FAILED'], 500);
        }

        // Nom de fichier unique par utilisateur + horodatage : évite les
        // collisions et les soucis de cache navigateur sur un remplacement.
        $filename = sprintf('%d_%d.%s', $user->getId(), time(), $extension);

        try {
            $file->move($uploadDir, $filename);
        } catch (\Throwable $e) {
            error_log('Avatar move failed: ' . $e->getMessage());
            return $this->json(['error' => 'AVATAR_SAVE_FAILED'], 500);
        }

        // Nettoyage de l'ancien avatar personnalisé avant d'en créer un
        // nouveau — jamais un avatar prédéfini, potentiellement partagé
        // entre plusieurs utilisateurs (aucune fonctionnalité de partage
        // n'existe pour l'instant, mais on ne le supprime jamais par
        // prudence).
        $oldAvatar = $user->getAvatar();
        if ($oldAvatar !== null && !$oldAvatar->isPredefined()) {
            $oldPath = $projectDir . '/public/' . ltrim((string) parse_url($oldAvatar->getImageUrl(), PHP_URL_PATH), '/');
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            $entityManager->remove($oldAvatar);
        }

        $avatar = new Avatar();
        $avatar->setName('Avatar personnalisé');
        $avatar->setType('custom');
        $avatar->setImageUrl($request->getSchemeAndHttpHost() . '/uploads/avatars/' . $filename);
        $avatar->setIsPredefined(false);

        $entityManager->persist($avatar);
        $user->setAvatar($avatar);
        $user->setUpdatedAt(new \DateTime());
        $entityManager->flush();

        return $this->json([
            'message' => 'Avatar uploaded successfully',
            'avatar_url' => $avatar->getImageUrl(),
        ]);
    }

    #[Route('/api/user/avatar', name: 'app_api_user_avatar_delete', methods: ['DELETE'])]
    public function removeAvatar(
        EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')] string $projectDir
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        $avatar = $user->getAvatar();

        if ($avatar !== null) {
            $user->setAvatar(null);

            if (!$avatar->isPredefined()) {
                $path = $projectDir . '/public/' . ltrim((string) parse_url($avatar->getImageUrl(), PHP_URL_PATH), '/');
                if (is_file($path)) {
                    @unlink($path);
                }
                $entityManager->remove($avatar);
            }

            $user->setUpdatedAt(new \DateTime());
            $entityManager->flush();
        }

        return $this->json(['message' => 'Avatar removed successfully']);
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
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['current_password'], $data['new_password'])) {
            return $this->json(['error' => 'USER_MISSING_PASSWORD_FIELDS'], 400);
        }

        if (!$passwordHasher->isPasswordValid($user, $data['current_password'])) {
            return $this->json(['error' => 'CURRENT_PASSWORD_INCORRECT'], 400);
        }

        if (strlen($data['new_password']) < 8) {
            return $this->json(['error' => 'PASSWORD_TOO_SHORT'], 400);
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
            return $this->json(['error' => 'NOT_AUTHENTICATED'], 401);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['password'])) {
            return $this->json(['error' => 'PASSWORD_REQUIRED_FOR_DELETION'], 400);
        }

        if (!$passwordHasher->isPasswordValid($user, $data['password'])) {
            return $this->json(['error' => 'INCORRECT_PASSWORD'], 400);
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
            error_log('Account deletion failed: ' . $e->getMessage());
            return $this->json(['error' => 'ACCOUNT_DELETION_FAILED'], 500);
        }

        return $this->json(['message' => 'Account deleted successfully']);
    }
}
