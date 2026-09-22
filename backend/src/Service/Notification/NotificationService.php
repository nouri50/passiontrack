<?php

namespace App\Service\Notification;

use App\Entity\Analysis;
use App\Entity\Notification;
use App\Entity\Session;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Point central de création des notifications PassionTrack.
 *
 * Persiste toujours la notification en base (visible dans la cloche /
 * page Notifications, quel que soit le canal demandé). L'envoi d'email
 * est une couche additionnelle, en best-effort : un échec d'envoi ne
 * doit JAMAIS faire échouer l'action qui a déclenché la notification
 * (analyse IA, sauvegarde de session...), donc toute exception mailer
 * est attrapée et journalisée, jamais propagée.
 *
 * Types actuellement utilisés par le frontend (voir Notifications.jsx,
 * NOTIF_ICONS) : 'ai_insight', 'progress_alert', 'achievement',
 * 'system_alert'. Le champ channel accepte 'in_app' (base uniquement),
 * 'email' (base + email), ou 'both' — actuellement toujours 'both' en
 * pratique côté appelants, pour tester la remontée réelle par email.
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        #[Autowire('%env(default::MAILER_FROM_ADDRESS)%')] private readonly ?string $fromAddress,
    ) {}

    public function notify(
        User $user,
        string $type,
        string $title,
        string $message,
        string $channel = 'both',
        ?Session $relatedSession = null,
        ?Analysis $relatedAnalysis = null,
    ): Notification {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setRelatedSession($relatedSession);
        $notification->setRelatedAnalysis($relatedAnalysis);
        $notification->setIsRead(false);
        $notification->setChannel($channel);
        $notification->setSetAt(new \DateTimeImmutable());

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        if (in_array($channel, ['email', 'both'], true)) {
            $this->sendEmail($user, $title, $message);
        }

        return $notification;
    }

    /**
     * Best-effort : ne lève jamais. Un problème d'envoi (DSN mal
     * configuré, Gmail qui rejette, pas d'email sur le compte...) ne
     * doit pas empêcher la notification d'exister en base.
     */
    private function sendEmail(User $user, string $subject, string $message): void
    {
        $to = $user->getEmail();

        if (!$to || !$this->fromAddress) {
            if (!$this->fromAddress) {
                error_log('Notification email skipped: MAILER_FROM_ADDRESS non configuré.');
            }
            return;
        }

        try {
            $email = (new Email())
                ->from($this->fromAddress)
                ->to($to)
                ->subject('[PassionTrack] ' . $subject)
                ->text($message)
                ->html($this->buildHtmlBody($subject, $message));

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            error_log('Notification email failed: ' . $e->getMessage());
        }
    }

    /**
     * Corps HTML minimal, volontairement sans dépendance à un moteur de
     * template (pas de Twig dans ce backend API-only) — juste du HTML
     * inline suffisant pour un email lisible.
     */
    private function buildHtmlBody(string $subject, string $message): string
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
            <div style="font-family: system-ui, sans-serif; max-width: 480px; margin: 0 auto; padding: 24px; background: #14111f; color: #f5f5f5; border-radius: 12px;">
                <p style="color: #a3a3a3; font-size: 12px; letter-spacing: 0.08em; text-transform: uppercase; margin: 0 0 12px;">PassionTrack</p>
                <h2 style="margin: 0 0 16px; font-size: 18px;">{$safeSubject}</h2>
                <p style="line-height: 1.6; margin: 0;">{$safeMessage}</p>
            </div>
            HTML;
    }
}
