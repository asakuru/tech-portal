<?php
require 'config.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['loggedin'])) { header('Location: index.php'); exit; }

$db = getDB();

// --- 1. GET FILTERS ---
$view = $_GET['view'] ?? 'daily'; // daily, weekly, monthly
$selected_year = $_GET['year'] ?? date('Y');
$selected_month = $_GET['month'] ?? date('m');

// --- 2. NAVIGATION LOGIC ---
$prev_link = "";
$next_link = "";

if ($view === 'daily') {
    // Navigate by MONTH
    $ts_curr = mktime(0, 0, 0, $selected_month, 1, $selected_year);
    
    $ts_prev = strtotime("-1 month", $ts_curr);
    $prev_link = "?view=daily&year=".date('Y', $ts_prev)."&month=".date('m', $ts_prev);
    
    $ts_next = strtotime("+1 month", $ts_curr);
    $next_link = "?view=daily&year=".date('Y', $ts_next)."&month=".date('m', $ts_next);
    
    // Range for Query
    $start_date = date('Y-m-01', $ts_curr);
    $end_date = date('Y-m-t', $ts_curr);
    
} else {
    // Navigate by YEAR (Weekly/Monthly views)
    $prev_link = "?view=$view&year=".($selected_year - 1);
    $next_link = "?view=$view&year=".($selected_year + 1);
    
    // Range for Query
    $start_date = "$selected_year-01-01";
    $end_date = "$selected_year-12-31";
}

// --- 3. FETCH DATA ---
$data = []; 

// A. Get Jobs (Income + Legacy PD Check)
$sql_jobs = "SELECT 
                install_date, 
                COUNT(*) as job_count, 
                SUM(pay_amount) as total_pay,
                SUM(CASE WHEN extra_per_diem = 'Yes' THEN 1 ELSE 0 END) as legacy_pd_count,
                SUM(CASE WHEN install_type != 'DO' THEN 1 ELSE 0 END) as working_job_count
             FROM jobs 
             WHERE user_id = ? AND install_date BETWEEN ? AND ? 
             GROUP BY install_date";

$stmt = $db->prepare($sql_jobs);
$stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);

while ($row = $stmt->fetch()) {
    $d = $row['install_date'];
    if (!isset($data[$d])) $data[$d] = ['pay'=>0, 'pd'=>0, 'fuel'=>0, 'miles'=>0, 'jobs'=>0, 'has_extra_pd'=>false];
    
    $data[$d]['pay'] += $row['total_pay'];
    $data[$d]['jobs'] += $row['job_count'];

    // 1. Standard PD Calculation
    $is_sunday = (date('N', strtotime($d)) == 7);
    $has_work = ($row['working_job_count'] > 0);
    
    if ($is_sunday || $has_work) {
        $data[$d]['pd'] += $rates['per_diem'];
    }

    // 2. Legacy Extra PD Calculation
    if ($row['legacy_pd_count'] > 0) {
        $data[$d]['pd'] += $rates['extra_pd'];
        $data[$d]['has_extra_pd'] = true; // Mark as paid so we don't double count in logs
    }
}

// B. Get Logs (Expenses + New PD Check)
$stmt = $db->prepare("SELECT log_date, SUM(fuel_cost) as total_fuel, SUM(mileage) as total_miles, extra_per_diem 
                      FROM daily_logs 
                      WHERE user_id = ? AND log_date BETWEEN ? AND ? 
                      GROUP BY log_date");
$stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);

while ($row = $stmt->fetch()) {
    $d = $row['log_date'];
    if (!isset($data[$d])) $data[$d] = ['pay'=>0, 'pd'=>0, 'fuel'=>0, 'miles'=>0, 'jobs'=>0, 'has_extra_pd'=>false];
    
    $data[$d]['fuel'] += $row['total_fuel'];
    $data[$d]['miles'] += $row['total_miles'];
    
    // 3. New Extra PD Calculation
    // Only add if we haven't already added it via the Legacy check above
    if ($row['extra_per_diem'] == 1 && !$data[$d]['has_extra_pd']) {
        $data[$d]['pd'] += $rates['extra_pd'];
        $data[$d]['has_extra_pd'] = true;
    }
}

// --- 4. AGGREGATE ---
$report_rows = [];
$grand_total = ['gross'=>0, 'pd'=>0, 'fuel'=>0, 'net'=>0, 'miles'=>0, 'jobs'=>0];

ksort($data);

foreach ($data as $date => $stats) {
    $gross = $stats['pay'] + $stats['pd'];
    $net = $gross - $stats['fuel'];

    $grand_total['gross'] += $gross;
    $grand_total['pd'] += $stats['pd'];
    $grand_total['fuel'] += $stats['fuel'];
    $grand_total['net'] += $net;
    $grand_total['miles'] += $stats['miles'];
    $grand_total['jobs'] += $stats['jobs'];

    // Determine Label Key
    if ($view === 'daily') {
        $key = $date;
        $label = date('D, M d', strtotime($date));
    } elseif ($view === 'weekly') {
        $key = date('W', strtotime($date));
        $label = "Week " . $key;
    } elseif ($view === 'monthly') {
        $key = date('m', strtotime($date));
        $label = date('F', strtotime($date));
    }

    if (!isset($report_rows[$key])) {
        $report_rows[$key] = ['label'=>$label, 'pay'=>0, 'pd'=>0, 'fuel'=>0, 'miles'=>0, 'jobs'=>0];
    }
    
    $report_rows[$key]['pay'] += $stats['pay'];
    $report_rows[$key]['pd'] += $stats['pd'];
    $report_rows[$key]['fuel'] += $stats['fuel'];
    $report_rows[$key]['miles'] += $stats['miles'];
    $report_rows[$key]['jobs'] += $stats['jobs'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reports</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <link rel="shortcut icon" href="favicon.ico?v=2">
    <link rel="apple-touch-icon" href="favicon.png">
    <style>
        .filter-bar { background: var(--bg-card); padding: 10px 15px; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .view-toggles { display: flex; gap: 5px; }
        .nav-controls { display: flex; align-items: center; gap: 10px; background: var(--bg-input); padding: 5px; border-radius: 6px; border: 1px solid var(--border); }
        
        .pill { padding: 6px 15px; border-radius: 20px; font-size: 0.9rem; background: var(--bg-input); cursor: pointer; text-decoration: none; color: var(--text-main); border: 1px solid var(--border); transition: 0.2s; }
        .pill.active { background: var(--primary); color: white; border-color: var(--primary); }
        .pill:hover:not(.active) { background: var(--border); }

        .nav-arrow { text-decoration: none; font-size: 1.2rem; padding: 0 10px; color: var(--primary); font-weight: bold; user-select: none; }
        .nav-arrow:hover { color: var(--text-main); }
        
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: var(--bg-card); padding: 15px; border-radius: 8px; border: 1px solid var(--border); text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card small { display: block; color: var(--text-muted); font-size: 0.85rem; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card strong { display: block; font-size: 1.4rem; color: var(--text-main); }
        .stat-net { color: var(--success-text) !important; }
        
        .report-table { width: 100%; border-collapse: collapse; background: var(--bg-card); border-radius: 8px; overflow: hidden; border: 1px solid var(--border); font-size: 0.9rem; }
        .report-table th, .report-table td { padding: 12px 10px; text-align: right; border-bottom: 1px solid var(--border); }
        .report-table th:first-child, .report-table td:first-child { text-align: left; }
        .report-table th { background: var(--bg-input); font-weight: bold; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; }
        .report-table tr:last-child td { border-bottom: none; }
    </style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
    
    <div class="filter-bar">
        <div class="view-toggles">
            <a href="?view=daily" class="pill <?= ($view=='daily')?'active':'' ?>">Daily</a>
            <a href="?view=weekly" class="pill <?= ($view=='weekly')?'active':'' ?>">Weekly</a>
            <a href="?view=monthly" class="pill <?= ($view=='monthly')?'active':'' ?>">Monthly</a>
        </div>

        <form method="get" class="nav-controls">
            <input type="hidden" name="view" value="<?=$view?>">
            
            <a href="<?=$prev_link?>" class="nav-arrow">&laquo;</a>

            <div style="flex:1; display:flex; gap:5px; justify-content:center;">
                <?php if($view === 'daily'): ?>
                    <select name="month" onchange="this.form.submit()" style="padding:5px; border-radius:4px; border:none; background:transparent; color:var(--text-main); font-weight:bold; cursor:pointer;">
                        <?php for($m=1; $m<=12; $m++): ?>
                            <option value="<?=sprintf('%02d', $m)?>" <?= ($selected_month==$m)?'selected':'' ?>><?=date('F', mktime(0,0,0,$m,1))?></option>
                        <?php endfor; ?>
                    </select>
                <?php endif; ?>
                
                <select name="year" onchange="this.form.submit()" style="padding:5px; border-radius:4px; border:none; background:transparent; color:var(--text-main); font-weight:bold; cursor:pointer;">
                    <?php for($y=date('Y')+1; $y>=2024; $y--): ?>
                        <option value="<?=$y?>" <?= ($selected_year==$y)?'selected':'' ?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <a href="<?=$next_link?>" class="nav-arrow">&raquo;</a>
        </form>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <small>Total Gross</small>
            <strong title="Jobs + PD">$<?= number_format($grand_total['gross'], 2) ?></strong>
        </div>
        <div class="stat-card">
            <small>Fuel Cost</small>
            <strong style="color:var(--danger-text);">$<?= number_format($grand_total['fuel'], 2) ?></strong>
        </div>
        <div class="stat-card">
            <small>Net Profit</small>
            <strong class="stat-net">$<?= number_format($grand_total['net'], 2) ?></strong>
        </div>
        <div class="stat-card">
            <small>Miles Driven</small>
            <strong>
                <?= number_format($grand_total['miles']) ?> mi
            </strong>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Jobs</th>
                    <th>Job Pay</th>
                    <th>PD</th>
                    <th>Fuel</th>
                    <th>Net</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_rows)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px; color:var(--text-muted);">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($report_rows as $row): 
                        $gross = $row['pay'] + $row['pd'];
                        $net = $gross - $row['fuel'];
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['label']) ?></strong></td>
                        <td><?= $row['jobs'] ?></td>
                        <td>$<?= number_format($row['pay'], 2) ?></td>
                        <td style="color:var(--primary);">$<?= number_format($row['pd'], 2) ?></td>
                        <td style="color:var(--danger-text);">$<?= number_format($row['fuel'], 2) ?></td>
                        <td style="font-weight:bold; color:<?= ($net >= 0 ? 'var(--success-text)' : 'var(--danger-text)') ?>">
                            $<?= number_format($net, 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
<script>if(localStorage.getItem('theme')==='dark'){document.body.classList.add('dark-mode');}</script>
</body>
</html>