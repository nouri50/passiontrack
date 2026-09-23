<?php

namespace App\Controller\Api;

use App\Entity\Session;
use App\Entity\User;
use App\Service\Import\FSHubClient;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Importe le dernier vol FSHub d'un pilote dans une session PassionTrack.
 *
 * Équivalent, côté FSHub, de SessionAttachmentController pour SimBit —
 * mais ici aucun fichier n'est stocké : les données viennent en direct
 * de l'API, à la demande.
 */
final class FSHubController extends AbstractController
{
    public function __construct(
        private readonly FSHubClient $client,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    #[Route('/api/sessions/{id}/fshub-import', name: 'app_api_session_fshub_import', methods: ['POST'])]
    public function import(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        $token = $user->getFshubToken();
        if (!$token) {
            return $this->json(['error' => 'FSHUB_TOKEN_MISSING'], 400);
        }

        // flight_id optionnel : permet d'importer un vol précis choisi
        // dans la liste (GET /api/fshub/flights) plutôt que toujours le
        // dernier — utile si plusieurs vols se sont accumulés avant
        // l'import (plusieurs missions dans la même soirée, par exemple).
        $payload = json_decode($request->getContent(), true) ?? [];
        $requestedFlightId = isset($payload['flight_id']) ? (int) $payload['flight_id'] : null;

        try {
            $flight = $requestedFlightId !== null
                ? $this->client->getFlight($token, $requestedFlightId)
                : $this->client->getLatestFlight($token);
        } catch (\Throwable $e) {
            error_log('FSHub import failed: ' . $e->getMessage());
            return $this->json(['error' => 'FSHUB_REQUEST_FAILED'], 502);
        }

        if ($flight === null) {
            return $this->json(['error' => 'FSHUB_NO_FLIGHT_FOUND'], 404);
        }

        $filledFields = $this->fillEmptyDataFields($session, $flight);

        // Récupération et stockage de la trace GPS, en meilleur effort : un
        // échec ici ne doit pas faire échouer l'import des champs, qui est
        // le résultat principal attendu par l'utilisateur.
        if (isset($flight['id'])) {
            try {
                $track = $this->client->getFlightTrack($token, (int) $flight['id']);
                if ($track !== null) {
                    $this->storeTrack($id, $track);
                }
            } catch (\Throwable $e) {
                error_log('FSHub track fetch failed: ' . $e->getMessage());
            }
        }

        $entityManager->flush();

        $notificationService->checkLandingAchievements($session);

        return $this->json([
            'message' => 'FSHub flight imported successfully',
            'filled_fields' => $filledFields,
            'data' => $session->getData(),
            'fshub_flight_id' => $flight['id'] ?? null,
        ]);
    }

    /**
     * Liste les vols FSHub récents du pilote (pas seulement le dernier),
     * sous une forme simplifiée — pour que l'utilisateur choisisse lequel
     * importer via import() (paramètre flight_id) quand plusieurs vols
     * se sont accumulés depuis le dernier import.
     */
    #[Route('/api/fshub/flights', name: 'app_api_fshub_flights', methods: ['GET'])]
    public function listFlights(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $token = $user->getFshubToken();
        if (!$token) {
            return $this->json(['error' => 'FSHUB_TOKEN_MISSING'], 400);
        }

        // L'API FSHub ne documente aucun ordre de tri garanti sur cette
        // collection : vérifié empiriquement, "le début du jeu de données"
        // correspond aux vols les PLUS ANCIENS (ex: un compte avec des
        // vols depuis 2023 renvoyait des vols de 2023 en premier, pas les
        // vols récents de 2026). On compense en paginant nous-mêmes sur
        // plusieurs appels, puis en triant le résultat agrégé par date de
        // départ décroissante — fiable quel que soit l'ordre réel de FSHub.
        //
        // Limite honnête : sur un compte au très long historique, les
        // vols vraiment récents pourraient nécessiter plus de pages que
        // MAX_PAGES pour apparaître. À ajuster si ça se révèle insuffisant.
        $maxPages = 10;
        $pageSize = 25;
        $allFlights = [];
        $cursor = null;

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $result = $this->client->getFlightsPage($token, $pageSize, $cursor);
                $allFlights = array_merge($allFlights, $result['flights']);

                if ($result['next_cursor'] === null || $result['next_cursor'] === $cursor) {
                    break;
                }
                $cursor = $result['next_cursor'];
            }
        } catch (\Throwable $e) {
            error_log('FSHub flight list failed: ' . $e->getMessage());
            return $this->json(['error' => 'FSHUB_REQUEST_FAILED'], 502);
        }

        usort($allFlights, static function (array $a, array $b): int {
            $aTime = $a['departure']['time'] ?? '';
            $bTime = $b['departure']['time'] ?? '';

            return $bTime <=> $aTime; // décroissant : le plus récent en premier
        });

        $simplified = array_map(static function (array $flight): array {
            return [
                'id' => $flight['id'] ?? null,
                'aircraft' => $flight['aircraft']['icao_name'] ?? $flight['aircraft']['name'] ?? null,
                'departure' => $flight['departure']['icao'] ?? null,
                'arrival' => $flight['arrival']['icao'] ?? null,
                'departure_time' => $flight['departure']['time'] ?? null,
                'arrival_time' => $flight['arrival']['time'] ?? null,
                'landing_rate' => $flight['landing_rate'] ?? null,
                'time' => $flight['time'] ?? null,
            ];
        }, array_slice($allFlights, 0, 10));

        return $this->json([
            'flights' => $simplified,
            'pages_fetched' => $page + 1,
            'total_fetched' => count($allFlights),
        ]);
    }

    /**
     * Complète les champs vides de Session::$data depuis un objet vol
     * FSHub. Même principe que SessionAttachmentController::
     * fillEmptyDataFields — n'écrase jamais une valeur déjà saisie.
     *
     * Portée volontairement plus réduite que côté SimBit : FSHub n'expose
     * ni Landing Gforce, ni XP/Score (concepts propres à SimBit), ni de
     * distinction Block Time / Flight Time (un seul champ 'time' global,
     * mappé ici sur flight_time uniquement).
     *
     * @return string[] les clés effectivement remplies
     */
    private function fillEmptyDataFields(Session $session, array $flight): array
    {
        $data = $session->getData() ?? [];
        $filled = [];

        $candidates = [
            'aircraft' => $flight['aircraft']['icao_name'] ?? $flight['aircraft']['name'] ?? null,
            'departure' => $flight['departure']['icao'] ?? null,
            'arrival' => $flight['arrival']['icao'] ?? null,
            'landing_rate' => isset($flight['landing_rate']) ? (string) $flight['landing_rate'] : null,
            'flight_time' => isset($flight['time']) ? $this->formatSeconds((int) $flight['time']) : null,
        ];

        foreach ($candidates as $dataKey => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $currentValue = $data[$dataKey] ?? null;
            $isEmpty = $currentValue === null || $currentValue === '';

            if ($isEmpty) {
                $data[$dataKey] = $value;
                $filled[] = $dataKey;
            }
        }

        $session->setData($data);

        return $filled;
    }

    private function formatSeconds(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /**
     * Stocke la trace GeoJSON reçue de FSHub sur disque, une fois pour
     * toutes à l'import plutôt que de la redemander à chaque affichage
     * de la carte — évite de multiplier les appels à l'API FSHub.
     */
    private function storeTrack(int $sessionId, array $track): void
    {
        $dir = $this->projectDir . '/var/uploads/fshub_tracks';

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log('FSHub track storage: failed to create directory ' . $dir);
            return;
        }

        file_put_contents($dir . '/' . $sessionId . '.json', json_encode($track));
    }
}
