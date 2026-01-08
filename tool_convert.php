<?php
require 'config.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

// --- HANDLE CONVERSION ---
if (isset($_POST['convert'])) {
    $raw = $_POST['raw_text'];
    $lines = explode("\n", $raw);
    $lines = array_map('trim', $lines);
    
    $jobs = [];
    $current_job = [];
    
    // MAPPING (Same as Smart Entry)
    $map = [
        '//WHAT TYPE OF INSTALL//' => 'install_type', 
        '//DROP//' => 'drop_length', 
        '//SPANS//' => 'spans',
        '//PATH//' => 'notes_append', 
        '//ADDITIONAL WORK//' => 'notes_append',
        '//ONT INSTALLED//' => 'ont_serial', 
        '//EEROS INSTALLED//' => 'eeros_serial',
        '//EXTERIOR PENETRATION SEALED//' => 'exterior_sealed', 
        '//NID INSTALLED//' => 'nid_installed',
        '//OLD AERIAL COPPER//' => 'copper_removed', 
        '//SOFT JUMPER//' => 'soft_jumper',
        '//CAT 6//' => 'cat6_lines', 
        '//JACKS//' => 'jacks_installed', 
        '//UNDERGROUND CONDUIT//' => 'conduit_ft',
        '//WIFI NAME//' => 'wifi_name', '//SSID//' => 'wifi_name',
        '//WIFI PASSWORD//' => 'wifi_pass', '//PASSWORD//' => 'wifi_pass',
        // Virtuals
        '//CUSTOMER EDUCATION//' => 'cust_ed',
        '//PHONE INBOUND//' => 'phone_test',
        '//WHOLE HOME WIFI//' => 'whole_home',
        '//UNBREAKABLE//' => 'unbreakable'
    ];

    $headers_started = false;
    $current_header = '';
    $buffer_notes = [];
    $top_section_lines = [];

    // --- PARSING ENGINE ---
    foreach ($lines as $line) {
        if (empty($line)) continue;

        // 1. DETECT NEW JOB (Ticket Number usually starts it)
        // If we find a 9-digit number AND we already have data, save previous job
        if (preg_match('/^\d{9}$/', $line)) {
            if (!empty($current_job)) {
                // Save previous job
                $current_job['addtl_work'] = implode("\n", $buffer_notes);
                $jobs[] = finalizeJob($current_job, $top_section_lines, $rates, $install_names);
            }
            // Reset for new job
            $current_job = ['ticket_number' => $line]; // Start with ticket
            $top_section_lines = [];
            $buffer_notes = [];
            $headers_started = false;
            continue;
        }

        // Initialize empty if first run
        if (empty($current_job)) $current_job = [];

        // 2. DETECT HEADERS
        if (strpos($line, '//') !== false) {
            $headers_started = true;
            $current_header = ''; 
            foreach ($map as $key => $field) {
                $clean_key = str_replace(['/', ' '], '', $key);
                $clean_line = str_replace(['/', ' '], '', $line);
                if (stripos($clean_line, $clean_key) !== false) { $current_header = $field; break; }
            }
            continue; 
        }

        // 3. CAPTURE DATA
        if ($headers_started) {
            if (!$current_header) continue;
            
            if ($current_header === 'notes_append') {
                if (stripos($line, 'No additional work') === false) $buffer_notes[] = $line;
            }
            elseif (in_array($current_header, ['drop_length', 'spans', 'conduit_ft', 'jacks_installed'])) {
                $current_job[$current_header] = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            }
            elseif (in_array($current_header, ['exterior_sealed', 'nid_installed', 'copper_removed'])) {
                if (stripos($line, 'Yes') !== false) $current_job[$current_header] = 'Yes';
                else $current_job[$current_header] = 'No';
            }
            elseif (in_array($current_header, ['cust_ed', 'phone_test', 'whole_home', 'unbreakable'])) {
                if (stripos($line, 'Yes') !== false) $buffer_notes[] = strtoupper($current_header) . ": Yes";
            }
            else {
                // Standard text fields
                if (empty($current_job[$current_header])) $current_job[$current_header] = $line;
                else $current_job[$current_header] .= " " . $line;
            }
        } else {
            // Address Block area
            $top_section_lines[] = $line;
        }
    }

    // Save the very last job
    if (!empty($current_job)) {
        $current_job['addtl_work'] = implode("\n", $buffer_notes);
        $jobs[] = finalizeJob($current_job, $top_section_lines, $rates, $install_names);
    }

    // --- GENERATE CSV DOWNLOAD ---
    if (!empty($jobs)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="converted_jobs_'.date('Y-m-d').'.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV Headers
        fputcsv($output, ['Date', 'Ticket', 'Type', 'Pay', 'Customer', 'Address', 'Phone', 'Spans', 'Conduit', 'Jacks', 'Notes', 'Serial', 'Eero', 'Wifi Name', 'Wifi Pass', 'NID', 'Seal', 'Copper', 'PD']);
        
        foreach ($jobs as $j) {
            fputcsv($output, [
                $j['date'],
                $j['ticket_number'],
                $j['install_type'],
                $j['pay_amount'],
                $j['cust_name'],
                $j['cust_address'],
                $j['cust_phone'],
                $j['spans'],
                $j['conduit_ft'],
                $j['jacks_installed'],
                $j['addtl_work'],
                $j['ont_serial'],
                $j['eeros_serial'],
                $j['wifi_name'],
                $j['wifi_pass'],
                $j['nid_installed'],
                $j['exterior_sealed'],
                $j['copper_removed'],
                $j['extra_per_diem']
            ]);
        }
        fclose($output);
        exit;
    } else {
        $error = "No valid jobs found in text.";
    }
}

// --- HELPER: FINALIZE JOB DATA ---
function finalizeJob($j, $top_lines, $rates, $install_names) {
    // 1. Process Top Block (Name/Addr/Phone)
    $j['cust_name'] = ''; $j['cust_address'] = ''; $j['cust_phone'] = '';
    
    foreach ($top_lines as $t_line) {
        if (preg_match('/^[A-Z]\d{3}/', $t_line) || stripos($t_line, 'CallDuck') !== false || stripos($t_line, 'Woven Wire') !== false) continue; 
        
        $clean_phone = preg_replace('/[^0-9]/', '', $t_line);
        if (empty($j['cust_phone']) && strlen($clean_phone) == 10) { $j['cust_phone'] = $clean_phone; continue; }
        
        if (empty($j['cust_name'])) { $j['cust_name'] = $t_line; } 
        else { if (empty($j['cust_address'])) $j['cust_address'] = $t_line; else $j['cust_address'] .= ", " . $t_line; }
    }

    // 2. Map Install Type (Text -> Code)
    $orig_type = strtoupper($j['install_type'] ?? '');
    $j['install_type'] = 'Imported';
    
    // Simple Mapping
    if (strpos($orig_type, 'DOUBLE PLAY') !== false) $j['install_type'] = 'F002';
    elseif (strpos($orig_type, 'TRIPLE PLAY') !== false) $j['install_type'] = 'F003';
    elseif (strpos($orig_type, 'SINGLE') !== false || strpos($orig_type, 'INTERNET') !== false) $j['install_type'] = 'F001';
    elseif (strpos($orig_type, 'REPAIR') !== false) $j['install_type'] = 'Repair';
    
    // 3. Defaults
    $defaults = [
        'date' => date('Y-m-d'), 'pay_amount' => 0.00, 'spans' => 0, 'conduit_ft' => 0, 'jacks_installed' => 0,
        'ont_serial' => '', 'eeros_serial' => '', 'wifi_name' => '', 'wifi_pass' => '',
        'nid_installed' => 'No', 'exterior_sealed' => 'No', 'copper_removed' => 'No', 'extra_per_diem' => 'No', 'addtl_work' => ''
    ];
    
    return array_merge($defaults, $j);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Text to CSV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
    <div class="box" style="text-align:center;">
        <h2 style="margin-bottom:5px;">📄 Text to CSV Converter</h2>
        <div style="color:var(--text-muted); margin-bottom:20px;">
            Paste multiple notes below. We will split them by Ticket Number and generate a CSV.
        </div>
        
        <?php if(isset($error)): ?>
            <div class="alert" style="background:var(--danger-bg); color:var(--danger-text);"><?= $error ?></div>
        <?php endif; ?>

        <form method="post">
            <textarea name="raw_text" style="width:100%; height:300px; padding:15px; border-radius:8px; border:1px solid var(--border); background:var(--bg-input); color:var(--text-main); font-family:monospace; margin-bottom:15px;" placeholder="Paste notes here..."></textarea>
            <button type="submit" name="convert" class="btn btn-full">⬇️ Download CSV</button>
        </form>
    </div>
</div>

<script>
    if(localStorage.getItem('theme')==='dark'){document.body.classList.add('dark-mode');}
</script>
</body>
</html>
