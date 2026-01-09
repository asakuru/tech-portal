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
        $parsed['why_missed'] = extract_val('//WHY MISSED//-----//', $notes);
        $parsed['supervisor'] = extract_val('//SUPERVISOR CONTACTED//-----//', $notes);
        $parsed['outcome'] = extract_val('//WHAT WAS TO DECIDED OUTCOME//-----//', $notes);
        $parsed['complaint'] = extract_val('//WHAT IS THE COMPLAINT//-----//', $notes);
        $parsed['resolution'] = extract_val('//WHAT DID YOU DO TO RESOLVE THE ISSUE//-----//', $notes);
        $parsed['equip_replaced'] = extract_val('//DID YOU REPLACE ANY EQUIPMENT//-----//', $notes);
        $parsed['service_restored'] = extract_val('//IS CUSTOMER SERVICE RESTORED//-----//', $notes);
        $parsed['misc_notes'] = extract_val('//ADDITIONAL WORK NOT LISTED ABOVE//', $notes);

        // FALLBACK: If we have notes but parsing yielded nothing (headers mismatch?),
        // put the raw notes into misc_notes to prevent data loss.
        $has_data = false;
        foreach ($parsed as $k => $v) {
            if (!empty($v) && $k !== 'hub_val' && $k !== 'ont_val') $has_data = true;
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
    <title>Edit Job</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Make textareas look like inputs but growable */
        .grow-wrap {
            width: 100%;
            box-sizing: border-box;
        }

        .grow-wrap textarea {
            width: 100%;
            overflow: hidden;
            resize: none;
            min-height: 40px;
            padding: 10px;
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text-main);
            border-radius: 6px;
            font-family: inherit;
            font-size: 1rem;
            line-height: 1.4;
            transition: height 0.1s ease;
        }

        .grow-wrap textarea:focus {
            border-color: var(--primary);
            outline: none;
        }

        .spacer {
            margin-bottom: 15px;
        }
    </style>
    <script>
        // AUTO-GROW FUNCTION
        function autoResize(el) {
            el.style.height = 'auto'; // Reset to recalc
            el.style.height = (el.scrollHeight) + 'px'; // Set to content height
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
            let t = el.value;
            let hideAll = (t === 'DO' || t === 'ND');
            let isMissedGroup = (t === 'F009' || t === 'F011');
            let isRepairGroup = (t === 'F008');
            let isSimpleEntry = (isMissedGroup || isRepairGroup);

            document.getElementById('secCustomer').style.display = hideAll ? 'none' : 'block';
            document.getElementById('groupMissed').style.display = isMissedGroup ? 'block' : 'none';
            document.getElementById('groupRepair').style.display = isRepairGroup ? 'block' : 'none';
            document.getElementById('groupTechStandard').style.display = (isSimpleEntry) ? 'none' : 'block';
        }

        function forceNegative(el) {
            let val = parseFloat(el.value);
            if (!isNaN(val) && val > 0) el.value = (val * -1).toFixed(2);
        }

        function copyNotes() {
            let notes = "";
            let t = document.getElementsByName('install_type')[0].value;
            // In edit mode F002 is just 'F002', so we check against codes directly.
            let isMissed = (t === 'F009' || t === 'F011');
            let isRepair = (t === 'F008');

            if (isMissed) {
                // F009/F011 MISSED/SIMPLE FORMAT
                let addField = (header, id) => {
                    let el = document.getElementsByName(id)[0];
                    if (el && el.value.trim() !== "") notes += header + "\n" + el.value.trim() + "\n\n";
                };
                addField('//WHY MISSED//-----//', 'why_missed');
                addField('//SUPERVISOR CONTACTED//-----//', 'supervisor');
                addField('//WHAT WAS TO DECIDED OUTCOME//-----//', 'outcome');

                let misc = document.getElementById('misc_notes').value;
                if (misc.trim() !== "") notes += "//ADDITIONAL WORK NOT LISTED ABOVE//\n" + misc.trim() + "\n\n";
            } else if (isRepair) {
                // F008 REPAIR SPECIFIC FORMAT
                let addField = (header, id) => {
                    let el = document.getElementsByName(id)[0];
                    if (el && el.value.trim() !== "") notes += header + "\n" + el.value.trim() + "\n\n";
                };
                
                addField('//WHAT IS THE COMPLAINT//-----//', 'complaint');
                // Resolution usually goes with complaint or restored, but if filled we show it
                addField('//WHAT DID YOU DO TO RESOLVE THE ISSUE//-----//', 'resolution');
                
                // User requested specific headers without leading // for these two
                addField('DID YOU REPLACE ANY EQUIPMENT//-----//', 'equip_replaced');
                addField('IS CUSTOMER SERVICE RESTORED//-----//', 'service_restored');

                let misc = document.getElementById('misc_notes').value;
                if (misc.trim() !== "") notes += "//ADDITIONAL WORK NOT LISTED ABOVE//\n" + misc.trim() + "\n\n";
            } else {
                // NEW STRICT FORMAT
                let getVal = (n) => { let el = document.getElementsByName(n)[0]; return (el && el.value.trim()!=='') ? el.value.trim() : ""; };
                let getCheck = (n) => { let el = document.getElementsByName(n)[0]; return (el && el.checked) ? "Yes" : "No"; };
                
                // TYPE - In edit mode we only have the code (e.g. F002), so we display that unless we map it.
                // Given constraints, I'll display the code or best guess.
                // Actually, let's try to pass the description via a map if possible, but simplest is Code for now.
                notes += "//WHAT TYPE OF INSTALL//\n" + t + "\n\n";

                // DROP
                let drop = getVal('drop_length');
                notes += "//DROP//\n" + (drop ? drop + "'" : "No") + "\n\n";

                // SPANS
                let spans = getVal('spans');
                notes += "//SPANS//\n" + (spans ? spans + " Spans" : "No") + "\n\n";

                // PATH
                let path = getVal('path_notes');
                notes += "//PATH//\n" + (path ? path : "Standard path.") + "\n\n";

                // CONDUIT
                let cond = getVal('conduit_ft');
                notes += "//UNDERGROUND CONDUIT PULLED//\n" + (cond ? cond + "'" : "No") + "\n\n";

                // NID
                notes += "//NID INSTALLED//\n" + getCheck('nid_installed') + "\n\n";

                // SEALED
                notes += "//EXTERIOR PENETRATION SEALED//\n" + getCheck('exterior_sealed') + "\n\n";

                // SOFT JUMPER
                let soft = getVal('soft_jumper');
                notes += "//FOOTAGE OF SOFT JUMPER INSTALLED//\n" + (soft ? soft + "'" : "No") + "\n\n";

                // ONT
                let ont = getVal('ont_serial');
                notes += "//ONT INSTALLED S/N//\n" + (ont ? ont : "N/A") + "\n\n";

                // CAT6
                let cat = getVal('cat6_lines');
                notes += "//CAT 6 LINES INSTALLED//\n" + (cat ? cat : "No") + "\n\n";

                // JACKS
                let jacks = getVal('jacks_installed');
                notes += "//JACKS INSTALLED//\n" + (jacks ? jacks : "0") + "\n\n";

                // EEROS
                let eeros = getVal('eeros_serial');
                notes += "//EEROS INSTALLED S/N//\n" + (eeros ? eeros : "N/A") + "\n\n";

                // WIFI FEATURES
                let unbreak = getCheck('unbreakable_wifi');
                notes += "//UNBREAKABLE WIFI INSTALLED, OR REMOVED//\n" + (unbreak === 'Yes' ? 'Yes' : 'N/A') + "\n\n";

                let whole = getCheck('whole_home_wifi');
                notes += "//WHOLE HOME WIFI INSTALLED, OR REMOVED//\n" + (whole === 'Yes' ? 'Yes' : 'N/A') + "\n\n";

                // EDUCATION
                notes += "//CUSTOMER EDUCATION PERFORMED//\n" + getCheck('cust_education') + "\n\n";

                // PHONE TEST
                notes += "//PHONE INBOUND OUTBOUND TEST PERFORMED//\n" + getCheck('phone_test') + "\n\n";

                // COPPER
                notes += "//OLD AERIAL COPPER LINE REMOVED//\n" + getCheck('copper_removed') + "\n\n";

                // TICI
                let hub = getVal('tici_hub');
                let ontSig = getVal('tici_ont');
                notes += "//TICI BEFORE AND AFTER//\n";
                notes += (hub ? hub + " db @ HUB" : "N/A @ HUB") + "\n";
                notes += (ontSig ? ontSig + " db @ ONT" : "N/A @ ONT") + "\n\n";

                // MISC
                let misc = document.getElementById('misc_notes').value;
                notes += "//ADDITIONAL WORK NOT LISTED ABOVE//\n" + (misc.trim() !== "" ? misc.trim() : "No additional work.") + "\n\n";
            }
            notes = notes.trim();

            if (notes.trim() === "") {
                notes = document.getElementsByName('addtl_work')[0].value;
            } else {
                document.getElementsByName('addtl_work')[0].value = notes;
            }

            navigator.clipboard.writeText(notes).then(function () {
                let btn = document.getElementById('copyBtn');
                let origText = btn.innerText;
                btn.innerText = "✅ Copied!";
                setTimeout(() => { btn.innerText = origText; }, 2000);
            });
        }
    </script>
</head>

<body onload="toggleFields(); initAutoResize();">

    <?php include 'nav.php'; ?>

    <div class="container">

        <?php if (!$job): ?>
            <div class="box" style="text-align:center; padding:40px;">
                <h2 style="color:var(--danger-text);">Job Not Found</h2>
                <a href="index.php" class="btn">Dashboard</a>
            </div>
            <?php die(); ?>
        <?php endif; ?>

        <?php if ($msg): ?>
            <div class="alert" style="border-left:4px solid var(--success-text);"><?= $msg ?></div><?php endif; ?>

        <div style="margin-bottom:15px;">
            <a href="index.php?date=<?= $job['install_date'] ?>" class="btn"
                style="background:var(--bg-input); color:var(--text-main);">&larr; Back</a>
        </div>

        <div class="box">
            <form method="post">
                <?= csrf_field() ?>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 style="margin:0;">Edit Job: <?= htmlspecialchars($job['ticket_number']) ?></h3>
                    <button type="submit" name="delete_job" onclick="return confirm('Delete?')" class="btn"
                        style="background:var(--danger-bg); color:var(--danger-text); border:none; font-size:0.8rem;">🗑️
                        Delete</button>
                </div>

                <div class="grid-container">
                    <div><label>Date</label><input type="date" name="install_date" value="<?= $job['install_date'] ?>"
                            required></div>
                    <div><label>Ticket</label><input type="text" name="ticket_number"
                            value="<?= htmlspecialchars($job['ticket_number']) ?>" required></div>
                    <div><label>Type</label><input type="text" name="install_type"
                            value="<?= htmlspecialchars($job['install_type']) ?>" onchange="toggleFields()"></div>
                </div>

                <div id="secCustomer" class="spacer" style="margin-top:15px;">
                    <hr>
                    <div class="grid-container">
                        <div><input type="text" name="cust_fname" value="<?= htmlspecialchars($job['cust_fname']) ?>"
                                placeholder="First Name"></div>
                        <div><input type="text" name="cust_lname" value="<?= htmlspecialchars($job['cust_lname']) ?>"
                                placeholder="Last Name"></div>
                    </div>
                    <div style="margin-top:10px;"><input type="text" name="cust_street"
                            value="<?= htmlspecialchars($job['cust_street']) ?>" placeholder="Address"
                            style="width:100%;"></div>
                    <div class="grid-container" style="margin-top:10px;">
                        <div><input type="text" name="cust_city" value="<?= htmlspecialchars($job['cust_city']) ?>"
                                placeholder="City"></div>
                        <div><input type="text" name="cust_zip" value="<?= htmlspecialchars($job['cust_zip']) ?>"
                                placeholder="Zip"></div>
                    </div>
                    <input type="hidden" name="cust_state" value="<?= htmlspecialchars($job['cust_state']) ?>">
                    <input type="hidden" name="cust_phone" value="<?= htmlspecialchars($job['cust_phone']) ?>">
                </div>

                <div style="margin-top:15px;">
                    <hr>
                    <div id="groupMissed"
                        style="display:none; background:var(--bg-input); padding:15px; border-radius:8px; margin-bottom:15px;">
                        <h5 style="margin:0 0 10px; color:var(--text-muted);">Outcome Report</h5>
                        <div class="grow-wrap spacer"><textarea name="why_missed"
                                placeholder="Why Missed?"><?= htmlspecialchars($parsed['why_missed']) ?></textarea></div>
                        <div class="grow-wrap spacer"><textarea name="supervisor"
                                placeholder="Supervisor Contacted"><?= htmlspecialchars($parsed['supervisor']) ?></textarea>
                        </div>
                        <div class="grow-wrap"><textarea name="outcome"
                                placeholder="Final Outcome"><?= htmlspecialchars($parsed['outcome']) ?></textarea></div>
                    </div>

                    <div id="groupRepair"
                        style="display:none; background:var(--bg-input); padding:15px; border-radius:8px; margin-bottom:15px;">
                        <h5 style="margin:0 0 10px; color:var(--text-muted);">Repair Log</h5>
                        <div class="grow-wrap spacer"><textarea name="complaint"
                                placeholder="Customer Complaint"><?= htmlspecialchars($parsed['complaint']) ?></textarea>
                        </div>
                        <div class="grow-wrap spacer"><textarea name="resolution"
                                placeholder="Resolution Steps"><?= htmlspecialchars($parsed['resolution']) ?></textarea>
                        </div>
                        <div class="grow-wrap spacer"><textarea name="equip_replaced"
                                placeholder="Equipment Replaced"><?= htmlspecialchars($parsed['equip_replaced']) ?></textarea>
                        </div>
                        <div class="grow-wrap"><textarea name="service_restored"
                                placeholder="Service Restored?"><?= htmlspecialchars($parsed['service_restored']) ?></textarea>
                        </div>
                    </div>

                    <div id="groupTechStandard">
                        <div id="subTechSpecs">
                            <div class="grid-container spacer">
                                <div><input type="text" name="ont_serial"
                                        value="<?= htmlspecialchars($job['ont_serial']) ?>" placeholder="ONT Serial">
                                </div>
                                <div><input type="text" name="eeros_serial"
                                        value="<?= htmlspecialchars($job['eeros_serial']) ?>" placeholder="Router Serial">
                                </div>
                            </div>
                            <div class="grid-container spacer">
                                <div><input type="text" name="wifi_name"
                                        value="<?= htmlspecialchars($job['wifi_name']) ?>" placeholder="WiFi SSID"></div>
                                <div><input type="text" name="wifi_pass"
                                        value="<?= htmlspecialchars($job['wifi_pass']) ?>" placeholder="WiFi Password">
                                </div>
                            </div>
                            <div class="grid-container spacer">
                                <div><input type="number" step="0.01" name="tici_hub"
                                        value="<?= htmlspecialchars($parsed['hub_val']) ?>" placeholder="Light @ Hub"
                                        onchange="forceNegative(this)"></div>
                                <div><input type="number" step="0.01" name="tici_ont"
                                        value="<?= htmlspecialchars($parsed['ont_val']) ?>" placeholder="Light @ ONT"
                                        onchange="forceNegative(this)"></div>
                            </div>
                            <div class="grid-container spacer">
                                <div><input type="number" name="spans" value="<?= $job['spans'] ?>" placeholder="Spans">
                                </div>
                                <div><input type="number" name="conduit_ft" value="<?= $job['conduit_ft'] ?>"
                                        placeholder="Conduit (Ft)"></div>
                            </div>
                            <div class="grid-container spacer">
                                <div><input type="number" name="jacks_installed" value="<?= $job['jacks_installed'] ?>"
                                        placeholder="Jacks"></div>
                                <div><input type="number" name="drop_length" value="<?= $job['drop_length'] ?>"
                                        placeholder="Drop (Ft)"></div>
                            </div>
                            <div class="grid-container spacer">
                                <div><input type="number" name="soft_jumper" value="<?= $job['soft_jumper'] ?>"
                                        placeholder="Soft Jumper (Ft)"></div>
                                <div><input type="text" name="cat6_lines"
                                        value="<?= htmlspecialchars($job['cat6_lines']) ?>" placeholder="Cat6 Lines">
                                </div>
                            </div>
                            <div class="grow-wrap spacer">
                                <label>Path Notes</label>
                                <textarea name="path_notes"><?= htmlspecialchars($job['path_notes']) ?></textarea>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <label><input type="checkbox" name="nid_installed" value="Yes"
                                    <?= ($job['nid_installed'] == 'Yes') ? 'checked' : '' ?>> NID</label>
                            <label><input type="checkbox" name="copper_removed" value="Yes"
                                    <?= ($job['copper_removed'] == 'Yes') ? 'checked' : '' ?>> Copper Rem</label>
                            <label><input type="checkbox" name="exterior_sealed" value="Yes"
                                    <?= ($job['exterior_sealed'] == 'Yes') ? 'checked' : '' ?>> Sealed</label>
                            <label><input type="checkbox" name="unbreakable_wifi" value="Yes"
                                    <?= ($job['unbreakable_wifi'] == 'Yes') ? 'checked' : '' ?>> Unbreakable</label>
                            <label><input type="checkbox" name="whole_home_wifi" value="Yes"
                                    <?= ($job['whole_home_wifi'] == 'Yes') ? 'checked' : '' ?>> Whole Home</label>
                            <label><input type="checkbox" name="cust_education" value="Yes"
                                    <?= ($job['cust_education'] == 'Yes') ? 'checked' : '' ?>> Cust Ed</label>
                            <label><input type="checkbox" name="phone_test" value="Yes"
                                    <?= ($job['phone_test'] == 'Yes') ? 'checked' : '' ?>> Phone Test</label>
                            <label><input type="checkbox" name="extra_per_diem" value="Yes"
                                    <?= ($job['extra_per_diem'] == 'Yes') ? 'checked' : '' ?>> Extra PD</label>
                        </div>
                    </div>
                </div>

                <div style="margin-top:15px;">
                    <div class="spacer">
                        <label style="font-weight:bold; color:var(--text-muted);">Additional Notes (Misc)</label>
                        <div class="grow-wrap">
                            <textarea id="misc_notes" name="misc_notes"
                                placeholder="Dog in yard, moved couch, etc..."><?= htmlspecialchars($parsed['misc_notes']) ?></textarea>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                        <label style="margin:0;">Full Notes (Preview Only)</label>
                        <button type="button" id="copyBtn" onclick="copyNotes()" class="btn"
                            style="padding:4px 10px; font-size:0.8rem; background:var(--primary); color:#fff; border:none;">📋
                            Copy to Clipboard</button>
                    </div>
                    <div class="grow-wrap">
                        <textarea name="addtl_work" readonly
                            style="background:#f3f4f6; color:#555;"><?= htmlspecialchars($job['addtl_work']) ?></textarea>
                    </div>
                </div>

                <div style="margin-top:15px;">
                    <label>Pay Amount ($)</label>
                    <input type="number" step="0.01" value="<?= $job['pay_amount'] ?>" readonly
                        style="background:#f3f4f6; color:#666;">
                </div>

                <div style="display:flex; gap:10px; margin-top:20px;">
                    <button type="submit" name="save_draft" class="btn"
                        style="flex:1; background:var(--bg-input); color:var(--text-main); border:1px solid var(--border);">💾
                        Save Draft</button>
                    <button type="submit" name="update_job" class="btn" style="flex:2;">💾 Update Job</button>
                </div>
            </form>
        </div>
    </div>

    <script>if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }</script>
</body>

</html>