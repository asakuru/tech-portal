<?php
require_once 'config.php';
require_once 'functions.php'; // <--- LOAD BRAIN

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// --- CSRF PROTECTION ---
csrf_check();

// --- ROBUST ADMIN CHECK ---
$is_admin = is_admin();

$selected_date = $_GET['date'] ?? date('Y-m-d');
$msg = "";
$error = "";

// --- 1. DETERMINE VIEW MODE ---
$show_entry_mode = !$is_admin;
if ($is_admin && isset($_GET['view']) && $_GET['view'] === 'entry') {
    $show_entry_mode = true;
}

// =========================================================
//  GLOBAL ACTIONS
// =========================================================

// 2. DELETE JOB
if (isset($_GET['delete'])) {
    try {
        if ($is_admin) {
            $db->prepare("DELETE FROM jobs WHERE id=?")->execute([$_GET['delete']]);
        } else {
            $db->prepare("DELETE FROM jobs WHERE id=? AND user_id=?")->execute([$_GET['delete'], $user_id]);
        }
        header("Location: index.php?date=$selected_date" . ($show_entry_mode ? "&view=entry" : ""));
        exit;
    } catch (Exception $e) {
        $error = "Delete Failed: " . $e->getMessage();
    }
}

// 3. LOCK / UNLOCK DAY
if (isset($_POST['toggle_lock'])) {
    try {
        $new_status = ($_POST['toggle_lock'] == 'lock') ? 1 : 0;
        $stmt = $db->prepare("SELECT id FROM daily_logs WHERE user_id=? AND log_date=?");
        $stmt->execute([$user_id, $selected_date]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("UPDATE daily_logs SET is_locked=? WHERE id=?")->execute([$new_status, $exists['id']]);
        } else {
            $db->prepare("INSERT INTO daily_logs (user_id, log_date, is_locked) VALUES (?, ?, ?)")->execute([$user_id, $selected_date, $new_status]);
        }
        header("Location: index.php?date=$selected_date" . ($show_entry_mode ? "&view=entry" : ""));
        exit;
    } catch (Exception $e) {
        $error = "Lock Error: " . $e->getMessage();
    }
}

// 4. SAVE DAILY TRUCK LOG
if (isset($_POST['save_truck_log'])) {
    try {
        $pd_val = isset($_POST['extra_pd']) ? 1 : 0;

        $stmt = $db->prepare("SELECT id FROM daily_logs WHERE user_id=? AND log_date=?");
        $stmt->execute([$user_id, $selected_date]);
        $exists = $stmt->fetch();

        $odo = !empty($_POST['odometer']) ? $_POST['odometer'] : 0;
        $mil = !empty($_POST['mileage']) ? $_POST['mileage'] : 0;
        $gal = !empty($_POST['gallons']) ? $_POST['gallons'] : 0;
        $cost = !empty($_POST['fuel_cost']) ? $_POST['fuel_cost'] : 0;

        if ($exists) {
            $sql = "UPDATE daily_logs SET odometer=?, mileage=?, gallons=?, fuel_cost=?, extra_per_diem=? WHERE id=?";
            $db->prepare($sql)->execute([$odo, $mil, $gal, $cost, $pd_val, $exists['id']]);
        } else {
            $sql = "INSERT INTO daily_logs (user_id, log_date, odometer, mileage, gallons, fuel_cost, extra_per_diem) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $db->prepare($sql)->execute([$user_id, $selected_date, $odo, $mil, $gal, $cost, $pd_val]);
        }
        $msg = "✅ Daily Log Saved";
    } catch (Exception $e) {
        $error = "Log Error: " . $e->getMessage();
    }
}

// 5. ADD JOB (HANDLE SAVE VS DRAFT)
if (isset($_POST['add_job']) || isset($_POST['save_draft'])) {
    try {
        $is_draft = isset($_POST['save_draft']);

        // --- 1. RECALCULATE PAY ---
        $rates = get_active_rates($db);
        $pay = calculate_job_pay($_POST, $rates);

        // --- 2. COMBINE NOTES (SERVER-SIDE) ---
        $final_notes = "";

        // Helper to add field if exists
        $addField = function ($header, $key) use (&$final_notes) {
            if (!empty($_POST[$key])) {
                $final_notes .= $header . "\n" . trim($_POST[$key]) . "\n\n";
            }
        };

        $addField('//WHY MISSED//-----//', 'why_missed');
        $addField('//SUPERVISOR CONTACTED//-----//', 'supervisor');
        $addField('//WHAT WAS TO DECIDED OUTCOME//-----//', 'outcome');
        $addField('//WHAT IS THE COMPLAINT//-----//', 'complaint');
        $addField('//WHAT DID YOU DO TO RESOLVE THE ISSUE//-----//', 'resolution');
        $addField('//DID YOU REPLACE ANY EQUIPMENT//-----//', 'equip_replaced');
        $addField('//IS CUSTOMER SERVICE RESTORED//-----//', 'service_restored');

        if (!empty($_POST['misc_notes'])) {
            $final_notes .= "//ADDITIONAL WORK NOT LISTED ABOVE//\n" . trim($_POST['misc_notes']);
        }
        if (empty(trim($final_notes)) && !empty($_POST['addtl_work'])) {
            $final_notes = $_POST['addtl_work'];
        }
        $final_notes = trim($final_notes);

        // --- 3. TICI SIGNAL ---
        $hub_val = floatval($_POST['tici_hub'] ?? 0);
        $ont_val = floatval($_POST['tici_ont'] ?? 0);
        if ($hub_val != 0)
            $hub_val = -abs($hub_val);
        if ($ont_val != 0)
            $ont_val = -abs($ont_val);

        $tici = "";
        if ($hub_val || $ont_val) {
            $tici = "$hub_val db @ HUB\n$ont_val db @ ONT";
        }

        $sql = "INSERT INTO jobs (
            user_id, install_date, ticket_number, install_type, 
            cust_fname, cust_lname, cust_street, cust_city, cust_state, cust_zip, cust_phone,
            spans, conduit_ft, jacks_installed, drop_length, 
            path_notes, soft_jumper, ont_serial, eeros_serial, cat6_lines, 
            wifi_name, wifi_pass, addtl_work, pay_amount, 
            extra_per_diem, nid_installed, exterior_sealed, copper_removed, tici_signal,
            unbreakable_wifi, whole_home_wifi, cust_education, phone_test
        ) VALUES (
            :uid, :date, :ticket, :type,
            :fname, :lname, :street, :city, :state, :zip, :phone,
            :spans, :conduit, :jacks, :drop,
            :path, :soft, :ont, :eeros, :cat6,
            :wifi, :pass, :notes, :pay,
            :extra_pd, :nid, :sealed, :copper, :tici,
            :unbreak, :whole, :ed, :test
        )";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':uid' => $user_id,
            ':date' => $_POST['install_date'] ?? date('Y-m-d'),
            ':ticket' => $_POST['ticket_number'] ?? '',
            ':type' => $_POST['install_type'] ?? '',
            ':fname' => $_POST['cust_fname'] ?? '',
            ':lname' => $_POST['cust_lname'] ?? '',
            ':street' => $_POST['cust_street'] ?? '',
            ':city' => $_POST['cust_city'] ?? '',
            ':state' => $_POST['cust_state'] ?? '',
            ':zip' => $_POST['cust_zip'] ?? '',
            ':phone' => $_POST['cust_phone'] ?? '',
            ':spans' => $_POST['spans'] ?? 0,
            ':conduit' => $_POST['conduit_ft'] ?? 0,
            ':jacks' => $_POST['jacks_installed'] ?? 0,
            ':drop' => $_POST['drop_length'] ?? 0,
            ':path' => $_POST['path_notes'] ?? '',
            ':soft' => $_POST['soft_jumper'] ?? 0,
            ':ont' => $_POST['ont_serial'] ?? '',
            ':eeros' => $_POST['eeros_serial'] ?? '',
            ':cat6' => $_POST['cat6_lines'] ?? '',
            ':wifi' => $_POST['wifi_name'] ?? '',
            ':pass' => $_POST['wifi_pass'] ?? '',
            ':notes' => $final_notes,
            ':pay' => $pay,
            ':extra_pd' => 'No',
            ':nid' => isset($_POST['nid_installed']) ? 'Yes' : 'No',
            ':sealed' => isset($_POST['exterior_sealed']) ? 'Yes' : 'No',
            ':copper' => isset($_POST['copper_removed']) ? 'Yes' : 'No',
            ':tici' => $tici,
            ':unbreak' => isset($_POST['unbreakable_wifi']) ? 'Yes' : 'No',
            ':whole' => isset($_POST['whole_home_wifi']) ? 'Yes' : 'No',
            ':ed' => isset($_POST['cust_education']) ? 'Yes' : 'No',
            ':test' => isset($_POST['phone_test']) ? 'Yes' : 'No'
        ]);

        if ($is_draft) {
            $new_id = $db->lastInsertId();
            header("Location: edit_job.php?id=" . $new_id);
            exit;
        } else {
            header("Location: index.php?date=" . $_POST['install_date'] . ($show_entry_mode ? "&view=entry" : ""));
            exit;
        }

    } catch (Exception $e) {
        $error = "Save Failed: " . $e->getMessage();
    }
}

// =========================================================
//  DATA FETCHING
// =========================================================
$day_log = ['odometer' => '', 'mileage' => '', 'gallons' => '', 'fuel_cost' => '', 'extra_per_diem' => 0, 'is_locked' => 0];
$last_odo = 0;

try {
    // 1. Fetch Today's Log
    $stmt = $db->prepare("SELECT * FROM daily_logs WHERE user_id=? AND log_date=?");
    $stmt->execute([$user_id, $selected_date]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res)
        $day_log = $res;

    // 2. Fetch MOST RECENT Previous Odometer
    $stmt = $db->prepare("SELECT odometer FROM daily_logs WHERE user_id=? AND log_date < ? ORDER BY log_date DESC LIMIT 1");
    $stmt->execute([$user_id, $selected_date]);
    $prev_res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($prev_res)
        $last_odo = $prev_res['odometer'];

} catch (Exception $e) {
}
$is_day_locked = ($day_log['is_locked'] == 1);

// =========================================================
//  VIEW 1: FINANCIAL DASHBOARD (ADMIN ONLY)
// =========================================================
if (!$show_entry_mode) {
    // ... (Admin Logic) ...
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-d');
    $mileage_rate = 0.67;
    $lead_pay_rate = 500.00;
    try {
        $rows = $db->query("SELECT rate_key, amount FROM rate_card WHERE rate_key IN ('IRS_MILEAGE', 'LEAD_PAY')")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ($r['rate_key'] == 'IRS_MILEAGE')
                $mileage_rate = (float) $r['amount'];
            if ($r['rate_key'] == 'LEAD_PAY')
                $lead_pay_rate = (float) $r['amount'];
        }
    } catch (Exception $e) {
    }
    $user_map = [];
    try {
        $stmt = $db->query("SELECT id, username FROM users");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $user_map[$row['id']] = ucfirst($row['username']);
        }
    } catch (Exception $e) {
    }
    $job_revenue = 0;
    $active_weeks_by_user = [];
    $stmt = $db->prepare("SELECT * FROM jobs WHERE install_date BETWEEN ? AND ? ORDER BY install_date DESC");
    $stmt->execute([$start_date, $end_date]);
    $admin_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($admin_jobs as $j) {
        $job_revenue += $j['pay_amount'];
        if ($j['install_type'] !== 'DO' && $j['install_type'] !== 'ND') {
            $ts = strtotime($j['install_date']);
            $week_end = date('Y-m-d', strtotime($j['install_date'] . " +" . (6 - date('w', $ts)) . " days"));
            $active_weeks_by_user[$j['user_id']][$week_end] = true;
        }
    }
    $lead_pay_total = 0;
    foreach ($active_weeks_by_user as $uid => $weeks) {
        $lead_pay_total += (count($weeks) * $lead_pay_rate);
    }
    $gross_revenue = $job_revenue + $lead_pay_total;
    $total_miles = 0;
    $total_fuel_cost = 0;
    try {
        $stmt = $db->prepare("SELECT mileage, fuel_cost FROM daily_logs WHERE log_date BETWEEN ? AND ?");
        $stmt->execute([$start_date, $end_date]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $total_miles += $row['mileage'];
            $total_fuel_cost += $row['fuel_cost'];
        }
    } catch (Exception $e) {
    }
    $mileage_deduction = $total_miles * $mileage_rate;
    $net_income = $gross_revenue - $mileage_deduction;
}
// =========================================================
//  VIEW 2: JOB ENTRY
// =========================================================
else {
    $job_codes_list = [];
    $daily_total = 0;
    $weekly_grand_total = 0;

    try {
        $stmt = $db->query("SELECT rate_key, description FROM rate_card WHERE rate_key NOT IN ('IRS_MILEAGE', 'TAX_PERCENT', 'LEAD_PAY') ORDER BY rate_key ASC");
        $job_codes_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $r_stmt = $db->query("SELECT rate_key, amount FROM rate_card WHERE rate_key IN ('per_diem', 'extra_pd')");
        $r_data = $r_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $rate_std = $r_data['per_diem'] ?? 0;
        $rate_ext = $r_data['extra_pd'] ?? 0;

        $stmt = $db->prepare("SELECT * FROM jobs WHERE user_id = ? AND install_date = ? ORDER BY id DESC");
        $stmt->execute([$user_id, $selected_date]);
        $daily_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $day_job_pay = 0;
        $has_work_today = false;
        foreach ($daily_jobs as $j) {
            $day_job_pay += $j['pay_amount'];
            if ($j['install_type'] !== 'DO' && $j['install_type'] !== 'ND')
                $has_work_today = true;
        }

        $is_sunday = (date('w', strtotime($selected_date)) == 0);
        $day_pd = 0;
        if ($is_sunday || $has_work_today)
            $day_pd += $rate_std;

        $l_stmt = $db->prepare("SELECT extra_per_diem FROM daily_logs WHERE user_id=? AND log_date=?");
        $l_stmt->execute([$user_id, $selected_date]);
        $day_log_data = $l_stmt->fetch(PDO::FETCH_ASSOC);
        if ($day_log_data && $day_log_data['extra_per_diem'] == 1)
            $day_pd += $rate_ext;

        $daily_total = $day_job_pay + $day_pd;

        $ts = strtotime($selected_date);
        if (date('N', $ts) == 1) {
            $start_of_week = $selected_date;
        } else {
            $start_of_week = date('Y-m-d', strtotime('last monday', $ts));
        }
        $end_of_week = date('Y-m-d', strtotime($start_of_week . ' +6 days'));

        $w_stmt = $db->prepare("SELECT install_date, install_type, pay_amount FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ?");
        $w_stmt->execute([$user_id, $start_of_week, $end_of_week]);
        $week_jobs_all = $w_stmt->fetchAll(PDO::FETCH_ASSOC);

        $wl_stmt = $db->prepare("SELECT log_date, extra_per_diem FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
        $wl_stmt->execute([$user_id, $start_of_week, $end_of_week]);
        $week_logs_all = $wl_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        for ($i = 0; $i < 7; $i++) {
            $loop_date = date('Y-m-d', strtotime($start_of_week . " +$i days"));
            $is_sun = (date('w', strtotime($loop_date)) == 0);
            $d_pay = 0;
            $d_work = false;
            foreach ($week_jobs_all as $wj) {
                if ($wj['install_date'] === $loop_date) {
                    $d_pay += $wj['pay_amount'];
                    if ($wj['install_type'] !== 'DO' && $wj['install_type'] !== 'ND')
                        $d_work = true;
                }
            }
            $d_pd_calc = 0;
            if ($is_sun || $d_work)
                $d_pd_calc += $rate_std;
            if (isset($week_logs_all[$loop_date]) && $week_logs_all[$loop_date] == 1)
                $d_pd_calc += $rate_ext;
            $weekly_grand_total += ($d_pay + $d_pd_calc);
        }
        $lead_pay_amt = 0;
        if (function_exists('get_lead_pay_amount')) {
            $lead_pay_amt = get_lead_pay_amount($db);
        }
        $has_billable = false;
        if (function_exists('has_billable_work')) {
            $has_billable = has_billable_work($db, $user_id, $start_of_week, $end_of_week);
        }
        if ($has_billable && $lead_pay_amt > 0)
            $weekly_grand_total += $lead_pay_amt;

    } catch (Exception $e) {
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Tech Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
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
        function autoResize(el) {
            el.style.height = 'auto'; el.style.height = (el.scrollHeight) + 'px';
        }
        function initAutoResize() {
            document.querySelectorAll('textarea').forEach(el => {
                autoResize(el); el.addEventListener('input', () => autoResize(el));
            });
        }
        function toggleFields() {
            let el = document.getElementById('install_type');
            if (!el) return;
            let t = el.value;
            let hideAll = (t === 'DO' || t === 'ND');
            let isMissedGroup = (t === 'F009' || t === 'F011');
            let isRepairGroup = (t === 'F008');
            let isPhoneOnly = (t === 'F020');
            let isSimpleEntry = (isMissedGroup || isRepairGroup);

            document.getElementById('secDetails').style.display = hideAll ? 'none' : 'block';
            document.getElementById('secCustomer').style.display = 'block';
            document.getElementById('groupMissed').style.display = isMissedGroup ? 'block' : 'none';
            document.getElementById('groupRepair').style.display = isRepairGroup ? 'block' : 'none';
            document.getElementById('groupTechStandard').style.display = (isSimpleEntry) ? 'none' : 'block';

            let hardTech = document.getElementById('subTechSpecs');
            if (hardTech) hardTech.style.display = isPhoneOnly ? 'none' : 'block';
        }
        function forceNegative(el) {
            let val = parseFloat(el.value);
            if (!isNaN(val) && val > 0) el.value = (val * -1).toFixed(2);
        }
        function updateMiles() {
            let odoInput = document.getElementsByName('odometer')[0];
            let milesInput = document.getElementById('truck_miles');
            let currentOdo = parseFloat(odoInput.value);
            let lastOdo = parseFloat(odoInput.getAttribute('data-prev-odo'));
            if (!isNaN(currentOdo) && !isNaN(lastOdo) && lastOdo > 0 && currentOdo > lastOdo) {
                let diff = currentOdo - lastOdo; milesInput.value = diff.toFixed(1); calculateMPG();
            }
        }
        function calculateMPG() {
            let miles = parseFloat(document.getElementById('truck_miles').value);
            let gallons = parseFloat(document.getElementById('truck_gallons').value);
            let mpgBox = document.getElementById('mpg_result');
            let mpgWrapper = document.getElementById('mpg_wrapper');
            if (!isNaN(miles) && !isNaN(gallons) && gallons > 0) {
                let mpg = miles / gallons; mpgBox.innerText = mpg.toFixed(2) + " MPG";
                if (mpgWrapper) { mpgWrapper.style.borderColor = "var(--success-text)"; mpgBox.style.color = "var(--success-text)"; }
            } else {
                mpgBox.innerText = "--.-- MPG";
                if (mpgWrapper) { mpgWrapper.style.borderColor = "var(--border)"; mpgBox.style.color = "var(--text-muted)"; }
            }
        }
        function copyNotes() {
            let notes = "";
            let t = document.getElementById('install_type').value;
            let isSimple = (t === 'F009' || t === 'F011' || t === 'F008');

            if (isSimple) {
                // OLD FORMAT for simple/missed/repair jobs
                let addField = (header, name) => {
                    let el = document.getElementsByName(name)[0];
                    if (el && el.value.trim() !== "") notes += header + "\n" + el.value.trim() + "\n\n";
                };
                addField('//WHY MISSED//-----//', 'why_missed');
                addField('//SUPERVISOR CONTACTED//-----//', 'supervisor');
                addField('//WHAT WAS TO DECIDED OUTCOME//-----//', 'outcome');
                addField('//WHAT IS THE COMPLAINT//-----//', 'complaint');
                addField('//WHAT DID YOU DO TO RESOLVE THE ISSUE//-----//', 'resolution');
                addField('//DID YOU REPLACE ANY EQUIPMENT//-----//', 'equip_replaced');
                addField('//IS CUSTOMER SERVICE RESTORED//-----//', 'service_restored');
                
                let misc = document.getElementById('misc_notes').value;
                if (misc.trim() !== "") notes += "//ADDITIONAL WORK NOT LISTED ABOVE//\n" + misc.trim() + "\n\n";
            } else {
                // NEW STRICT FORMAT for installs
                let getVal = (n) => { let el = document.getElementsByName(n)[0]; return (el && el.value.trim()!=='') ? el.value.trim() : ""; };
                let getCheck = (n) => { let el = document.getElementsByName(n)[0]; return (el && el.checked) ? "Yes" : "No"; };
                
                // TYPE
                let sel = document.getElementById('install_type');
                let typeText = sel.options[sel.selectedIndex].text;
                if(typeText.includes(' - ')) typeText = typeText.split(' - ')[1];
                notes += "//WHAT TYPE OF INSTALL//\n" + typeText + "\n\n";

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
            
            // Populate preview
            document.getElementsByName('addtl_work')[0].value = notes;

            navigator.clipboard.writeText(notes).then(function () { alert("Copied!"); });
        }
        // COLLAPSIBLE TRUCK LOG
        function toggleTruckLog() {
            let content = document.getElementById('truckLogContent');
            let icon = document.getElementById('truckLogIcon');
            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.innerText = '▼';
            } else {
                content.style.display = 'none';
                icon.innerText = '▶';
            }
        }
    </script>
</head>

<body onload="toggleFields(); initAutoResize();">

    <?php include 'nav.php'; ?>

    <div class="container">

        <?php if (!$show_entry_mode): ?>
            <div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:end;">
                <div>
                    <h2 style="margin:0;">📅 Month to Date</h2>
                    <div style="color:var(--text-muted); font-size:0.9rem;"><?= date('F 1') ?> - <?= date('F j, Y') ?></div>
                </div>
                <div style="text-align:right;"><a href="?view=entry" class="btn" style="padding:6px 12px;">📝 Manually Enter
                        Job</a> <a href="financials.php" class="btn" style="padding:6px 12px;">Full Year &rarr;</a></div>
            </div>
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Gross Revenue</div>
                    <div class="kpi-value positive">$<?= number_format($gross_revenue, 2) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Mileage Deduction</div>
                    <div class="kpi-value">$<?= number_format($mileage_deduction, 2) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Est. Net Income</div>
                    <div class="kpi-value" style="color:var(--primary);">$<?= number_format($net_income, 2) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Actual Fuel</div>
                    <div class="kpi-value negative">$<?= number_format($total_fuel_cost, 2) ?></div>
                </div>
            </div>
            <div class="box">
                <h3 style="margin-top:0;">📋 Recent Activity (All Users)</h3>
                <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                    <tr style="text-align:left; color:var(--text-muted); border-bottom:2px solid var(--border);">
                        <th style="padding:8px;">Date</th>
                        <th>Tech</th>
                        <th>Ticket</th>
                        <th style="text-align:right;">Pay</th>
                        <th></th>
                    </tr>
                    <?php $recent = array_slice($admin_jobs, 0, 8);
                    foreach ($recent as $j):
                        $tech_name = $user_map[$j['user_id']] ?? 'Unknown'; ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:10px 8px;"><?= date('m/d', strtotime($j['install_date'])) ?></td>
                            <td style="padding:10px 8px;"><span class="tech-badge"><?= htmlspecialchars($tech_name) ?></span>
                            </td>
                            <td onclick="window.location='edit_job.php?id=<?= $j['id'] ?>'"
                                style="padding:10px 8px; cursor:pointer; font-weight:bold; color:var(--primary);">
                                <?= $j['ticket_number'] ?></td>
                            <td style="padding:10px 8px; text-align:right; font-weight:bold;">
                                $<?= number_format($j['pay_amount'], 2) ?></td>
                            <td style="padding:10px 8px; text-align:right;">
                                <a href="?delete=<?= $j['id'] ?>&date=<?= $selected_date ?>"
                                    onclick="if(!confirm('Delete Job?')) return false;" class="btn btn-small btn-danger">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

        <?php else: ?>
            <?php if ($is_admin): ?>
                <div style="margin-bottom:15px;"><a href="index.php" class="btn">&larr; Back to Dashboard</a></div>
            <?php endif; ?>
            <?php if ($msg): ?>
                <div class="alert" style="border-left:4px solid var(--success-text);"><?= $msg ?></div><?php endif; ?>
            <?php if ($error): ?>
                <div class="alert" style="background:var(--danger-bg); color:var(--danger-text);"><?= $error ?></div>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <a href="?date=<?= date('Y-m-d', strtotime($selected_date . ' -1 day')) ?>&view=entry"
                    class="btn btn-small btn-secondary">◀</a>
                <h3 style="margin:0;"><?= date('D, M j', strtotime($selected_date)) ?></h3>
                <a href="?date=<?= date('Y-m-d', strtotime($selected_date . ' +1 day')) ?>&view=entry"
                    class="btn btn-small btn-secondary">▶</a>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                <div class="box" style="text-align:center; padding:10px;">
                    <div style="font-size:0.8rem; color:var(--text-muted);">Day Total</div>
                    <div style="font-size:1.5rem; font-weight:bold; color:var(--primary);">
                        $<?= number_format($daily_total, 2) ?></div>
                </div>
                <div class="box" style="text-align:center; padding:10px;">
                    <div style="font-size:0.8rem; color:var(--text-muted);">Week Total</div>
                    <div style="font-size:1.5rem; font-weight:bold; color:var(--success-text);">
                        $<?= number_format($weekly_grand_total, 2) ?></div>
                </div>
            </div>

            <h4 style="margin:0 0 10px; color:var(--text-muted);">Jobs Today</h4>
            <?php if (empty($daily_jobs)): ?>
                <div class="box" style="text-align:center; padding:20px; color:var(--text-muted); margin-bottom:20px;">No jobs
                    entered for today.</div>
            <?php else:
                foreach ($daily_jobs as $job): ?>
                    <div class="box" style="margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
                        <div onclick="window.location='edit_job.php?id=<?= $job['id'] ?>'" style="cursor:pointer; flex:1;">
                            <div style="font-weight:bold;"><?= $job['ticket_number'] ?></div>
                            <div style="font-size:0.85rem; color:var(--text-muted);"><?= $job['install_type'] ?> •
                                <?= $job['cust_city'] ?></div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-weight:bold; color:var(--success-text); margin-bottom:5px;">
                                $<?= number_format($job['pay_amount'], 2) ?></div>
                            <a href="?date=<?= $selected_date ?>&delete=<?= $job['id'] ?>&view=entry"
                                onclick="if(!confirm('Delete this job?')) return false;" class="btn btn-small btn-danger">🗑️
                                Delete</a>
                        </div>
                    </div>
                <?php endforeach; endif; ?>

            <div class="box" style="margin-bottom:20px; background:var(--bg-card); border-left:4px solid var(--primary);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <div onclick="toggleTruckLog()" style="cursor:pointer; display:flex; align-items:center; gap:5px;">
                        <span id="truckLogIcon">▶</span>
                        <h4 style="margin:0; font-size:0.9rem;">🚛 Daily Truck Log</h4>
                    </div>
                    <form method="post" style="margin:0;"><?= csrf_field() ?><?php if ($is_day_locked): ?>
                            <button type="submit" name="toggle_lock" value="unlock" class="btn btn-small btn-secondary">🔓
                                Unlock</button>
                        <?php else: ?>
                            <button type="submit" name="toggle_lock" value="lock" class="btn btn-small btn-danger">🔒
                                Lock</button>
                        <?php endif; ?>
                    </form>
                </div>

                <div id="truckLogContent" style="display:none;">
                    <?php if ($is_day_locked): ?>
                        <?php
                        $l_mpg = "N/A";
                        if ($day_log['mileage'] > 0 && $day_log['gallons'] > 0) {
                            $l_mpg = number_format($day_log['mileage'] / $day_log['gallons'], 2) . " MPG";
                        }
                        ?>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; text-align:left;">
                            <div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Odometer</div>
                                <div style="font-weight:bold;"><?= $day_log['odometer'] ?></div>
                            </div>
                            <div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Miles</div>
                                <div style="font-weight:bold;"><?= $day_log['mileage'] ?></div>
                            </div>
                            <div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Gallons</div>
                                <div style="font-weight:bold;"><?= $day_log['gallons'] ?></div>
                            </div>
                            <div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Fuel Cost</div>
                                <div style="font-weight:bold;">$<?= $day_log['fuel_cost'] ?></div>
                            </div>
                            <div
                                style="grid-column: span 2; border-top:1px solid var(--border); padding-top:10px; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);">Performance</div>
                                    <div style="font-weight:bold; color:var(--success-text);"><?= $l_mpg ?></div>
                                </div>
                                <?php if ($day_log['extra_per_diem']): ?><span
                                        style="background:var(--success-text); color:#fff; padding:2px 6px; border-radius:4px; font-size:0.7rem;">EXTRA
                                        PD</span><?php endif; ?>
                            </div>
                        </div>

                    <?php else: ?>
                        <form method="post" style="display:flex; flex-direction:column; gap:10px;">
                            <?= csrf_field() ?>
                            <div style="display:flex; gap:10px;">
                                <div style="flex:1;">
                                    <label style="font-size:0.75rem;">Odometer</label>
                                    <input type="number" name="odometer" value="<?= htmlspecialchars($day_log['odometer']) ?>"
                                        data-prev-odo="<?= $last_odo ?>" oninput="updateMiles()" style="padding:6px; width:100%;">
                                </div>
                                <div style="flex:1;">
                                    <label style="font-size:0.75rem;">Miles</label>
                                    <input type="number" id="truck_miles" name="mileage"
                                        value="<?= htmlspecialchars($day_log['mileage']) ?>" oninput="calculateMPG()"
                                        style="padding:6px; width:100%;">
                                </div>
                            </div>

                            <div style="display:flex; gap:10px;">
                                <div style="flex:1;">
                                    <label style="font-size:0.75rem;">Gallons</label>
                                    <input type="number" step="0.001" id="truck_gallons" name="gallons"
                                        value="<?= htmlspecialchars($day_log['gallons']) ?>" oninput="calculateMPG()"
                                        style="padding:6px; width:100%;">
                                </div>
                                <div style="flex:1;">
                                    <label style="font-size:0.75rem;">Fuel $</label>
                                    <input type="number" step="0.01" name="fuel_cost"
                                        value="<?= htmlspecialchars($day_log['fuel_cost']) ?>" style="padding:6px; width:100%;">
                                </div>
                            </div>

                            <div style="display:flex; gap:10px; align-items:end;">
                                <div style="flex:1;">
                                    <label style="font-size:0.75rem;">Calculated MPG</label>
                                    <div id="mpg_wrapper"
                                        style="border:1px solid var(--border); border-radius:4px; padding:6px; background:var(--bg-input); text-align:center;">
                                        <span id="mpg_result" style="font-weight:bold; color:var(--text-muted);">--.--
                                            MPG</span>
                                    </div>
                                </div>
                                <div style="flex:1; display:flex; justify-content:space-between; align-items:center;">
                                    <label style="color:var(--success-text); font-weight:bold; font-size:0.8rem;">
                                        <input type="checkbox" name="extra_pd" value="1"
                                            <?= ($day_log['extra_per_diem'] == 1) ? 'checked' : '' ?>> Extra PD
                                    </label>
                                    <button type="submit" name="save_truck_log" class="btn btn-small">💾 Save</button>
                                </div>
                            </div>
                        </form>
                        <script>calculateMPG();</script>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$is_day_locked): ?>
                <div class="box">
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="grid-container">
                            <div><label>Date</label><input type="date" name="install_date" value="<?= $selected_date ?>" required>
                            </div>
                            <div><label>Ticket #</label><input type="text" name="ticket_number" required></div>
                        </div>
                        <div class="full-width" style="margin-top:10px;">
                            <label>Job Code</label>
                            <select name="install_type" id="install_type" onchange="toggleFields()" required
                                style="width:100%; padding:10px;">
                                <option value="" disabled selected>Select Code...</option>
                                <?php if (!empty($job_codes_list)):
                                    foreach ($job_codes_list as $jc): ?>
                                        <option value="<?= htmlspecialchars($jc['rate_key']) ?>">
                                            <?= htmlspecialchars($jc['rate_key']) ?> - <?= htmlspecialchars($jc['description']) ?>
                                        </option><?php endforeach; else: ?>
                                    <option value="I-6600">I-6600 - Install</option>
                                    <option value="R-6600">R-6600 - Repair</option>
                                    <option value="TC">TC - Trouble Call</option>
                                    <option value="F008">F008 - Wire Run</option>
                                    <option value="F009">F009 - Jack Install</option>
                                    <option value="F011">F011 - Misc</option>
                                    <option value="DO">DO - Day Off</option><?php endif; ?>
                            </select>
                        </div>

                        <div id="secDetails" style="margin-top:15px;">
                            <hr style="margin:15px 0; border:0; border-top:1px solid var(--border);">
                            <div id="secCustomer">
                                <div class="grid-container">
                                    <div><input type="text" name="cust_fname" placeholder="First Name"></div>
                                    <div><input type="text" name="cust_lname" placeholder="Last Name"></div>
                                </div>
                                <div style="margin-top:10px;"><input type="text" name="cust_street" placeholder="Address"
                                        style="width:100%;"></div>
                                <div class="grid-container" style="margin-top:10px;">
                                    <div><input type="text" name="cust_city" placeholder="City"></div>
                                    <div><input type="text" name="cust_zip" placeholder="Zip"></div>
                                </div>
                                <div class="grid-container" style="margin-top:10px;">
                                    <div><input type="text" name="cust_state" placeholder="State"></div>
                                    <div><input type="text" name="cust_phone" placeholder="Phone"></div>
                                </div>
                                <hr style="margin:15px 0; border:0; border-top:1px solid var(--border);">
                            </div>

                            <div id="groupMissed"
                                style="display:none; background:var(--bg-input); padding:15px; border-radius:8px; margin-bottom:15px;">
                                <h5 style="margin:0 0 10px; color:var(--text-muted);">Outcome Report</h5>
                                <div class="grow-wrap spacer"><textarea name="why_missed" placeholder="Why Missed?"></textarea>
                                </div>
                                <div class="grow-wrap spacer"><textarea name="supervisor"
                                        placeholder="Supervisor Contacted"></textarea></div>
                                <div class="grow-wrap"><textarea name="outcome" placeholder="Final Outcome"></textarea></div>
                            </div>

                            <div id="groupRepair"
                                style="display:none; background:var(--bg-input); padding:15px; border-radius:8px; margin-bottom:15px;">
                                <h5 style="margin:0 0 10px; color:var(--text-muted);">Repair Log</h5>
                                <div class="grow-wrap spacer"><textarea name="complaint"
                                        placeholder="Customer Complaint"></textarea></div>
                                <div class="grow-wrap spacer"><textarea name="resolution"
                                        placeholder="Resolution Steps"></textarea></div>
                                <div class="grow-wrap spacer"><textarea name="equip_replaced"
                                        placeholder="Equipment Replaced"></textarea></div>
                                <div class="grow-wrap"><textarea name="service_restored"
                                        placeholder="Service Restored?"></textarea></div>
                            </div>

                            <div id="groupTechStandard">
                                <div id="subTechSpecs">
                                    <div class="grid-container spacer">
                                        <div><input type="text" name="ont_serial" placeholder="ONT Serial"></div>
                                        <div><input type="text" name="eeros_serial" placeholder="Router Serial"></div>
                                    </div>
                                    <div class="grid-container spacer">
                                        <div><input type="text" name="wifi_name" placeholder="WiFi SSID"></div>
                                        <div><input type="text" name="wifi_pass" placeholder="WiFi Password"></div>
                                    </div>
                                    <div class="grid-container spacer">
                                        <div><input type="number" step="0.01" name="tici_hub"
                                                placeholder="Light @ Hub (e.g. -13.50)" onchange="forceNegative(this)"></div>
                                        <div><input type="number" step="0.01" name="tici_ont"
                                                placeholder="Light @ ONT (e.g. -16.20)" onchange="forceNegative(this)"></div>
                                    </div>
                                    <div class="grid-container spacer">
                                        <div><input type="number" name="spans" placeholder="Spans"></div>
                                        <div><input type="number" name="conduit_ft" placeholder="Conduit (Ft)"></div>
                                    </div>
                                    <div class="grid-container spacer">
                                        <div><input type="number" name="jacks_installed" placeholder="Jacks"></div>
                                        <div><input type="number" name="drop_length" placeholder="Drop (Ft)"></div>
                                    </div>
                                    <div class="grid-container spacer">
                                        <div><input type="number" name="soft_jumper" placeholder="Soft Jumper (Ft)"></div>
                                        <div><input type="text" name="cat6_lines" placeholder="Cat6 Lines"></div>
                                    </div>
                                    <div class="grow-wrap spacer">
                                        <label>Path Notes</label>
                                        <textarea name="path_notes" placeholder="Path details..."></textarea>
                                    </div>
                                </div>

                                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                    <label><input type="checkbox" name="nid_installed" value="Yes"> NID</label>
                                    <label><input type="checkbox" name="copper_removed" value="Yes"> Copper Rem</label>
                                    <label><input type="checkbox" name="exterior_sealed" value="Yes"> Sealed</label>
                                    <label><input type="checkbox" name="unbreakable_wifi" value="Yes"> Unbreakable</label>
                                    <label><input type="checkbox" name="whole_home_wifi" value="Yes"> Whole Home</label>
                                    <label><input type="checkbox" name="cust_education" value="Yes"> Cust Ed</label>
                                    <label><input type="checkbox" name="phone_test" value="Yes"> Phone Test</label>
                                </div>
                            </div>

                            <div style="margin-top:10px;" id="groupNotes">
                                <div style="margin-bottom:10px;">
                                    <label style="font-weight:bold; color:var(--text-muted);">Additional Notes (Misc)</label>
                                    <div class="grow-wrap"><textarea id="misc_notes" name="misc_notes"
                                            placeholder="Dog in yard, moved couch, etc..."></textarea></div>
                                </div>
                                <div style="margin-bottom:5px; text-align:right;">
                                    <button type="button" onclick="copyNotes()" class="btn btn-small btn-secondary">Copy
                                        Notes</button>
                                </div>
                                <div class="grow-wrap"><textarea name="addtl_work" placeholder="Full Notes Preview..." readonly
                                        style="background:#f3f4f6; color:#555;"></textarea></div>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; margin-top:20px;">
                            <button type="submit" name="save_draft" class="btn btn-secondary" style="flex:1;">💾 Save
                                Draft</button>
                            <button type="submit" name="add_job" class="btn" style="flex:2;">➕ Save Job</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
    <script>if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }</script>
</body>

</html>