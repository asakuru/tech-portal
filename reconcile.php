<?php
require_once 'config.php';
require_once 'functions.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin'])) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// --- 0. PRELOAD RATES ---
$rates = get_active_rates($db);
$install_names = get_rate_descriptions($db);
$lead_pay_amount = get_lead_pay_amount($db);

// --- 1. WEEKLY DATE LOGIC ---
$week = $_GET['w'] ?? date('W');
$year = $_GET['y'] ?? date('o');

$dto = new DateTime();
$dto->setISODate($year, $week);
$start_date = $dto->format('Y-m-d');
$week_label_start = $dto->format('M d');

$dto->modify('+6 days');
$end_date = $dto->format('Y-m-d');
$week_label_end = $dto->format('M d');

$prev_week_ts = strtotime("$start_date -7 days");
$next_week_ts = strtotime("$start_date +7 days");
$prev_link = "?w=" . date('W', $prev_week_ts) . "&y=" . date('o', $prev_week_ts);
$next_link = "?w=" . date('W', $next_week_ts) . "&y=" . date('o', $next_week_ts);

// --- 2. FETCH & TALLY LOCAL DATA ---
$local_jobs = [];
$total_local = 0;
$code_tally = [];

function addToTally(&$tally, $code, $count, $rate, $desc)
{
    if ($count <= 0)
        return;
    if (!isset($tally[$code])) {
        $tally[$code] = ['desc' => $desc, 'count' => 0, 'rate' => $rate, 'total' => 0];
    }
    $tally[$code]['count'] += $count;
    $tally[$code]['total'] += ($count * $rate);
}

// A. Get Jobs
$stmt = $db->prepare("SELECT * FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? ORDER BY install_date ASC");
$stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);
$do_dates = [];

while ($row = $stmt->fetch()) {
    $t = trim($row['ticket_number']);
    $type = $row['install_type'];
    if ($type === 'DO') {
        $do_dates[$row['install_date']] = true;
    }

    // --- USE BRAIN TO CALCULATE ---
    $details = calculate_job_details($row, $rates);
    $job_total = 0;

    foreach ($details as $item) {
        $desc = $install_names[$item['code']] ?? $item['desc'];
        addToTally($code_tally, $item['code'], $item['qty'], $item['rate'], $desc);
        $job_total += $item['total'];
    }

    $cust_display = $row['cust_lname'];
    if (!empty($row['cust_lname'])) {
        if (!empty($row['cust_fname']))
            $cust_display = substr($row['cust_fname'], 0, 1) . '. ' . $row['cust_lname'];
    } elseif (!empty($row['cust_fname'])) {
        $cust_display = $row['cust_fname'];
    } else {
        $cust_display = "Unknown";
    }

    $local_jobs[$t] = [
        'date' => date('m/d', strtotime($row['install_date'])),
        'full_date' => $row['install_date'],
        'type' => $type,
        'pay' => $job_total,
        'ticket' => $t,
        'cust' => $cust_display,
        'found_in_scrub' => false
    ];
}

// B. PER DIEM & VEHICLE EXPENSES (Aligned with financials.php logic)
$total_miles = 0;
$total_fuel = 0;

// Track work dates = days with billable jobs (not DO, not ND)
// ND still gets per diem, just like Sundays
$work_dates = [];
$nd_dates = [];

foreach ($local_jobs as $ticket => $job) {
    $jtype = $job['type'];
    $jdate = $job['full_date'];

    if ($jtype === 'ND') {
        $nd_dates[$jdate] = true; // ND gets PD but isn't "work"
    } elseif ($jtype !== 'DO') {
        $work_dates[$jdate] = true; // Actual billable work
    }
}

// Calculate Standard Per Diem:
// 1. Per diem for each WORK day (billable jobs)
// 2. Per diem for each ND day (Not Designated = still gets PD)
// 3. Per diem for Sunday (if not already counted as work or ND)

$pd_days = 0;
$pd_rate = $rates['per_diem'] ?? 0;

// Add PD for work days
foreach ($work_dates as $wdate => $val) {
    $pd_days++;
}

// Add PD for ND days
foreach ($nd_dates as $ndate => $val) {
    $pd_days++;
}

// Add PD for Sunday (if not already counted)
$sunday_date = $start_date;
while (date('N', strtotime($sunday_date)) != 7) {
    $sunday_date = date('Y-m-d', strtotime("$sunday_date +1 day"));
}
if ($sunday_date <= $end_date) {
    // Only add if Sunday wasn't already a work day or ND day
    if (!isset($work_dates[$sunday_date]) && !isset($nd_dates[$sunday_date])) {
        $pd_days++;
    }
}

// Fetch daily logs for Extra PD, Mileage, Fuel
$extra_pd_days = 0;
try {
    $stmt = $db->prepare("SELECT log_date, extra_per_diem, mileage, fuel_cost FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);
    while ($l = $stmt->fetch()) {
        if ($l['extra_per_diem'] == 1) {
            $extra_pd_days++;
        }
        $total_miles += $l['mileage'];
        $total_fuel += $l['fuel_cost'];
    }
} catch (Exception $e) {
}

// Standard PD
if ($pd_days > 0) {
    addToTally($code_tally, 'Per Diem', $pd_days, $pd_rate, 'Standard Per Diem (Work Days + ND + Sun)');
}
// Extra PD (from Logs)
if ($extra_pd_days > 0) {
    $r = $rates['extra_pd'] ?? 0;
    addToTally($code_tally, 'Extra PD', $extra_pd_days, $r, 'Extra Per Diem (Day Log)');
}

// C. LEAD PAY
if ((count($local_jobs) > 0 || $pd_days > 0) && $lead_pay_amount > 0) {
    if (has_billable_work($db, $_SESSION['user_id'], $start_date, $end_date)) {
        addToTally($code_tally, 'LEAD-PAY', 1, $lead_pay_amount, 'Weekly Lead/Triage Pay');
    }
}

$grand_total_tally = 0;
foreach ($code_tally as $i)
    $grand_total_tally += $i['total'];
ksort($code_tally);

$net_profit = $grand_total_tally - $total_fuel;

// --- 3. HANDLE CODE-BASED COMPARISON ---
$scrub_codes = []; // code => qty
$comparison_mode = false;
$code_variance = []; // For display

// Handle CSV Upload
if (isset($_FILES['scrub_csv']) && $_FILES['scrub_csv']['error'] == 0) {
    $comparison_mode = true;
    $handle = fopen($_FILES['scrub_csv']['tmp_name'], "r");
    
    // Skip empty rows to find the header
    $header = null;
    while (($row = fgetcsv($handle)) !== false) {
        // Look for a row containing "Unit Code" or "QTY"
        $row_str = implode('', $row);
        if (stripos($row_str, 'Unit Code') !== false || stripos($row_str, 'QTY') !== false) {
            $header = $row;
            break;
        }
    }
    
    // Find column indexes from header
    $col_code = 0;  // Default to first column
    $col_qty = 5;   // Default based on example (index 5)
    
    if ($header) {
        foreach ($header as $i => $col) {
            $c = strtolower(trim($col));
            if ($c === 'unit code' || strpos($c, 'unit code') !== false)
                $col_code = $i;
            if ($c === 'qty' || $c === 'quantity')
                $col_qty = $i;
        }
    }
    
    // Read data rows
    while (($row = fgetcsv($handle)) !== false) {
        // Get code - try column 0 first, then column 1 (for "Per Diem")
        $code = '';
        if (isset($row[$col_code]) && !empty(trim($row[$col_code]))) {
            $code = strtoupper(trim($row[$col_code]));
        } elseif (isset($row[1]) && !empty(trim($row[1]))) {
            // Check column 1 for things like "Per Diem"
            $code = strtoupper(trim($row[1]));
        }
        
        // Skip invalid codes (empty, too long, is a price, is just numbers)
        if (empty($code) || strlen($code) > 25) continue;
        if (preg_match('/^\$/', $code)) continue;
        if (preg_match('/^[\d,\.]+$/', $code)) continue;
        if (stripos($code, 'TOTAL') !== false) continue;
        
        // Get QTY from the correct column
        $qty = 0;
        if (isset($row[$col_qty])) {
            $qty = (int) filter_var($row[$col_qty], FILTER_SANITIZE_NUMBER_INT);
        }
        
        // Only add if qty > 0
        if ($qty > 0) {
            $scrub_codes[$code] = ($scrub_codes[$code] ?? 0) + $qty;
        }
    }
    fclose($handle);
}

// Handle Pasted Text

if (isset($_POST['scrub_text']) && !empty($_POST['scrub_text'])) {
    $comparison_mode = true;
    $text = $_POST['scrub_text'];
    $lines = explode("\n", $text);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line))
            continue;

        // Skip header row
        if (stripos($line, 'Unit Code') !== false || stripos($line, 'Unit Description') !== false)
            continue;

        // Parse tab-separated or multi-space separated data
        // Columns: Unit Code | Unit Description | QTY | UOM | SUB RATES | Sub Total
        
        // Try tab-separated first
        if (strpos($line, "\t") !== false) {
            $parts = explode("\t", $line);
        } else {
            // Fallback to 2+ spaces
            $parts = preg_split('/\s{2,}/', $line);
        }
        
        if (count($parts) < 2) continue;
        
        // First column is always the code
        $code = trim($parts[0]);
        
        // Skip if code looks invalid (too long, no letters, is a number, or looks like money)
        if (strlen($code) > 25 || !preg_match('/[A-Z]/i', $code) || preg_match('/^\$/', $code))
            continue;
        
        // Find QTY: it's the first pure integer in the parts (after code and description)
        $qty = 0;
        foreach ($parts as $idx => $part) {
            if ($idx == 0) continue; // Skip code
            $part = trim($part);
            // Skip empty parts
            if (empty($part)) continue;
            // If it's a pure integer (not a price), that's QTY
            if (preg_match('/^(\d+)$/', $part, $m)) {
                $qty = (int) $m[1];
                break;
            }
            // If we hit a price (starts with $), stop looking
            if (preg_match('/^\$/', $part)) break;
        }

        // Normalize code to uppercase
        $code = strtoupper($code);

        // Only add if qty > 0 (items with qty 0 don't matter)
        if ($qty > 0) {
            $scrub_codes[$code] = ($scrub_codes[$code] ?? 0) + $qty;
        }
    }
}

// --- 4. BUILD CODE COMPARISON TABLE ---
if ($comparison_mode) {
    // Normalize local codes to uppercase for matching
    $normalized_code_tally = [];
    foreach ($code_tally as $code => $data) {
        $normalized_code_tally[strtoupper($code)] = $data;
    }
    
    // Compare local tally against scrub codes
    foreach ($normalized_code_tally as $code => $data) {
        $local_qty = $data['count'];
        $local_total = $data['total'];
        $scrub_qty = $scrub_codes[$code] ?? 0;

        $status = 'match';
        if ($scrub_qty == 0 && $local_qty > 0) {
            $status = 'missing'; // In DB but NOT in pasted text
        } elseif ($local_qty != $scrub_qty) {
            $status = 'variance';
        }

        $code_variance[$code] = [
            'desc' => $data['desc'],
            'local_qty' => $local_qty,
            'local_total' => $local_total,
            'scrub_qty' => $scrub_qty,
            'status' => $status
        ];

        unset($scrub_codes[$code]); // Mark as processed
    }

    // Remaining scrub codes = EXTRA (in pasted text but NOT in DB)
    // Only include if qty > 0
    foreach ($scrub_codes as $code => $qty) {
        if ($qty > 0) {
            $code_variance[$code] = [
                'desc' => '(Not in your records)',
                'local_qty' => 0,
                'local_total' => 0,
                'scrub_qty' => $qty,
                'status' => 'extra'
            ];
        }
    }

    // Sort by code
    ksort($code_variance);
}

// Keep legacy job-level display (for reference, won't affect comparison)
$display_rows = [];
foreach ($local_jobs as $ticket => $job) {
    $display_rows[] = $job;
}
usort($display_rows, function ($a, $b) {
    return $a['full_date'] <=> $b['full_date'];
});
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Reconcile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .control-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .week-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }

        .week-nav a {
            text-decoration: none;
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--text-main);
            padding: 0 15px;
        }

        .paper-sheet {
            background: white !important;
            color: #111 !important;
            padding: 30px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
            max-width: 1000px;
            margin: 0 auto 40px auto;
            border-radius: 4px;
        }

        .summary-table,
        .sheet-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Arial', sans-serif;
            font-size: 13px;
            white-space: nowrap;
            background: white !important;
            color: #111 !important;
        }

        .summary-table th,
        .sheet-table th {
            text-align: left;
            border-bottom: 2px solid #000;
            padding: 8px 5px;
            font-weight: 800;
            text-transform: uppercase;
            color: #000 !important;
            background: white !important;
        }

        .summary-table td,
        .sheet-table td {
            padding: 8px 5px;
            border-bottom: 1px solid #eee;
            color: #111 !important;
            background: white !important;
        }

        .summary-table tr,
        .sheet-table tr {
            background: white !important;
        }

        .summary-table tr:hover td,
        .sheet-table tr:hover td {
            background: #f7f7f7 !important;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
            margin-bottom: 20px;
        }

        .num {
            text-align: right;
            font-family: monospace;
            font-weight: 600;
        }

        .upload-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 600px) {
            .paper-sheet {
                padding: 15px;
            }

            .week-nav {
                font-size: 0.9rem;
            }

            .upload-options {
                grid-template-columns: 1fr;
            }
        }

        .st-match {
            color: #10b981 !important;
            font-weight: bold;
        }

        .st-var {
            color: #f59e0b !important;
            font-weight: bold;
        }

        .st-miss {
            color: #ef4444 !important;
            font-weight: bold;
        }

        .st-extra {
            color: #3b82f6 !important;
            font-weight: bold;
        }

        .profit-row td {
            border-top: 1px solid #000;
            font-weight: bold;
            font-size: 1.1em;
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <div class="control-bar">
            <div class="week-nav">
                <a href="<?= htmlspecialchars($prev_link) ?>">&laquo;</a>
                <div style="text-align:center;">
                    <div style="font-weight:bold; font-size:1.1rem;">Week <?= htmlspecialchars($week) ?></div>
                    <div style="color:var(--text-muted); font-size:0.85rem;"><?= htmlspecialchars($week_label_start) ?>
                        - <?= htmlspecialchars($week_label_end) ?></div>
                </div>
                <a href="<?= htmlspecialchars($next_link) ?>">&raquo;</a>
            </div>
        </div>

        <div class="paper-sheet">

            <div
                style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px; padding-bottom:10px; border-bottom:2px solid #000;">
                <div>
                    <h2 style="margin:0; font-size:1.4rem; text-transform:uppercase; color:#000;">Weekly Summary</h2>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:0.8rem; text-transform:uppercase; color:#666;">Miles Driven</div>
                    <div style="font-size:1.2rem; font-weight:800; color:#000;"><?= number_format($total_miles) ?> mi
                    </div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Description</th>
                            <th style="text-align:center;">Qty</th>
                            <th class="num">Rate</th>
                            <th class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($code_tally)): ?>
                            <tr>
                                <td colspan="5" style="padding:20px; text-align:center; color:#555;">No activity recorded
                                    for this week.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($code_tally as $code => $d): ?>
                                <tr>
                                    <td style="font-weight:bold;"><?= htmlspecialchars($code) ?></td>
                                    <td><?= htmlspecialchars($d['desc']) ?></td>
                                    <td style="text-align:center;"><?= $d['count'] ?></td>
                                    <td class="num">$<?= number_format($d['rate'], 2) ?></td>
                                    <td class="num">$<?= number_format($d['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <tr style="height:10px;">
                                <td colspan="5" style="border:none;"></td>
                            </tr>

                            <tr>
                                <td colspan="4" style="text-align:right; font-weight:bold; padding-top:10px;">GROSS REVENUE
                                </td>
                                <td class="num" style="padding-top:10px; font-weight:bold;">
                                    $<?= number_format($grand_total_tally, 2) ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" style="text-align:right; color:#dc2626;">LESS FUEL COST</td>
                                <td class="num" style="color:#dc2626;">-$<?= number_format($total_fuel, 2) ?></td>
                            </tr>
                            <tr class="profit-row">
                                <td colspan="4" style="text-align:right; color:#15803d;">NET PROFIT</td>
                                <td class="num" style="color:#15803d; border-bottom:2px solid #000;">
                                    $<?= number_format($net_profit, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$comparison_mode): ?>
                <h3 style="margin:20px 0 10px 0; font-size:1rem; text-transform:uppercase; color:#000;">Reconcile Scrub</h3>
                <div class="upload-options">
                    <div
                        style="background:#f9fafb; border:1px dashed #999; padding:15px; text-align:center; border-radius:4px;">
                        <form method="post" enctype="multipart/form-data">
                            <div style="font-weight:bold; font-size:0.9rem; margin-bottom:10px; color:#333;">Option 1:
                                Upload CSV</div>
                            <input type="file" name="scrub_csv" accept=".csv"
                                style="font-size:0.9rem; max-width:200px; color:#333; margin-bottom:10px;">
                            <br><button type="submit" class="btn"
                                style="padding:5px 15px; background:#000; color:#fff; border:none; cursor:pointer;">Compare
                                CSV</button>
                        </form>
                    </div>
                    <div
                        style="background:#f9fafb; border:1px dashed #999; padding:15px; text-align:center; border-radius:4px;">
                        <form method="post">
                            <div style="font-weight:bold; font-size:0.9rem; margin-bottom:10px; color:#333;">Option 2: Paste
                                Text</div>
                            <textarea name="scrub_text" rows="3" placeholder="Paste report text..."
                                style="width:100%; font-size:0.8rem; margin-bottom:10px; border:1px solid #ccc;"></textarea>
                            <br><button type="submit" class="btn"
                                style="padding:5px 15px; background:#000; color:#fff; border:none; cursor:pointer;">Compare
                                Text</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($comparison_mode && !empty($code_variance)): ?>
                <h3
                    style="margin:20px 0 10px 0; font-size:1rem; text-transform:uppercase; color:#000; border-bottom:2px solid #000; padding-bottom:5px;">
                    📊 Code Comparison
                </h3>
                <div style="margin-bottom:15px; font-size:0.85rem;">
                    <span
                        style="background:#fee2e2; color:#dc2626; padding:3px 8px; border-radius:4px; margin-right:10px;">🔴
                        Missing (In DB, Not in Scrub)</span>
                    <span
                        style="background:#dcfce7; color:#16a34a; padding:3px 8px; border-radius:4px; margin-right:10px;">🟢
                        Extra (In Scrub, Not in DB)</span>
                    <span style="background:#fef3c7; color:#d97706; padding:3px 8px; border-radius:4px;">🟡 Qty
                        Variance</span>
                </div>
                <div class="table-wrap">
                    <table class="summary-table" style="margin-bottom:20px;">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Description</th>
                                <th style="text-align:center;">My Qty</th>
                                <th style="text-align:center;">Scrub Qty</th>
                                <th class="num">My Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($code_variance as $code => $v):
                                $row_bg = '';
                                $status_text = '';
                                $status_color = '';

                                if ($v['status'] === 'missing') {
                                    $row_bg = 'background:#fee2e2;';
                                    $status_text = 'MISSING';
                                    $status_color = 'color:#dc2626; font-weight:bold;';
                                } elseif ($v['status'] === 'extra') {
                                    $row_bg = 'background:#dcfce7;';
                                    $status_text = 'EXTRA';
                                    $status_color = 'color:#16a34a; font-weight:bold;';
                                } elseif ($v['status'] === 'variance') {
                                    $row_bg = 'background:#fef3c7;';
                                    $status_text = 'VARIANCE';
                                    $status_color = 'color:#d97706; font-weight:bold;';
                                } else {
                                    $status_text = '✓ Match';
                                    $status_color = 'color:#10b981;';
                                }
                                ?>
                                <tr style="<?= $row_bg ?>">
                                    <td style="font-weight:bold;"><?= htmlspecialchars($code) ?></td>
                                    <td style="font-size:0.85rem;"><?= htmlspecialchars(substr($v['desc'], 0, 40)) ?></td>
                                    <td style="text-align:center; font-weight:bold;"><?= $v['local_qty'] ?></td>
                                    <td style="text-align:center; font-weight:bold;"><?= $v['scrub_qty'] ?></td>
                                    <td class="num">$<?= number_format($v['local_total'], 2) ?></td>
                                    <td style="<?= $status_color ?>"><?= $status_text ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a href="?" class="btn" style="margin-bottom:20px; display:inline-block;">← Clear Comparison</a>
            <?php endif; ?>

            <h3
                style="margin:0 0 10px 0; font-size:1rem; text-transform:uppercase; border-bottom:1px solid #000; padding-bottom:5px; color:#000;">
                Job Detail List</h3>
            <div class="table-wrap">
                <table class="sheet-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Ticket</th>
                            <th>Type</th>
                            <th>Cust</th>
                            <th class="num">My Pay</th>
                            <?php if ($comparison_mode): ?>
                                <th class="num">Scrub</th>
                                <th class="num">Diff</th>
                                <th style="padding-left:10px;">Status</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($display_rows)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:30px; color:#555;">No jobs recorded.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($display_rows as $row):
                                $st_class = '';
                                $row_status = $row['status'] ?? '';
                                if ($row_status == 'Match')
                                    $st_class = 'st-match';
                                if ($row_status == 'Variance')
                                    $st_class = 'st-var';
                                if ($row_status == 'Missing')
                                    $st_class = 'st-miss';
                                if ($row_status == 'Extra')
                                    $st_class = 'st-extra';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['date']) ?></td>
                                    <td><?= htmlspecialchars($row['ticket']) ?></td>
                                    <td><?= htmlspecialchars($row['type']) ?></td>
                                    <td><?= htmlspecialchars(substr($row['cust'], 0, 10)) ?></td>
                                    <td class="num"><?= ($row['pay'] > 0) ? number_format($row['pay'], 2) : '-' ?></td>

                                    <?php if ($comparison_mode): ?>
                                        <?php
                                        $scrub_pay = $row['scrub_pay'] ?? 0;
                                        $diff = $row['diff'] ?? 0;
                                        ?>
                                        <td class="num"><?= ($scrub_pay != 0) ? number_format($scrub_pay, 2) : '-' ?>
                                        </td>
                                        <td class="num" style="color:<?= $diff >= 0 ? '#10b981' : '#ef4444' ?>;">
                                            <?= ($diff != 0) ? number_format($diff, 2) : '-' ?>
                                        </td>
                                        <td style="padding-left:10px;" class="<?= htmlspecialchars($st_class) ?>">
                                            <?= htmlspecialchars($row_status) ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
    </script>
</body>

</html>