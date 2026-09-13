<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordResetController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'FRONTEND_URL')] private readonly string $frontendUrl,
        #[Autowire(env: 'MAILER_FROM_ADDRESS')] private readonly string $mailerFromAddress,
    ) {}

    #[Route('/api/forgot-password', name: 'app_api_forgot_password', methods: ['POST'])]
    public function forgotPassword(
        Request $request,
        EntityManagerInterface $entityManager,
        MailerInterface $mailer
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'])) {
            return $this->json(['error' => 'FORGOT_PASSWORD_MISSING_EMAIL'], 400);
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $user->setResetToken($token);
            $user->setResetTokenExpiresAt((new \DateTime())->modify('+1 hour'));
            $entityManager->flush();

            // NOTE — compromis assumé pour la phase de dev/usage perso :
            // on remonte ici un échec d'envoi réel au client, ce qui revient à
            // révéler que le compte existe (fuite d'énumération de comptes).
            // C'est acceptable tant qu'il n'y a pas d'utilisateurs tiers à
            // protéger. Si PassionTrack s'ouvre un jour à d'autres personnes,
            // repasser ce bloc dans un try/catch silencieux (voir historique
            // Git) pour ne plus jamais révéler d'échec technique ici.
            try {
                $this->sendResetEmail($mailer, $user, $token);
            } catch (\Throwable $e) {
                error_log('Password reset email failed to send: ' . $e->getMessage());
                return $this->json(['error' => 'EMAIL_SEND_FAILED'], 502);
            }
        }

        return $this->json(['message' => 'If an account exists for this email, a reset link has been sent.']);
    }

    #[Route('/api/reset-password', name: 'app_api_reset_password', methods: ['POST'])]
    public function resetPassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['token'], $data['new_password'])) {
            return $this->json(['error' => 'RESET_PASSWORD_MISSING_FIELDS'], 400);
        }

        if (strlen($data['new_password']) < 8) {
            return $this->json(['error' => 'PASSWORD_TOO_SHORT'], 400);
        }

        $user = $entityManager->getRepository(User::class)->findOneBy(['resetToken' => $data['token']]);

        if (!$user || !$user->getResetTokenExpiresAt() || $user->getResetTokenExpiresAt() < new \DateTime()) {
            return $this->json(['error' => 'INVALID_OR_EXPIRED_RESET_TOKEN'], 400);
        }

        $hashedPassword = $passwordHasher->hashPassword($user, $data['new_password']);
        $user->setPasswordHash($hashedPassword);
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $user->setUpdatedAt(new \DateTime());

        $entityManager->flush();

        return $this->json(['message' => 'Password reset successfully']);
    }

    private function sendResetEmail(MailerInterface $mailer, User $user, string $token): void
    {
        $resetLink = rtrim($this->frontendUrl, '/') . '/reset-password?token=' . $token;
        $isFrench = $user->getLanguage() !== 'en';

        if ($isFrench) {
            $subject = 'Réinitialisation de ton mot de passe PassionTrack';
            $html = '<p>Bonjour,</p>'
                . '<p>Tu as demandé la réinitialisation de ton mot de passe PassionTrack.</p>'
                . '<p><a href="' . htmlspecialchars($resetLink) . '">Clique ici pour choisir un nouveau mot de passe</a></p>'
                . '<p>Ce lien expire dans 1 heure. Si tu n\'es pas à l\'origine de cette demande, ignore simplement cet email.</p>';
        } else {
            $subject = 'Reset your PassionTrack password';
            $html = '<p>Hello,</p>'
                . '<p>You requested a password reset for your PassionTrack account.</p>'
                . '<p><a href="' . htmlspecialchars($resetLink) . '">Click here to choose a new password</a></p>'
                . '<p>This link expires in 1 hour. If you did not request this, you can safely ignore this email.</p>';
        }

        $email = (new Email())
            ->from($this->mailerFromAddress)
            ->to($user->getEmail())
            ->subject($subject)
            ->html($html);

        $mailer->send($email);
    }
}
