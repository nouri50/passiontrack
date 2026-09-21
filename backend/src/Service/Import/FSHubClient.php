<?php

namespace App\Service\Import;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP pour l'API REST v3 de FSHub (fshub.io).
 *
 * Contrairement à SimBitReportParser (qui lit un fichier .xlsx exporté
 * manuellement), ce client interroge directement l'API de FSHub — aucun
 * fichier à uploader, une simple requête HTTP suffit.
 *
 * Authentification : un token personnel (généré sur fshub.io, Settings >
 * Integrations), passé en en-tête X-Pilot-Token. Stocké sur
 * User::$fshubToken, jamais en dur dans le code.
 */
class FSHubClient
{
    private const BASE_URL = 'https://fshub.io/api/v3/';

    public function __construct(private HttpClientInterface $client) {}

    /**
     * Résout l'identifiant pilote associé au token, via /api/v3/user.
     * Pas besoin de stocker cet ID séparément : on le redemande à chaque
     * fois (un appel HTTP de plus, négligeable en coût pour cet usage).
     */
    public function getCurrentPilotId(string $token): int
    {
        $response = $this->client->request('GET', self::BASE_URL . 'user', [
            'headers' => ['X-Pilot-Token' => $token],
        ]);

        $data = $response->toArray();

        return (int) $data['data']['id'];
    }

    /**
     * Renvoie le dernier vol du pilote, ou null s'il n'a encore aucun vol
     * enregistré (l'API répond alors 204 No Content).
     */
    public function getLatestFlight(string $token): ?array
    {
        $pilotId = $this->getCurrentPilotId($token);

        $response = $this->client->request(
            'GET',
            self::BASE_URL . "pilot/{$pilotId}/flight/latest",
            ['headers' => ['X-Pilot-Token' => $token]]
        );

        if ($response->getStatusCode() === 204) {
            return null;
        }

        $data = $response->toArray();

        return $data['data'] ?? null;
    }

    /**
     * Renvoie un vol précis par son ID, indépendamment du fait qu'il soit
     * le dernier ou non — utile une fois que l'utilisateur a choisi lequel
     * importer parmi une liste (voir FSHubController::listFlights()).
     */
    public function getFlight(string $token, int $flightId): ?array
    {
        $response = $this->client->request(
            'GET',
            self::BASE_URL . "flight/{$flightId}",
            ['headers' => ['X-Pilot-Token' => $token]]
        );

        if ($response->getStatusCode() === 204) {
            return null;
        }

        $data = $response->toArray();

        return $data['data'] ?? null;
    }

    /**
     * Renvoie UNE page de la collection de vols du pilote, avec le
     * curseur de la page suivante (ou null s'il n'y en a pas). La doc
     * FSHub ne précise aucun ordre de tri garanti sur cette collection —
     * vérifié empiriquement : le début du jeu de données correspond aux
     * vols les PLUS ANCIENS, pas aux plus récents. C'est pour ça que
     * cette méthode ne prétend pas renvoyer "les derniers vols" — c'est
     * FSHubController::listFlights() qui pagine sur plusieurs appels et
     * trie lui-même le résultat agrégé.
     */
    public function getFlightsPage(string $token, int $limit = 25, ?string $cursor = null): array
    {
        $pilotId = $this->getCurrentPilotId($token);

        $query = ['limit' => $limit];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        $response = $this->client->request(
            'GET',
            self::BASE_URL . "pilot/{$pilotId}/flight",
            [
                'headers' => ['X-Pilot-Token' => $token],
                'query' => $query,
            ]
        );

        if ($response->getStatusCode() === 204) {
            return ['flights' => [], 'next_cursor' => null];
        }

        $data = $response->toArray();

        return [
            'flights' => $data['data'] ?? [],
            'next_cursor' => $data['meta']['cursor']['next'] ?? null,
        ];
    }

    /**
     * Renvoie la trace GPS (GeoJSON, format FeatureCollection) d'un vol
     * précis. Utilisée par FSHubController::import() pour stocker la
     * trace sur disque, ensuite servie par SessionAttachmentController::
     * trace() (repli FSHub quand aucun fichier SimBit n'est attaché).
     */
    public function getFlightTrack(string $token, int $flightId): ?array
    {
        $response = $this->client->request(
            'GET',
            self::BASE_URL . "flight/{$flightId}/geo",
            ['headers' => ['X-Pilot-Token' => $token]]
        );

        $data = $response->toArray();

        return $data['data']['track'] ?? null;
    }
}
