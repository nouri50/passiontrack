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
     * Vérifie si le Landing Rate ou le Landing Gforce de cette session bat
     * le meilleur historique du pilote dans la même catégorie, et notifie
     * un record personnel ('achievement') si c'est le cas.
     *
     * Best-effort et silencieux : ne fait rien si la catégorie n'est pas
     * Aviation, si aucune des deux métriques n'est renseignée, ou s'il n'y
     * a pas encore d'historique pour comparer (première session du genre —
     * pas de "record" possible sans référence).
     *
     * Compare contre TOUT l'historique de la catégorie (pas juste les 5
     * dernières sessions comme PromptGenerator::buildHistoryContext(), qui
     * limite volontairement le contexte envoyé à l'IA) : un record doit
     * battre le meilleur de toujours, pas juste la tendance récente.
     *
     * À appeler après chaque écriture de Session::$data susceptible de
     * contenir ces métriques : SessionController::update() (édition
     * manuelle), FSHubController::import(), SessionAttachmentController::
     * upload().
     */
    public function checkLandingAchievements(Session $session): void
    {
        if ($session->getCategory()->getSlug() !== 'aviation') {
            return;
        }

        $data = $session->getData();
        $rate = $this->extractNumericField($data, ['landing rate']);
        $gforce = $this->extractNumericField($data, ['landing gforce']);

        if ($rate === null && $gforce === null) {
            return;
        }

        $previousSessions = $this->entityManager->getRepository(Session::class)
            ->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.category = :category')
            ->andWhere('s.id != :currentId')
            ->setParameter('user', $session->getUser())
            ->setParameter('category', $session->getCategory())
            ->setParameter('currentId', $session->getId())
            ->getQuery()
            ->getResult();

        if (empty($previousSessions)) {
            return;
        }

        $bestRate = null;
        $bestGforce = null;
        foreach ($previousSessions as $previous) {
            $previousData = $previous->getData();

            $previousRate = $this->extractNumericField($previousData, ['landing rate']);
            if ($previousRate !== null && ($bestRate === null || $previousRate > $bestRate)) {
                $bestRate = $previousRate;
            }

            $previousGforce = $this->extractNumericField($previousData, ['landing gforce']);
            if ($previousGforce !== null && ($bestGforce === null || abs($previousGforce) < abs($bestGforce))) {
                $bestGforce = $previousGforce;
            }
        }

        if ($rate !== null && $bestRate !== null && $rate > $bestRate) {
            $this->notify(
                $session->getUser(),
                'achievement',
                'Nouveau record personnel : atterrissage le plus doux',
                sprintf(
                    "Landing Rate de %.0f fpm sur \"%s\", ton meilleur jusqu'ici (ancien record : %.0f fpm).",
                    $rate,
                    $session->getTitle(),
                    $bestRate
                ),
                'both',
                $session,
            );
        }

        if ($gforce !== null && $bestGforce !== null && abs($gforce) < abs($bestGforce)) {
            $this->notify(
                $session->getUser(),
                'achievement',
                'Nouveau record personnel : impact vertical le plus doux',
                sprintf(
                    "Landing Gforce de %.2f G sur \"%s\", ton meilleur jusqu'ici (ancien record : %.2f G).",
                    abs($gforce),
                    $session->getTitle(),
                    abs($bestGforce)
                ),
                'both',
                $session,
            );
        }
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace([' ', "'", '_', '-'], '', $key));
    }

    private function extractNumericField(array $data, array $candidateKeys): ?float
    {
        foreach ($data as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);
            foreach ($candidateKeys as $candidate) {
                if ($normalizedKey === $this->normalizeKey($candidate) && is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return null;
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
