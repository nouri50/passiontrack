<?php

namespace App\Service\AI;

use App\Entity\Session;
use Doctrine\ORM\EntityManagerInterface;

class PromptGenerator
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function generate(Session $session): string
    {
        $category = $session->getCategory()->getSlug();
        $data = $session->getData();
        $dataJson = json_encode($data);
        $duration = $session->getDuration();
        $title = $session->getTitle();
        $notes = $session->getNotes();

        $historyContext = $this->buildHistoryContext($session, $category);
        $technicalIncident = $this->getTechnicalIncident($data);
        $referenceContext = $this->buildReferenceContext($category, $data, $technicalIncident);

        $basePrompt = match ($category) {
            'aviation' => "Tu es un instructeur de simulation de vol. Analyse cette session de vol : titre \"{$title}\", durée de vol réelle {$duration}s, données détaillées: {$dataJson}.",
            'racing' => "Tu es un coach de course automobile. Analyse cette session de course : titre \"{$title}\", durée {$duration}s, données: {$dataJson}.",
            'fitness' => "Tu es un coach sportif. Analyse cette séance d'entraînement : titre \"{$title}\", durée {$duration}s, données: {$dataJson}.",
            'gaming' => "Tu es un coach esport. Analyse cette session de jeu : titre \"{$title}\", durée {$duration}s, données: {$dataJson}.",
            default => "Analyse cette session d'activité : titre \"{$title}\", durée {$duration}s, données: {$dataJson}.",
        };

        if ($notes) {
            $basePrompt .= " Notes de l'utilisateur sur cette session : \"{$notes}\".";
        }

        if ($referenceContext) {
            $basePrompt .= " " . $referenceContext;
        }

        if ($historyContext) {
            $basePrompt .= " " . $historyContext;
        }

        if ($technicalIncident) {
            $basePrompt .= " INCIDENT TECHNIQUE DÉCLARÉ POUR CETTE SESSION (PRIORITÉ ABSOLUE SUR TOUTE AUTRE INTERPRÉTATION, Y COMPRIS LES NOTES LIBRES CI-DESSUS) : \"{$technicalIncident}\". Toute dégradation de performance mesurée sur cette session précise (score inférieur à l'habitude, dépassements de paramètres de vol comme pitch/bank/G-force/overspeed, corrections brusques, atterrissage moins propre) DOIT être attribuée à cet incident technique déclaré et NE DOIT JAMAIS être présentée comme un manque de compétence de pilotage. N'inclus AUCUN conseil visant à améliorer la technique de pilotage en te basant sur les métriques dégradées de cette session. Si tu donnes un conseil lié à cet incident, oriente-le exclusivement vers l'aspect technique/matériel (stabilité du simulateur, ressources système), jamais vers le pilotage.";
        }

        if ($notes) {
            $basePrompt .= " RAPPEL CRITIQUE SUR LES NOTES DE L'UTILISATEUR : « {$notes} ». ";
            $basePrompt .= "Règles d'interprétation strictes : ";
            $basePrompt .= "1. Si les notes confirment l'absence de problème technique ou matériel : INTERDICTION ABSOLUE de mentionner la maintenance, le matériel, la configuration système ou les mises à jour dans 'tips' ou ailleurs. Les conseils ('tips') doivent porter EXCLUSIVEMENT sur des techniques de pilotage ou des défis d'entraînement (ex : gestion du vent traversier, approches aux instruments, vols de nuit). ";
            $basePrompt .= "2. Si et SEULEMENT SI les notes décrivent une panne ou un bug réel : concentre les conseils sur ce souci technique précis sans impacter la note de pilotage.";
        }

        $basePrompt .= " Consigne de cohérence : si le score de la session est de 100 (ou la note maximale), n'invente pas de points faibles artificiels — laisse 'weaknesses' vide ([]) ou indique 'Aucun point faible majeur identifié'. N'invente jamais de point faible générique du type « données non fournies » ou « informations manquantes » : l'absence de donnée n'est pas une faiblesse de pilotage. N'utilise aucun terme technique qui n'existe pas dans le vocabulaire aéronautique standard (par exemple, n'invente pas un terme comme « raccords d'altitude »). Vérifie aussi la cohérence physique de tes conseils avant de les donner : ne suggère jamais une action qui aggraverait le problème identifié (par exemple, ne recommande pas d'augmenter la vitesse ou la pente de descente pour réduire un Landing Rate déjà élevé, puisque cela l'empirerait — la bonne recommandation serait de réduire la vitesse verticale plus tôt dans l'approche). N'invente jamais de référence à un système, bouton ou sélecteur de cockpit précis (par exemple un sélecteur de limite d'inclinaison) sans certitude qu'il existe réellement sur l'appareil piloté — les architectures de protection de vol diffèrent significativement d'un constructeur à l'autre (Airbus vs Boeing notamment), et même entre appareils d'un même constructeur. Si un conseil sur la gestion d'un paramètre (inclinaison, vitesse, assiette...) est pertinent mais que tu n'es pas certain du système exact disponible sur cet appareil, formule-le en termes génériques de pilotage (par exemple « rester attentif à l'inclinaison lors des virages automatiques serrés ») plutôt que de citer un instrument ou un contrôle spécifique que tu ne peux pas garantir. Si un verdict officiel a été fourni pour le Landing Rate ou le Landing Gforce (par exemple « bon (atterrissage doux) »), ce verdict fait autorité sur l'intégralité de ta réponse — ne le contredis JAMAIS par une caractérisation contraire ailleurs, y compris dans le résumé ('summary') ou les conseils ('tips') : par exemple, ne dis jamais qu'une vitesse verticale est « élevée » ou « à surveiller » si le verdict officiel fourni est « bon ». Le chiffre brut du Landing Rate ou du Landing Gforce peut apparaître ailleurs dans les données de la session (JSON) : ignore ta propre impression sur ce chiffre brut et fie-toi exclusivement au verdict officiel calculé, qui seul fait foi.";
        return $basePrompt . " Réponds UNIQUEMENT en JSON valide, ENTIÈREMENT EN FRANÇAIS (tous les textes doivent être en français, pas d'anglais), avec cette structure exacte : "
            . '{"strengths": ["point fort 1", "point fort 2"], "weaknesses": ["point faible 1"], '
            . '"tips": ["conseil 1", "conseil 2"], "predictions": ["prédiction 1"], "summary": "résumé court en une phrase"}';
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

    private function getAircraftType(array $data): ?string
    {
        foreach ($data as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);
            if ($normalizedKey === $this->normalizeKey("type d'appareil") || $normalizedKey === 'aircrafttype') {
                return $value !== '' ? (string) $value : null;
            }
        }

        return null;
    }

    private function isAutoland(array $data): bool
    {
        foreach ($data as $key => $value) {
            if ($this->normalizeKey((string) $key) === 'autoland') {
                if (is_bool($value)) {
                    return $value;
                }
                return in_array(strtolower((string) $value), ['true', '1', 'yes'], true);
            }
        }

        return false;
    }

    private function hadGoAround(array $data): bool
    {
        foreach ($data as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);
            if (in_array($normalizedKey, ['remisedegaz', 'goaround'], true)) {
                if (is_bool($value)) {
                    return $value;
                }
                return in_array(strtolower((string) $value), ['true', '1', 'yes', 'oui'], true);
            }
        }

        return false;
    }

    private function getAtcType(array $data): ?string
    {
        foreach ($data as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);
            if (in_array($normalizedKey, ['typedatc', 'atc'], true)) {
                return $value !== '' ? (string) $value : null;
            }
        }

        return null;
    }

    /**
     * Catégorise la valeur du champ "Type d'ATC" (liste déroulante) en comparant
     * une égalité STRICTE avec les libellés exacts proposés dans le formulaire.
     * Volontairement pas de correspondance approximative/mots-clés : une liste
     * déroulante garantit que la valeur est toujours l'une des options connues,
     * donc une égalité stricte est fiable — contrairement à du texte libre où
     * deviner des variantes ("fshud ai traffic control" vu en pratique) est un
     * jeu perdu d'avance.
     */
    private function getAtcCategory(array $data): ?string
    {
        $atcType = $this->getAtcType($data);
        if ($atcType === null) {
            return null;
        }

        $normalized = $this->normalizeKey($atcType);

        return match (true) {
            $normalized === $this->normalizeKey('VATSIM / IVAO (contrôleur réel)') => 'real',
            $normalized === $this->normalizeKey('FSHud AI Traffic Control') => 'ai_addon',
            $normalized === $this->normalizeKey('ATC automatique du simulateur') => 'ai_default',
            $normalized === $this->normalizeKey('Aucun') => 'none',
            default => null, // valeur non reconnue (ancienne saisie en texte libre par ex.) : on ne devine pas
        };
    }

    private function getSituationManagement(array $data): ?string
    {
        foreach ($data as $key => $value) {
            $normalizedKey = $this->normalizeKey((string) $key);
            if (in_array($normalizedKey, ['gestiondesituation', 'gestiondelasituation'], true)) {
                return $value !== '' ? (string) $value : null;
            }
        }

        return null;
    }

    /**
     * Transmet les critères d'approche (vitesse, altitude d'interception) de façon
     * purement qualitative. Volontairement AUCUN seuil chiffré de "stabilisation"
     * n'est calculé ici : ces seuils dépendent trop du type d'appareil et de la
     * procédure pour être fiables sans référence type par appareil (même logique
     * de prudence que pour l'hélicoptère dans computeLandingVerdict).
     */
    private function extractApproachCriteriaContext(array $data): ?string
    {
        $approachSpeed = $this->extractNumericField($data, ["vitesse d'approche"]);
        $interceptAltitude = $this->extractNumericField($data, ["altitude d'interception"]);

        if ($approachSpeed === null && $interceptAltitude === null) {
            return null;
        }

        $parts = [];
        if ($approachSpeed !== null) {
            $parts[] = sprintf("vitesse d'approche %.0f kt", $approachSpeed);
        }
        if ($interceptAltitude !== null) {
            $parts[] = sprintf("altitude d'interception %.0f ft", $interceptAltitude);
        }

        return "CRITÈRES D'APPROCHE DISPONIBLES : " . implode(', ', $parts) . ". Ces valeurs varient énormément selon le type d'appareil et la procédure : N'INVENTE AUCUN seuil chiffré universel de stabilisation. Commente-les uniquement de façon qualitative (gestion de l'énergie, régularité de la capture du plan de descente), en t'appuyant sur la cohérence avec le reste du vol plutôt que sur un chiffre absolu que tu ne peux pas justifier.";
    }

    private function extractWeatherContext(array $data): ?string
    {
        $weatherInfo = [];

        foreach ($data as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }

            $normKey = $this->normalizeKey((string) $key);

            if (in_array($normKey, ['meteo', 'weather', 'conditionsmeteo', 'weatherconditions', 'météo'])) {
                $weatherInfo[] = "Météo globale : " . $value;
            } elseif (in_array($normKey, ['vent', 'wind', 'venttraversier', 'crosswind'])) {
                $weatherInfo[] = "Vent : " . $value;
            } elseif (in_array($normKey, ['visibilite', 'visibility', 'visibilité'])) {
                $weatherInfo[] = "Visibilité : " . $value;
            }
        }

        if (empty($weatherInfo)) {
            return null;
        }

        return "CONDITIONS MÉTÉO DU VOL : " . implode(' | ', $weatherInfo) . ". Prends en compte ces conditions météo dans l'analyse : si les conditions étaient difficiles (vent fort, faible visibilité, météo réelle), valorise d'autant plus la performance d'atterrissage.";
    }

    /**
     * Cherche un champ de données "Incident technique" (select) et retourne sa
     * valeur si elle indique un incident réel (pas vide, pas "Aucun"/"None").
     * Champ à créer manuellement via /categories si pas encore présent —
     * cette méthode reste silencieuse (retourne null) tant qu'il n'existe pas.
     */
    private function getTechnicalIncident(array $data): ?string
    {
        foreach ($data as $key => $value) {
            if ($this->normalizeKey((string) $key) === 'incidenttechnique') {
                if ($value === null || $value === '') {
                    return null;
                }

                $normalizedValue = $this->normalizeKey((string) $value);
                if (in_array($normalizedValue, ['aucun', 'none', 'na'], true)) {
                    return null;
                }

                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Seuils Landing Rate calculés en dur.
     * - Avion de ligne / défaut et Aviation générale : bornes issues des critères de
     *   certification commerciale (atterrissage dur au-delà de -400/-600 fpm selon les sources).
     * - Hélicoptère : PAS de verdict calculé ici. Aucune norme fiable trouvée pour
     *   un seuil fpm isolé sur hélicoptère (l'évaluation réelle porte sur la stabilité/dérive,
     *   pas uniquement la vitesse verticale). Le cas hélicoptère est traité uniquement en
     *   texte qualitatif dans buildReferenceContext(), pas ici.
     */
    private function computeLandingVerdict(array $data, ?string $aircraftType): ?array
    {
        if ($aircraftType === 'Hélicoptère') {
            return null;
        }

        $rate = $this->extractNumericField($data, ['landing rate']);
        $gforce = $this->extractNumericField($data, ['landing gforce']);

        if ($rate === null) {
            return null;
        }

        [$goodBound, $firmBound] = match ($aircraftType) {
            'Aviation générale' => [-150, -300],
            default => [-250, -400],
        };

        $rateVerdict = match (true) {
            $rate >= $goodBound => 'bon (atterrissage doux)',
            $rate >= $firmBound => 'ferme mais acceptable',
            default => 'dur',
        };

        $gforceVerdict = $gforce !== null
            ? match (true) {
                abs($gforce) <= 1.3 => 'bon (impact vertical doux)',
                abs($gforce) <= 1.5 => 'notable',
                default => 'important',
            }
            : null;

        return [
            'rate' => $rate,
            'rate_verdict' => $rateVerdict,
            'gforce' => $gforce !== null ? abs($gforce) : null,
            'gforce_verdict' => $gforceVerdict,
        ];
    }

    private function buildReferenceContext(string $category, array $data = [], ?string $technicalIncident = null): ?string
    {
        if ($category !== 'aviation') {
            return null;
        }

        $aircraftType = $this->getAircraftType($data);
        $isAutoland = $this->isAutoland($data);
        $weatherContext = $this->extractWeatherContext($data);

        $baseContext = "RÈGLES STRICTES DE VOCABULAIRE AÉRONAUTIQUE — À RESPECTER IMPÉRATIVEMENT : "
            . "1. « Landing Rate » = vitesse verticale à l'impact des roues en ft/min (ce n'est NI du freinage NI du décrochage). INTERDICTION ABSOLUE d'employer les mots « freinage » ou « décélération » pour qualifier le Landing Rate ou la vitesse verticale en général. "
            . "2. « Landing Gforce » = facteur de charge/force d'impact vertical au contact du sol. INTERDICTION ABSOLUE d'employer les mots « freinage », « décélération » ou « virage » pour qualifier le Landing Gforce. "
            . "3. « Block Time » = durée totale parking à parking. « Flight Time » = durée réelle en l'air. ";

        if ($isAutoland) {
            $baseContext .= "IMPORTANT : Cet atterrissage a été effectué en AUTOLAND (atterrissage automatique). Précise-le clairement dans l'analyse et ne félicite PAS le pilotage manuel pour la douceur du toucher de roues. ";
        }

        if ($weatherContext) {
            $baseContext .= $weatherContext . " ";
        }

        $baseContext .= match ($aircraftType) {
            'Avion de ligne' => "L'appareil est un avion de ligne commercial. ",
            'Aviation générale' => "L'appareil est un avion léger d'aviation générale, plus sensible aux variations. ",
            'Hélicoptère' => "L'appareil utilisé est un hélicoptère. Il n'existe pas de seuil universel standardisé pour le Landing Rate comme pour l'aviation de ligne — évalue plutôt la qualité de l'atterrissage sur la stabilité générale (peu de dérive latérale, poser progressif) plutôt que sur un chiffre fpm isolé. À titre indicatif seulement (estimation non vérifiée, à ne pas traiter comme un seuil officiel) : un Landing Rate entre 0 et -100 fpm est généralement doux, entre -100 et -200 fpm reste acceptable, au-delà de -300 fpm l'atterrissage est probablement ferme. Le train d'atterrissage (skids) étant plus rigide que sur un avion, la tolérance en G-force peut être légèrement supérieure, mais aucun seuil fiable n'est disponible pour la chiffrer — reste prudent et qualitatif sur ce point. ",
            default => "Le type d'appareil n'est pas renseigné avec certitude, seuils standards d'aviation commerciale appliqués par défaut. ",
        };

        $verdict = $this->computeLandingVerdict($data, $aircraftType);
        if ($verdict) {
            $baseContext .= sprintf(
                "Verdict DÉJÀ CALCULÉ ET VÉRIFIÉ, à utiliser TEL QUEL sans le recalculer ni modifier les termes : Landing Rate = %.0f fpm → verdict officiel : %s.",
                $verdict['rate'],
                $verdict['rate_verdict']
            );
            if ($verdict['gforce'] !== null) {
                $baseContext .= sprintf(
                    " Landing Gforce = %.2f G (impact vertical au sol) → verdict officiel : %s.",
                    $verdict['gforce'],
                    $verdict['gforce_verdict']
                );
            }
        }

        if ($this->hadGoAround($data)) {
            $baseContext .= " REMISE DE GAZ EFFECTUÉE PENDANT CE VOL : c'est une manœuvre de sécurité standard, PAS un échec. Valorise-la comme un point fort d'airmanship (bonne décision de ne pas forcer un atterrissage sur une approche déstabilisée ou une vitesse excessive), SAUF si les notes de l'utilisateur attribuent explicitement cette remise de gaz à une erreur de pilotage — dans ce cas uniquement, ne la présente pas comme un point fort. Les métriques d'atterrissage analysées ici (Landing Rate, Landing Gforce) concernent l'atterrissage final réussi, pas l'approche interrompue.";
        }

        $atcCategory = $this->getAtcCategory($data);
        switch ($atcCategory) {
            case 'real':
                $baseContext .= " CONTRÔLE AÉRIEN RÉEL (contrôleur humain, réseau type VATSIM/IVAO) utilisé sur ce vol : cela implique une charge de travail et un réalisme de communication significativement supérieurs à l'ATC automatique. Valorise cette complexité supplémentaire si pertinent dans l'analyse.";
                break;
            case 'ai_addon':
                $baseContext .= " Ce vol utilisait FSHud AI Traffic Control, un ATC synthétique généré par IA (pas un contrôleur humain réel), plus riche que l'ATC automatique par défaut du simulateur. NOTE INTERNE POUR TOI UNIQUEMENT, à ne surtout pas reformuler dans 'strengths'/'weaknesses'/'tips' : cette distinction IA/contrôleur humain est une précision de contexte, pas un sujet d'analyse pour le pilote — ne la mentionne nulle part dans ta réponse.";
                break;
            case 'ai_default':
                $baseContext .= " Ce vol utilisait l'ATC automatique par défaut du simulateur (pas de contrôleur humain réel).";
                break;
            case 'none':
            case null:
                // Rien à ajouter : soit aucun ATC, soit une valeur non reconnue —
                // dans les deux cas, mieux vaut ne rien affirmer que de deviner.
                break;
        }

        if ($technicalIncident !== null) {
            $normalizedIncident = $this->normalizeKey($technicalIncident);
            if (str_contains($normalizedIncident, 'visuel') || str_contains($normalizedIncident, 'manuel')) {
                $baseContext .= " L'incident technique déclaré a nécessité un passage en navigation/pilotage visuel(le) ou manuel(le) : valorise explicitement cette capacité d'adaptation en temps réel, en plus de ne jamais reprocher au pilote la dégradation liée à cet incident (voir consigne dédiée sur l'incident technique).";
            }
        }

        $situationManagement = $this->getSituationManagement($data);
        if ($situationManagement) {
            $baseContext .= " GESTION DE SITUATION DÉCRITE PAR L'UTILISATEUR : « {$situationManagement} ». Commente spécifiquement cette gestion de situation dans l'analyse (en point fort si elle a été bien gérée, en conseil si des pistes d'amélioration existent) — c'est un aspect que l'utilisateur juge important pour ce vol.";
        }

        $approachContext = $this->extractApproachCriteriaContext($data);
        if ($approachContext) {
            $baseContext .= " " . $approachContext;
        }

        return $baseContext;
    }

    private function buildHistoryContext(Session $session, string $category): ?string
    {
        $previousSessions = $this->entityManager->getRepository(Session::class)
            ->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.category = :category')
            ->andWhere('s.id != :currentId')
            ->andWhere('s.date_start < :currentDateStart')
            ->setParameter('user', $session->getUser())
            ->setParameter('category', $session->getCategory())
            ->setParameter('currentId', $session->getId())
            ->setParameter('currentDateStart', $session->getDateStart())
            ->orderBy('s.date_start', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        if (empty($previousSessions)) {
            return null;
        }

        $summaries = array_map(function (Session $s) {
            return sprintf(
                '"%s" (données: %s)',
                $s->getTitle(),
                json_encode($s->getData())
            );
        }, $previousSessions);

        $count = count($previousSessions);
        $averagesText = $this->buildNumericAverages($previousSessions, $category);

        $context = "Pour comparaison, voici les données de ses {$count} dernière(s) session(s) précédente(s) dans cette même catégorie : " . implode(', ', $summaries) . ".";

        if ($averagesText) {
            $context .= " " . $averagesText;
        }

        $context .= " Compare la session actuelle à cet historique quand c'est pertinent. Si l'historique est limité (moins de 3 sessions), reste prudent dans les comparaisons statistiques.";

        return $context;
    }

    private function buildNumericAverages(array $sessions, string $category): ?string
    {
        $whitelist = match ($category) {
            'aviation' => ['landing rate', 'landing gforce', 'score', 'xp'],
            default => null,
        };

        $numericFields = [];

        foreach ($sessions as $s) {
            foreach ($s->getData() as $key => $value) {
                if ($whitelist !== null) {
                    $normalizedKey = $this->normalizeKey((string) $key);
                    $isAllowed = false;
                    foreach ($whitelist as $allowed) {
                        if ($normalizedKey === $this->normalizeKey($allowed)) {
                            $isAllowed = true;
                            break;
                        }
                    }
                    if (!$isAllowed) {
                        continue;
                    }
                }

                if (is_numeric($value)) {
                    $numericFields[$key][] = (float) $value;
                }
            }
        }

        if (empty($numericFields)) {
            return null;
        }

        $lines = [];
        foreach ($numericFields as $key => $values) {
            $avg = array_sum($values) / count($values);
            $lines[] = sprintf('%s (moyenne historique: %.2f)', $key, $avg);
        }

        return "Moyennes historiques sur les champs pertinents : " . implode(', ', $lines) . ".";
    }
}
