<?php
require 'config.php';
require_once 'functions.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// --- ROBUST ADMIN CHECK ---
$is_admin = is_admin();

// --- CSRF PROTECTION ---
csrf_check();

$job_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$msg = "";
$job = false;

// --- DELETE HANDLING ---
if (isset($_POST['delete_job'])) {
    if ($is_admin) {
        $stmt = $db->prepare("DELETE FROM jobs WHERE id = ?");
        $stmt->execute([$job_id]);
    } else {
        $stmt = $db->prepare("DELETE FROM jobs WHERE id = ? AND user_id = ?");
        $stmt->execute([$job_id, $user_id]);
    }
    header("Location: index.php");
    exit;
}

// --- FETCH JOB ---
if ($is_admin) {
    $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
    $stmt->execute([$job_id]);
} else {
    $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ? AND user_id = ?");
    $stmt->execute([$job_id, $user_id]);
}
$job = $stmt->fetch(PDO::FETCH_ASSOC);

// --- PARSE FIELDS (READING) ---
$parsed = [
    'why_missed' => '',
    'supervisor' => '',
    'outcome' => '',
    'complaint' => '',
    'resolution' => '',
    'equip_replaced' => '',
    'service_restored' => '',
    'misc_notes' => '',
    'hub_val' => '',
    'ont_val' => ''
];

if ($job) {
    $notes = $job['addtl_work'] ?? '';

    // Check if we have formatted headers.
    if (strpos($notes, '//') === false && !empty(trim($notes))) {
        $parsed['misc_notes'] = $notes;
    } else {
        function extract_val($header, $text)
        {
            $pattern = '/' . preg_quote($header, '/') . '\s*(.*?)\s*(?=\/\/|$)/s';
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[1]);
            }
            return '';
        }
        $parsed['why_missed'] = str_replace(['-----//', '-----'], '', extract_val('//WHY MISSED//', $notes));
        $parsed['supervisor'] = str_replace(['-----//', '-----'], '', extract_val('//SUPERVISOR CONTACTED//', $notes));
        $parsed['outcome'] = str_replace(['-----//', '-----'], '', extract_val('//WHAT WAS TO DECIDED OUTCOME//', $notes));
        // Try both formats: with and without -----// suffix
        $complaint = extract_val('//WHAT IS THE COMPLAINT//-----//', $notes);
        if ($complaint === '')
            $complaint = extract_val('//WHAT IS THE COMPLAINT//', $notes);
        $parsed['complaint'] = str_replace(['-----//', '-----'], '', $complaint);

        $resolution = extract_val('//WHAT DID YOU DO TO RESOLVE THE ISSUE//-----//', $notes);
        if ($resolution === '')
            $resolution = extract_val('//WHAT DID YOU DO TO RESOLVE THE ISSUE//', $notes);
        $parsed['resolution'] = str_replace(['-----//', '-----'], '', $resolution);

        // Try strict F008 headers first (with suffix), then fallback to other formats
        $equip = extract_val('DID YOU REPLACE ANY EQUIPMENT//-----//', $notes);
        if ($equip === '')
            $equip = extract_val('DID YOU REPLACE ANY EQUIPMENT', $notes);
        if ($equip === '')
            $equip = extract_val('//DID YOU REPLACE ANY EQUIPMENT//', $notes);
        $parsed['equip_replaced'] = str_replace(['-----//', '-----'], '', $equip);

        $restored = extract_val('IS CUSTOMER SERVICE RESTORED//-----//', $notes);
        if ($restored === '')
            $restored = extract_val('IS CUSTOMER SERVICE RESTORED', $notes);
        if ($restored === '')
            $restored = extract_val('//IS CUSTOMER SERVICE RESTORED//', $notes);
        $parsed['service_restored'] = str_replace(['-----//', '-----'], '', $restored);
        $parsed['misc_notes'] = extract_val('//ADDITIONAL WORK NOT LISTED ABOVE//', $notes);

        // FALLBACK: If we have notes but parsing yielded nothing (headers mismatch?),
        // put the raw notes into misc_notes to prevent data loss.
        $has_data = false;
        foreach ($parsed as $k => $v) {
            if (!empty($v) && $k !== 'hub_val' && $k !== 'ont_val')
                $has_data = true;
        }

        if (!$has_data && !empty(trim($notes))) {
            $parsed['misc_notes'] = $notes;
        }
    }

    $tici = $job['tici_signal'] ?? '';
    if (preg_match('/([\d\.-]+)\s*db @ HUB/', $tici, $m)) {
        $parsed['hub_val'] = $m[1];
    }
    if (preg_match('/([\d\.-]+)\s*db @ ONT/', $tici, $m)) {
        $parsed['ont_val'] = $m[1];
    }
}

// --- UPDATE HANDLING (SAVING) ---
if ($job && (isset($_POST['update_job']) || isset($_POST['save_draft']))) {
    try {
        $is_draft = isset($_POST['save_draft']);

        $rates = get_active_rates($db);
        $pay = calculate_job_pay($_POST, $rates);

        $new_notes = "";
        $fields_to_map = [
            '//WHY MISSED//-----//' => 'why_missed',
            '//SUPERVISOR CONTACTED//-----//' => 'supervisor',
            '//WHAT WAS TO DECIDED OUTCOME//-----//' => 'outcome',
            '//WHAT IS THE COMPLAINT//-----//' => 'complaint',
            '//WHAT DID YOU DO TO RESOLVE THE ISSUE//-----//' => 'resolution',
            '//DID YOU REPLACE ANY EQUIPMENT//-----//' => 'equip_replaced',
            '//IS CUSTOMER SERVICE RESTORED//-----//' => 'service_restored',
            '//ADDITIONAL WORK NOT LISTED ABOVE//' => 'misc_notes'
        ];

        foreach ($fields_to_map as $header => $post_key) {
            $val = trim($_POST[$post_key] ?? '');
            if (!empty($val)) {
                $new_notes .= $header . "\n" . $val . "\n\n";
            }
        }
        $new_notes = trim($new_notes);

        $hub_val = floatval($_POST['tici_hub'] ?? 0);
        $ont_val = floatval($_POST['tici_ont'] ?? 0);
        if ($hub_val != 0)
            $hub_val = -abs($hub_val);
        if ($ont_val != 0)
            $ont_val = -abs($ont_val);
        $tici_str = "";
        if ($hub_val || $ont_val)
            $tici_str = "$hub_val db @ HUB\n$ont_val db @ ONT";

        $sql = "UPDATE jobs SET 
                install_date=?, ticket_number=?, install_type=?, 
                cust_fname=?, cust_lname=?, cust_street=?, cust_city=?, cust_state=?, cust_zip=?, cust_phone=?,
                spans=?, conduit_ft=?, jacks_installed=?, drop_length=?, 
                path_notes=?, soft_jumper=?, ont_serial=?, eeros_serial=?, cat6_lines=?, 
                wifi_name=?, wifi_pass=?, 
                addtl_work=?, pay_amount=?, tici_signal=?,
                extra_per_diem=?, nid_installed=?, exterior_sealed=?, copper_removed=?, 
                unbreakable_wifi=?, whole_home_wifi=?, cust_education=?, phone_test=?
                WHERE id=?";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $_POST['install_date'],
            $_POST['ticket_number'],
            $_POST['install_type'],
            $_POST['cust_fname'],
            $_POST['cust_lname'],
            $_POST['cust_street'],
            $_POST['cust_city'],
            $_POST['cust_state'],
            $_POST['cust_zip'],
            $_POST['cust_phone'],
            $_POST['spans'],
            $_POST['conduit_ft'],
            $_POST['jacks_installed'],
            $_POST['drop_length'],
            $_POST['path_notes'],
            $_POST['soft_jumper'],
            $_POST['ont_serial'],
            $_POST['eeros_serial'],
            $_POST['cat6_lines'],
            $_POST['wifi_name'],
            $_POST['wifi_pass'],
            $new_notes,
            $pay,
            $tici_str,
            isset($_POST['extra_per_diem']) ? 'Yes' : 'No',
            isset($_POST['nid_installed']) ? 'Yes' : 'No',
            isset($_POST['exterior_sealed']) ? 'Yes' : 'No',
            isset($_POST['copper_removed']) ? 'Yes' : 'No',
            isset($_POST['unbreakable_wifi']) ? 'Yes' : 'No',
            isset($_POST['whole_home_wifi']) ? 'Yes' : 'No',
            isset($_POST['cust_education']) ? 'Yes' : 'No',
            isset($_POST['phone_test']) ? 'Yes' : 'No',
            $job_id
        ]);

        if ($is_draft) {
            $msg = "✅ Draft Saved! Pay: $" . number_format($pay, 2);
            echo "<meta http-equiv='refresh' content='0'>";
            exit;
        } else {
            header("Location: index.php?date=" . $_POST['install_date']);
            exit;
        }

    } catch (Exception $e) {
        $msg = "❌ Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Edit Job | Tech Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css?v=1.4">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <link rel="shortcut icon" href="favicon.ico?v=2">
    <link rel="apple-touch-icon" href="favicon.png">
    <?php include 'head_pwa.php'; ?>
    <style>
        .grow-wrap {
            width: 100%;
        }

        .grow-wrap textarea {
            width: 100%;
            overflow: hidden;
            resize: none;
            min-height: 44px;
            padding: 12px;
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text-main);
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 1rem;
            line-height: 1.4;
            transition: var(--transition);
        }

        .grow-wrap textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 2px var(--primary-glow);
        }

        .spacer {
            margin-bottom: 20px;
        }

        hr {
            border: none;
            border-top: 1px solid var(--border);
            margin: 20px 0;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 8px;
            letter-spacing: 0.05em;
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            margin-top: 20px;
            background: rgba(255, 255, 255, 0.02);
            padding: 16px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .checkbox-item input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
    </style>
    <script>
        function autoResize(el) {
            el.style.height = 'auto';
            el.style.height = (el.scrollHeight) + 'px';
        }

        function initAutoResize() {
            document.querySelectorAll('textarea').forEach(el => {
                autoResize(el);
                el.addEventListener('input', () => autoResize(el));
            });
        }

        function toggleFields() {
            let el = document.getElementsByName('install_type')[0];
            if (!el) return;
            let t = el.value.toUpperCase();
            let hideAll = (t === 'DO' || t === 'ND');
            let isMissedGroup = (t === 'F009' || t === 'F011');
            let isRepairGroup = (t === 'F008');
            let isSimpleEntry = (isMissedGroup || isRepairGroup);

            document.getElementById('secCustomer').style.display = hideAll ? 'none' : 'block';
            document.getElementById('groupMissed').style.display = isMissedGroup ? 'block' : 'none';
            document.getElementById('groupRepair').style.display = isRepairGroup ? 'block' : 'none';
            document.getElementById('groupTechStandard').style.display = (isSimpleEntry || hideAll) ? 'none' : 'block';

            let groupNotes = document.getElementById('groupNotes');
            if (groupNotes) groupNotes.style.display = hideAll ? 'none' : 'block';
        }

        function forceNegative(el) {
            let val = parseFloat(el.value);
            if (!isNaN(val) && val > 0) el.value = (val * -1).toFixed(2);
        }

        function generateNotesString() {
            let notes = "";
            let t = document.getElementsByName('install_type')[0].value.toUpperCase();
            let isMissed = (t === 'F009' || t === 'F011');
            let isRepair = (t === 'F008');

            if (isMissed) {
                let addField = (header, id) => {
                    let el = document.getElementsByName(id)[0];
                    if (el && el.value.trim() !== "") notes += header + "\n" + el.value.trim() + "\n\n";
                };
                addField('//WHY MISSED//', 'why_missed');
                addField('//SUPERVISOR CONTACTED//', 'supervisor');
                addField('//WHAT WAS TO DECIDED OUTCOME//', 'outcome');

                let miscEl = document.getElementsByName('misc_notes')[0];
                let misc = miscEl ? miscEl.value : "";
                if (misc.trim() !== "") notes += "//ADDITIONAL WORK NOT LISTED ABOVE//\n" + misc.trim() + "\n\n";
            } else if (isRepair) {
                let addField = (header, id) => {
                    let el = document.getElementsByName(id)[0];
                    if (el && el.value.trim() !== "") notes += header + "\n" + el.value.trim() + "\n\n";
                };
                addField('//WHAT IS THE COMPLAINT//-----//', 'complaint');
                addField('//WHAT DID YOU DO TO RESOLVE THE ISSUE//-----//', 'resolution');
                addField('DID YOU REPLACE ANY EQUIPMENT//-----//', 'equip_replaced');
                addField('IS CUSTOMER SERVICE RESTORED//-----//', 'service_restored');

                let miscEl = document.getElementsByName('misc_notes')[0];
                let misc = miscEl ? miscEl.value : "";
                if (misc.trim() !== "") notes += "//ADDITIONAL WORK NOT LISTED ABOVE//\n" + misc.trim() + "\n\n";
            } else {
                let getVal = (n) => { let el = document.getElementsByName(n)[0]; return (el && el.value.trim() !== '') ? el.value.trim() : ""; };
                let getCheck = (n) => { let el = document.getElementsByName(n)[0]; return (el && el.checked) ? "Yes" : "No"; };

                notes += "//WHAT TYPE OF INSTALL//\n" + t + "\n\n";
                let drop = getVal('drop_length');
                notes += "//DROP//\n" + (drop ? drop + "'" : "No") + "\n\n";
                let spans = getVal('spans');
                notes += "//SPANS//\n" + (spans ? spans + " Spans" : "No") + "\n\n";
                let path = getVal('path_notes');
                notes += "//PATH//\n" + (path ? path : "Standard path.") + "\n\n";
                let cond = getVal('conduit_ft');
                notes += "//UNDERGROUND CONDUIT PULLED//\n" + (cond ? cond + "'" : "No") + "\n\n";
                notes += "//NID INSTALLED//\n" + getCheck('nid_installed') + "\n\n";
                notes += "//EXTERIOR PENETRATION SEALED//\n" + getCheck('exterior_sealed') + "\n\n";
                let soft = getVal('soft_jumper');
                notes += "//FOOTAGE OF SOFT JUMPER INSTALLED//\n" + (soft ? soft + "'" : "No") + "\n\n";
                let ont = getVal('ont_serial');
                notes += "//ONT INSTALLED S/N//\n" + (ont ? ont : "N/A") + "\n\n";
                let cat = getVal('cat6_lines');
                notes += "//CAT 6 LINES INSTALLED//\n" + (cat ? cat : "No") + "\n\n";
                let jacks = getVal('jacks_installed');
                notes += "//JACKS INSTALLED//\n" + (jacks ? jacks : "0") + "\n\n";
                let eeros = getVal('eeros_serial');
                notes += "//EEROS INSTALLED S/N//\n" + (eeros ? eeros : "N/A") + "\n\n";
                let unbreak = getCheck('unbreakable_wifi');
                notes += "//UNBREAKABLE WIFI INSTALLED, OR REMOVED//\n" + (unbreak === 'Yes' ? 'Yes' : 'N/A') + "\n\n";
                let whole = getCheck('whole_home_wifi');
                notes += "//WHOLE HOME WIFI INSTALLED, OR REMOVED//\n" + (whole === 'Yes' ? 'Yes' : 'N/A') + "\n\n";
                notes += "//CUSTOMER EDUCATION PERFORMED//\n" + getCheck('cust_education') + "\n\n";
                notes += "//PHONE INBOUND OUTBOUND TEST PERFORMED//\n" + getCheck('phone_test') + "\n\n";
                let copperRem = getCheck('copper_removed');
                notes += "//OLD AERIAL COPPER LINE REMOVED//\n" + (copperRem === 'Yes' ? 'Yes, removed old copper per client request.' : 'No') + "\n\n";
                let hub = getVal('tici_hub');
                let ontSig = getVal('tici_ont');
                notes += "//TICI BEFORE AND AFTER//\n";
                notes += (hub ? hub + " db @ HUB" : "N/A @ HUB") + "\n";
                notes += (ontSig ? ontSig + " db @ ONT" : "N/A @ ONT") + "\n\n";
                let miscEl = document.getElementsByName('misc_notes')[0];
                let misc = miscEl ? miscEl.value : "";
                notes += "//ADDITIONAL WORK NOT LISTED ABOVE//\n" + (misc.trim() !== "" ? misc.trim() : "No additional work.") + "\n\n";
            }
            return notes.trim();
        }

        function updatePreview() {
            let notes = generateNotesString();
            if (notes === "") notes = "No additional work.";
            let el = document.getElementsByName('addtl_work')[0];
            if (el) {
                el.value = notes;
            }
        }

        function copyNotes() {
            let notes = generateNotesString();
            if (notes === "") notes = "No additional work.";
            updatePreview();

            navigator.clipboard.writeText(notes).then(function () {
                let btn = document.getElementById('copyBtn');
                let origText = btn.innerHTML;
                btn.innerHTML = "✅ Copied!";
                setTimeout(() => { btn.innerHTML = origText; }, 2000);
            });
        }

        function initLivePreview() {
            let inputs = document.querySelectorAll('input, textarea, select');
            inputs.forEach(el => {
                el.addEventListener('input', updatePreview);
                el.addEventListener('change', updatePreview);
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            initLivePreview();
        });
    </script>
</head>

<body onload="toggleFields(); initAutoResize();">
    <div class="app-container">
        <?php include 'nav.php'; ?>
        <main class="main-content">

            <div class="welcome-banner">
                <h2 style="font-weight: 800; letter-spacing: -0.03em;">✏️ Edit Job</h2>
                <div class="date" style="font-weight: 500; opacity: 0.8;">
                    <?= date('l, F j, Y', strtotime($job['install_date'])) ?>
                </div>
            </div>

            <?php if (!$job): ?>
                <div class="ha-card" style="text-align:center; padding:60px;">
                    <h2 style="color:var(--danger-text); margin-bottom: 20px;">Job Not Found</h2>
                    <a href="index.php" class="btn">Return to Dashboard</a>
                </div>
                <?php die(); ?>
            <?php endif; ?>

            <?php if ($msg): ?>
                <div class="alert success" style="margin-bottom: 20px;"><?= $msg ?></div>
            <?php endif; ?>

            <div style="margin-bottom:20px;">
                <a href="<?= htmlspecialchars(get_return_url('index.php?date=' . $job['install_date'])) ?>"
                    class="btn btn-secondary" style="padding: 10px 16px; border-radius: var(--radius);">&larr; Back</a>
            </div>

            <div class="ha-card">
                <form method="post">
                    <?= csrf_field() ?>
                    <div
                        style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 25px;">
                        <div class="section-header" style="margin: 0; border:none; padding: 0; grid-column: auto;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                            Job Details
                        </div>
                        <?php if ($is_admin || $job['user_id'] == $user_id): ?>
                            <button type="submit" name="delete_job" onclick="return confirm('Really delete this job?')"
                                class="btn"
                                style="background:rgba(229, 115, 115, 0.1); color:var(--danger-text); border:1px solid rgba(229, 115, 115, 0.2); font-size:0.85rem; padding: 6px 12px;">
                                🗑️ Delete
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="bento-grid">
                        <div class="form-group" style="grid-column: span 4;">
                            <label>Install Date</label>
                            <input type="date" name="install_date" value="<?= $job['install_date'] ?>" required>
                        </div>
                        <div class="form-group" style="grid-column: span 4;">
                            <label>Ticket Number</label>
                            <input type="text" name="ticket_number"
                                value="<?= htmlspecialchars($job['ticket_number']) ?>" required>
                        </div>
                        <div class="form-group" style="grid-column: span 4;">
                            <label>Unit Code</label>
                            <input type="text" name="install_type" value="<?= htmlspecialchars($job['install_type']) ?>"
                                onchange="toggleFields()">
                        </div>
                    </div>

                    <div id="secCustomer" class="spacer" style="margin-top:25px;">
                        <div class="section-header">
                            👤 Customer Details
                        </div>
                        <div class="bento-grid">
                            <div class="form-group" style="grid-column: span 6;">
                                <label>Customer First Name</label>
                                <input type="text" name="cust_fname" value="<?= htmlspecialchars($job['cust_fname']) ?>"
                                    placeholder="First Name">
                            </div>
                            <div class="form-group" style="grid-column: span 6;">
                                <label>Customer Last Name</label>
                                <input type="text" name="cust_lname" value="<?= htmlspecialchars($job['cust_lname']) ?>"
                                    placeholder="Last Name">
                            </div>
                            <div class="form-group" style="grid-column: span 12;">
                                <label>Phone</label>
                                <input type="text" name="cust_phone" value="<?= htmlspecialchars($job['cust_phone']) ?>"
                                    placeholder="Phone">
                            </div>
                            <div class="form-group" style="grid-column: span 12;">
                                <label>Street Address</label>
                                <input type="text" name="cust_street"
                                    value="<?= htmlspecialchars($job['cust_street']) ?>" placeholder="Address">
                            </div>
                            <div class="form-group" style="grid-column: span 5;">
                                <label>City</label>
                                <input type="text" name="cust_city" value="<?= htmlspecialchars($job['cust_city']) ?>"
                                    placeholder="City">
                            </div>
                            <div class="form-group" style="grid-column: span 4;">
                                <label>State</label>
                                <input type="text" name="cust_state" value="<?= htmlspecialchars($job['cust_state']) ?>"
                                    placeholder="ST">
                            </div>
                            <div class="form-group" style="grid-column: span 3;">
                                <label>Zip</label>
                                <input type="text" name="cust_zip" value="<?= htmlspecialchars($job['cust_zip']) ?>"
                                    placeholder="Zip">
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:25px;">
                        <div class="section-header">
                            📋 Job Report
                        </div>
                        <div id="groupMissed"
                            style="display:none; background:rgba(255,152,0,0.05); padding:20px; border-radius:var(--radius); border:1px solid rgba(255,152,0,0.1); margin-bottom:20px;">
                            <h5
                                style="margin:0 0 15px; color:var(--accent); text-transform:uppercase; font-size:0.8rem; letter-spacing:0.05em;">
                                Outcome Report</h5>
                            <div class="form-group spacer">
                                <label>Why Missed?</label>
                                <div class="grow-wrap"><textarea
                                        name="why_missed"><?= htmlspecialchars($parsed['why_missed']) ?></textarea>
                                </div>
                            </div>
                            <div class="form-group spacer">
                                <label>Supervisor Contacted</label>
                                <div class="grow-wrap"><textarea
                                        name="supervisor"><?= htmlspecialchars($parsed['supervisor']) ?></textarea>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Final Outcome</label>
                                <div class="grow-wrap"><textarea
                                        name="outcome"><?= htmlspecialchars($parsed['outcome']) ?></textarea></div>
                            </div>
                        </div>

                        <div id="groupRepair"
                            style="display:none; background:rgba(3, 169, 244, 0.05); padding:20px; border-radius:var(--radius); border:1px solid rgba(3, 169, 244, 0.1); margin-bottom:20px;">
                            <h5
                                style="margin:0 0 15px; color:var(--primary); text-transform:uppercase; font-size:0.8rem; letter-spacing:0.05em;">
                                Repair Log</h5>
                            <div class="form-group spacer">
                                <label>Customer Complaint</label>
                                <div class="grow-wrap"><textarea
                                        name="complaint"><?= htmlspecialchars($parsed['complaint']) ?></textarea></div>
                            </div>
                            <div class="form-group spacer">
                                <label>Resolution Steps</label>
                                <div class="grow-wrap"><textarea
                                        name="resolution"><?= htmlspecialchars($parsed['resolution']) ?></textarea>
                                </div>
                            </div>
                            <div class="form-group spacer">
                                <label>Equipment Replaced</label>
                                <div class="grow-wrap"><textarea
                                        name="equip_replaced"><?= htmlspecialchars($parsed['equip_replaced']) ?></textarea>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Service Restored?</label>
                                <div class="grow-wrap"><textarea
                                        name="service_restored"><?= htmlspecialchars($parsed['service_restored']) ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="section-header">
                            🔌 Technical Specs
                        </div>
                        <div id="groupTechStandard">
                            <div class="bento-grid">
                                <div class="form-group" style="grid-column: span 6;">
                                    <label>ONT Serial</label>
                                    <input type="text" name="ont_serial"
                                        value="<?= htmlspecialchars($job['ont_serial']) ?>" placeholder="ONT S/N">
                                </div>
                                <div class="form-group" style="grid-column: span 6;">
                                    <label>Router Serial</label>
                                    <input type="text" name="eeros_serial"
                                        value="<?= htmlspecialchars($job['eeros_serial']) ?>" placeholder="Router S/N">
                                </div>
                                <div class="form-group" style="grid-column: span 6;">
                                    <label>WiFi SSID</label>
                                    <input type="text" name="wifi_name"
                                        value="<?= htmlspecialchars($job['wifi_name']) ?>" placeholder="Name">
                                </div>
                                <div class="form-group" style="grid-column: span 6;">
                                    <label>WiFi Password</label>
                                    <input type="text" name="wifi_pass"
                                        value="<?= htmlspecialchars($job['wifi_pass']) ?>" placeholder="Pass">
                                </div>
                                <!-- Row 3: Lights, Drop, Spans, Jacks (12 cols) -->
                                <div class="form-group" style="grid-column: span 3;">
                                    <label>Hub (db)</label>
                                    <input type="number" step="0.01" name="tici_hub"
                                        value="<?= htmlspecialchars($parsed['hub_val']) ?>" placeholder="-X.XX"
                                        onchange="forceNegative(this)">
                                </div>
                                <div class="form-group" style="grid-column: span 3;">
                                    <label>ONT (db)</label>
                                    <input type="number" step="0.01" name="tici_ont"
                                        value="<?= htmlspecialchars($parsed['ont_val']) ?>" placeholder="-X.XX"
                                        onchange="forceNegative(this)">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>Drop</label>
                                    <input type="number" name="drop_length" value="<?= $job['drop_length'] ?>"
                                        placeholder="Ft">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>Spans</label>
                                    <input type="number" name="spans" value="<?= $job['spans'] ?>" placeholder="#">
                                </div>
                                <div class="form-group" style="grid-column: span 2;">
                                    <label>Jacks</label>
                                    <input type="number" name="jacks_installed" value="<?= $job['jacks_installed'] ?>"
                                        placeholder="#">
                                </div>
                                <!-- Row 4: Conduit, Soft Jumper, Cat6 -->
                                <div class="form-group" style="grid-column: span 3;">
                                    <label>Conduit (Ft)</label>
                                    <input type="number" name="conduit_ft" value="<?= $job['conduit_ft'] ?>">
                                </div>
                                <div class="form-group" style="grid-column: span 3;">
                                    <label>Soft Jumper</label>
                                    <input type="number" name="soft_jumper" value="<?= $job['soft_jumper'] ?>"
                                        placeholder="Ft">
                                </div>
                                <div class="form-group" style="grid-column: span 6;">
                                    <label>Cat6 Lines</label>
                                    <input type="text" name="cat6_lines"
                                        value="<?= htmlspecialchars($job['cat6_lines']) ?>">
                                </div>
                                <div class="form-group" style="grid-column: span 12;">
                                    <label>Path Notes</label>
                                    <div class="grow-wrap"><textarea
                                            name="path_notes"><?= htmlspecialchars($job['path_notes']) ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <label class="checkbox-item"><input type="checkbox" name="nid_installed" value="Yes"
                                        <?= ($job['nid_installed'] == 'Yes') ? 'checked' : '' ?>> NID</label>
                                <label class="checkbox-item"><input type="checkbox" name="copper_removed" value="Yes"
                                        <?= ($job['copper_removed'] == 'Yes') ? 'checked' : '' ?>> Copper Rem</label>
                                <label class="checkbox-item"><input type="checkbox" name="exterior_sealed" value="Yes"
                                        <?= ($job['exterior_sealed'] == 'Yes') ? 'checked' : '' ?>> Sealed</label>
                                <label class="checkbox-item"><input type="checkbox" name="unbreakable_wifi" value="Yes"
                                        <?= ($job['unbreakable_wifi'] == 'Yes') ? 'checked' : '' ?>> Unbreakable</label>
                                <label class="checkbox-item"><input type="checkbox" name="whole_home_wifi" value="Yes"
                                        <?= ($job['whole_home_wifi'] == 'Yes') ? 'checked' : '' ?>> Whole Home</label>
                                <label class="checkbox-item"><input type="checkbox" name="cust_education" value="Yes"
                                        <?= ($job['cust_education'] == 'Yes') ? 'checked' : '' ?>> Cust Ed</label>
                                <label class="checkbox-item"><input type="checkbox" name="phone_test" value="Yes"
                                        <?= ($job['phone_test'] == 'Yes') ? 'checked' : '' ?>> Phone Test</label>
                                <label class="checkbox-item"><input type="checkbox" name="extra_per_diem" value="Yes"
                                        <?= ($job['extra_per_diem'] == 'Yes') ? 'checked' : '' ?>> Extra PD</label>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:25px;" id="groupNotes">
                        <hr>
                        <div class="spacer">
                            <div class="section-header" style="margin: 0 0 15px; border:none; padding: 0;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                                Miscellaneous Notes
                            </div>
                            <div class="grow-wrap">
                                <textarea id="misc_notes" name="misc_notes"
                                    placeholder="Dog in yard, moved couch, etc..."><?= htmlspecialchars($parsed['misc_notes']) ?></textarea>
                            </div>
                        </div>

                        <div
                            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; margin-top:25px;">
                            <span
                                style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Full
                                Notes (Preview)</span>
                            <button type="button" id="copyBtn" onclick="copyNotes()" class="btn"
                                style="padding:6px 12px; font-size:0.8rem; background:var(--primary); color:#fff; border:none; border-radius:var(--radius-sm); font-weight:600;">📋
                                Copy All</button>
                        </div>
                        <div class="grow-wrap">
                            <textarea name="addtl_work" readonly
                                style="background:var(--bg-input); color:var(--text-muted); font-size:0.85rem; border-color:var(--border);"><?= htmlspecialchars($job['addtl_work']) ?></textarea>
                        </div>
                    </div>

                    <div
                        style="margin-top:30px; padding: 20px; background: rgba(129, 199, 132, 0.05); border: 1px dashed var(--success-text); border-radius: var(--radius); display: flex; justify-content: space-between; align-items: center;">
                        <div style="font-weight: 700; color: var(--success-text);">PAY ESTIMATE</div>
                        <div style="font-size:1.5rem; font-weight: 800; color: var(--success-text);">
                            $<?= number_format($job['pay_amount'], 2) ?></div>
                    </div>

                    <div style="display:flex; gap:16px; margin-top:30px;">
                        <button type="submit" name="save_draft" class="btn btn-secondary"
                            style="flex:1; padding:12px; border-radius:var(--radius);">💾
                            Save Draft</button>
                        <button type="submit" name="update_job" class="btn"
                            style="flex:2; background:var(--primary); color:white; border:none; padding:12px; border-radius:var(--radius);">💾
                            Update Job</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>

</html>