<?php
require 'config.php';
require_once 'functions.php'; // Load the calculator

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$msg = "";
$mode = "paste"; // Modes: 'paste', 'review'
$data = []; // Holds the form data

// --- CSRF PROTECTION ---
csrf_check();

// --- HELPER: PARSING FUNCTION ---
function parse_job_text($full_text, $install_names)
{
    $d = [
        'install_date' => date('Y-m-d'),
        'ticket_number' => '',
        'install_type' => 'F001',
        'cust_fname' => '',
        'cust_lname' => '',
        'cust_street' => '',
        'cust_city' => '',
        'cust_state' => 'PA',
        'cust_zip' => '',
        'cust_phone' => '',
        'spans' => 0,
        'conduit_ft' => 0,
        'jacks_installed' => 0,
        'drop_length' => 0,
        'ont_serial' => '',
        'eeros_serial' => '',
        'wifi_name' => '',
        'wifi_pass' => '',
        'tici_hub' => '',
        'tici_ont' => '',
        'addtl_work' => '',
        'copper_removed' => '',
        'path_notes' => '',
        'soft_jumper' => '',
        'cat6_lines' => ''
    ];

    // A. TICKET (9 Digits on its own line preferred)
    if (preg_match('/^(\d{9})\s*$/m', $full_text, $m)) {
        $d['ticket_number'] = $m[1];
    } elseif (preg_match('/\b(\d{9})\b/', $full_text, $m)) {
        $d['ticket_number'] = $m[1];
    }

    // B. CUSTOMER (Look for Name then Address pattern)
    if (preg_match('/^([A-Z][a-z]+ [A-Z][a-z]+)\s*\n(\d+ .*?)\s*\n(.*?, PA \d{5})/m', $full_text, $m)) {
        $parts = explode(' ', $m[1]);
        $d['cust_fname'] = $parts[0];
        array_shift($parts);
        $d['cust_lname'] = implode(' ', $parts);
        $d['cust_street'] = trim($m[2]);
        if (preg_match('/^(.*?), PA (\d{5})/', $m[3], $z)) {
            $d['cust_city'] = trim($z[1]);
            $d['cust_zip'] = $z[2];
        }
    } elseif (preg_match('/^([A-Z][a-z]+ [A-Z][a-z]+)\s*\n(\d+ .*?)\s*\n/m', $full_text, $m)) {
        $parts = explode(' ', $m[1]);
        $d['cust_fname'] = $parts[0];
        array_shift($parts);
        $d['cust_lname'] = implode(' ', $parts);
        $d['cust_street'] = trim($m[2]);
    }

    // Phone (10 digits starting with 570 typically, or just 10 digits on own line)
    if (preg_match('/^(\d{10})\s*$/m', $full_text, $m))
        $d['cust_phone'] = $m[1];

    // C. INSTALL TYPE
    if (stripos($full_text, 'Single Play') !== false)
        $d['install_type'] = 'F001';
    elseif (stripos($full_text, 'Double Play') !== false)
        $d['install_type'] = 'F002';
    elseif (stripos($full_text, 'Triple Play') !== false)
        $d['install_type'] = 'F003';
    else {
        foreach ($install_names as $code => $name) {
            if (strpos($code, 'F014') === false && strpos($code, 'F006') === false && stripos($full_text, $code) !== false) {
                $d['install_type'] = $code;
                break;
            }
        }
    }

    // D. FIELDS
    if (preg_match('/\/\/DROP\/\/\s*(\d+)/', $full_text, $m))
        $d['drop_length'] = $m[1];
    if (preg_match('/\/\/SPANS\/\/\s*(\d+)/', $full_text, $m))
        $d['spans'] = $m[1];
    elseif (preg_match('/F006\s*x\s*(\d+)/i', $full_text, $m))
        $d['spans'] = $m[1];

    if (preg_match('/\/\/PATH\/\/\s*(.*?)\s*(?=\/\/)/s', $full_text, $m))
        $d['path_notes'] = trim($m[1]);

    if (preg_match('/\/\/UNDERGROUND CONDUIT PULLED\/\/\s*(.*?)\s*(?=\/\/)/s', $full_text, $m)) {
        $val = trim($m[1]);
        if (strtolower($val) !== 'no' && preg_match('/(\d+)/', $val, $x))
            $d['conduit_ft'] = $x[1];
    }

    if (preg_match('/\/\/FOOTAGE OF SOFT JUMPER.*?\/\/\s*(\d+)/', $full_text, $m))
        $d['soft_jumper'] = $m[1];
    if (preg_match('/\/\/ONT INSTALLED S\/N\/\/\s*([A-Z0-9]+)/', $full_text, $m))
        $d['ont_serial'] = $m[1];

    // EERO MULTI-CAPTURE FIX
    if (preg_match('/\/\/EEROS? INSTALLED S\/N\/\/(.*?)(?=\/\/|$)/s', $full_text, $block)) {
        if (preg_match_all('/\b([A-Z0-9]{12,})\b/', $block[1], $matches)) {
            $d['eeros_serial'] = implode(', ', $matches[1]);
        }
    }

    // Jacks logic
    if (preg_match('/\/\/JACKS INSTALLED\/\/\s*(\d+)/', $full_text, $m))
        $d['jacks_installed'] = $m[1];
    elseif (stripos($full_text, '1-F014-5') !== false)
        $d['jacks_installed'] = 2;

    if (preg_match('/\/\/CAT 6 LINES INSTALLED\/\/\s*(.*?)\s*(?=\/\/)/s', $full_text, $m))
        $d['cat6_lines'] = trim($m[1]);

    if (preg_match('/wifi name\s*\n\s*(.*)/i', $full_text, $m))
        $d['wifi_name'] = trim($m[1]);
    if (preg_match('/wifi password\s*\n\s*(.*)/i', $full_text, $m))
        $d['wifi_pass'] = trim($m[1]);

    if (preg_match('/(-?\d+\.?\d*)\s*db\s*@\s*HUB/i', $full_text, $m))
        $d['tici_hub'] = $m[1];
    if (preg_match('/(-?\d+\.?\d*)\s*db\s*@\s*ONT/i', $full_text, $m))
        $d['tici_ont'] = $m[1];

    if (stripos($full_text, 'Aerial drop removed') !== false || stripos($full_text, 'F014-7') !== false)
        $d['copper_removed'] = 'Yes';
    if (preg_match('/\/\/NID INSTALLED\/\/\s*Yes/i', $full_text))
        $d['nid_installed'] = 'Yes';
    if (preg_match('/\/\/EXTERIOR PENETRATION SEALED\/\/\s*Yes/i', $full_text))
        $d['exterior_sealed'] = 'Yes';

    // Notes
    if (preg_match('/\/\/ADDITIONAL WORK.*?\/\/\s*(.*)/s', $full_text, $m)) {
        $clean = trim($m[1]);
        if (stripos($clean, 'No additional work') === false)
            $d['addtl_work'] = $clean;
    }

    return $d;
}

// --- 1. HANDLE SAVE & NEXT ---
if (isset($_POST['confirm_save'])) {
    try {
        // USE CENTRAL FUNCTION FOR PAY
        $calc_data = $_POST;
        $calc_data['copper_removed'] = isset($_POST['copper_removed']) ? 'Yes' : 'No';
        $pay = calculate_job_pay($calc_data, $rates);

        $tici = "";
        if (!empty($_POST['tici_hub']) || !empty($_POST['tici_ont']))
            $tici = ($_POST['tici_hub'] ?: '?') . " db @ HUB\n" . ($_POST['tici_ont'] ?: '?') . " db @ ONT";
        $notes = $_POST['addtl_work'];

        $sql = "INSERT INTO jobs (id, user_id, install_date, ticket_number, install_type, cust_fname, cust_lname, cust_street, cust_city, cust_state, cust_zip, cust_phone, spans, conduit_ft, jacks_installed, drop_length, path_notes, soft_jumper, ont_serial, eeros_serial, cat6_lines, wifi_name, wifi_pass, tici_signal, addtl_work, pay_amount, extra_per_diem, nid_installed, exterior_sealed, copper_removed) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $id = uniqid('smart_');
        $db->prepare($sql)->execute([$id, $_SESSION['user_id'], $_POST['install_date'], $_POST['ticket_number'], $_POST['install_type'], $_POST['cust_fname'], $_POST['cust_lname'], $_POST['cust_street'], $_POST['cust_city'] ?? '', $_POST['cust_state'] ?? 'PA', $_POST['cust_zip'] ?? '', $_POST['cust_phone'] ?? '', $_POST['spans'], $_POST['conduit_ft'], $_POST['jacks_installed'], $_POST['drop_length'] ?? 0, $_POST['path_notes'] ?? '', $_POST['soft_jumper'] ?? '', $_POST['ont_serial'] ?? '', $_POST['eeros_serial'] ?? '', $_POST['cat6_lines'] ?? '', $_POST['wifi_name'] ?? '', $_POST['wifi_pass'] ?? '', $tici, $notes, $pay, 'No', isset($_POST['nid_installed']) ? 'Yes' : 'No', isset($_POST['exterior_sealed']) ? 'Yes' : 'No', isset($_POST['copper_removed']) ? 'Yes' : 'No']);

        $msg = "✅ Job Saved! (" . $_POST['ticket_number'] . ")";

        // CHECK QUEUE
        if (!empty($_SESSION['smart_queue'])) {
            $next_text = array_shift($_SESSION['smart_queue']);
            $data = parse_job_text($next_text, $install_names);
            $mode = "review";
            $msg .= " Reviewing next job (" . count($_SESSION['smart_queue']) . " remaining)...";
        } else {
            $mode = "paste";
            $msg .= " All jobs done!";
        }

    } catch (Exception $e) {
        $msg = "❌ Error: " . $e->getMessage();
        $mode = "review";
        $data = $_POST;
    }
}

// --- 2. HANDLE NEW PARSE ---
if (isset($_POST['parse_text'])) {
    $_SESSION['smart_queue'] = []; // Reset
    $full_text = $_POST['paste_content'];

    // SPLIT BY TICKET NUMBER (9 Digits at start of line)
    // We look for 9 digits that are likely a ticket header
    $matches = preg_split('/(?=^\d{9}\s*$)/m', $full_text, -1, PREG_SPLIT_NO_EMPTY);

    if (count($matches) > 0) {
        $first_job = array_shift($matches);
        $_SESSION['smart_queue'] = $matches; // Store rest
        $data = parse_job_text($first_job, $install_names);
        $mode = "review";
        if (count($matches) > 0)
            $msg = "Found " . (count($matches) + 1) . " jobs. Reviewing #1...";
    } else {
        // Try fallback if no split found (maybe just 1 job pasted weirdly)
        $data = parse_job_text($full_text, $install_names);
        $mode = "review";
    }
}

// --- 3. FETCH RECENT ---
$stmt = $db->prepare("SELECT * FROM jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$_SESSION['user_id']]);
$recent_jobs = $stmt->fetchAll();

function val($k, $arr)
{
    return htmlspecialchars($arr[$k] ?? '');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Smart Entry App</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .paste-box {
            width: 100%;
            height: 200px;
            padding: 15px;
            border: 2px dashed var(--border);
            border-radius: 8px;
            background: var(--bg-input);
            color: var(--text-main);
            font-family: monospace;
        }

        .review-card {
            background: var(--bg-card);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .recent-list {
            margin-top: 30px;
            border-top: 1px solid var(--border);
            padding-top: 20px;
        }

        .recent-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            border-bottom: 1px solid var(--border);
            font-size: 0.9rem;
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <?php if ($msg): ?>
            <div class="alert" style="border-left: 4px solid var(--success-text); margin-bottom: 20px; font-weight:bold;">
                <?= $msg ?>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'paste'): ?>
            <div style="max-width: 600px; margin: 0 auto;">
                <h2 style="text-align:center;">⚡ Batch Entry</h2>
                <p style="text-align:center; color:var(--text-muted);">Paste one or multiple jobs. We'll queue them up for
                    review.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <textarea name="paste_content" class="paste-box"
                        placeholder="Paste full ticket text here..."></textarea>
                    <button type="submit" name="parse_text" class="btn btn-full"
                        style="margin-top:15px; font-size:1.1rem;">🔍 Analyze Text</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'review'): ?>
            <div class="review-card">
                <h3 style="margin-top:0; border-bottom:1px solid var(--border); padding-bottom:10px;">
                    Review Job
                    <?= isset($_SESSION['smart_queue']) ? (count($_SESSION['smart_queue']) > 0 ? "(Queue: " . count($_SESSION['smart_queue']) . ")" : "(Last one)") : "" ?>
                </h3>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="grid-container">
                        <div><label>Date</label><input type="date" name="install_date"
                                value="<?= val('install_date', $data) ?>"></div>
                        <div><label>Ticket #</label><input type="text" name="ticket_number"
                                value="<?= val('ticket_number', $data) ?>"></div>
                    </div>
                    <div class="grid-container">
                        <div class="full-width"><label>Job Type</label>
                            <select name="install_type">
                                <?php foreach ($install_names as $c => $n): ?>
                                    <option value="<?= $c ?>" <?= ($data['install_type'] == $c) ? 'selected' : '' ?>><?= $c ?> - <?= $n ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="grid-container"
                        style="margin-top:10px; background:var(--bg-input); padding:10px; border-radius:4px;">
                        <div><label>First Name</label><input type="text" name="cust_fname"
                                value="<?= val('cust_fname', $data) ?>"></div>
                        <div><label>Last Name</label><input type="text" name="cust_lname"
                                value="<?= val('cust_lname', $data) ?>"></div>
                        <div style="grid-column: span 2;"><label>Street</label><input type="text" name="cust_street"
                                value="<?= val('cust_street', $data) ?>"></div>
                        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:5px; grid-column: span 2;">
                            <input type="text" name="cust_city" placeholder="City" value="<?= val('cust_city', $data) ?>">
                            <input type="text" name="cust_state" placeholder="State" value="<?= val('cust_state', $data) ?>">
                            <input type="text" name="cust_zip" placeholder="Zip" value="<?= val('cust_zip', $data) ?>">
                        </div>
                        <div style="grid-column: span 2;"><label>Phone</label><input type="text" name="cust_phone"
                                value="<?= val('cust_phone', $data) ?>"></div>
                    </div>
                    <div class="grid-container" style="margin-top:10px;">
                        <div><label>Spans</label><input type="number" name="spans" value="<?= val('spans', $data) ?>"></div>
                        <div><label>Conduit</label><input type="number" name="conduit_ft"
                                value="<?= val('conduit_ft', $data) ?>"></div>
                        <div><label>Jacks</label><input type="number" name="jacks_installed"
                                value="<?= val('jacks_installed', $data) ?>"></div>
                        <div><label>Drop Len</label><input type="number" name="drop_length"
                                value="<?= val('drop_length', $data) ?>"></div>
                        <div class="full-width"><label>Path</label><input type="text" name="path_notes"
                                value="<?= val('path_notes', $data) ?>"></div>
                        <div><label>Soft Jump</label><input type="text" name="soft_jumper"
                                value="<?= val('soft_jumper', $data) ?>"></div>
                        <div><label>Cat6 Lines</label><input type="text" name="cat6_lines"
                                value="<?= val('cat6_lines', $data) ?>"></div>
                    </div>
                    <div class="grid-container"
                        style="margin-top:15px; border-top:1px dashed var(--border); padding-top:10px;">
                        <div><label>ONT S/N</label><input type="text" name="ont_serial"
                                value="<?= val('ont_serial', $data) ?>"></div>
                        <div><label>Eero S/N</label><input type="text" name="eeros_serial"
                                value="<?= val('eeros_serial', $data) ?>"></div>
                        <div><label>Wifi Name</label><input type="text" name="wifi_name"
                                value="<?= val('wifi_name', $data) ?>"></div>
                        <div><label>Wifi Pass</label><input type="text" name="wifi_pass"
                                value="<?= val('wifi_pass', $data) ?>"></div>
                    </div>
                    <div class="grid-container" style="margin-top:10px;">
                        <div><label>TICI @ Hub</label><input type="text" name="tici_hub" value="<?= val('tici_hub', $data) ?>">
                        </div>
                        <div><label>TICI @ ONT</label><input type="text" name="tici_ont" value="<?= val('tici_ont', $data) ?>">
                        </div>
                    </div>
                    <div style="margin-top:15px; display:flex; gap:15px; flex-wrap:wrap;">
                        <label><input type="checkbox" name="copper_removed"
                                <?= ($data['copper_removed'] == 'Yes') ? 'checked' : '' ?>> Copper Rem?</label>
                        <label><input type="checkbox" name="nid_installed"
                                <?= ($data['nid_installed'] == 'Yes') ? 'checked' : '' ?>> NID?</label>
                        <label><input type="checkbox" name="exterior_sealed"
                                <?= ($data['exterior_sealed'] == 'Yes') ? 'checked' : '' ?>> Sealed?</label>
                    </div>
                    <div style="margin-top:15px;">
                        <label>Notes</label>
                        <textarea name="addtl_work" rows="3" style="width:100%;"><?= val('addtl_work', $data) ?></textarea>
                    </div>
                    <div style="display:flex; gap:10px; margin-top:20px;">
                        <a href="smart_entry.php" class="btn"
                            style="background:var(--bg-input); color:var(--text-main); border:1px solid var(--border);">Cancel
                            / Reset</a>
                        <button type="submit" name="confirm_save" class="btn btn-full" style="flex:2; font-weight:bold;">✅
                            Save & Next</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="recent-list">
            <h4 style="color:var(--text-muted); text-transform:uppercase;">Recently Added</h4>
            <?php if (count($recent_jobs) === 0): ?>
                <div style="text-align:center; padding:10px; color:var(--text-muted);">No recent jobs found.</div>
            <?php else: ?>
                <?php foreach ($recent_jobs as $j): ?>
                    <div class="recent-item">
                        <div><strong><?= $j['ticket_number'] ?></strong> <span style="color:var(--text-muted);"> -
                                <?= date('m/d', strtotime($j['install_date'])) ?></span></div>
                        <div><span
                                style="font-weight:bold; color:var(--success-text);">$<?= number_format($j['pay_amount'], 2) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <script>if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }</script>
</body>

</html>