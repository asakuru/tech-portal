<?php
require 'config.php';
require 'functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// Get rates
$std_pd_rate = (float) ($rates['per_diem'] ?? 0.00);
$lead_pay_rate = (float) ($rates['lead_pay'] ?? 0.00);

// Date ranges
$today = date('Y-m-d');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end = date('Y-m-t', strtotime('-1 month'));
$two_months_ago_start = date('Y-m-01', strtotime('-2 months'));
$two_months_ago_end = date('Y-m-t', strtotime('-2 months'));
$twelve_weeks_ago = date('Y-m-d', strtotime('-12 weeks'));

// ============================================
// 1. WEEKLY EARNINGS TREND (Last 12 weeks)
// ============================================
$weekly_earnings = [];
for ($i = 11; $i >= 0; $i--) {
    $week_start = date('Y-m-d', strtotime("-$i weeks monday"));
    $week_end = date('Y-m-d', strtotime("-$i weeks sunday"));
    $week_label = date('M j', strtotime($week_start));

    // Get jobs for this week
    $stmt = $db->prepare("SELECT SUM(pay_amount) as work FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? AND install_type NOT IN ('DO', 'ND')");
    $stmt->execute([$user_id, $week_start, $week_end]);
    $work = (float) ($stmt->fetch()['work'] ?? 0);

    // Count work days for per diem
    $stmt = $db->prepare("SELECT COUNT(DISTINCT install_date) as days FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? AND install_type NOT IN ('DO')");
    $stmt->execute([$user_id, $week_start, $week_end]);
    $work_days = (int) ($stmt->fetch()['days'] ?? 0);

    // Add Sundays
    $current = strtotime($week_start);
    $end = strtotime($week_end);
    while ($current <= $end) {
        if (date('N', $current) == 7)
            $work_days++;
        $current = strtotime('+1 day', $current);
    }

    $pd = $work_days * $std_pd_rate;
    $total = $work + $pd + ($work > 0 ? $lead_pay_rate : 0);

    $weekly_earnings[] = [
        'label' => $week_label,
        'work' => $work,
        'pd' => $pd,
        'total' => $total
    ];
}

// ============================================
// 2. JOB TYPE BREAKDOWN (All time for this user)
// ============================================
$stmt = $db->prepare("SELECT install_type, COUNT(*) as count, SUM(pay_amount) as revenue FROM jobs WHERE user_id = ? AND install_type NOT IN ('DO', 'ND') GROUP BY install_type ORDER BY revenue DESC");
$stmt->execute([$user_id]);
$job_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

$type_labels = [];
$type_counts = [];
$type_revenues = [];
$type_colors = ['#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899', '#f43f5e', '#f97316', '#eab308', '#22c55e', '#14b8a6'];
foreach ($job_types as $idx => $jt) {
    $label = $dropdown_labels[$jt['install_type']] ?? $jt['install_type'];
    $type_labels[] = $label;
    $type_counts[] = (int) $jt['count'];
    $type_revenues[] = (float) $jt['revenue'];
}

// ============================================
// 3. MONTH COMPARISON
// ============================================
function getMonthStats($db, $user_id, $start, $end, $std_pd_rate, $lead_pay_rate)
{
    // Jobs
    $stmt = $db->prepare("SELECT SUM(pay_amount) as work, COUNT(*) as jobs FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? AND install_type NOT IN ('DO', 'ND')");
    $stmt->execute([$user_id, $start, $end]);
    $job_data = $stmt->fetch();
    $work = (float) ($job_data['work'] ?? 0);
    $jobs = (int) ($job_data['jobs'] ?? 0);

    // Days worked
    $stmt = $db->prepare("SELECT COUNT(DISTINCT install_date) as days FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? AND install_type NOT IN ('DO')");
    $stmt->execute([$user_id, $start, $end]);
    $days = (int) ($stmt->fetch()['days'] ?? 0);

    // Fuel
    $stmt = $db->prepare("SELECT SUM(fuel_cost) as fuel, SUM(mileage) as miles FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $stmt->execute([$user_id, $start, $end]);
    $log_data = $stmt->fetch();
    $fuel = (float) ($log_data['fuel'] ?? 0);
    $miles = (float) ($log_data['miles'] ?? 0);

    // Estimate PD (work days + Sundays in range)
    $pd_days = $days;
    $current = strtotime($start);
    $end_ts = strtotime($end);
    while ($current <= $end_ts) {
        if (date('N', $current) == 7)
            $pd_days++;
        $current = strtotime('+1 day', $current);
    }
    $pd = $pd_days * $std_pd_rate;

    // Lead pay (estimate 1 per week with work)
    $weeks_with_work = ceil($days / 5);
    $lead = $work > 0 ? $weeks_with_work * $lead_pay_rate : 0;

    $gross = $work + $pd + $lead;
    $net = $gross - $fuel;
    $avg_day = $days > 0 ? $gross / $days : 0;

    return [
        'gross' => $gross,
        'work' => $work,
        'pd' => $pd,
        'jobs' => $jobs,
        'days' => $days,
        'miles' => $miles,
        'fuel' => $fuel,
        'net' => $net,
        'avg_day' => $avg_day
    ];
}

$this_month = getMonthStats($db, $user_id, $current_month_start, $current_month_end, $std_pd_rate, $lead_pay_rate);
$last_month = getMonthStats($db, $user_id, $last_month_start, $last_month_end, $std_pd_rate, $lead_pay_rate);
$two_months = getMonthStats($db, $user_id, $two_months_ago_start, $two_months_ago_end, $std_pd_rate, $lead_pay_rate);

// Calculate % changes
function pctChange($current, $previous)
{
    if ($previous == 0)
        return $current > 0 ? 100 : 0;
    return round((($current - $previous) / $previous) * 100);
}

$avg_day_change = pctChange($this_month['avg_day'], $last_month['avg_day']);
$jobs_change = pctChange($this_month['jobs'], $last_month['jobs']);
$miles_change = pctChange($this_month['miles'], $last_month['miles']);

// Cost per mile
$cost_per_mile = $this_month['miles'] > 0 ? $this_month['fuel'] / $this_month['miles'] : 0;
$last_cpm = $last_month['miles'] > 0 ? $last_month['fuel'] / $last_month['miles'] : 0;
$cpm_change = pctChange($cost_per_mile, $last_cpm);

// Jobs per day
$jobs_per_day = $this_month['days'] > 0 ? $this_month['jobs'] / $this_month['days'] : 0;
$last_jpd = $last_month['days'] > 0 ? $last_month['jobs'] / $last_month['days'] : 0;
$jpd_change = pctChange($jobs_per_day, $last_jpd);

// ============================================
// 4. BEST DAYS & WEEKS
// ============================================
// Best days
$stmt = $db->prepare("SELECT install_date, SUM(pay_amount) as total FROM jobs WHERE user_id = ? AND install_type NOT IN ('DO', 'ND') GROUP BY install_date ORDER BY total DESC LIMIT 5");
$stmt->execute([$user_id]);
$best_days = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Best weeks (by week ending Saturday)
$stmt = $db->prepare("
    SELECT 
        strftime('%Y-%W', install_date) as week_num,
        MIN(install_date) as week_start,
        SUM(pay_amount) as total 
    FROM jobs 
    WHERE user_id = ? AND install_type NOT IN ('DO', 'ND')
    GROUP BY week_num 
    ORDER BY total DESC 
    LIMIT 5
");
$stmt->execute([$user_id]);
$best_weeks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Month labels for chart
$month_labels = [
    date('M Y', strtotime('-2 months')),
    date('M Y', strtotime('-1 month')),
    date('M Y')
];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Analytics</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .analytics-header h2 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .kpi-card {
            position: relative;
        }

        .kpi-change {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 6px;
            display: inline-block;
        }

        .kpi-change.positive {
            background: rgba(34, 197, 94, 0.15);
            color: var(--success-text);
        }

        .kpi-change.negative {
            background: rgba(239, 68, 68, 0.15);
            color: var(--danger-text);
        }

        .chart-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .chart-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }

        .chart-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .chart-container {
            position: relative;
            height: 280px;
        }

        .chart-container-small {
            position: relative;
            height: 220px;
        }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .insight-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
        }

        .insight-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .insight-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .insight-list li {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }

        .insight-list li:last-child {
            border-bottom: none;
        }

        .insight-rank {
            width: 24px;
            height: 24px;
            background: var(--gradient-primary);
            color: white;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            margin-right: 10px;
        }

        .insight-amount {
            font-weight: 700;
            color: var(--success-text);
        }

        @media (max-width: 768px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }

            .chart-container {
                height: 220px;
            }
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <div class="analytics-header">
            <h2>📊 Analytics</h2>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Avg / Day</div>
                <div class="kpi-value">$<?= number_format($this_month['avg_day'], 0) ?></div>
                <div class="kpi-change <?= $avg_day_change >= 0 ? 'positive' : 'negative' ?>">
                    <?= $avg_day_change >= 0 ? '↑' : '↓' ?> <?= abs($avg_day_change) ?>% vs last month
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Jobs / Day</div>
                <div class="kpi-value"><?= number_format($jobs_per_day, 1) ?></div>
                <div class="kpi-change <?= $jpd_change >= 0 ? 'positive' : 'negative' ?>">
                    <?= $jpd_change >= 0 ? '↑' : '↓' ?> <?= abs($jpd_change) ?>%
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Cost / Mile</div>
                <div class="kpi-value">$<?= number_format($cost_per_mile, 2) ?></div>
                <div class="kpi-change <?= $cpm_change <= 0 ? 'positive' : 'negative' ?>">
                    <?= $cpm_change <= 0 ? '↓' : '↑' ?> <?= abs($cpm_change) ?>%
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">This Month</div>
                <div class="kpi-value positive">$<?= number_format($this_month['gross'], 0) ?></div>
                <div class="kpi-sub"><?= $this_month['jobs'] ?> jobs, <?= $this_month['days'] ?> days</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="chart-grid">
            <!-- Earnings Trend -->
            <div class="chart-card">
                <div class="chart-title">📈 Earnings Trend (12 Weeks)</div>
                <div class="chart-container">
                    <canvas id="earningsChart"></canvas>
                </div>
            </div>

            <!-- Job Type Breakdown -->
            <div class="chart-card">
                <div class="chart-title">🎯 Job Type Breakdown</div>
                <div class="chart-container-small">
                    <canvas id="jobTypeChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Month Comparison -->
        <div class="chart-card" style="margin-bottom: 24px;">
            <div class="chart-title">📊 Month Comparison</div>
            <div class="chart-container-small">
                <canvas id="monthChart"></canvas>
            </div>
        </div>

        <!-- Best Days & Weeks -->
        <div class="insights-grid">
            <div class="insight-card">
                <div class="insight-title">🏆 Best Days</div>
                <ul class="insight-list">
                    <?php foreach ($best_days as $idx => $day): ?>
                        <li>
                            <span>
                                <span class="insight-rank"><?= $idx + 1 ?></span>
                                <?= date('M j, Y', strtotime($day['install_date'])) ?>
                            </span>
                            <span class="insight-amount">$<?= number_format($day['total'], 2) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($best_days)): ?>
                        <li style="color: var(--text-muted); justify-content: center;">No data yet</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="insight-card">
                <div class="insight-title">🚀 Best Weeks</div>
                <ul class="insight-list">
                    <?php foreach ($best_weeks as $idx => $week): ?>
                        <li>
                            <span>
                                <span class="insight-rank"><?= $idx + 1 ?></span>
                                Week of <?= date('M j', strtotime($week['week_start'])) ?>
                            </span>
                            <span class="insight-amount">$<?= number_format($week['total'], 2) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($best_weeks)): ?>
                        <li style="color: var(--text-muted); justify-content: center;">No data yet</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

    </div>

    <script>
        // Chart.js configuration
        Chart.defaults.color = getComputedStyle(document.body).getPropertyValue('--text-muted').trim() || '#64748b';
        Chart.defaults.borderColor = getComputedStyle(document.body).getPropertyValue('--border').trim() || '#334155';

        // Earnings Trend Chart
        const earningsCtx = document.getElementById('earningsChart').getContext('2d');
        new Chart(earningsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($weekly_earnings, 'label')) ?>,
                datasets: [{
                    label: 'Total',
                    data: <?= json_encode(array_column($weekly_earnings, 'total')) ?>,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3
                }, {
                    label: 'Work',
                    data: <?= json_encode(array_column($weekly_earnings, 'work')) ?>,
                    borderColor: '#22c55e',
                    backgroundColor: 'transparent',
                    tension: 0.4,
                    borderWidth: 2,
                    borderDash: [5, 5]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Job Type Donut Chart
        const jobTypeCtx = document.getElementById('jobTypeChart').getContext('2d');
        new Chart(jobTypeCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($type_labels) ?>,
                datasets: [{
                    data: <?= json_encode($type_revenues) ?>,
                    backgroundColor: <?= json_encode(array_slice($type_colors, 0, count($type_labels))) ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 10
                        }
                    }
                }
            }
        });

        // Month Comparison Bar Chart
        const monthCtx = document.getElementById('monthChart').getContext('2d');
        new Chart(monthCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($month_labels) ?>,
                datasets: [{
                    label: 'Gross Revenue',
                    data: [<?= $two_months['gross'] ?>, <?= $last_month['gross'] ?>, <?= $this_month['gross'] ?>],
                    backgroundColor: ['#64748b', '#8b5cf6', '#6366f1'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    </script>

</body>

</html>