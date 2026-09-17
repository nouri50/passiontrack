<?php

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Parse un export Excel "report_flight_*.xlsx" généré par SimBit.
 *
 * Le parseur cherche des libellés repères (colonne B/C) plutôt que des
 * numéros de ligne fixes : le tableau de scoring a toujours 37 critères
 * répartis en 10 phases, mais la position exacte peut varier légèrement
 * selon la version du template SimBit. Seule la trace brute (RAW FLIGHT
 * DATA) a une longueur variable selon la durée du vol.
 *
 * Structure vérifiée sur un export réel (LMML->LIMC, A320, score 93/100) :
 * - En-tête : labels en colonne B/C, valeur en E (lignes ~6 à 23).
 * - Scoring : label en C (préfixé "- " pour un critère individuel, sans
 *   préfixe pour un nom de phase). VALUE en N, POINTS en Q, MAX en S
 *   (format "/7"). Chaque phase apparaît deux fois (ouverture et
 *   fermeture de section), et porte elle-même un sous-total en Q/S
 *   (ex: GENERAL -> 35/35) — non exploité ici, mais disponible si besoin.
 *   La ligne TOTAL FLIGHT SCORE utilise les MÊMES colonnes Q/S que les
 *   critères normaux (pas de colonnes séparées, vérifié explicitement).
 * - Trace brute : "RAW FLIGHT DATA" en B, puis une ligne vide, puis la
 *   ligne d'en-têtes (TIME/LATITUDE/LONGITUDE/ALT/GALT/IAS/TAS/GS/
 *   VSPEED/HEADING en colonnes B/D/F/G/K/L/M/P/R/T), puis les données
 *   jusqu'à la fin de la feuille.
 */
final class SimBitReportParser
{
    private const HEADER_LABELS = [
        'pilot_name' => 'Pilot name :',
        'simulator' => 'Simulator :',
        'aircraft' => 'Aircraft :',
        'registration' => 'Registration :',
        'flight_number' => 'Flight number :',
        'callsign' => 'Callsign :',
        'departure' => 'Departure :',
        'arrival' => 'Arrival :',
        'departure_time' => 'Departure time :',
        'arrival_time' => 'Arrival time :',
        'block_time' => 'Block time :',
        'flight_time' => 'Flight time :',
        'landing_rate' => 'Landing rate :',
        'landing_gforce' => 'Landing GForce :',
        'score' => 'Score :',
        'xp' => 'XP :',
    ];

    private const RAW_DATA_COLUMNS = [
        'time' => 'B',
        'latitude' => 'D',
        'longitude' => 'F',
        'alt' => 'G',
        'galt' => 'K',
        'ias' => 'L',
        'tas' => 'M',
        'gs' => 'P',
        'vspeed' => 'R',
        'heading' => 'T',
    ];

    public function parse(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        return [
            'header' => $this->parseHeader($sheet),
            'scoring' => $this->parseScoring($sheet),
            'total_score' => $this->parseTotalScore($sheet),
            'raw_data' => $this->parseRawData($sheet),
        ];
    }

    private function parseHeader(Worksheet $sheet): array
    {
        $result = [];
        $maxRow = $sheet->getHighestRow();

        foreach (self::HEADER_LABELS as $key => $label) {
            $row = $this->findRowByLabel($sheet, $label, 1, min($maxRow, 30), ['B', 'C']);
            if ($row !== null) {
                $result[$key] = trim((string) $sheet->getCell('E' . $row)->getValue());
            }
        }

        return $result;
    }

    private function parseScoring(Worksheet $sheet): array
    {
        $startRow = $this->findRowByLabel($sheet, 'FLIGHT SCORING DETAILS', 1, $sheet->getHighestRow(), ['C']);
        $endRow = $this->findRowByLabel($sheet, 'TOTAL FLIGHT SCORE', $startRow ?? 1, $sheet->getHighestRow(), ['C']);

        if ($startRow === null || $endRow === null) {
            return [];
        }

        $criteria = [];
        $currentPhase = null;

        for ($row = $startRow + 1; $row < $endRow; $row++) {
            $label = trim((string) $sheet->getCell('C' . $row)->getValue());
            if ($label === '') {
                continue;
            }

            if (str_starts_with($label, '-')) {
                // Ligne de critère individuel
                $value = trim((string) $sheet->getCell('N' . $row)->getValue());
                $points = trim((string) $sheet->getCell('Q' . $row)->getValue());
                $max = trim((string) $sheet->getCell('S' . $row)->getValue());

                $criteria[] = [
                    'phase' => $currentPhase,
                    'criterion' => ltrim($label, '- '),
                    'value' => $value,
                    'points' => is_numeric($points) ? (float) $points : null,
                    'max' => is_numeric(ltrim($max, '/')) ? (float) ltrim($max, '/') : null,
                    'passed' => $value === 'PASS' ? true : ($value === 'FAIL' ? false : null),
                ];
            } else {
                // Ligne de nom de phase (ouverture ou fermeture de section) —
                // porte aussi un sous-total en Q/S, non exploité ici.
                $currentPhase = $label;
            }
        }

        return $criteria;
    }

    private function parseTotalScore(Worksheet $sheet): ?array
    {
        $row = $this->findRowByLabel($sheet, 'TOTAL FLIGHT SCORE', 1, $sheet->getHighestRow(), ['C']);
        if ($row === null) {
            return null;
        }

        // Mêmes colonnes que les critères normaux (vérifié : Q83='93', S83='/100')
        $points = trim((string) $sheet->getCell('Q' . $row)->getValue());
        $max = trim((string) $sheet->getCell('S' . $row)->getValue());

        return [
            'points' => is_numeric($points) ? (float) $points : null,
            'max' => is_numeric(ltrim($max, '/')) ? (float) ltrim($max, '/') : null,
        ];
    }

    private function parseRawData(Worksheet $sheet): array
    {
        $headerRow = $this->findRowByLabel($sheet, 'TIME', 1, $sheet->getHighestRow(), ['B']);
        if ($headerRow === null) {
            return [];
        }

        $maxRow = $sheet->getHighestRow();
        $points = [];

        for ($row = $headerRow + 1; $row <= $maxRow; $row++) {
            $time = trim((string) $sheet->getCell(self::RAW_DATA_COLUMNS['time'] . $row)->getValue());
            if ($time === '') {
                continue;
            }

            $point = ['time' => $time];
            foreach (self::RAW_DATA_COLUMNS as $key => $col) {
                if ($key === 'time') {
                    continue;
                }
                $raw = trim((string) $sheet->getCell($col . $row)->getValue());
                $point[$key] = is_numeric($raw) ? (float) $raw : $raw;
            }

            $points[] = $point;
        }

        return $points;
    }

    private function findRowByLabel(Worksheet $sheet, string $label, int $startRow, int $endRow, array $columns): ?int
    {
        for ($row = $startRow; $row <= $endRow; $row++) {
            foreach ($columns as $col) {
                $cellValue = trim((string) $sheet->getCell($col . $row)->getValue());
                if ($cellValue === $label) {
                    return $row;
                }
            }
        }

        return null;
    }
}
