<?php
require_once 'config.php';
require_once 'functions.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

// --- CSRF PROTECTION ---
csrf_check();

$db = getDB();
$user_id = $_SESSION['user_id'];
$is_admin = is_admin();

// --- FETCH RATES ---
$rates = get_active_rates($db);
$mileage_rate = $rates['IRS_MILEAGE'] ?? 0.67;
$tax_percent = $rates['TAX_PERCENT'] ?? 0.25;
$lead_pay_rate = get_lead_pay_amount($db);

// --- VIEW MODE (weekly, monthly, yearly) ---
$view = $_GET['view'] ?? 'yearly';
if (!in_array($view, ['weekly', 'monthly', 'yearly']))
    $view = 'yearly';

// --- DATE CALCULATIONS BASED ON VIEW ---
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$week = $_GET['week'] ?? date('W');

if ($view === 'weekly') {
    // ISO week dates
    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $start_date = $dto->format('Y-m-d');
    $period_label_start = $dto->format('M j');
    $dto->modify('+6 days');
    $end_date = $dto->format('Y-m-d');
    $period_label = $period_label_start . ' - ' . $dto->format('M j, Y');

    // Navigation
    $prev_ts = strtotime("$start_date -7 days");
    $next_ts = strtotime("$start_date +7 days");
    $prev_link = "?view=weekly&week=" . date('W', $prev_ts) . "&year=" . date('o', $prev_ts);
    $next_link = "?view=weekly&week=" . date('W', $next_ts) . "&year=" . date('o', $next_ts);

} elseif ($view === 'monthly') {
    $start_date = "$year-$month-01";
    $days_in_month = date('t', strtotime($start_date));
    $end_date = "$year-$month-$days_in_month";
    $period_label = date('F Y', strtotime($start_date));

    // Navigation
    $prev_ts = strtotime("$start_date -1 month");
    $next_ts = strtotime("$start_date +1 month");
    $prev_link = "?view=monthly&month=" . date('m', $prev_ts) . "&year=" . date('Y', $prev_ts);
    $next_link = "?view=monthly&month=" . date('m', $next_ts) . "&year=" . date('Y', $next_ts);

} else { // yearly
    $start_date = "$year-01-01";
    $end_date = "$year-12-31";
    $period_label = $year;
    $prev_link = "?view=yearly&year=" . ($year - 1);
    $next_link = "?view=yearly&year=" . ($year + 1);
}

// --- FETCH DATA ---
$job_revenue = 0;
$active_weeks_by_user = [];
$breakdown_data = [];

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
    $pay = calculate_job_pay($j, $rates);
    $job_revenue += $pay;
    $j_uid = $j['user_id'];

    // Breakdown key based on view
    if ($view === 'weekly') {
        $key = date('D m/d', strtotime($j['install_date'])); // Mon 01/06
    } elseif ($view === 'monthly') {
        $key = 'Week ' . date('W', strtotime($j['install_date']));
    } else {
        $key = date('M', strtotime($j['install_date']));
    }

    if (!isset($breakdown_data[$key])) {
        $breakdown_data[$key] = ['label' => $key, 'rev' => 0, 'miles' => 0, 'fuel' => 0];
    }
    $breakdown_data[$key]['rev'] += $pay;

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
    }
}

// --- FETCH MILEAGE & FUEL ---
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

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $miles = (float) $row['mileage'];
        $fuel = (float) ($row['fuel_cost'] ?? 0);

        $total_miles += $miles;
        $total_fuel += $fuel;

        // Breakdown key
        if ($view === 'weekly') {
            $key = date('D m/d', strtotime($row['log_date']));
        } elseif ($view === 'monthly') {
            $key = 'Week ' . date('W', strtotime($row['log_date']));
        } else {
            $key = date('M', strtotime($row['log_date']));
        }

        if (!isset($breakdown_data[$key])) {
            $breakdown_data[$key] = ['label' => $key, 'rev' => 0, 'miles' => 0, 'fuel' => 0];
        }
        $breakdown_data[$key]['miles'] += $miles;
        $breakdown_data[$key]['fuel'] += $fuel;
    }
}

$mileage_deduction = $total_miles * $mileage_rate;
$net_taxable_income = $job_revenue - $mileage_deduction;
$estimated_tax_due = ($net_taxable_income > 0) ? ($net_taxable_income * $tax_percent) : 0;

// Sort breakdown by key for better display
ksort($breakdown_data);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Financials</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        .kpi-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .kpi-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--text-main);
            margin-top: 10px;
        }

        .kpi-sub {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 5px;
        }

        .positive {
            color: var(--success-text);
        }

        .negative {
            color: var(--danger-text);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .data-table th {
            text-align: left;
            background: var(--bg-input);
            padding: 10px;
            border-bottom: 2px solid var(--border);
        }

        .data-table td {
            padding: 10px;
            border-bottom: 1px solid var(--border);
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .header-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .view-tabs {
            display: flex;
            gap: 5px;
        }

        .view-tab {
            padding: 8px 16px;
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text-main);
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .view-tab:hover {
            background: var(--border);
        }

        .view-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .period-nav {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .period-nav a {
            font-size: 1.5rem;
            text-decoration: none;
            color: var(--text-main);
            font-weight: bold;
        }

        .period-nav a:hover {
            color: var(--primary);
        }

        .period-label {
            font-weight: 800;
            font-size: 1.1rem;
            min-width: 180px;
            text-align: center;
        }

        .info-bar {
            background: var(--bg-input);
            padding: 10px 15px;
            border-radius: 6px;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 20px;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        @media (max-width: 600px) {
            .header-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .view-tabs {
                justify-content: center;
            }

            .period-nav {
                justify-content: center;
            }
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <div class="header-controls">
            <h2>📊 Financials
                <?= $is_admin ? '<span style="font-size:0.6em; color:var(--primary); background:var(--bg-input); padding:2px 8px; border-radius:4px; vertical-align:middle;">ADMIN</span>' : '' ?>
            </h2>

            <div class="view-tabs">
                <a href="?view=weekly" class="view-tab <?= $view === 'weekly' ? 'active' : '' ?>">Weekly</a>
                <a href="?view=monthly" class="view-tab <?= $view === 'monthly' ? 'active' : '' ?>">Monthly</a>
                <a href="?view=yearly" class="view-tab <?= $view === 'yearly' ? 'active' : '' ?>">Yearly</a>
            </div>
        </div>

        <div class="period-nav">
            <a href="<?= htmlspecialchars($prev_link) ?>">&laquo;</a>
            <div class="period-label"><?= htmlspecialchars($period_label) ?></div>
            <a href="<?= htmlspecialchars($next_link) ?>">&raquo;</a>
        </div>

        <div class="info-bar">
            <div><strong>IRS Rate:</strong> $<?= number_format($mileage_rate, 3) ?>/mi</div>
            <div><strong>Tax Rate:</strong> <?= $tax_percent * 100 ?>%</div>
            <div><a href="settings.php" style="color:var(--primary); text-decoration:none;">Change Rates &rarr;</a>
            </div>
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
                <div class="kpi-sub"><?= number_format($total_miles) ?> Miles</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Actual Fuel</div>
                <div class="kpi-value negative">$<?= number_format($total_fuel, 2) ?></div>
                <div class="kpi-sub">Real Expense</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Net Taxable</div>
                <div class="kpi-value">$<?= number_format($net_taxable_income, 2) ?></div>
                <div class="kpi-sub">Rev - Mileage</div>
            </div>
            <div class="kpi-card" style="border-color: var(--primary);">
                <div class="kpi-label" style="color:var(--primary);">Est. Tax Due</div>
                <div class="kpi-value">$<?= number_format($estimated_tax_due, 2) ?></div>
                <div class="kpi-sub"><?= $tax_percent * 100 ?>% Rate</div>
            </div>
        </div>

        <div class="box" style="padding:0; overflow:hidden;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= $view === 'weekly' ? 'Day' : ($view === 'monthly' ? 'Week' : 'Month') ?></th>
                        <th style="text-align:right;">Revenue</th>
                        <th style="text-align:right;">Miles</th>
                        <th style="text-align:right;">Fuel</th>
                        <th style="text-align:right;">Deduction</th>
                        <th style="text-align:right;">Net</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $has_data = false;
                    foreach ($breakdown_data as $b_data):
                        $b_ded = $b_data['miles'] * $mileage_rate;
                        $b_net = $b_data['rev'] - $b_ded;

                        if ($b_data['rev'] == 0 && $b_data['miles'] == 0 && $b_data['fuel'] == 0)
                            continue;
                        $has_data = true;
                        ?>
                        <tr>
                            <td style="font-weight:bold;"><?= htmlspecialchars($b_data['label']) ?></td>
                            <td style="text-align:right; color:var(--success-text);">
                                $<?= number_format($b_data['rev'], 2) ?></td>
                            <td style="text-align:right;"><?= number_format($b_data['miles']) ?></td>
                            <td style="text-align:right; color:var(--danger-text);">
                                $<?= number_format($b_data['fuel'], 2) ?></td>
                            <td style="text-align:right;">$<?= number_format($b_ded, 2) ?></td>
                            <td style="text-align:right; font-weight:bold;">$<?= number_format($b_net, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$has_data): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">No data for
                                this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
    </script>
</body>

</html>