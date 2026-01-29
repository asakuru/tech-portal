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
    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $start_date = $dto->format('Y-m-d');
    $period_label_start = $dto->format('M j');
    $dto->modify('+6 days');
    $end_date = $dto->format('Y-m-d');
    $period_label = $period_label_start . ' - ' . $dto->format('M j, Y');

    $prev_ts = strtotime("$start_date -7 days");
    $next_ts = strtotime("$start_date +7 days");
    $prev_link = "?view=weekly&week=" . date('W', $prev_ts) . "&year=" . date('o', $prev_ts);
    $next_link = "?view=weekly&week=" . date('W', $next_ts) . "&year=" . date('o', $next_ts);

} elseif ($view === 'monthly') {
    $month_start = "$year-$month-01";
    $days_in_month = date('t', strtotime($month_start));
    $month_end = "$year-$month-$days_in_month";
    $period_label = date('F Y', strtotime($month_start));

    $first_day_dow = date('N', strtotime($month_start));
    $start_date = date('Y-m-d', strtotime($month_start . " -" . ($first_day_dow - 1) . " days"));

    $last_day_dow = date('N', strtotime($month_end));
    $end_date = date('Y-m-d', strtotime($month_end . " +" . (7 - $last_day_dow) . " days"));

    $prev_ts = strtotime("$month_start -1 month");
    $next_ts = strtotime("$month_start +1 month");
    $prev_link = "?view=monthly&month=" . date('m', $prev_ts) . "&year=" . date('Y', $prev_ts);
    $next_link = "?view=monthly&month=" . date('m', $next_ts) . "&year=" . date('Y', $next_ts);

} else { // yearly
    $start_date = "$year-01-01";
    $end_date = "$year-12-31";
    $period_label = $year;
    $prev_link = "?view=yearly&year=" . ($year - 1);
    $next_link = "?view=yearly&year=" . ($year + 1);
}

// --- HELPERS ---
function getBreakdownKey($date, $view)
{
    if ($view === 'weekly')
        return $date;
    if ($view === 'monthly')
        return date('W', strtotime($date));
    return date('m', strtotime($date));
}

function getBreakdownLabel($date, $view)
{
    if ($view === 'weekly')
        return date('D m/d', strtotime($date));
    if ($view === 'monthly')
        return 'Week ' . date('W', strtotime($date));
    return date('M', strtotime($date));
}

// --- 3. FETCH & CALCULATE DATA (Consolidated Brain) ---
$job_revenue = 0;
$total_per_diem = 0;
$total_lead_pay = 0;
$total_miles = 0;
$total_fuel = 0;
$breakdown_data = [];
$job_type_stats = [];

$current = $start_date;
$today_str = date('Y-m-d');
$active_weeks = [];

while ($current <= $end_date) {
    $is_future = ($current > $today_str);
    $is_sunday = (date('w', strtotime($current)) == 0);

    if (!$is_future || $is_sunday) {
        $uids = $is_admin ? $db->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN) : [$user_id];

        $day_job_pay = 0;
        $day_pd = 0;

        foreach ($uids as $uid) {
            $p = calculate_daily_payroll($db, $uid, $current, $rates);
            $day_job_pay += $p['job_pay'];
            $day_pd += $p['std_pd'] + $p['ext_pd'];

            foreach ($p['jobs'] as $j) {
                $jtype = $j['install_type'];
                if ($jtype !== 'DO' && $jtype !== 'ND') {
                    $wk_end = date('Y-m-d', strtotime($current . " +" . (6 - date('w', strtotime($current))) . " days"));
                    $active_weeks[$uid][$wk_end] = true;
                }
                if (!isset($job_type_stats[$jtype]))
                    $job_type_stats[$jtype] = ['count' => 0, 'revenue' => 0];
                $job_type_stats[$jtype]['count']++;
                $job_type_stats[$jtype]['revenue'] += (float) $j['pay_amount'];
            }
        }

        $job_revenue += $day_job_pay + $day_pd;
        $total_per_diem += $day_pd;

        // Mileage/Fuel from logs
        if ($is_admin) {
            $stmt = $db->prepare("SELECT SUM(mileage) as m, SUM(fuel_cost) as f FROM daily_logs WHERE log_date = ?");
            $params = [$current];
        } else {
            $stmt = $db->prepare("SELECT mileage as m, fuel_cost as f FROM daily_logs WHERE user_id = ? AND log_date = ?");
            $params = [$user_id, $current];
        }
        $stmt->execute($params);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        $day_miles = (float) ($log['m'] ?? 0);
        $day_fuel = (float) ($log['f'] ?? 0);
        $total_miles += $day_miles;
        $total_fuel += $day_fuel;

        $key = getBreakdownKey($current, $view);
        if (!isset($breakdown_data[$key])) {
            $breakdown_data[$key] = ['label' => getBreakdownLabel($current, $view), 'work' => 0, 'pd' => 0, 'miles' => 0, 'fuel' => 0, 'lead' => 0];
        }
        $breakdown_data[$key]['work'] += $day_job_pay;
        $breakdown_data[$key]['pd'] += $day_pd;
        $breakdown_data[$key]['miles'] += $day_miles;
        $breakdown_data[$key]['fuel'] += $day_fuel;
    }
    $current = date('Y-m-d', strtotime("$current +1 day"));
}

foreach ($active_weeks as $uid => $weeks) {
    foreach ($weeks as $wk_end => $val) {
        $total_lead_pay += $lead_pay_rate;
        $job_revenue += $lead_pay_rate;
        $key = getBreakdownKey($wk_end, $view);
        if (isset($breakdown_data[$key])) {
            if (!isset($breakdown_data[$key]['lead']))
                $breakdown_data[$key]['lead'] = 0;
            $breakdown_data[$key]['lead'] += $lead_pay_rate;
        }
    }
}

$mileage_deduction = $total_miles * $mileage_rate;
$net_taxable_income = $job_revenue - $mileage_deduction;
$estimated_tax_due = ($net_taxable_income > 0) ? ($net_taxable_income * $tax_percent) : 0;

ksort($breakdown_data);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Financials</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
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
                <?= $is_admin ? '<span class="badge" style="background:var(--primary); font-size:0.6em; vertical-align:middle; padding:2px 6px; border-radius:4px; margin-left:8px;">ADMIN</span>' : '' ?>
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
            <div><strong>Total Miles:</strong> <?= number_format($total_miles) ?></div>
            <div><strong>IRS Rate:</strong> $<?= number_format($mileage_rate, 3) ?>/mi</div>
            <div><strong>Tax Rate:</strong> <?= $tax_percent * 100 ?>%</div>
            <div><a href="settings.php" style="color:var(--primary); text-decoration:none;">Change Rates &rarr;</a>
            </div>
        </div>

        <div class="kpi-grid">
            <?php
            include __DIR__ . '/components/kpi_card.php';
            $label = "Gross Revenue";
            $value = "$" . number_format($job_revenue, 2);
            $class = "positive";
            $sub = "PD: $" . number_format($total_per_diem) . " | Lead: $" . number_format($total_lead_pay);
            $style = "";
            include __DIR__ . '/components/kpi_card.php';
            $label = "Mileage Deduction";
            $value = "$" . number_format($mileage_deduction, 2);
            $class = "";
            $sub = number_format($total_miles) . " Miles";
            include __DIR__ . '/components/kpi_card.php';
            $label = "Actual Fuel";
            $value = "$" . number_format($total_fuel, 2);
            $class = "negative";
            $sub = "Real Expense";
            include __DIR__ . '/components/kpi_card.php';
            $label = "Net Taxable";
            $value = "$" . number_format($net_taxable_income, 2);
            $class = "";
            $sub = "Rev - Mileage";
            include __DIR__ . '/components/kpi_card.php';
            $label = "Est. Tax Due";
            $value = "$" . number_format($estimated_tax_due, 2);
            $class = "";
            $sub = ($tax_percent * 100) . "% Rate";
            $style = "border-color: var(--primary);";
            include __DIR__ . '/components/kpi_card.php';
            ?>
        </div>

        <div class="box" style="padding:0; overflow:hidden; overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= $view === 'weekly' ? 'Day' : ($view === 'monthly' ? 'Week' : 'Month') ?></th>
                        <th style="text-align:right;">Work</th>
                        <th style="text-align:right;">Per Diem</th>
                        <th style="text-align:right;">Gross</th>
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
                        $b_lead = $b_data['lead'] ?? 0;
                        $b_gross = $b_data['work'] + $b_data['pd'] + $b_lead;
                        $b_ded = $b_data['miles'] * $mileage_rate;
                        $b_net = $b_gross - $b_ded;
                        if ($view !== 'weekly' && $b_gross == 0 && $b_data['miles'] == 0 && $b_data['fuel'] == 0)
                            continue;
                        $has_data = true;
                        ?>
                        <tr>
                            <td style="font-weight:bold;"><?= htmlspecialchars($b_data['label']) ?></td>
                            <td style="text-align:right;">$<?= number_format($b_data['work'], 2) ?></td>
                            <td style="text-align:right; color:var(--primary);">
                                $<?= number_format($b_data['pd'] + $b_lead, 2) ?></td>
                            <td style="text-align:right; color:var(--success-text); font-weight:bold;">
                                $<?= number_format($b_gross, 2) ?></td>
                            <td style="text-align:right;"><?= number_format($b_data['miles']) ?></td>
                            <td style="text-align:right; color:var(--danger-text);">
                                $<?= number_format($b_data['fuel'], 2) ?></td>
                            <td style="text-align:right;">$<?= number_format($b_ded, 2) ?></td>
                            <td style="text-align:right; font-weight:bold;">$<?= number_format($b_net, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$has_data): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:20px; color:var(--text-muted);">No data for
                                this period.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($job_type_stats)): ?>
            <h3
                style="margin-top:2rem; margin-bottom:1rem; border-bottom:1px solid var(--border-color); padding-bottom:0.5rem;">
                Profit Analysis <span class="badge"
                    style="background:var(--primary); vertical-align:middle; font-size:0.6em;">JOB TYPE</span></h3>
            <div class="box" style="padding:0; overflow:hidden;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Job Type</th>
                            <th style="text-align:right;">Count</th>
                            <th style="text-align:right;">Total Revenue</th>
                            <th style="text-align:right;">Avg. Revenue</th>
                            <th>Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        uasort($job_type_stats, function ($a, $b) {
                            return $b['revenue'] <=> $a['revenue'];
                        });
                        $max_rev = 0;
                        foreach ($job_type_stats as $stat)
                            $max_rev = max($max_rev, $stat['revenue']);
                        foreach ($job_type_stats as $type => $stats):
                            $avg = $stats['revenue'] / $stats['count'];
                            $percent = ($max_rev > 0) ? ($stats['revenue'] / $max_rev) * 100 : 0;
                            ?>
                            <tr>
                                <td style="font-weight:bold;"><?= htmlspecialchars($type) ?></td>
                                <td style="text-align:right;"><?= number_format($stats['count']) ?></td>
                                <td style="text-align:right; color:var(--success-text); font-weight:bold;">
                                    $<?= number_format($stats['revenue'], 2) ?></td>
                                <td style="text-align:right;">$<?= number_format($avg, 2) ?></td>
                                <td style="width:150px; padding-right:20px;">
                                    <div
                                        style="background:var(--bg-secondary); height:8px; border-radius:4px; overflow:hidden; width:100%;">
                                        <div style="background:var(--primary); height:100%; width:<?= $percent ?>%;"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <script>if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }</script>
</body>

</html>