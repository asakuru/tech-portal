<?php
require 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// FIX 1: Force rates to be floats
$std_pd_rate = (float) ($rates['per_diem'] ?? 0.00);
$ext_pd_rate = (float) ($rates['extra_pd'] ?? 0.00);

$month = $_GET['m'] ?? date('m');
$year = $_GET['y'] ?? date('Y');
$first_day = "$year-$month-01";
$days_in_month = date('t', strtotime($first_day));
$month_name = date('F Y', strtotime($first_day));

$user_id = $_SESSION['user_id'];
$start_date = "$year-$month-01";
$end_date = "$year-$month-$days_in_month";

// 1. Fetch Jobs
$sql = "SELECT * FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? ORDER BY install_date ASC";
$stmt = $db->prepare($sql);
$stmt->execute([$user_id, $start_date, $end_date]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Logs
$stmt = $db->prepare("SELECT * FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
$stmt->execute([$user_id, $start_date, $end_date]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Process Data
$month_data = [];
$total_work = 0;
$total_std_pd = 0;
$total_ext_pd = 0;

for ($d = 1; $d <= $days_in_month; $d++) {
    $date = sprintf("%s-%02d-%02d", $year, $month, $d);
    $month_data[$date] = [
        'date' => $date,
        'day_num' => $d,
        'work' => 0.0,
        'std_pd' => 0.0,
        'ext_pd' => 0.0,
        'total' => 0.0,
        'miles' => 0,
        'fuel' => 0.0,
        'net' => 0.0,
        'is_closed' => false,
        'has_do' => false,
        'has_nd' => false,
        'jobs' => []
    ];
}

// Map Jobs (Legacy Extra PD Support)
foreach ($jobs as $j) {
    $d = $j['install_date'];
    if (isset($month_data[$d])) {
        // FIX 2: Force pay to float
        $pay_amount = (float) $j['pay_amount'];

        $month_data[$d]['jobs'][] = [
            'ticket' => $j['ticket_number'],
            'type' => $j['install_type'],
            'pay' => $pay_amount
        ];
        $month_data[$d]['work'] += $pay_amount;

        // Track DO and ND job types
        if ($j['install_type'] == 'DO') {
            $month_data[$d]['has_do'] = true;
        }
        if ($j['install_type'] == 'ND') {
            $month_data[$d]['has_nd'] = true;
        }

        // CHECK 1: Did we mark this JOB as Extra PD? (Legacy)
        if ($j['extra_per_diem'] == 'Yes') {
            $month_data[$d]['ext_pd'] = $ext_pd_rate;
        }
    }
}

// Map Logs (New Extra PD Support)
foreach ($logs as $l) {
    $d = $l['log_date'];
    if (isset($month_data[$d])) {
        $month_data[$d]['is_closed'] = ($l['is_closed'] == 1);
        $month_data[$d]['miles'] = (float) $l['mileage'];
        // FIX 3: Force fuel to float to prevent "float - string" error
        $month_data[$d]['fuel'] = (float) $l['fuel_cost'];

        // CHECK 2: Did we check the box on the DAY? (New Way)
        if (isset($l['extra_per_diem']) && $l['extra_per_diem'] == 1) {
            $month_data[$d]['ext_pd'] = $ext_pd_rate;
        }
    }
}

// Final Calculations
foreach ($month_data as $date => &$day) {
    // Standard PD Rules
    $is_sunday = (date('N', strtotime($date)) == 7);
    $has_eligible_work = false;
    foreach ($day['jobs'] as $job) {
        if ($job['type'] != 'DO')
            $has_eligible_work = true;
    }

    if ($is_sunday || $has_eligible_work) {
        $day['std_pd'] = $std_pd_rate;
    }

    $day['total'] = $day['work'] + $day['std_pd'] + $day['ext_pd'];

    // FIX 4: Ensure subtraction happens between floats
    $day['net'] = (float) $day['total'] - (float) $day['fuel'];

    $total_work += $day['work'];
    $total_std_pd += $day['std_pd'];
    $total_ext_pd += $day['ext_pd'];
}
unset($day);

$grand_total = $total_work + $total_std_pd + $total_ext_pd;

// Additional Dashboard Metrics
$total_fuel = 0;
$total_miles = 0;
$days_worked = 0;
$total_jobs = count($jobs);

foreach ($month_data as $d) {
    $total_fuel += (float) $d['fuel'];
    $total_miles += (float) $d['miles'];
    if ($d['work'] > 0 || count($d['jobs']) > 0) {
        $days_worked++;
    }
}

$net_profit = $grand_total - $total_fuel;
$avg_daily = ($days_worked > 0) ? ($grand_total / $days_worked) : 0;
$cost_per_mile = ($total_miles > 0) ? ($total_fuel / $total_miles) : 0;
$avg_mpg = 0;

// Get average MPG from daily_logs for this month
try {
    $stmt = $db->prepare("SELECT SUM(mileage) as miles, SUM(gallons) as gal FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $stmt->execute([$user_id, $start_date, $end_date]);
    $mpg_data = $stmt->fetch();
    if ($mpg_data && floatval($mpg_data['gal']) > 0) {
        $avg_mpg = floatval($mpg_data['miles']) / floatval($mpg_data['gal']);
    }
} catch (Exception $e) {
}

// Nav Links
$prev_m = date('m', strtotime("$first_day -1 month"));
$prev_y = date('Y', strtotime("$first_day -1 month"));
$next_m = date('m', strtotime("$first_day +1 month"));
$next_y = date('Y', strtotime("$first_day +1 month"));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .cal-wrapper {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            background: var(--border);
            border: 1px solid var(--border);
        }

        .cal-header {
            background: var(--bg-input);
            text-align: center;
            padding: 10px 0;
            font-weight: bold;
            font-size: 0.9rem;
        }

        .cal-day {
            background: var(--bg-card);
            min-height: 120px;
            padding: 6px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            cursor: pointer;
        }

        .cal-day:hover {
            background: var(--bg-input);
        }

        .day-num {
            font-weight: bold;
            margin-bottom: 4px;
            font-size: 1rem;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
        }

        .lock-icon {
            font-size: 0.8rem;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            margin-bottom: 2px;
        }

        .label {
            color: var(--text-muted);
        }

        .val-work {
            color: var(--text-main);
        }

        .val-std {
            color: var(--primary);
        }

        .val-ext {
            color: var(--success-text);
        }

        .day-total {
            border-top: 1px solid var(--border);
            margin-top: 4px;
            padding-top: 4px;
            text-align: right;
            font-weight: 800;
            font-size: 0.95rem;
            color: var(--text-main);
        }

        .add-btn {
            display: block;
            text-align: center;
            font-size: 2rem;
            line-height: 1;
            text-decoration: none;
            color: var(--border);
            margin-top: auto;
        }

        .cal-day:hover .add-btn {
            color: var(--primary);
        }

        .summary-card {
            background: var(--bg-card);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border);
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .sum-item {
            text-align: center;
        }

        .sum-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sum-val {
            font-size: 1.2rem;
            font-weight: bold;
        }

        .sum-total-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
        }

        .dashboard-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            padding: 10px 15px;
            border-radius: 8px;
            border: 1px solid var(--border);
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .nav-btn {
            text-decoration: none;
            background: var(--bg-input);
            color: var(--text-main);
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid var(--border);
            font-weight: bold;
            transition: background 0.2s;
        }

        .nav-btn:hover {
            background: var(--border);
        }

        .nav-title {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* MODAL STYLES */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--bg-card);
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            position: relative;
        }

        .close-modal {
            position: absolute;
            right: 15px;
            top: 10px;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-muted);
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
            margin-bottom: 15px;
            text-align: center;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed var(--border);
            font-size: 0.9rem;
        }

        .modal-total {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-top: 2px solid var(--border);
            font-weight: bold;
            font-size: 1.1rem;
            margin-top: 10px;
        }

        .modal-nav {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }

        .day-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: bold;
            margin-right: 3px;
        }

        .badge-do {
            background: var(--warning-bg);
            color: var(--warning-text);
        }

        .badge-nd {
            background: var(--primary);
            color: white;
        }

        .day-badges {
            margin-bottom: 4px;
        }

        @media (max-width: 600px) {
            .cal-header {
                font-size: 0.7rem;
                padding: 5px 0;
            }

            .cal-day {
                min-height: 80px;
                padding: 2px;
            }

            .stat-row {
                font-size: 0.65rem;
            }
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <div class="dashboard-nav">
            <a href="?m=<?= htmlspecialchars($prev_m) ?>&y=<?= htmlspecialchars($prev_y) ?>" class="nav-btn">&laquo;
                Prev</a>
            <div class="nav-title"><?= htmlspecialchars($month_name) ?></div>
            <a href="?m=<?= htmlspecialchars($next_m) ?>&y=<?= htmlspecialchars($next_y) ?>" class="nav-btn">Next
                &raquo;</a>
        </div>

        <!-- Earnings Summary -->
        <div class="summary-card">
            <div class="sum-item">
                <div class="sum-label">Work</div>
                <div class="sum-val">$<?= number_format($total_work, 2) ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-label">Per Diem</div>
                <div class="sum-val" style="color:var(--primary);">
                    $<?= number_format($total_std_pd + $total_ext_pd, 2) ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-label">Fuel Cost</div>
                <div class="sum-val" style="color:var(--danger-text);">-$<?= number_format($total_fuel, 2) ?></div>
            </div>
            <div class="sum-item" style="border-left:1px solid var(--border);">
                <div class="sum-label">Net Profit</div>
                <div class="sum-total-val"
                    style="color:<?= $net_profit >= 0 ? 'var(--success-text)' : 'var(--danger-text)' ?>;">
                    $<?= number_format($net_profit, 2) ?></div>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div class="summary-card" style="grid-template-columns: repeat(6, 1fr);">
            <div class="sum-item">
                <div class="sum-label">Jobs</div>
                <div class="sum-val"><?= $total_jobs ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-label">Days</div>
                <div class="sum-val"><?= $days_worked ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-label">Avg/Day</div>
                <div class="sum-val">$<?= number_format($avg_daily, 0) ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-label">Miles</div>
                <div class="sum-val"><?= number_format($total_miles) ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-label">$/Mile</div>
                <div class="sum-val"><?= $cost_per_mile > 0 ? '$' . number_format($cost_per_mile, 2) : '--' ?></div>
            </div>
            <div class="sum-item">
                <div class="sum-label">Avg MPG</div>
                <div class="sum-val" style="color:var(--primary);">
                    <?= $avg_mpg > 0 ? number_format($avg_mpg, 1) : '--' ?>
                </div>
            </div>
        </div>

        <div class="cal-wrapper">
            <div class="cal-header">Sun</div>
            <div class="cal-header">Mon</div>
            <div class="cal-header">Tue</div>
            <div class="cal-header">Wed</div>
            <div class="cal-header">Thu</div>
            <div class="cal-header">Fri</div>
            <div class="cal-header">Sat</div>

            <?php
            $day_of_week = date('w', strtotime($first_day));
            for ($i = 0; $i < $day_of_week; $i++) {
                echo "<div class='cal-day' style='opacity:0.5; cursor:default;'></div>";
            }

            foreach ($month_data as $date => $d) {
                $lock = $d['is_closed'] ? '<span class="lock-icon">🔒</span>' : '';
                $onclick = $d['is_closed'] ? "openSummary('" . htmlspecialchars($date) . "')" : "window.location='index.php?date=" . htmlspecialchars($date) . "'";

                echo "<div class='cal-day' onclick=\"$onclick\">";
                echo "<div class='day-num'><span>{$d['day_num']}</span>$lock</div>";

                // Show DO/ND badges
                if ($d['has_do'] || $d['has_nd']) {
                    echo "<div class='day-badges'>";
                    if ($d['has_do'])
                        echo "<span class='day-badge badge-do'>DO</span>";
                    if ($d['has_nd'])
                        echo "<span class='day-badge badge-nd'>ND</span>";
                    echo "</div>";
                }

                if ($d['work'] > 0)
                    echo "<div class='stat-row'><span class='label'>Work</span><span class='val-work'>$" . number_format($d['work'], 0) . "</span></div>";
                if ($d['std_pd'] > 0)
                    echo "<div class='stat-row'><span class='label'>Std</span><span class='val-std'>$" . number_format($d['std_pd'], 0) . "</span></div>";
                if ($d['ext_pd'] > 0)
                    echo "<div class='stat-row'><span class='label'>Ext</span><span class='val-ext'>$" . number_format($d['ext_pd'], 0) . "</span></div>";

                if ($d['total'] > 0) {
                    echo "<div class='day-total'>$" . number_format($d['total'], 0) . "</div>";
                } else if ($d['has_do']) {
                    echo "<div class='day-total' style='color:var(--warning-text);'>Day Off</div>";
                } else if ($d['has_nd']) {
                    echo "<div class='day-total' style='color:var(--primary);'>No Dispatch</div>";
                } else if (!$d['is_closed']) {
                    echo "<div class='add-btn'>+</div>";
                }

                echo "</div>";
            }
            ?>
        </div>
    </div>

    <div id="dayModal" class="modal" onclick="closeModal(event)">
        <div class="modal-content">
            <span class="close-modal" onclick="document.getElementById('dayModal').style.display='none'">&times;</span>
            <div id="modalDate" class="modal-header"></div>
            <div id="modalBody"></div>

            <div class="modal-nav">
                <button id="btnPrev" class="btn btn-secondary">&laquo; Prev</button>
                <button id="btnEdit" class="btn">✏️ Edit / Reopen</button>
                <button id="btnNext" class="btn btn-secondary">Next &raquo;</button>
            </div>
        </div>
    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }

        const monthData = <?= json_encode($month_data) ?>;
        const dates = Object.keys(monthData);

        function openSummary(date) {
            const data = monthData[date];
            if (!data) return;

            document.getElementById('modalDate').innerText = new Date(date + 'T00:00:00').toDateString();

            let html = '';
            if (data.jobs.length > 0) {
                data.jobs.forEach(j => {
                    html += `<div class="modal-row"><span>${j.ticket} (${j.type})</span><span>$${j.pay.toFixed(2)}</span></div>`;
                });
            } else {
                html += `<div class="modal-row" style="justify-content:center; color:gray;">No Jobs</div>`;
            }

            html += `<div class="modal-row" style="margin-top:10px; color:var(--primary);"><span>Per Diem</span><span>$${(data.std_pd + data.ext_pd).toFixed(2)}</span></div>`;
            html += `<div class="modal-row" style="color:var(--danger-text);"><span>Fuel Cost</span><span>-$${parseFloat(data.fuel).toFixed(2)}</span></div>`;
            html += `<div class="modal-total"><span>Net Profit</span><span style="color:var(--success-text);">$${data.net.toFixed(2)}</span></div>`;
            html += `<div style="text-align:center; margin-top:5px; font-size:0.8rem; color:gray;">Miles Driven: ${data.miles}</div>`;

            document.getElementById('modalBody').innerHTML = html;
            document.getElementById('dayModal').style.display = 'flex';

            document.getElementById('btnEdit').onclick = () => window.location = `index.php?date=${date}`;

            let idx = dates.indexOf(date);
            let prevDate = (idx > 0) ? dates[idx - 1] : null;
            let nextDate = (idx < dates.length - 1) ? dates[idx + 1] : null;

            let btnPrev = document.getElementById('btnPrev');
            let btnNext = document.getElementById('btnNext');

            btnPrev.onclick = () => prevDate ? openSummary(prevDate) : null;
            btnNext.onclick = () => nextDate ? openSummary(nextDate) : null;

            btnPrev.style.visibility = prevDate ? 'visible' : 'hidden';
            btnNext.style.visibility = nextDate ? 'visible' : 'hidden';
        }

        function closeModal(e) {
            if (e.target.id === 'dayModal') {
                document.getElementById('dayModal').style.display = 'none';
            }
        }
    </script>
</body>

</html>