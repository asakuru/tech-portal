<?php
/**
 * api_sync.php
 * Handles JSON job submissions from the offline sync manager.
 */

require_once 'config.php';
require_once 'functions.php';

// Ensure JSON response
header('Content-Type: application/json');

// 1. Auth Check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$db = getDB();

// 2. Get JSON Input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

try {
    // 3. Extract & Sanitize Data (Mirroring entry.php logic)
    $install_date = $input['install_date'] ?? date('Y-m-d');
    $install_type = $input['install_type'] ?? '';

    // Valid types check
    if (!in_array($install_type, ['New', 'Repair', 'Upgrade', 'F008', 'F009', 'F011', 'ND', 'DO'])) {
        throw new Exception("Invalid Job Type");
    }

    $cust_fname = trim($input['cust_fname'] ?? '');
    $cust_lname = trim($input['cust_lname'] ?? '');

    // Create Full Name for legacy support
    $cust_name = trim("$cust_fname $cust_lname");

    $cust_street = trim($input['cust_street'] ?? '');
    $cust_city = trim($input['cust_city'] ?? '');
    $cust_state = trim($input['cust_state'] ?? '');
    $cust_zip = trim($input['cust_zip'] ?? '');
    $cust_phone = trim($input['cust_phone'] ?? ''); // New field
    $ticket_number = trim($input['ticket_number'] ?? '');

    // Technical Fields
    $tech_id = trim($input['tech_id'] ?? ''); // Should be auto-filled by JS usually
    $wifi_name = trim($input['wifi_name'] ?? '');
    $wifi_pass = trim($input['wifi_pass'] ?? '');
    $tici_hub = trim($input['tici_hub'] ?? '');
    $tici_ont = trim($input['tici_ont'] ?? '');
    $drop_length = trim($input['drop_length'] ?? '');
    $jacks = trim($input['jacks_installed'] ?? '');
    $spans = trim($input['spans'] ?? '');

    $legacy_ont = trim($input['ont_sn'] ?? '');
    $legacy_gpon = trim($input['gpon_sn'] ?? '');
    $legacy_stb = trim($input['stb_sn'] ?? '');
    $legacy_alarm = trim($input['alarm_mac'] ?? '');

    // Technical Specs (Consolidated)
    $conduit_ft = trim($input['conduit_ft'] ?? '');
    $soft_jumper = trim($input['soft_jumper'] ?? '');
    $cat6_lines = trim($input['cat6_lines'] ?? '');

    // Checkboxes (JSON usually sends true/false or "Yes"/null)
    $chk = function ($key) use ($input) {
        $val = $input[$key] ?? '';
        return ($val === 'Yes' || $val === true) ? 'Yes' : 'No';
    };

    $nid_installed = $chk('nid_installed');
    $copper_removed = $chk('copper_removed');
    $exterior_sealed = $chk('exterior_sealed');
    $unbreakable_wifi = $chk('unbreakable_wifi');
    $whole_home_wifi = $chk('whole_home_wifi');
    $cust_education = $chk('cust_education');
    $phone_test = $chk('phone_test');

    // Notes
    $path_notes = trim($input['path_notes'] ?? '');
    $misc_notes = trim($input['misc_notes'] ?? '');

    // Note Construction
    $notes = "";
    if ($path_notes)
        $notes .= "PATH NOTES: $path_notes\n";
    if ($misc_notes)
        $notes .= "$misc_notes\n";

    // If Job Type is DO/ND, clear fields
    if ($install_type === 'DO' || $install_type === 'ND') {
        $pay_amount = 0.00;
        // Keep date and type, clear rest if needed, but usually we just save minimal record
    } else {
        // Calculate Pay
        // Re-use logic or rely on rate card? 
        // Best to re-calculate server side to prevent tampering.
        $rates = get_active_rates($db);

        // Base Pay
        $pay_amount = 0.00;
        if (isset($rates[$install_type])) {
            $pay_amount += $rates[$install_type];
        }

        // Add-ons
        if ($unbreakable_wifi === 'Yes')
            $pay_amount += ($rates['unbreakable'] ?? 0);
        if ($whole_home_wifi === 'Yes')
            $pay_amount += ($rates['whole_home'] ?? 0);
        if ($nid_installed === 'Yes')
            $pay_amount += ($rates['new_nid'] ?? 0);
        if ($copper_removed === 'Yes')
            $pay_amount += ($rates['copper_rem'] ?? 0);

        // Per-unit items
        if (is_numeric($drop_length) && $drop_length > 0) {
            $pay_amount += $drop_length * ($rates['drop'] ?? 0);
        }
        if (is_numeric($conduit_ft) && $conduit_ft > 0) {
            $pay_amount += $conduit_ft * ($rates['conduit'] ?? 0);
        }
        if (is_numeric($jacks) && $jacks > 0) {
            $pay_amount += $jacks * ($rates['jack'] ?? 0);
        }
        // Spans? Soft Jumper? Cat6? (Assuming these might be tracked but not directly paid or paid differently)
        if (is_numeric($spans) && $spans > 0) {
            // Example: no rate for spans in basic logic usually, but add if exists
        }
    }

    // 4. Insert
    $stmt = $db->prepare("INSERT INTO jobs (
        user_id, install_date, install_type, 
        cust_name, cust_fname, cust_lname, cust_street, cust_city, cust_state, cust_zip, cust_phone,
        ticket_number, tech_id, wifi_name, wifi_pass,
        tici_hub, tici_ont, drop_length, jacks_installed, spans,
        ont_sn, gpon_sn, stb_sn, alarm_mac,
        conduit_ft, soft_jumper, cat6_lines,
        nid_installed, copper_removed, exterior_sealed, unbreakable_wifi, whole_home_wifi, cust_education, phone_test,
        addtl_work, pay_amount
    ) VALUES (
        ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?,
        ?, ?
    )");

    $stmt->execute([
        $user_id,
        $install_date,
        $install_type,
        $cust_name,
        $cust_fname,
        $cust_lname,
        $cust_street,
        $cust_city,
        $cust_state,
        $cust_zip,
        $cust_phone,
        $ticket_number,
        $tech_id,
        $wifi_name,
        $wifi_pass,
        $tici_hub,
        $tici_ont,
        $drop_length,
        $jacks,
        $spans,
        $legacy_ont,
        $legacy_gpon,
        $legacy_stb,
        $legacy_alarm,
        $conduit_ft,
        $soft_jumper,
        $cat6_lines,
        $nid_installed,
        $copper_removed,
        $exterior_sealed,
        $unbreakable_wifi,
        $whole_home_wifi,
        $cust_education,
        $phone_test,
        $notes,
        $pay_amount
    ]);

    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
