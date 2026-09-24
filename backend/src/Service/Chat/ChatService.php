<?php

namespace App\Service\Chat;

use App\Entity\Session;
use App\Entity\User;
use App\Service\AI\AIProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point central du mini chat IA. Contrairement à PromptGenerator (dédié à
 * l'analyse détaillée d'UNE session, avec ses règles par catégorie), ce
 * service construit un contexte global et léger sur TOUTES les sessions
 * de l'utilisateur — juste assez pour que l'assistant réponde à des
 * questions générales ("comment je progresse ?", "sur quoi je devrais
 * m'améliorer ?"), pas une analyse exhaustive.
 *
 * Pas de persistance de conversation en base : l'historique est fourni
 * par l'appelant à chaque requête (le frontend le garde en mémoire tant
 * que la fenêtre de chat reste ouverte) et sert uniquement à construire
 * le prompt de CET appel — rien n'est stocké côté serveur.
 */
class ChatService
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const MAX_HISTORY_TURNS = 10;
    private const RECENT_SESSIONS_LIMIT = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AIProviderInterface $aiProvider,
    ) {}

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    public function ask(User $user, string $message, array $history = []): string
    {
        $prompt = $this->buildPrompt($user, $message, $history);

        return $this->aiProvider->chat($prompt);
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     */
    private function buildPrompt(User $user, string $message, array $history): string
    {
        $displayName = $user->getFirstName() ?: $user->getUsernameField();

        $prompt = "Tu es l'assistant intégré de PassionTrack, une application où {$displayName} suit ses sessions liées à ses passions (simulation de vol, sport, jeu vidéo, course automobile selon les catégories qu'il/elle a créées). Réponds TOUJOURS entièrement en français, sur un ton amical et encourageant, de façon concise (quelques phrases, jamais un roman — c'est un chat, pas un rapport). Réponds en texte normal, JAMAIS en JSON ni en liste à puces systématique. N'invente JAMAIS d'affirmation technique sur le fonctionnement d'une activité, d'un mode de jeu ou d'un simulateur qui ne figure pas explicitement dans le contexte fourni ci-dessous — en particulier, ne déduis RIEN du nom ou du titre d'une session (par exemple, le mot « carrière » dans un titre ne signifie PAS pilotage/contrôle automatique, et rien ne permet de l'affirmer). Si tu ne sais pas quelque chose avec certitude à partir du contexte fourni, dis-le simplement plutôt que d'inventer une explication plausible.";

        $prompt .= ' ' . $this->buildUserContext($user);

        $prompt .= " Tu ne connais QUE les sessions listées explicitement ci-dessus (les plus récentes) et les totaux agrégés donnés — tu n'as PAS accès au détail des autres sessions. Si on te demande la liste complète des sessions, l'historique complet, ou des sessions au-delà de celles listées ci-dessus, N'INVENTE JAMAIS de sessions supplémentaires (même avec des dates ou des titres plausibles) : dis clairement que tu n'as accès qu'aux sessions les plus récentes et aux totaux, pas au détail de l'historique complet, et invite à consulter la page \"Sessions\" de l'application pour ça.";

        if (!empty($history)) {
            $recentHistory = array_slice($history, -self::MAX_HISTORY_TURNS);
            $prompt .= ' Historique récent de cette conversation (du plus ancien au plus récent) : ';
            foreach ($recentHistory as $turn) {
                $role = ($turn['role'] ?? '') === 'assistant' ? 'Toi' : 'Utilisateur';
                $content = (string) ($turn['content'] ?? '');
                $prompt .= "{$role} : \"{$content}\" ";
            }
        }

        $prompt .= " Nouveau message de {$displayName} : \"{$message}\". Réponds directement à ce message, en t'appuyant sur le contexte ci-dessus uniquement si c'est pertinent pour la question posée — ne récite pas les statistiques si l'utilisateur ne parle pas de sa progression.";

        return $prompt;
    }

    private function buildUserContext(User $user): string
    {
        $sessions = $this->entityManager->getRepository(Session::class)
            ->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.date_start', 'DESC')
            ->getQuery()
            ->getResult();

        if (empty($sessions)) {
            return "Contexte : l'utilisateur n'a encore enregistré aucune session dans l'application.";
        }

        $totalCount = count($sessions);
        $totalSeconds = array_sum(array_map(
            static fn(Session $s) => $s->getDuration() ?? 0,
            $sessions
        ));
        $totalHours = $totalSeconds / 3600;

        $byCategory = [];
        foreach ($sessions as $s) {
            $name = $s->getCategory()->getName();
            $byCategory[$name] = ($byCategory[$name] ?? 0) + 1;
        }
        $categoryParts = [];
        foreach ($byCategory as $name => $count) {
            $categoryParts[] = "{$count} en {$name}";
        }

        $recentSessions = array_slice($sessions, 0, self::RECENT_SESSIONS_LIMIT);
        $recentParts = array_map(static function (Session $s): string {
            return sprintf(
                '"%s" (%s, %s)',
                $s->getTitle(),
                $s->getCategory()->getName(),
                $s->getDateStart()->format('d/m/Y')
            );
        }, $recentSessions);

        return sprintf(
            'Contexte sur les sessions déjà enregistrées par cet utilisateur : %d sessions au total (%.1f heures cumulées), réparties ainsi : %s. Les %d sessions les plus récentes : %s.',
            $totalCount,
            $totalHours,
            implode(', ', $categoryParts),
            count($recentSessions),
            implode(', ', $recentParts)
        );
    }

    public function isMessageValid(string $message): bool
    {
        $trimmed = trim($message);

        return $trimmed !== '' && mb_strlen($trimmed) <= self::MAX_MESSAGE_LENGTH;
    }
}
