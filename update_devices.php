<?php
/*
 * TSS Saver - Device List Updater
 * Fetches current Apple device identifiers and names from ipsw.me v4 API
 * and rebuilds all JSON files used by the frontend and blob-saving logic.
 *
 * Cron (weekly, Sunday 3am):
 *   0 3 * * 0 php /path/to/tsssaver/update_devices.php >> /var/log/tsssaver_update.log 2>&1
 */

define('JSON_DIR', __DIR__ . '/json');
define('IPSW_DEVICES_API', 'https://api.ipsw.me/v4/devices');

$supportedTypes = ['iPhone', 'iPad', 'iPod', 'AppleTV'];

function fetchJSON($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TSSSaver-Updater/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($result === false || $httpCode !== 200) {
        echo "[ERROR] HTTP $httpCode fetching $url" . ($err ? ": $err" : '') . "\n";
        return null;
    }
    return json_decode($result, true);
}

function compareIdentifiers($a, $b) {
    // Natural numeric sort: iPhone1,1 < iPhone2,1 < iPhone10,1 < iPhone16,3
    if (preg_match('/^([A-Za-z]+)(\d+),(\d+)$/', $a, $ma) &&
        preg_match('/^([A-Za-z]+)(\d+),(\d+)$/', $b, $mb)) {
        if ($ma[1] !== $mb[1]) return strcmp($ma[1], $mb[1]);
        if ((int)$ma[2] !== (int)$mb[2]) return (int)$ma[2] - (int)$mb[2];
        return (int)$ma[3] - (int)$mb[3];
    }
    return strcmp($a, $b);
}

function writeJSON($path, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($path, $json) === false) {
        echo "[ERROR] Could not write $path\n";
        return false;
    }
    return true;
}

// -----------------------------------------------------------------------

echo '[' . date('Y-m-d H:i:s') . "] Fetching device list from ipsw.me...\n";

$raw = fetchJSON(IPSW_DEVICES_API);
if (!$raw) {
    echo "[ERROR] Aborting — could not fetch device list.\n";
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . '] Got ' . count($raw) . " devices from API.\n";

// Group by device type, keeping only supported types
$byType = array_fill_keys($supportedTypes, []);

foreach ($raw as $device) {
    $id   = $device['identifier'] ?? '';
    $name = $device['name'] ?? $id;

    foreach ($supportedTypes as $type) {
        if (strpos($id, $type) === 0) {
            $byType[$type][] = ['identifier' => $id, 'name' => $name];
            break;
        }
    }
}

// Sort each type
foreach ($supportedTypes as $type) {
    usort($byType[$type], fn($a, $b) => compareIdentifiers($a['identifier'], $b['identifier']));
}

// Build output arrays
$allIdentifiers = [];
$deviceModels   = [];

foreach ($supportedTypes as $type) {
    $identifiers = [];
    $names       = [];

    foreach ($byType[$type] as $entry) {
        $identifiers[] = $entry['identifier'];
        $names[]        = $entry['name'];
        $allIdentifiers[] = $entry['identifier'];
    }

    $deviceModels[$type] = $identifiers;

    // json/iPhone.json, json/iPad.json, etc. — human-readable names for the dropdown
    $typePath = JSON_DIR . '/' . $type . '.json';
    if (writeJSON($typePath, $names)) {
        echo "  Updated $typePath (" . count($names) . " devices)\n";
    }
}

// json/devices.json — flat list of all identifiers (used for validation)
if (writeJSON(JSON_DIR . '/devices.json', $allIdentifiers)) {
    echo "  Updated " . JSON_DIR . '/devices.json (' . count($allIdentifiers) . " total identifiers)\n";
}

// json/deviceModels.json — type → [identifier, ...] used to resolve dropdown index → identifier
if (writeJSON(JSON_DIR . '/deviceModels.json', $deviceModels)) {
    echo "  Updated " . JSON_DIR . "/deviceModels.json\n";
}

echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
