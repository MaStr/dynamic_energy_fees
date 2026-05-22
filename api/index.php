<?php
/**
 * §14a EnWG Modul 3 – Netzentgelt-API
 *
 * Daten: https://github.com/MaStr/dynamic_energy_fees
 * Web:   https://mastr.github.io/dynamic_energy_fees
 *
 * Endpunkte:
 *   GET /api/
 *       → diese Hilfe (JSON)
 *
 *   GET /api/?country=de&operator=syna
 *       → evcc-kompatible Preisreihe für heute + morgen (JSON Array)
 *
 *   GET /api/?country=de&operator=syna&mode=raw
 *       → vollständige Operatordaten als JSON
 *
 *   GET /api/?country=de&operator=syna&mode=quarter&year=2026&quarter=Q2
 *       → Slots eines einzelnen Quartals
 *
 * evcc tariff config (in evcc.yaml):
 *   tariffs:
 *     grid:
 *       type: custom
 *       forecast: http://YOUR_HOST/api/?country=de&operator=syna
 *
 * Das Format folgt dem evcc HTTP-Tarif-Interface:
 *   [ { "start": "<RFC3339>", "end": "<RFC3339>", "value": <float €/kWh> }, … ]
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

// ── Config ────────────────────────────────────────────────────────────────────

const DATA_DIR = __DIR__ . '/../dist/operators';   // built by build-index.mjs
const ALLOWED_COUNTRIES = ['de', 'at', 'ch'];
const REPO_URL = 'https://github.com/MaStr/dynamic_energy_fees';
const WEB_URL  = 'https://mastr.github.io/dynamic_energy_fees';

// ── Help (no params → self-description) ──────────────────────────────────────

if (empty($_GET)) {
    echo json_encode([
        'name'        => '§14a EnWG Modul 3 – Netzentgelt-API',
        'description' => 'Zeitvariable Netzentgelte (Modul 3) für DE, AT und CH als maschinenlesbares JSON. '
                       . 'Daten community-gepflegt auf GitHub.',
        'repository'  => REPO_URL,
        'web'         => WEB_URL,
        'countries'   => ALLOWED_COUNTRIES,
        'endpoints'   => [
            [
                'description' => 'evcc-Format: Preisslots für heute + morgen (Standard)',
                'url'         => '?country=de&operator=syna',
                'returns'     => '[{"start":"<RFC3339>","end":"<RFC3339>","value":<€/kWh>}, …]',
            ],
            [
                'description' => 'Vollständige Operatordaten (YAML als JSON)',
                'url'         => '?country=de&operator=syna&mode=raw',
                'returns'     => 'Objekt mit id, name, bdew_code, website, regions, tariffs',
            ],
            [
                'description' => 'Slots eines einzelnen Quartals',
                'url'         => '?country=de&operator=syna&mode=quarter&year=2026&quarter=Q2',
                'returns'     => '[{"from":"HH:MM","to":"HH:MM","tariff":"NT|ST|HT","price_ct_kwh_net":<float>}, …]',
            ],
            [
                'description' => 'Diese Hilfe',
                'url'         => '(keine Parameter)',
                'returns'     => 'Dieses Objekt',
            ],
        ],
        'evcc_example' => [
            'comment' => 'evcc.yaml – Netzentgelt als HTTP-Tarif einbinden',
            'yaml'    => "tariffs:\n  grid:\n    type: custom\n    forecast: https://YOUR_HOST/api/?country=de&operator=syna",
        ],
        'tariff_levels' => [
            'NT' => 'Niedertarif  (günstigste Stufe, 10–40 % des ST-Preises)',
            'ST' => 'Standardtarif',
            'HT' => 'Hochtarif    (max. 2× ST, min. 2 h/Tag aktiv)',
        ],
        'legal' => '§14a EnWG BK8-22/010-A – Daten aus öffentlichen Preisblättern der Netzbetreiber. Lizenz: MIT.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Request parsing ───────────────────────────────────────────────────────────

$country    = preg_replace('/[^a-z]/', '', strtolower($_GET['country'] ?? ''));
$operatorId = preg_replace('/[^a-z0-9_-]/', '', strtolower($_GET['operator'] ?? ''));
$mode       = $_GET['mode'] ?? 'evcc';
$year       = (int)($_GET['year'] ?? date('Y'));
$quarter    = strtoupper($_GET['quarter'] ?? '');

if ($country === '' || !in_array($country, ALLOWED_COUNTRIES, true)) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'Missing or invalid parameter: country',
        'allowed' => ALLOWED_COUNTRIES,
        'help'    => 'Call /api/ without parameters for usage info.',
    ]);
    exit;
}

if ($operatorId === '') {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing parameter: operator',
        'help'  => 'Call /api/ without parameters for usage info.',
    ]);
    exit;
}

$file = DATA_DIR . '/' . $country . '/' . $operatorId . '.json';
if (!file_exists($file)) {
    http_response_code(404);
    echo json_encode([
        'error'      => "Operator '$operatorId' not found for country '$country'",
        'repository' => REPO_URL,
        'hint'       => 'Missing operator? Contributions welcome – see repository.',
    ]);
    exit;
}

$data = json_decode(file_get_contents($file), true);

// ── Mode: raw ────────────────────────────────────────────────────────────────

if ($mode === 'raw') {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Mode: quarter ────────────────────────────────────────────────────────────

if ($mode === 'quarter') {
    $slots = $data['tariffs'][(string)$year][$quarter] ?? null;
    if ($slots === null) {
        http_response_code(404);
        echo json_encode(['error' => "No data for $year/$quarter"]);
        exit;
    }
    echo json_encode($slots, JSON_PRETTY_PRINT);
    exit;
}

// ── Mode: evcc (default) ──────────────────────────────────────────────────────
// Returns price slots for today + tomorrow in evcc HTTP tariff format.
// evcc expects: [{"start":"2026-01-15T00:00:00+01:00","end":"...","value":0.0871}]
// value is in €/kWh (not ct/kWh)

function quarterForMonth(int $month): string {
    return match(true) {
        $month <= 3  => 'Q1',
        $month <= 6  => 'Q2',
        $month <= 9  => 'Q3',
        default      => 'Q4',
    };
}

function slotsForDate(array $tariffs, \DateTimeImmutable $date): array {
    $y = (int)$date->format('Y');
    $q = quarterForMonth((int)$date->format('n'));
    return $tariffs[(string)$y][$q] ?? [];
}

function buildEvccSlots(array $yamlSlots, \DateTimeImmutable $date): array {
    $result = [];
    $tz = $date->getTimezone();
    $dateStr = $date->format('Y-m-d');

    foreach ($yamlSlots as $slot) {
        $startTs = new \DateTimeImmutable("{$dateStr}T{$slot['from']}:00", $tz);
        if ($slot['to'] === '24:00') {
            $endTs = (new \DateTimeImmutable("{$dateStr}T00:00:00", $tz))->modify('+1 day');
        } else {
            $endTs = new \DateTimeImmutable("{$dateStr}T{$slot['to']}:00", $tz);
        }

        $result[] = [
            'start' => $startTs->format(\DateTimeInterface::RFC3339),
            'end'   => $endTs->format(\DateTimeInterface::RFC3339),
            'value' => round($slot['price_ct_kwh_net'] / 100, 6),  // ct/kWh → €/kWh
        ];
    }

    return $result;
}

$tz    = new \DateTimeZone('Europe/Berlin');
$today = new \DateTimeImmutable('today', $tz);
$tomorrow = $today->modify('+1 day');

$tariffs = $data['tariffs'] ?? [];

$evccSlots = array_merge(
    buildEvccSlots(slotsForDate($tariffs, $today), $today),
    buildEvccSlots(slotsForDate($tariffs, $tomorrow), $tomorrow)
);

if (empty($evccSlots)) {
    http_response_code(404);
    echo json_encode(['error' => "No tariff data available for operator '$operatorId' ($country)"]);
    exit;
}

echo json_encode($evccSlots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
