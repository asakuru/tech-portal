<?php
require_once 'config.php';
require_once 'functions.php'; 

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['loggedin'])) { header('Location: index.php'); exit; }

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

function addToTally(&$tally, $code, $count, $rate, $desc) {
    if ($count <= 0) return;
    if (!isset($tally[$code])) {
        $tally[$code] = ['desc'=>$desc, 'count'=>0, 'rate'=>$rate, 'total'=>0];
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
    if ($type === 'DO') { $do_dates[$row['install_date']] = true; }

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
        if (!empty($row['cust_fname'])) $cust_display = substr($row['cust_fname'], 0, 1) . '. ' . $row['cust_lname'];
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

// B. PER DIEM & VEHICLE EXPENSES
$pd_days = 0; $extra_pd_days = 0;
$total_miles = 0; $total_fuel = 0;

$current_check_date = $start_date;
for ($i = 0; $i < 7; $i++) {
    if (!isset($do_dates[$current_check_date])) { $pd_days++; }
    $current_check_date = date('Y-m-d', strtotime("$current_check_date +1 day"));
}

try {
    $stmt = $db->prepare("SELECT log_date, extra_per_diem, mileage, fuel_cost FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);
    while($l = $stmt->fetch()) { 
        if ($l['extra_per_diem'] == 1) { $extra_pd_days++; }
        $total_miles += $l['mileage'];
        $total_fuel += $l['fuel_cost'];
    }
} catch (Exception $e) {}

// Standard PD
if ($pd_days > 0) {
    $r = $rates['per_diem'] ?? 0;
    addToTally($code_tally, 'Per Diem', $pd_days, $r, 'Standard Per Diem (7 Days - DO)');
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
foreach($code_tally as $i) $grand_total_tally += $i['total'];
ksort($code_tally);

$net_profit = $grand_total_tally - $total_fuel;

// --- 3. HANDLE COMPARISON ---
$scrub_data = []; $comparison_mode = false;
$stats = ['matches'=>0, 'variance_count'=>0, 'variance_amt'=>0.00];

if (isset($_FILES['scrub_csv']) && $_FILES['scrub_csv']['error'] == 0) {
    $comparison_mode = true;
    $handle = fopen($_FILES['scrub_csv']['tmp_name'], "r");
    $header = fgetcsv($handle);
    $col_ticket = 0; $col_pay = -1;
    foreach ($header as $i => $col) {
        $c = strtolower($col);
        if (strpos($c, 'ticket')!==false || strpos($c, 'order')!==false) $col_ticket = $i;
        if (strpos($c, 'amount')!==false || strpos($c, 'pay')!==false || strpos($c, 'total')!==false) $col_pay = $i;
    }
    while (($row = fgetcsv($handle)) !== false) {
        if (!isset($row[$col_ticket])) continue;
        $t = trim($row[$col_ticket]);
        if (empty($t)) continue;
        $p = 0.00;
        if ($col_pay > -1 && isset($row[$col_pay])) {
            $p = (float)filter_var($row[$col_pay], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        }
        $scrub_data[$t] = $p;
    }
    fclose($handle);
}

if (isset($_POST['scrub_text']) && !empty($_POST['scrub_text'])) {
    $comparison_mode = true;
    $text = $_POST['scrub_text'];
    $lines = explode("\n", $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (preg_match('/(NY\d+|PA\d+|[A-Z]{2,3}\d{7,})/', $line, $m)) {
            $ticket = $m[1];
            if (preg_match_all('/\$?(\d{1,3}(?:,\d{3})*\.\d{2})/', $line, $prices)) {
                $last_price = end($prices[1]);
                $p = (float)str_replace(',', '', $last_price);
                $scrub_data[$ticket] = $p;
            }
        }
    }
}

// --- 4. MERGE ---
$display_rows = [];
foreach ($local_jobs as $ticket => $job) {
    $row = $job; $row['scrub_pay'] = 0; $row['status'] = ''; $row['diff'] = 0;
    if ($comparison_mode) {
        if (isset($scrub_data[$ticket])) {
            $row['scrub_pay'] = $scrub_data[$ticket];
            $row['diff'] = $row['scrub_pay'] - $row['pay'];
            unset($scrub_data[$ticket]); 
            if (abs($row['diff']) < 0.01) { $row['status'] = 'Match'; $stats['matches']++; }
            else { $row['status'] = 'Variance'; $stats['variance_count']++; $stats['variance_amt'] += $row['diff']; }
        } else {
            $row['status'] = 'Missing'; $row['diff'] = -$row['pay'];
            $stats['variance_count']++; $stats['variance_amt'] += $row['diff'];
        }
    }
    $display_rows[] = $row;
}
if ($comparison_mode) {
    foreach ($scrub_data as $ticket => $pay) {
        $display_rows[] = ['date'=>'-','full_date'=>'9999-99-99','ticket'=>$ticket,'cust'=>'Unknown','type'=>'CSV','pay'=>0,'scrub_pay'=>$pay,'diff'=>$pay,'status'=>'Extra'];
        $stats['variance_count']++; $stats['variance_amt'] += $pay;
    }
}
usort($display_rows, function($a, $b) { return $a['full_date'] <=> $b['full_date']; });
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reconcile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .control-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .week-nav { display: flex; align-items: center; justify-content: space-between; width: 100%; max-width: 400px; margin: 0 auto; }
        .week-nav a { text-decoration: none; font-size: 1.5rem; font-weight: bold; color: var(--text-main); padding: 0 15px; }
        
        .paper-sheet {
            background: white !important; color: #111 !important; padding: 30px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.15);
            max-width: 1000px; margin: 0 auto 40px auto; border-radius: 4px;
        }

        .summary-table, .sheet-table { 
            width: 100%; border-collapse: collapse; font-family: 'Arial', sans-serif; font-size: 13px; white-space: nowrap; 
            background: white !important; color: #111 !important;
        }
        .summary-table th, .sheet-table th { 
            text-align: left; border-bottom: 2px solid #000; padding: 8px 5px; 
            font-weight: 800; text-transform: uppercase; color: #000 !important; background: white !important;
        }
        .summary-table td, .sheet-table td { 
            padding: 8px 5px; border-bottom: 1px solid #eee; color: #111 !important; background: white !important;
        }
        .summary-table tr, .sheet-table tr { background: white !important; }
        .summary-table tr:hover td, .sheet-table tr:hover td { background: #f7f7f7 !important; }

        .table-wrap { width: 100%; overflow-x: auto; margin-bottom: 20px; }
        .num { text-align: right; font-family: monospace; font-weight: 600; }
        
        .upload-options {
            display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;
        }
        @media (max-width: 600px) {
            .paper-sheet { padding: 15px; }
            .week-nav { font-size: 0.9rem; }
            .upload-options { grid-template-columns: 1fr; }
        }

        .st-match { color: #10b981 !important; font-weight: bold; }
        .st-var   { color: #f59e0b !important; font-weight: bold; }
        .st-miss  { color: #ef4444 !important; font-weight: bold; }
        .st-extra { color: #3b82f6 !important; font-weight: bold; }
        
        .profit-row td { 
            border-top: 1px solid #000; font-weight: bold; font-size: 1.1em;
        }
    </style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">

    <div class="control-bar">
        <div class="week-nav">
            <a href="<?= $prev_link ?>">&laquo;</a>
            <div style="text-align:center;">
                <div style="font-weight:bold; font-size:1.1rem;">Week <?= $week ?></div>
                <div style="color:var(--text-muted); font-size:0.85rem;"><?= $week_label_start ?> - <?= $week_label_end ?></div>
            </div>
            <a href="<?= $next_link ?>">&raquo;</a>
        </div>
    </div>

    <div class="paper-sheet">
        
        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px; padding-bottom:10px; border-bottom:2px solid #000;">
            <div>
                <h2 style="margin:0; font-size:1.4rem; text-transform:uppercase; color:#000;">Weekly Summary</h2>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.8rem; text-transform:uppercase; color:#666;">Miles Driven</div>
                <div style="font-size:1.2rem; font-weight:800; color:#000;"><?= number_format($total_miles) ?> mi</div>
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
                    <?php if(empty($code_tally)): ?>
                        <tr><td colspan="5" style="padding:20px; text-align:center; color:#555;">No activity recorded for this week.</td></tr>
                    <?php else: ?>
                        <?php foreach($code_tally as $code => $d): ?>
                        <tr>
                            <td style="font-weight:bold;"><?= $code ?></td>
                            <td><?= $d['desc'] ?></td>
                            <td style="text-align:center;"><?= $d['count'] ?></td>
                            <td class="num">$<?= number_format($d['rate'], 2) ?></td>
                            <td class="num">$<?= number_format($d['total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr style="height:10px;"><td colspan="5" style="border:none;"></td></tr>
                        
                        <tr>
                            <td colspan="4" style="text-align:right; font-weight:bold; padding-top:10px;">GROSS REVENUE</td>
                            <td class="num" style="padding-top:10px; font-weight:bold;">$<?= number_format($grand_total_tally, 2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="4" style="text-align:right; color:#dc2626;">LESS FUEL COST</td>
                            <td class="num" style="color:#dc2626;">-$<?= number_format($total_fuel, 2) ?></td>
                        </tr>
                        <tr class="profit-row">
                            <td colspan="4" style="text-align:right; color:#15803d;">NET PROFIT</td>
                            <td class="num" style="color:#15803d; border-bottom:2px solid #000;">$<?= number_format($net_profit, 2) ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if(!$comparison_mode): ?>
        <h3 style="margin:20px 0 10px 0; font-size:1rem; text-transform:uppercase; color:#000;">Reconcile Scrub</h3>
        <div class="upload-options">
            <div style="background:#f9fafb; border:1px dashed #999; padding:15px; text-align:center; border-radius:4px;">
                <form method="post" enctype="multipart/form-data">
                    <div style="font-weight:bold; font-size:0.9rem; margin-bottom:10px; color:#333;">Option 1: Upload CSV</div>
                    <input type="file" name="scrub_csv" accept=".csv" style="font-size:0.9rem; max-width:200px; color:#333; margin-bottom:10px;">
                    <br><button type="submit" class="btn" style="padding:5px 15px; background:#000; color:#fff; border:none; cursor:pointer;">Compare CSV</button>
                </form>
            </div>
            <div style="background:#f9fafb; border:1px dashed #999; padding:15px; text-align:center; border-radius:4px;">
                <form method="post">
                    <div style="font-weight:bold; font-size:0.9rem; margin-bottom:10px; color:#333;">Option 2: Paste Text</div>
                    <textarea name="scrub_text" rows="3" placeholder="Paste report text..." style="width:100%; font-size:0.8rem; margin-bottom:10px; border:1px solid #ccc;"></textarea>
                    <br><button type="submit" class="btn" style="padding:5px 15px; background:#000; color:#fff; border:none; cursor:pointer;">Compare Text</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <h3 style="margin:0 0 10px 0; font-size:1rem; text-transform:uppercase; border-bottom:1px solid #000; padding-bottom:5px; color:#000;">Job Detail List</h3>
        <div class="table-wrap">
            <table class="sheet-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Ticket</th>
                        <th>Type</th>
                        <th>Cust</th>
                        <th class="num">My Pay</th>
                        <?php if($comparison_mode): ?>
                            <th class="num">Scrub</th>
                            <th class="num">Diff</th>
                            <th style="padding-left:10px;">Status</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($display_rows)): ?>
                        <tr><td colspan="8" style="text-align:center; padding:30px; color:#555;">No jobs recorded.</td></tr>
                    <?php else: ?>
                        <?php foreach($display_rows as $row): 
                            $st_class = '';
                            if($row['status'] == 'Match') $st_class = 'st-match';
                            if($row['status'] == 'Variance') $st_class = 'st-var';
                            if($row['status'] == 'Missing') $st_class = 'st-miss';
                            if($row['status'] == 'Extra') $st_class = 'st-extra';
                        ?>
                        <tr>
                            <td><?= $row['date'] ?></td>
                            <td><?= $row['ticket'] ?></td>
                            <td><?= $row['type'] ?></td>
                            <td><?= substr($row['cust'], 0, 10) ?></td>
                            <td class="num"><?= ($row['pay'] > 0) ? number_format($row['pay'], 2) : '-' ?></td>
                            
                            <?php if($comparison_mode): ?>
                                <td class="num"><?= ($row['scrub_pay'] != 0) ? number_format($row['scrub_pay'], 2) : '-' ?></td>
                                <td class="num" style="color:<?= $row['diff'] >= 0 ? '#10b981':'#ef4444' ?>;">
                                    <?= ($row['diff'] != 0) ? number_format($row['diff'], 2) : '-' ?>
                                </td>
                                <td style="padding-left:10px;" class="<?= $st_class ?>"><?= $row['status'] ?></td>
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
    if(localStorage.getItem('theme')==='dark'){document.body.classList.add('dark-mode');}
</script>
</body>
</html>