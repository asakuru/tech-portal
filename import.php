<?php
require 'config.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$msg = "";
$error = "";
$success_count = 0;
$fail_count = 0;

// Fetch Users for Admin Dropdown
$users = [];
if ($_SESSION['role'] === 'admin') {
    $users = $db->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll();
}

// --- HELPER: CLEAN BOOLEANS (Yes/No) ---
function cleanBool($val) {
    $v = strtolower(trim($val));
    if (in_array($v, ['yes', 'y', 'true', '1', 'on'])) return 'Yes';
    return 'No';
}

// --- HANDLE IMPORT ---
if (isset($_POST['import'])) {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
        
        // DETERMINE TARGET USER
        $target_user_id = $_SESSION['user_id']; // Default to self
        if ($_SESSION['role'] === 'admin' && !empty($_POST['target_user'])) {
            $target_user_id = $_POST['target_user'];
        }

        $filename = $_FILES['csv_file']['tmp_name'];
        $file = fopen($filename, 'r');

        // 1. READ HEADER ROW
        $headers = fgetcsv($file);
        
        if (!$headers) {
            $error = "File appears to be empty.";
        } else {
            // Normalize headers for easier matching
            $headers = array_map(function($h) { return strtolower(trim($h)); }, $headers);

            // 2. DEFINE MAPPING (CSV Header Keyword -> DB Column)
            // The script will look for the KEY in the CSV header to fill the VALUE in the DB
            $map = [
                'date' => 'install_date',
                'ticket' => 'ticket_number',
                'type' => 'install_type',
                'customer' => 'cust_name', 'name' => 'cust_name',
                'address' => 'cust_address',
                'phone' => 'cust_phone',
                'pay' => 'pay_amount', 'amount' => 'pay_amount',
                'notes' => 'addtl_work', 'work' => 'addtl_work',
                'spans' => 'spans',
                'conduit' => 'conduit_ft',
                'jacks' => 'jacks_installed',
                'drop' => 'drop_length',
                'soft' => 'soft_jumper', 'jumper' => 'soft_jumper',
                'serial' => 'ont_serial', 'ont' => 'ont_serial',
                'eero' => 'eeros_serial',
                'cat6' => 'cat6_lines',
                'wifi name' => 'wifi_name', 'ssid' => 'wifi_name',
                'wifi pass' => 'wifi_pass', 'password' => 'wifi_pass',
                // Checkboxes
                'nid' => 'nid_installed',
                'seal' => 'exterior_sealed',
                'copper' => 'copper_removed',
                'pd' => 'extra_per_diem', 'per diem' => 'extra_per_diem'
            ];

            // Locate Indices
            $indices = [];
            foreach ($map as $key => $db_col) {
                $idx = array_search($key, $headers);
                if ($idx !== false) {
                    // If multiple keys map to same DB col (e.g. 'customer' and 'name'), last one wins
                    $indices[$db_col] = $idx;
                }
            }

            // Validation: Must have Date and Ticket
            if (!isset($indices['install_date']) || !isset($indices['ticket_number'])) {
                $error = "❌ Error: CSV must contain 'Date' and 'Ticket' columns.";
            } else {
                
                $db->beginTransaction();
                
                while (($row = fgetcsv($file)) !== false) {
                    try {
                        // Extract Core Data
                        $raw_date = $row[$indices['install_date']] ?? null;
                        $ticket = $row[$indices['ticket_number']] ?? null;
                        
                        if (empty($raw_date) || empty($ticket)) continue;

                        $clean_date = date('Y-m-d', strtotime($raw_date));
                        
                        // Extract Optional Data (Default to defaults if missing in CSV)
                        $install_type = isset($indices['install_type']) ? ($row[$indices['install_type']] ?? 'Imported') : 'Imported';
                        $cust_name    = isset($indices['cust_name']) ? ($row[$indices['cust_name']] ?? '') : '';
                        $cust_addr    = isset($indices['cust_address']) ? ($row[$indices['cust_address']] ?? '') : '';
                        $cust_phone   = isset($indices['cust_phone']) ? ($row[$indices['cust_phone']] ?? '') : '';
                        
                        // Numeric Fields
                        $spans   = isset($indices['spans']) ? (int)($row[$indices['spans']] ?? 0) : 0;
                        $conduit = isset($indices['conduit_ft']) ? (int)($row[$indices['conduit_ft']] ?? 0) : 0;
                        $jacks   = isset($indices['jacks_installed']) ? (int)($row[$indices['jacks_installed']] ?? 0) : 0;
                        $drop    = isset($indices['drop_length']) ? (int)($row[$indices['drop_length']] ?? 0) : 0;
                        
                        // Text Fields
                        $soft   = isset($indices['soft_jumper']) ? ($row[$indices['soft_jumper']] ?? '') : '';
                        $ont    = isset($indices['ont_serial']) ? ($row[$indices['ont_serial']] ?? '') : '';
                        $eero   = isset($indices['eeros_serial']) ? ($row[$indices['eeros_serial']] ?? '') : '';
                        $cat6   = isset($indices['cat6_lines']) ? ($row[$indices['cat6_lines']] ?? '') : '';
                        $ssid   = isset($indices['wifi_name']) ? ($row[$indices['wifi_name']] ?? '') : '';
                        $pass   = isset($indices['wifi_pass']) ? ($row[$indices['wifi_pass']] ?? '') : '';
                        $notes  = isset($indices['addtl_work']) ? ($row[$indices['addtl_work']] ?? '') : '';
                        
                        // Money
                        $pay = 0.00;
                        if (isset($indices['pay_amount'])) {
                             $pay = (float)preg_replace('/[^0-9.]/', '', $row[$indices['pay_amount']]);
                        }

                        // Booleans (Yes/No)
                        $nid    = isset($indices['nid_installed']) ? cleanBool($row[$indices['nid_installed']]) : 'No';
                        $seal   = isset($indices['exterior_sealed']) ? cleanBool($row[$indices['exterior_sealed']]) : 'No';
                        $copper = isset($indices['copper_removed']) ? cleanBool($row[$indices['copper_removed']]) : 'No';
                        $pd     = isset($indices['extra_per_diem']) ? cleanBool($row[$indices['extra_per_diem']]) : 'No';

                        // SQL INSERT
                        $sql = "INSERT INTO jobs (
                            id, user_id, install_date, ticket_number, install_type, 
                            cust_name, cust_address, cust_phone,
                            spans, conduit_ft, jacks_installed, drop_length,
                            soft_jumper, ont_serial, eeros_serial, cat6_lines,
                            wifi_name, wifi_pass, addtl_work, pay_amount,
                            extra_per_diem, nid_installed, exterior_sealed, copper_removed
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

                        $stmt = $db->prepare($sql);
                        $stmt->execute([
                            uniqid('imp_'), $target_user_id, $clean_date, $ticket, $install_type,
                            $cust_name, $cust_addr, $cust_phone,
                            $spans, $conduit, $jacks, $drop,
                            $soft, $ont, $eero, $cat6,
                            $ssid, $pass, $notes, $pay,
                            $pd, $nid, $seal, $copper
                        ]);
                        $success_count++;

                    } catch (Exception $e) {
                        $fail_count++;
                    }
                }
                
                $db->commit();
                $msg = "✅ Success! Imported $success_count jobs for User ID: " . $target_user_id;
            }
        }
        fclose($file);
    } else {
        $error = "Please select a valid CSV file.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Import Jobs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
    <div class="box" style="text-align:center;">
        <h2>📥 Full Data Import</h2>
        <p style="color:var(--text-muted); margin-bottom:20px;">
            Bulk upload jobs with full details.
        </p>
        
        <?php if($msg): ?><div class="alert" style="border-left: 4px solid var(--success-text);"><?= $msg ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger-text); border:none;"><?= $error ?></div><?php endif; ?>
        
        <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
            
            <?php if($_SESSION['role'] === 'admin'): ?>
                <div style="text-align:left; margin-bottom:15px;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Import For User:</label>
                    <select name="target_user" style="width:100%; padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-input); color:var(--text-main);">
                        <?php foreach($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($u['id'] == $_SESSION['user_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <input type="file" name="csv_file" accept=".csv" required style="margin-bottom:20px; padding:10px; border:1px solid var(--border); border-radius:6px; width:100%; box-sizing:border-box;">
            <br>
            <button type="submit" name="import" class="btn btn-full">Upload & Import</button>
        </form>
    </div>

    <div class="box" style="margin-top:20px;">
        <h3>📋 CSV Headers Guide</h3>
        <p style="font-size:0.9rem; color:var(--text-muted);">
            Your CSV <strong>header row</strong> can contain any of these columns (order doesn't matter).<br>
            Only <strong>Date</strong> and <strong>Ticket</strong> are required.
        </p>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:0.85rem; margin-top:15px;">
            <div>
                <strong>Date</strong> (Required)<br>
                <strong>Ticket</strong> (Required)<br>
                <strong>Type</strong> (e.g. F002)<br>
                <strong>Pay</strong><br>
                <strong>Customer</strong><br>
                <strong>Address</strong><br>
                <strong>Phone</strong><br>
                <strong>Notes</strong><br>
                <strong>Wifi Name</strong> / <strong>Wifi Pass</strong>
            </div>
            <div>
                <strong>Spans</strong> (Number)<br>
                <strong>Conduit</strong> (Number)<br>
                <strong>Jacks</strong> (Number)<br>
                <strong>Drop</strong> (Number)<br>
                <strong>Serial</strong> (ONT)<br>
                <strong>Eero</strong> (Serial)<br>
                <strong>NID</strong> (Yes/No)<br>
                <strong>Seal</strong> (Yes/No)<br>
                <strong>PD</strong> (Extra Per Diem)
            </div>
        </div>
    </div>
</div>

<script>
    if(localStorage.getItem('theme')==='dark'){document.body.classList.add('dark-mode');}
</script>
</body>
</html>