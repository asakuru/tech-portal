<?php
require_once 'config.php';
require_once 'functions.php'; // <--- USE THE BRAIN

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php'); exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// --- 1. FETCH RATES VIA FUNCTIONS ---
// We pull all rates once so we can use them for calculations and settings
$rates = get_active_rates($db);

$mileage_rate = $rates['IRS_MILEAGE'] ?? 0.67; 
$tax_percent  = $rates['TAX_PERCENT'] ?? 0.25;
$lead_pay_rate = get_lead_pay_amount($db); // Helper from functions.php

// --- DATE FILTER (Default to Current Year) ---
$year = $_GET['year'] ?? date('Y');
$start_date = "$year-01-01";
$end_date   = "$year-12-31";

// --- 2. FETCH REVENUE (JOBS) ---
$job_revenue = 0;
$active_weeks_by_user = []; 
$monthly_data = [];

// Initialize months (1-12)
for($m=1; $m<=12; $m++) {
    $monthly_data[$m] = ['label'=>date('M', mktime(0,0,0,$m,1)), 'rev'=>0, 'miles'=>0, 'fuel'=>0];
}

// Get Jobs
if ($is_admin) {
    $sql = "SELECT * FROM jobs WHERE install_date BETWEEN ? AND ? ORDER BY install_date ASC";
    $params = [$start_date, $end_date];
} else {
    $sql = "SELECT * FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? ORDER BY install_date ASC";
    $params = [$user_id, $start_date, $end_date];
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$all_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($all_jobs as $j) {
    // --- USE CENTRALIZED MATH ---
    // Instead of using the stored 'pay_amount', we recalculate it based on current rates
    // This ensures your dashboard reflects your current pricing configuration.
    $pay = calculate_job_pay($j, $rates);
    
    $job_revenue += $pay;
    $j_uid = $j['user_id'];
    
    // Add to monthly bucket
    $m = (int)date('n', strtotime($j['install_date']));
    $monthly_data[$m]['rev'] += $pay;

    // Track weeks for Lead Pay
    if ($j['install_type'] !== 'DO' && $j['install_type'] !== 'ND') {
        $date_ts = strtotime($j['install_date']);
        $day_of_week = date('w', $date_ts); 
        $days_to_sat = 6 - $day_of_week;
        $week_ending = date('Y-m-d', strtotime($j['install_date'] . " +$days_to_sat days"));
        
        $active_weeks_by_user[$j_uid][$week_ending] = true;
    }
}

// Add Lead Pay
$total_lead_pay = 0;
foreach ($active_weeks_by_user as $uid => $weeks) {
    foreach ($weeks as $wk_end => $bool) {
        $total_lead_pay += $lead_pay_rate;
        $job_revenue += $lead_pay_rate;
        
        $m = (int)date('n', strtotime($wk_end));
        if (isset($monthly_data[$m])) {
            $monthly_data[$m]['rev'] += $lead_pay_rate;
        }
    }
}

// --- 3. FETCH MILEAGE & FUEL ---
$total_miles = 0;
$total_fuel = 0;
$check_table = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='daily_logs'")->fetch();

if ($check_table) {
    if ($is_admin) {
        $sql = "SELECT log_date, mileage, fuel_cost FROM daily_logs WHERE log_date BETWEEN ? AND ?";
        $params = [$start_date, $end_date];
    } else {
        $sql = "SELECT log_date, mileage, fuel_cost FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?";
        $params = [$user_id, $start_date, $end_date];
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $miles = (float)$row['mileage'];
        $fuel  = (float)($row['fuel_cost'] ?? 0);
        
        $total_miles += $miles;
        $total_fuel  += $fuel;
        
        $m = (int)date('n', strtotime($row['log_date']));
        if (isset($monthly_data[$m])) {
            $monthly_data[$m]['miles'] += $miles;
            $monthly_data[$m]['fuel'] += $fuel;
        }
    }
}

$mileage_deduction = $total_miles * $mileage_rate;
$net_taxable_income = $job_revenue - $mileage_deduction;
$estimated_tax_due = ($net_taxable_income > 0) ? ($net_taxable_income * $tax_percent) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Financials</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .kpi-card { background: var(--bg-card); border: 1px solid var(--border); padding: 20px; border-radius: 8px; text-align: center; }
        .kpi-label { font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; font-weight: bold; letter-spacing: 0.5px; }
        .kpi-value { font-size: 1.8rem; font-weight: 800; color: var(--text-main); margin-top: 10px; }
        .kpi-sub { font-size: 0.8rem; color: var(--text-muted); margin-top: 5px; }
        
        .positive { color: var(--success-text); }
        .negative { color: var(--danger-text); }
        
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .data-table th { text-align: left; background: var(--bg-input); padding: 10px; border-bottom: 2px solid var(--border); }
        .data-table td { padding: 10px; border-bottom: 1px solid var(--border); }
        .data-table tr:last-child td { border-bottom: none; }
        
        .header-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .year-select { border: 1px solid var(--border); background: var(--bg-input); color: var(--text-main); font-weight: bold; padding: 8px 15px; border-radius: 4px; font-size:1rem; }
        
        .info-bar { background: var(--bg-input); padding: 10px 15px; border-radius: 6px; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px; display: flex; gap: 20px; flex-wrap: wrap; }
    </style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">

    <div class="header-controls">
        <h2>📊 Financial Dashboard <?= $is_admin ? '<span style="font-size:0.6em; color:var(--primary); background:var(--bg-input); padding:2px 8px; border-radius:4px; vertical-align:middle;">ADMIN VIEW</span>' : '' ?></h2>
        <div>
            <select onchange="window.location.href='?year='+this.value" class="year-select">
                <?php 
                $curr = date('Y');
                for($y=$curr+1; $y>=$curr-3; $y--) {
                    $sel = ($y == $year) ? 'selected' : '';
                    echo "<option value='$y' $sel>$y</option>";
                }
                ?>
            </select>
        </div>
    </div>

    <div class="info-bar">
        <div><strong>IRS Rate:</strong> $<?= number_format($mileage_rate, 3) ?>/mi</div>
        <div><strong>Tax Rate:</strong> <?= $tax_percent * 100 ?>%</div>
        <div><a href="settings.php" style="color:var(--primary); text-decoration:none;">Change Rates &rarr;</a></div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Gross Revenue</div>
            <div class="kpi-value positive">$<?= number_format($job_revenue, 2) ?></div>
            <div class="kpi-sub">Includes $<?= number_format($total_lead_pay) ?> Lead Pay</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Mileage Deduction</div>
            <div class="kpi-value">$<?= number_format($mileage_deduction, 2) ?></div>
            <div class="kpi-sub"><?= number_format($total_miles) ?> Miles × $<?= $mileage_rate ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Actual Fuel Cost</div>
            <div class="kpi-value negative">$<?= number_format($total_fuel, 2) ?></div>
            <div class="kpi-sub">Real Pump Expense</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Net Taxable Income</div>
            <div class="kpi-value">$<?= number_format($net_taxable_income, 2) ?></div>
            <div class="kpi-sub">Rev - Mileage Ded</div>
        </div>
        <div class="kpi-card" style="border-color: var(--primary);">
            <div class="kpi-label" style="color:var(--primary);">Est. Tax Due</div>
            <div class="kpi-value">$<?= number_format($estimated_tax_due, 2) ?></div>
            <div class="kpi-sub">Based on <?= $tax_percent * 100 ?>% Rate</div>
        </div>
    </div>

    <div class="box" style="padding:0; overflow:hidden;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th style="text-align:right;">Gross Revenue</th>
                    <th style="text-align:right;">Miles</th>
                    <th style="text-align:right;">Actual Fuel</th>
                    <th style="text-align:right;">Deduction Value</th>
                    <th style="text-align:right;">Taxable Net</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $has_data = false;
                foreach($monthly_data as $m_data): 
                    $mon_ded = $m_data['miles'] * $mileage_rate;
                    $mon_net = $m_data['rev'] - $mon_ded;
                    
                    // Only show rows that have data
                    if($m_data['rev'] == 0 && $m_data['miles'] == 0 && $m_data['fuel'] == 0) continue;
                    $has_data = true;
                ?>
                <tr>
                    <td style="font-weight:bold;"><?= $m_data['label'] ?></td>
                    <td style="text-align:right; color:var(--success-text);">$<?= number_format($m_data['rev'], 2) ?></td>
                    <td style="text-align:right;"><?= number_format($m_data['miles']) ?></td>
                    <td style="text-align:right; color:var(--danger-text);">$<?= number_format($m_data['fuel'], 2) ?></td>
                    <td style="text-align:right;">$<?= number_format($mon_ded, 2) ?></td>
                    <td style="text-align:right; font-weight:bold;">$<?= number_format($mon_net, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(!$has_data): ?>
                    <tr><td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">No data found for <?= $year ?>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
    if(localStorage.getItem('theme')==='dark'){document.body.classList.add('dark-mode');}
</script>
</body>
</html>