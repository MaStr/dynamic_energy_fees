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
 *       → Preisreihe für die nächsten 48h (JSON Array)
 *
 *   GET /api/?country=de&operator=syna&next_hours=96
 *       → Preisreihe für die nächsten 96h (1–168 möglich)
 *
 *   GET /api/?country=de&operator=syna&mode=raw
 *       → vollständige Operatordaten als JSON
 *
 *   GET /api/?country=de&operator=syna&mode=quarter&year=2026&quarter=Q2
 *       → Slots eines einzelnen Quartals
 *
 * Format: [ { "start": "<RFC3339>", "end": "<RFC3339>", "value": <float €/kWh> }, … ]
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

// ── Config ────────────────────────────────────────────────────────────────────

const DATA_DIR = __DIR__ . '/../operators';   // built by build-index.mjs, deployed to server root
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
                'description' => 'Preisslots für die nächsten N Stunden (Standard: 48, max: 168)',
                'url'         => '?country=de&operator=syna',
                'params'      => ['next_hours' => 'optional, 1–168, Standard: 48'],
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
$nextHours  = min(168, max(1, (int)($_GET['next_hours'] ?? 48)));

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

// ── Mode: slots (default) ─────────────────────────────────────────────────────
// Preisslots für die nächsten N Stunden als JSON Array.
// value ist in €/kWh. Parameter next_hours: 1–168, Standard 48.

$tz        = new \DateTimeZone('Europe/Berlin');
$now       = new \DateTimeImmutable('now', $tz);
$tariffs   = $data['tariffs'] ?? [];
$evccSlots = buildSlotsForRange($tariffs, $now, $nextHours);

if (empty($evccSlots)) {
    http_response_code(404);
    echo json_encode(['error' => "No tariff data available for operator '$operatorId' ($country)"]);
    exit;
}

echo json_encode($evccSlots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
