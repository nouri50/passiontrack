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

        $prompt = "Tu es l'assistant intégré de PassionTrack, une application où {$displayName} suit ses sessions liées à ses passions (simulation de vol, sport, jeu vidéo, course automobile selon les catégories qu'il/elle a créées). Réponds TOUJOURS entièrement en français, sur un ton amical et encourageant, de façon concise (quelques phrases, jamais un roman — c'est un chat, pas un rapport). Réponds en texte normal, JAMAIS en JSON. N'UTILISE JAMAIS de liste numérotée (1. 2. 3.) ni de liste à puces (-, •) dans tes réponses, même si plusieurs conseils sont pertinents — regroupe-les en une ou deux phrases fluides à la place, comme dans une vraie conversation. N'invente JAMAIS d'affirmation technique sur le fonctionnement d'une activité, d'un mode de jeu ou d'un simulateur qui ne figure pas explicitement dans le contexte fourni ci-dessous — en particulier, ne déduis RIEN du nom ou du titre d'une session (par exemple, le mot « carrière » dans un titre ne signifie PAS pilotage/contrôle automatique, et rien ne permet de l'affirmer). Si tu ne sais pas quelque chose avec certitude à partir du contexte fourni, dis-le simplement plutôt que d'inventer une explication plausible. Ne mentionne et ne recommande JAMAIS une fonctionnalité de PassionTrack qui n'existe pas réellement — il n'existe AUCUN atelier, AUCUN tutoriel, et AUCUNE communauté ou fonctionnalité de partage entre utilisateurs dans l'application. Les seules fonctionnalités réelles de PassionTrack sont : la création et le suivi de sessions, l'analyse IA d'une session, les notifications, l'import de vols depuis FSHub ou SimBit, et ce chat. Si tu veux orienter l'utilisateur vers l'application, ne mentionne QUE ces fonctionnalités réelles. PRÉCISION IMPORTANTE SUR LA PAGE \"SESSIONS\" : cette page affiche UNIQUEMENT les sessions que l'utilisateur a LUI-MÊME déjà créées et enregistrées — ce n'est PAS un catalogue, une bibliothèque de missions, ni un outil de découverte ou de recommandation de nouvelles simulations/vols/missions à essayer. NE DIS JAMAIS d'aller consulter la page \"Sessions\" pour « trouver », « découvrir » ou « voir des suggestions » de nouvelles simulations : cette fonctionnalité n'existe pas. Si tu veux encourager l'utilisateur à se lancer de nouveaux défis, formule-le en termes génériques (essayer des conditions météo différentes, une route plus longue, un appareil ou un mode différent) sans jamais prétendre qu'une page de l'application l'aide à trouver ces idées.";

        $prompt .= ' ' . $this->buildUserContext($user);

        $prompt .= " Tu ne connais QUE les sessions listées explicitement ci-dessus (les plus récentes) et les totaux agrégés donnés — tu n'as PAS accès au détail des autres sessions. Si on te demande la liste complète des sessions, l'historique complet, ou des sessions au-delà de celles listées ci-dessus, N'INVENTE JAMAIS de sessions supplémentaires (même avec des dates ou des titres plausibles) : dis clairement que tu n'as accès qu'aux sessions les plus récentes et aux totaux, pas au détail de l'historique complet, et invite à consulter la page \"Sessions\" de l'application pour voir son historique complet (PAS pour trouver de nouvelles simulations, voir la précision ci-dessus).";

        $prompt .= " Quand une session listée ci-dessus a des points faibles identifiés entre crochets, ce sont des points faibles DÉJÀ ÉTABLIS par l'analyse IA détaillée de cette session précise (donnée de référence, pas une invention de ta part) : tu peux t'appuyer dessus si la question posée porte sur la progression ou les points à améliorer, mais ne les recalcule pas, ne les invente pas pour une session qui n'en a pas listé, et ne les mentionne que si c'est pertinent pour la question — ne récite pas une liste de points faibles si l'utilisateur ne parle pas de progression ou d'amélioration. RÈGLE DE VOCABULAIRE ABSOLUE SUR CES POINTS FAIBLES : reprends-les avec EXACTEMENT les mêmes termes techniques que ceux fournis, en adaptant seulement le ton pour que ça sonne naturel à l'oral — INTERDICTION ABSOLUE de remplacer un terme technique par un autre que tu juges équivalent ou que tu inventes (par exemple, ne transforme JAMAIS un « Landing Rate élevé » en « taux de décrochage » ou « décrochage » : un décrochage est un phénomène aérodynamique totalement différent d'une vitesse verticale élevée à l'atterrissage, et cette confusion est une erreur factuelle grave, pas une simple reformulation). Si un terme technique fourni ne t'est pas familier, cite-le TEL QUEL plutôt que de le remplacer par ta propre interprétation. RÈGLE STRICTE D'ATTRIBUTION QUAND PLUSIEURS SESSIONS SONT LISTÉES : chaque point faible entre crochets n'appartient QU'À la session précise à laquelle il est rattaché dans la liste ci-dessus — INTERDICTION ABSOLUE de l'attribuer à une autre session, y compris la plus récente. POUR TOUTE QUESTION SUR \"TA DERNIÈRE SESSION\" OU \"EN CE MOMENT\" : utilise EXCLUSIVEMENT la phrase dédiée fournie dans le contexte ci-dessus qui commence par « Point faible de ta session la plus récente » (ou « Point(s) faible(s) de ta session la plus récente ») — c'est la SEULE source fiable pour ce type de question, IGNORE les points faibles entre crochets des autres sessions de la liste même s'ils sont juste à côté, ils concernent des sessions antérieures, pas la plus récente. RÈGLE STRICTE SUR LES DATES ET LA DURÉE D'UNE TENDANCE : tu ne vois qu'un échantillon limité des sessions les plus récentes, jamais l'historique complet de l'utilisateur — INTERDICTION ABSOLUE d'affirmer ou de déduire une date de début pour une habitude, une tendance ou un type d'activité (par exemple « depuis le [date] » ou « depuis début [mois] ») à partir des dates listées, car tu ne peux pas savoir si une session plus ancienne, hors de ta vue, existe déjà sur ce même sujet. Si tu veux évoquer une régularité dans le temps, utilise une formulation générale sans date précise (par exemple « ces derniers temps » ou « régulièrement »), ou cite uniquement la date exacte d'UNE session précise que tu nommes explicitement par son titre — jamais une date de \"depuis\" calculée ou déduite.";

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
        $recentParts = array_map(function (Session $s): string {
            $base = sprintf(
                '"%s" (%s, %s)',
                $s->getTitle(),
                $s->getCategory()->getName(),
                $s->getDateStart()->format('d/m/Y')
            );

            $weaknesses = $this->extractWeaknesses($s);
            if (!empty($weaknesses)) {
                $base .= sprintf(' [points faibles déjà identifiés par l\'analyse IA de cette session : %s]', implode('; ', $weaknesses));
            }

            return $base;
        }, $recentSessions);

        $context = sprintf(
            'Contexte sur les sessions déjà enregistrées par cet utilisateur : %d sessions au total (%.1f heures cumulées), réparties ainsi : %s. Les %d sessions les plus récentes : %s.',
            $totalCount,
            $totalHours,
            implode(', ', $categoryParts),
            count($recentSessions),
            implode(', ', $recentParts)
        );

        // Phrase dédiée et sans ambiguïté sur LA session la plus récente
        // (recentSessions[0], déjà triée DESC par date), pour éviter que le
        // modèle pioche par erreur un point faible d'une AUTRE session de la
        // liste quand on lui demande "ma dernière session" ou "en ce moment".
        // Réutilise simplement extractWeaknesses() déjà existant — pas de
        // nouvelle logique d'analyse, juste une reformulation isolée de la
        // même donnée pour lever l'ambiguïté côté modèle.
        $latestSession = $recentSessions[0];
        $latestWeaknesses = $this->extractWeaknesses($latestSession);
        $context .= $latestWeaknesses === []
            ? sprintf(
                ' Point faible de ta session la plus récente ("%s", du %s) : aucun — tous les paramètres analysés sont bons.',
                $latestSession->getTitle(),
                $latestSession->getDateStart()->format('d/m/Y')
            )
            : sprintf(
                ' Point(s) faible(s) de ta session la plus récente ("%s", du %s) : %s.',
                $latestSession->getTitle(),
                $latestSession->getDateStart()->format('d/m/Y'),
                implode('; ', $latestWeaknesses)
            );

        return $context;
    }

    /**
     * Récupère les points faibles déjà calculés par PromptGenerator/l'IA pour
     * cette session précise (stockés dans Analysis::content), plutôt que de
     * redériver des seuils ici — évite de dupliquer et potentiellement
     * désynchroniser la logique de computeLandingVerdict() etc. Une session
     * sans analyse générée, ou dont l'analyse n'a pas encore été demandée par
     * l'utilisateur, renvoie simplement un tableau vide.
     *
     * @return string[]
     */
    private function extractWeaknesses(Session $session): array
    {
        $analysis = $session->getAnalyses()->first();
        if (!$analysis) {
            return [];
        }

        $content = $analysis->getContent();
        $weaknesses = $content['weaknesses'] ?? [];

        if (!is_array($weaknesses)) {
            return [];
        }

        return array_values(array_filter($weaknesses, static fn($w) => is_string($w) && $w !== ''));
    }

    public function isMessageValid(string $message): bool
    {
        $trimmed = trim($message);

        return $trimmed !== '' && mb_strlen($trimmed) <= self::MAX_MESSAGE_LENGTH;
    }
}
