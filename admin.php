<?php
/*
 * TSS Saver - Admin Panel
 * Requires $suPassword set in inc/config.php.
 */
session_start();

require_once 'inc/medoo.php';
require_once 'inc/config.php';
require_once 'inc/functions.php';

// ── Auth ────────────────────────────────────────────────────────────────────

function isAuthed() {
    return !empty($_SESSION['su_auth']);
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

if (isset($_POST['su_login'])) {
    if (!empty($suPassword) && hash_equals($suPassword, $_POST['su_password'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['su_auth'] = true;
    } else {
        $loginError = 'Wrong password.';
    }
}

if (isset($_POST['su_logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ── DB helper ────────────────────────────────────────────────────────────────

function db() {
    global $db;
    static $instance = null;
    if (!$instance) {
        $instance = new medoo([
            'database_type' => 'mysql',
            'database_name' => $db['name'],
            'server'        => $db['server'],
            'username'      => $db['user'],
            'password'      => $db['password'],
            'charset'       => 'utf8',
        ]);
    }
    return $instance;
}

// ── Actions (require auth + CSRF) ────────────────────────────────────────────

$msg = '';

if (isAuthed() && isset($_POST['action'])) {
    checkCsrf();

    if ($_POST['action'] === 'delete' && isset($_POST['ecid'])) {
        $ecid = (int)$_POST['ecid'];
        db()->delete($db['table'], ['deviceECID' => $ecid]);
        $msg = "Deleted ECID $ecid from tracking list.";
    }

    if ($_POST['action'] === 'add') {
        $rawECID    = trim($_POST['ECID'] ?? '');
        $ecidType   = (int)($_POST['ECIDType'] ?? 0);
        $deviceType = $_POST['deviceType'] ?? '';
        $modelIndex = $_POST['deviceModel'] ?? '';

        // Validate ECID
        $deviceECID = null;
        if ($ecidType === 0) {
            if (ctype_xdigit($rawECID) && is_numeric(hexdec($rawECID))) {
                $deviceECID = hexdec($rawECID);
            } else {
                $msg = 'Invalid ECID (expected hex).';
            }
        } else {
            if (is_numeric($rawECID)) {
                $deviceECID = (int)$rawECID;
            } else {
                $msg = 'Invalid ECID (expected decimal).';
            }
        }

        if ($deviceECID !== null) {
            $allowedTypes   = ['iPhone', 'iPad', 'iPod', 'AppleTV'];
            $deviceModelMap = json_decode(file_get_contents('json/deviceModels.json'), true);
            $deviceList     = json_decode(file_get_contents('json/devices.json'), true);

            if (!in_array($deviceType, $allowedTypes, true)) {
                $msg = 'Invalid device type.';
            } elseif (!isset($deviceModelMap[$deviceType][(int)$modelIndex])) {
                $msg = 'Invalid device model.';
            } else {
                $deviceIdentifier = $deviceModelMap[$deviceType][(int)$modelIndex];

                if (!in_array($deviceIdentifier, $deviceList, true)) {
                    $msg = "Device identifier $deviceIdentifier not in known device list.";
                } else {
                    $deviceInfo = [
                        'deviceIdentifier' => $deviceIdentifier,
                        'deviceType'       => $deviceType,
                        'deviceID'         => str_replace($deviceType, '', $deviceIdentifier),
                        'deviceECID'       => $deviceECID,
                    ];

                    $existing = db()->select($db['table'], 'deviceECID', ['deviceECID' => $deviceECID]);
                    if (count($existing) === 0) {
                        db()->insert($db['table'], $deviceInfo);
                    }

                    if (!file_exists('shsh/' . $deviceECID)) {
                        mkdir('shsh/' . $deviceECID, 0777, true);
                    }

                    saveBlobs($deviceInfo, $apnonce, $signedVersionsURL);
                    $msg = "ECID $deviceECID ($deviceIdentifier) added and blob save triggered.";
                }
            }
        }
    }
}

// ── Render ───────────────────────────────────────────────────────────────────

$deviceList = isAuthed() ? db()->select($db['table'], '*') : [];
$csrf       = csrfToken();

// Disable admin access if password not configured
if (empty($suPassword)) {
    http_response_code(403);
    die('<p>Admin panel disabled: $suPassword not set in config.</p>');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TSS Saver - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,800" rel="stylesheet">
    <style>
        table { width:100%; border-collapse:collapse; color:#eee; }
        th, td { padding:6px 10px; border-bottom:1px solid #333; text-align:left; }
        th { color:#aaa; font-weight:600; }
        .msg { color:#7fc97f; font-weight:600; margin:8px 0; }
        .err { color:#e06c75; font-weight:600; margin:8px 0; }
        .del-btn { background:#c0392b; border:none; color:#fff; padding:4px 10px;
                   border-radius:3px; cursor:pointer; font-size:12px; }
    </style>
</head>
<body>
<div class="box">
    <h1 class="title"><span style="font-weight:600;">TSS Saver</span> - Admin Panel</h1>
</div>

<?php if (!isAuthed()): ?>
<div class="box">
    <h1 class="note">Superuser Login</h1>
    <?php if (!empty($loginError)): ?>
        <p class="err"><?= htmlspecialchars($loginError) ?></p>
    <?php endif; ?>
    <form method="post" action="admin.php">
        <input type="password" name="su_password" placeholder="Password" style="width:100%"><br><br>
        <input class="button" type="submit" name="su_login" value="Login" style="width:100%">
    </form>
</div>

<?php else: ?>

<div class="box">
    <?php if ($msg): ?>
        <p class="msg"><?= htmlspecialchars($msg) ?></p>
    <?php endif; ?>

    <form method="post" action="admin.php" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input class="button" type="submit" name="su_logout" value="Logout" style="width:auto;padding:4px 16px;">
    </form>
</div>

<!-- Add ECID -->
<div class="box">
    <h1 class="note">Add ECID to tracking</h1>
    <form method="post" action="admin.php">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add">

        <h1 class="note">ECID:</h1>
        <div class="inputGroup">
            <select name="ECIDType" style="width:15%;float:left;height:29px">
                <option value="0">Hex</option>
                <option value="1">Dec</option>
            </select>
            <input type="text" name="ECID" placeholder="ECID..." style="width:85%">
        </div>
        <br><br>

        <h1 class="note">Device:</h1>
        <select id="adm_deviceType" name="deviceType">
            <option value="iPhone">iPhone</option>
            <option value="iPod">iPod</option>
            <option value="iPad">iPad</option>
            <option value="AppleTV">AppleTV</option>
        </select>
        <select id="adm_deviceModel" name="deviceModel"></select>
        <br><br>

        <input class="button" type="submit" value="Add &amp; Save Blobs" style="width:100%">
    </form>
</div>

<!-- ECID List -->
<div class="box">
    <h1 class="note">Tracked ECIDs (<?= count($deviceList) ?>)</h1>
    <?php if (empty($deviceList)): ?>
        <p>No ECIDs registered.</p>
    <?php else: ?>
    <table>
        <tr><th>ECID (dec)</th><th>Identifier</th><th>Type</th><th>Blobs</th><th></th></tr>
        <?php foreach ($deviceList as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['deviceECID']) ?></td>
            <td><?= htmlspecialchars($row['deviceIdentifier']) ?></td>
            <td><?= htmlspecialchars($row['deviceType']) ?></td>
            <td><a href="<?= htmlspecialchars($savedSHSHURL . $row['deviceECID']) ?>" target="_blank">View</a></td>
            <td>
                <form method="post" action="admin.php" style="margin:0;" onsubmit="return confirm('Delete ECID <?= (int)$row['deviceECID'] ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="ecid" value="<?= (int)$row['deviceECID'] ?>">
                    <button class="del-btn" type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<script>
var serverURL = "<?= htmlspecialchars($serverURL, ENT_QUOTES) ?>";

function getJSON(url) {
    var req = new XMLHttpRequest();
    req.open('GET', url, false);
    req.send();
    return req.status === 200 ? JSON.parse(req.responseText) : [];
}

function populateModels(type, selectEl) {
    var names = getJSON(serverURL + 'json/' + type + '.json');
    selectEl.innerHTML = '';
    for (var i = 0; i < names.length; i++) {
        var opt = document.createElement('option');
        opt.value = i;
        opt.textContent = names[i];
        selectEl.appendChild(opt);
    }
}

var typeEl  = document.getElementById('adm_deviceType');
var modelEl = document.getElementById('adm_deviceModel');

populateModels(typeEl.value, modelEl);

typeEl.onchange = function () {
    populateModels(typeEl.value, modelEl);
};
</script>

<?php endif; ?>
</body>
</html>
