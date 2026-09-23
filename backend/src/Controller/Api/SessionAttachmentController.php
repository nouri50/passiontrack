<?php

namespace App\Controller\Api;

use App\Entity\Session;
use App\Entity\User;
use App\Service\Import\SimBitReportParser;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gère le fichier de vol (export SimBit .xlsx) attaché à une session.
 *
 * Le fichier est stocké hors de public/ (jamais accessible par une URL
 * directe) et n'est consultable qu'en repassant par download(), qui
 * vérifie l'appartenance de la session avant de servir le fichier.
 *
 * Volontairement, le contenu détaillé du fichier (37 critères de scoring,
 * 90 points de trace) n'est jamais recopié dans Session::$data — ce champ
 * est celui envoyé tel quel à l'IA par PromptGenerator, et on ne veut pas
 * y faire exploser la taille du prompt. Le fichier reste une source à part,
 * reparsée à la demande si besoin (carte, détail des critères...).
 */
final class SessionAttachmentController extends AbstractController
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 Mo
    private const ALLOWED_EXTENSIONS = ['xlsx'];
    private const STORAGE_SUBDIR = 'flight_reports';
    private const FSHUB_TRACKS_SUBDIR = 'fshub_tracks';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly SimBitReportParser $parser,
    ) {}

    #[Route('/api/sessions/{id}/attachment', name: 'app_api_session_attachment_upload', methods: ['POST'])]
    public function upload(
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

        $file = $request->files->get('file');

        if (!$file) {
            return $this->json(['error' => 'ATTACHMENT_MISSING_FILE'], 400);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->json(['error' => 'ATTACHMENT_INVALID_FORMAT'], 400);
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => 'ATTACHMENT_TOO_LARGE'], 400);
        }

        $uploadDir = $this->projectDir . '/var/uploads/' . self::STORAGE_SUBDIR;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            error_log('Attachment upload: failed to create directory ' . $uploadDir);
            return $this->json(['error' => 'ATTACHMENT_SAVE_FAILED'], 500);
        }

        $filename = $id . '.xlsx';
        $destination = $uploadDir . '/' . $filename;

        try {
            $file->move($uploadDir, $filename);
        } catch (\Throwable $e) {
            error_log('Attachment move failed: ' . $e->getMessage());
            return $this->json(['error' => 'ATTACHMENT_SAVE_FAILED'], 500);
        }

        // Validation de contenu : le fichier doit être un export SimBit
        // réellement exploitable, pas juste un .xlsx quelconque.
        try {
            $parsed = $this->parser->parse($destination);

            if ($parsed['total_score'] === null || empty($parsed['raw_data'])) {
                throw new \RuntimeException('Structure SimBit non reconnue (score ou trace absente)');
            }
        } catch (\Throwable $e) {
            @unlink($destination);
            error_log('Attachment parse validation failed: ' . $e->getMessage());
            return $this->json(['error' => 'ATTACHMENT_PARSE_FAILED'], 400);
        }

        $session->setAttachmentUrl(self::STORAGE_SUBDIR . '/' . $filename);
        $filledFields = $this->fillEmptyDataFields($session, $parsed['header']);
        $entityManager->flush();

        $notificationService->checkLandingAchievements($session);

        return $this->json([
            'message' => 'Attachment uploaded successfully',
            'attachment_url' => $session->getAttachmentUrl(),
            'total_score' => $parsed['total_score'],
            'filled_fields' => $filledFields,
            'data' => $session->getData(),
        ]);
    }

    /**
     * Complète les champs vides de Session::$data à partir de l'en-tête
     * parsé, SANS JAMAIS écraser une valeur déjà saisie par l'utilisateur.
     * Volontairement une liste fixe de correspondances (pas de matching
     * flou) : ce sont les seuls champs où le sens est sans ambiguïté.
     *
     * @return string[] les clés effectivement remplies
     */
    private function fillEmptyDataFields(Session $session, array $parsedHeader): array
    {
        $mapping = [
            'xp' => 'xp',
            'score' => 'score',
            'landing_rate' => 'landing_rate',
            'landing_gforce' => 'landing_gforce',
            'aircraft' => 'aircraft',
            'departure' => 'departure',
            'arrival' => 'arrival',
            'block_time' => 'block_time',
            'flight_time' => 'flight_time',
        ];

        $data = $session->getData() ?? [];
        $filled = [];

        foreach ($mapping as $parserKey => $dataKey) {
            $value = $parsedHeader[$parserKey] ?? null;
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

    #[Route('/api/sessions/{id}/attachment', name: 'app_api_session_attachment_download', methods: ['GET'])]
    public function download(int $id, EntityManagerInterface $entityManager): BinaryFileResponse|JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        if (!$session->getAttachmentUrl()) {
            return $this->json(['error' => 'ATTACHMENT_NOT_FOUND'], 404);
        }

        $filePath = $this->projectDir . '/var/uploads/' . $session->getAttachmentUrl();

        if (!is_file($filePath)) {
            return $this->json(['error' => 'ATTACHMENT_NOT_FOUND'], 404);
        }

        return $this->file($filePath, basename($filePath));
    }

    /**
     * Sert les points de trace GPS affichés par FlightMap sur SessionDetail.
     *
     * Deux sources possibles, jamais combinées :
     * - Un fichier SimBit attaché (session.attachment_url renseigné) : on
     *   reparse le .xlsx à la demande (voir docblock de la classe — le
     *   détail n'est jamais stocké en base) et on renvoie raw_data tel
     *   quel, déjà sous la forme { time, latitude, longitude, alt, galt,
     *   ias, tas, gs, vspeed, heading } attendue par FlightMap.
     * - Un import FSHub (attachment_url vide) : on relit le fichier stocké
     *   par FSHubController::storeTrack() à l'import (GeoJSON
     *   FeatureCollection, coordonnées [longitude, latitude] à l'ordre
     *   standard GeoJSON) et on le convertit vers la même forme
     *   { latitude, longitude } que ci-dessus.
     */
    #[Route('/api/sessions/{id}/trace', name: 'app_api_session_trace', methods: ['GET'])]
    public function trace(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        if ($session->getAttachmentUrl()) {
            $filePath = $this->projectDir . '/var/uploads/' . $session->getAttachmentUrl();

            if (!is_file($filePath)) {
                return $this->json(['error' => 'ATTACHMENT_NOT_FOUND'], 404);
            }

            try {
                $parsed = $this->parser->parse($filePath);
            } catch (\Throwable $e) {
                error_log('Trace parse failed: ' . $e->getMessage());
                return $this->json(['error' => 'TRACE_PARSE_FAILED'], 500);
            }

            return $this->json(['points' => $parsed['raw_data']]);
        }

        // Repli FSHub : aucun fichier SimBit attaché, on tente la trace
        // stockée par FSHubController::storeTrack() à l'import.
        $trackPath = $this->projectDir . '/var/uploads/' . self::FSHUB_TRACKS_SUBDIR . '/' . $id . '.json';

        if (!is_file($trackPath)) {
            return $this->json(['error' => 'TRACE_NOT_FOUND'], 404);
        }

        $track = json_decode((string) file_get_contents($trackPath), true);

        if (!is_array($track)) {
            return $this->json(['error' => 'TRACE_NOT_FOUND'], 404);
        }

        return $this->json(['points' => $this->extractPointsFromGeoJson($track)]);
    }

    /**
     * Convertit une trace GeoJSON FSHub (FeatureCollection de géométries
     * LineString, coordonnées [longitude, latitude] à l'ordre standard
     * GeoJSON) vers la même forme que SimBitReportParser::parseRawData() :
     * un tableau de points { latitude, longitude }, consommé tel quel par
     * FlightMap.
     *
     * @return array<int, array{latitude: float, longitude: float}>
     */
    private function extractPointsFromGeoJson(array $geoJson): array
    {
        $points = [];

        foreach ($geoJson['features'] ?? [] as $feature) {
            $coordinates = $feature['geometry']['coordinates'] ?? [];

            foreach ($coordinates as $coordinate) {
                if (!isset($coordinate[0], $coordinate[1])) {
                    continue;
                }

                $points[] = [
                    'longitude' => (float) $coordinate[0],
                    'latitude' => (float) $coordinate[1],
                ];
            }
        }

        return $points;
    }

    #[Route('/api/sessions/{id}/attachment', name: 'app_api_session_attachment_delete', methods: ['DELETE'])]
    public function delete(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $session = $entityManager->getRepository(Session::class)->find($id);

        if (!$session) {
            return $this->json(['error' => 'SESSION_NOT_FOUND'], 404);
        }

        if ($session->getUser() !== $user) {
            return $this->json(['error' => 'ACCESS_DENIED'], 403);
        }

        if ($session->getAttachmentUrl()) {
            $filePath = $this->projectDir . '/var/uploads/' . $session->getAttachmentUrl();
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            $session->setAttachmentUrl(null);
            $entityManager->flush();
        }

        return $this->json(['message' => 'Attachment deleted successfully']);
    }
}
