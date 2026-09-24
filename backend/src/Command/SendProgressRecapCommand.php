<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\Session;
use App\Entity\User;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie un récapitulatif périodique d'activité (type de notification
 * 'progress_alert') à chaque utilisateur ayant eu au moins une session
 * sur la période. Volontairement déclenchée à la main pour l'instant
 * (pas de vrai cron en prod, juste le PC local) :
 *
 *   php bin/console app:notifications:progress-recap --period=weekly
 *   php bin/console app:notifications:progress-recap --period=monthly
 *
 * Aucune activité sur la période = aucune notification pour cet
 * utilisateur (pas de récap vide qui ne dit rien d'utile).
 *
 * Garde-fou anti-doublon : si un récap du même type a déjà été envoyé à
 * cet utilisateur DANS la fenêtre de la période, on ne renvoie rien —
 * utile puisque la commande est lancée manuellement et peut être
 * relancée par erreur le même jour.
 */
#[AsCommand(
    name: 'app:notifications:progress-recap',
    description: "Envoie un récap d'activité périodique (hebdo ou mensuel) à chaque utilisateur",
)]
class SendProgressRecapCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'period',
            null,
            InputOption::VALUE_REQUIRED,
            'weekly ou monthly',
            'weekly'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $period = $input->getOption('period');

        if (!in_array($period, ['weekly', 'monthly'], true)) {
            $io->error(sprintf('Période invalide : "%s". Utilise --period=weekly ou --period=monthly.', $period));

            return Command::FAILURE;
        }

        $since = $period === 'weekly'
            ? new \DateTimeImmutable('-7 days')
            : new \DateTimeImmutable('-1 month');

        $periodLabel = $period === 'weekly' ? 'cette semaine' : 'ce mois-ci';
        $title = $period === 'weekly' ? 'Récap de la semaine' : 'Récap du mois';

        $users = $this->entityManager->getRepository(User::class)->findAll();
        $sentCount = 0;
        $skippedCount = 0;

        foreach ($users as $user) {
            $alreadySent = $this->entityManager->getRepository(Notification::class)
                ->createQueryBuilder('n')
                ->where('n.user = :user')
                ->andWhere('n.type = :type')
                ->andWhere('n.title = :title')
                ->andWhere('n.set_at >= :since')
                ->setParameter('user', $user)
                ->setParameter('type', 'progress_alert')
                ->setParameter('title', $title)
                ->setParameter('since', $since)
                ->getQuery()
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($alreadySent !== null) {
                $io->writeln(sprintf('  - %s : récap déjà envoyé sur cette période, ignoré.', $user->getEmail()));
                $skippedCount++;
                continue;
            }

            $sessions = $this->entityManager->getRepository(Session::class)
                ->createQueryBuilder('s')
                ->where('s.user = :user')
                ->andWhere('s.date_start >= :since')
                ->setParameter('user', $user)
                ->setParameter('since', $since)
                ->getQuery()
                ->getResult();

            if (empty($sessions)) {
                continue;
            }

            $sessionCount = count($sessions);
            $totalSeconds = array_sum(array_map(
                static fn(Session $s) => $s->getDuration() ?? 0,
                $sessions
            ));
            $totalHours = $totalSeconds / 3600;

            $byCategory = [];
            foreach ($sessions as $s) {
                $categoryName = $s->getCategory()->getName();
                $byCategory[$categoryName] = ($byCategory[$categoryName] ?? 0) + 1;
            }

            $categoryParts = [];
            foreach ($byCategory as $name => $count) {
                $categoryParts[] = sprintf('%d en %s', $count, $name);
            }

            $message = sprintf(
                "%d session%s %s (%s), pour un total de %.1f heure%s.",
                $sessionCount,
                $sessionCount > 1 ? 's' : '',
                $periodLabel,
                implode(', ', $categoryParts),
                $totalHours,
                $totalHours > 1 ? 's' : ''
            );

            $this->notificationService->notify(
                $user,
                'progress_alert',
                $title,
                $message,
                'both',
            );

            $io->writeln(sprintf('  - %s : récap envoyé (%d session%s).', $user->getEmail(), $sessionCount, $sessionCount > 1 ? 's' : ''));
            $sentCount++;
        }

        $io->success(sprintf(
            '%d récap(s) %s envoyé(s), %d ignoré(s) (déjà fait), sur %d utilisateur(s) au total.',
            $sentCount,
            $period,
            $skippedCount,
            count($users)
        ));

        return Command::SUCCESS;
    }
}
