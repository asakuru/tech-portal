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

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$is_admin = is_admin();

// Get today's data
$today = date('Y-m-d');
$today_formatted = date('l, F j');

// Get rates
$std_pd_rate = (float) ($rates['per_diem'] ?? 0.00);
$lead_pay_rate = (float) ($rates['lead_pay'] ?? 500.00);

// Today's pay (Using centralized brain)
$today_payroll = calculate_daily_payroll($db, $user_id, $today, $rates);
$today_job_pay = $today_payroll['job_pay'];
$today_pd = $today_payroll['std_pd'] + $today_payroll['ext_pd'];
$today_total = $today_payroll['total'];
$today_jobs = $today_payroll['jobs'];
$has_work_today = $today_payroll['has_billable'];

// This week's data - using accurate day-by-day calculation
$ts = strtotime($today);
$start_of_week = (date('N', $ts) == 1) ? $today : date('Y-m-d', strtotime('last monday', $ts));
$end_of_week = date('Y-m-d', strtotime($start_of_week . ' +6 days'));

// Calculate week total using centralized logic
$week_payroll = calculate_weekly_payroll($db, $user_id, $start_of_week, $end_of_week, $rates);
$week_total = $week_payroll['grand_total'];

// Get billable job count for display
$week_jobs = 0;
foreach ($week_payroll['days'] as $day) {
    foreach ($day['jobs'] as $job) {
        if ($job['install_type'] !== 'DO' && $job['install_type'] !== 'ND') {
            $week_jobs++;
        }
    }
}

// Recent jobs (last 5)
$stmt = $db->prepare("SELECT j.*, u.username FROM jobs j JOIN users u ON j.user_id = u.id WHERE j.user_id = ? ORDER BY j.install_date DESC, j.id DESC LIMIT 5");
$stmt->execute([$user_id]);
$recent_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if day is locked
$stmt = $db->prepare("SELECT is_locked FROM daily_logs WHERE user_id = ? AND log_date = ?");
$stmt->execute([$user_id, $today]);
$day_log = $stmt->fetch();
$is_today_locked = ($day_log && $day_log['is_locked'] == 1);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Tech Portal - Home</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <link rel="shortcut icon" href="favicon.ico?v=2">
    <link rel="apple-touch-icon" href="favicon.png">
    <style>
        .welcome-banner {
            margin-bottom: 24px;
        }

        .welcome-banner h2 {
            margin: 0 0 4px;
            font-size: 1.5rem;
        }

        .welcome-banner .date {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 28px;
        }

        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 16px;
            background: var(--gradient-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--text-main);
            transition: all 0.3s;
            text-align: center;
        }

        .quick-action:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .quick-action .icon {
            font-size: 1.8rem;
            margin-bottom: 8px;
        }

        .quick-action .label {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 28px;
        }

        .summary-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            text-align: center;
        }

        .summary-card .title {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-card .value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .summary-card .sub {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .summary-card.today {
            border-left: 4px solid var(--primary);
        }

        .summary-card.week {
            border-left: 4px solid var(--success-text);
        }

        .recent-section h3 {
            margin: 0 0 16px;
            font-size: 1rem;
            color: var(--text-muted);
        }

        .recent-job {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .recent-job:hover {
            border-color: var(--primary);
        }

        .recent-job .info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .recent-job .ticket {
            font-weight: 700;
            color: var(--primary);
        }

        .recent-job .meta {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .recent-job .pay {
            font-weight: 700;
            color: var(--success-text);
        }

        .no-jobs {
            text-align: center;
            padding: 30px;
            color: var(--text-muted);
            background: var(--bg-card);
            border: 1px dashed var(--border);
            border-radius: var(--radius);
        }

        @media (max-width: 500px) {
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2>👋 Welcome, <?= htmlspecialchars(ucfirst($username)) ?></h2>
            <div class="date"><?= $today_formatted ?></div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="entry.php" class="quick-action">
                <div class="icon">📝</div>
                <div class="label">New Job</div>
            </a>
            <a href="smart_entry.php" class="quick-action">
                <div class="icon">⚡</div>
                <div class="label">Smart Paste</div>
            </a>
            <a href="entry.php?date=<?= $today ?>" class="quick-action">
                <div class="icon"><?= $is_today_locked ? '🔒' : '📋' ?></div>
                <div class="label"><?= $is_today_locked ? 'Day Closed' : 'Close Day' ?></div>
            </a>
            <a href="dashboard.php" class="quick-action">
                <div class="icon">📊</div>
                <div class="label">Analytics</div>
            </a>
        </div>

        <!-- Summary Cards -->
        <div class="summary-grid">
            <?php
            include 'components/kpi_card.php';

            // Today Card
            $label = "Today";
            $value = "$" . number_format($today_total, 2);
            $class = "positive";
            $sub = count($today_jobs) . " job" . (count($today_jobs) != 1 ? 's' : '');
            $onclick = "openTallyModal('day')";
            $style = "border-left: 4px solid var(--primary);";
            include 'components/kpi_card.php';

            // Week Card
            $label = "This Week";
            $value = "$" . number_format($week_total, 2);
            $class = "";
            $sub = $week_jobs . " job" . ($week_jobs != 1 ? 's' : '');
            $onclick = "openTallyModal('week')";
            $style = "border-left: 4px solid var(--success-text);";
            include 'components/kpi_card.php';
            ?>
        </div>

        <!-- Recent Activity -->
        <div class="recent-section">
            <h3>📋 Recent Activity</h3>
            <?php if (empty($recent_jobs)): ?>
                <div class="no-jobs">
                    No jobs entered yet. <a href="entry.php" style="color: var(--primary);">Add your first job →</a>
                </div>
            <?php else: ?>
                <?php
                include 'components/job_summary_card.php';
                foreach ($recent_jobs as $job) {
                    $showDate = true;
                    include 'components/job_summary_card.php';
                }
                ?>
            <?php endif; ?>
        </div>

        <?php if ($is_admin): ?>
            <!-- Admin Quick Links -->
            <div style="margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--border);">
                <h3 style="margin: 0 0 16px; font-size: 1rem; color: var(--text-muted);">⚙️ Admin Tools</h3>
                <div class="quick-actions" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
                    <a href="admin.php" class="quick-action" style="padding: 14px 12px;">
                        <div class="icon" style="font-size: 1.4rem;">👥</div>
                        <div class="label" style="font-size: 0.8rem;">Users</div>
                    </a>
                    <a href="financials.php?view=monthly" class="quick-action" style="padding: 14px 12px;">
                        <div class="icon" style="font-size: 1.4rem;">💰</div>
                        <div class="label" style="font-size: 0.8rem;">Financials</div>
                    </a>
                    <a href="settings.php" class="quick-action" style="padding: 14px 12px;">
                        <div class="icon" style="font-size: 1.4rem;">⚙️</div>
                        <div class="label" style="font-size: 0.8rem;">Settings</div>
                    </a>
                    <a href="backup.php" class="quick-action" style="padding: 14px 12px;">
                        <div class="icon" style="font-size: 1.4rem;">💾</div>
                        <div class="label" style="font-size: 0.8rem;">Backup</div>
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Tally Breakdown Modal -->
    <div id="tallyModal"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; overflow-y:auto; padding:20px;"
        onclick="if(event.target===this) closeTallyModal()">
        <div
            style="background:var(--bg-card); max-width:500px; margin:40px auto; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); overflow:hidden;">
            <div
                style="background:linear-gradient(135deg, var(--primary), #1e40af); color:white; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:1.1rem;" id="tallyModalTitle">Breakdown</h3>
                <button onclick="closeTallyModal()"
                    style="background:rgba(255,255,255,0.2); border:none; color:white; font-size:1.2rem; width:32px; height:32px; border-radius:50%; cursor:pointer;">×</button>
            </div>
            <div id="tallyModalContent" style="padding:20px; max-height:70vh; overflow-y:auto;"></div>
        </div>
    </div>

    <script>
        // Breakdown data passed from PHP
        <?php
        // Build breakdown data for daily jobs
        $day_breakdown = [];
        $day_per_diem = 0;
        $day_std_pd = 0;
        $day_ext_pd = 0;

        if (!empty($today_jobs)) {
            foreach ($today_jobs as $job) {
                // We likely need to re-fetch full job data if today_jobs assumes a simple select
                // But typically index.php select * so it should be fine.
                // However, entry.php used calculate_job_details.
                // Let's use today_jobs directly but ensure we have all fields.
                if (function_exists('calculate_job_details')) {
                    $items = calculate_job_details($job, $rates);
                } else {
                    $items = [];
                }
                $day_breakdown[] = [
                    'ticket' => $job['ticket_number'] ?: 'N/A',
                    'type' => $job['install_type'],
                    'total' => (float) $job['pay_amount'],
                    'items' => $items
                ];
            }
        }

        // Calculate day per diem breakdown
        if (isset($today_pd) && $today_pd > 0) {
            $day_per_diem = $today_pd;
            // Check if it includes standard and/or extra
            if (isset($rate_std) && isset($std_pd_rate) && ($has_work_today || $is_sunday)) {
                $day_std_pd = $std_pd_rate;
            }
            if (isset($log) && $log && $log['extra_per_diem'] == 1 && isset($rates['extra_pd'])) {
                $day_ext_pd = (float) $rates['extra_pd'];
            }
        }

        // Ensure accurate PD breakdown if logic above was fuzzy
        // Simple fallback: If today_pd > 0, we credit it. 
        // We'll trust the $today_pd scalar, but for display we try to split it.
        if ($day_std_pd + $day_ext_pd != $today_pd) {
            // Logic mismatch fix: just use today_pd as std if not split
            if ($day_std_pd == 0 && $day_ext_pd == 0)
                $day_std_pd = $today_pd;
        }

        // Build breakdown for weekly jobs - grouped by day
        $week_by_day = [];
        $week_per_diem_total = 0;

        // We need to fetch detailed weekly jobs for the modal
        // index.php only fetched summary count/total.
        $w_stmt = $db->prepare("SELECT * FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? ORDER BY install_date ASC");
        $w_stmt->execute([$user_id, $start_of_week, $end_of_week]);
        $week_jobs_detailed = $w_stmt->fetchAll(PDO::FETCH_ASSOC);

        $wl_stmt = $db->prepare("SELECT log_date, extra_per_diem FROM daily_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?");
        $wl_stmt->execute([$user_id, $start_of_week, $end_of_week]);
        $week_logs_all = $wl_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (!empty($week_jobs_detailed)) {
            foreach ($week_jobs_detailed as $full_job) {
                $wj_date = $full_job['install_date'];
                if (!isset($week_by_day[$wj_date])) {
                    $week_by_day[$wj_date] = [
                        'date' => $wj_date,
                        'date_display' => date('D m/d', strtotime($wj_date)),
                        'jobs' => [],
                        'jobs_total' => 0,
                        'std_pd' => 0,
                        'ext_pd' => 0,
                        'day_total' => 0
                    ];
                }

                if (function_exists('calculate_job_details')) {
                    $items = calculate_job_details($full_job, $rates);
                } else {
                    $items = [];
                }

                $week_by_day[$wj_date]['jobs'][] = [
                    'ticket' => $full_job['ticket_number'] ?: 'N/A',
                    'type' => $full_job['install_type'],
                    'total' => (float) $full_job['pay_amount'],
                    'items' => $items
                ];
                $week_by_day[$wj_date]['jobs_total'] += (float) $full_job['pay_amount'];
            }
        }

        // We also need to add days that have NO jobs but might have Per Diem (e.g. Sunday)
        // Iterate through all days of week
        $current_loop_date = $start_of_week;
        while (strtotime($current_loop_date) <= strtotime($end_of_week)) {
            $wj_date = $current_loop_date;
            if (!isset($week_by_day[$wj_date])) {
                // Only create entry if there's PD to show
                $is_sun = (date('w', strtotime($wj_date)) == 0);
                $has_ext = (isset($week_logs_all[$wj_date]) && $week_logs_all[$wj_date] == 1);

                // Note: We don't know if 'has_work' is true for empty job list, obviously false.
                // But Sunday always gets PD.
                if ($is_sun || $has_ext) {
                    $week_by_day[$wj_date] = [
                        'date' => $wj_date,
                        'date_display' => date('D m/d', strtotime($wj_date)),
                        'jobs' => [],
                        'jobs_total' => 0,
                        'std_pd' => 0,
                        'ext_pd' => 0,
                        'day_total' => 0
                    ];
                }
            }
            $current_loop_date = date('Y-m-d', strtotime($current_loop_date . ' +1 day'));
        }

        // Add per diem for each day
        foreach ($week_by_day as $wdate => &$day_data) {
            $is_sun = (date('w', strtotime($wdate)) == 0);
            $has_work = false;
            foreach ($day_data['jobs'] as $j) {
                if ($j['type'] !== 'DO' && $j['type'] !== 'ND') {
                    $has_work = true;
                    break;
                }
            }
            // Has ND job? That also qualifies for per diem
            $has_nd = false;
            foreach ($day_data['jobs'] as $j) {
                if ($j['type'] === 'ND') {
                    $has_nd = true;
                    break;
                }
            }

            if ($is_sun || $has_work || $has_nd) {
                $day_data['std_pd'] = $std_pd_rate ?? 0;
                $week_per_diem_total += $day_data['std_pd'];
            }

            // Check for extra per diem from daily log
            if (isset($week_logs_all[$wdate]) && $week_logs_all[$wdate] == 1) {
                $day_data['ext_pd'] = (float) ($rates['extra_pd'] ?? 0);
                $week_per_diem_total += $day_data['ext_pd'];
            }

            $day_data['day_total'] = $day_data['jobs_total'] + $day_data['std_pd'] + $day_data['ext_pd'];
        }
        unset($day_data);

        // Sort by date
        ksort($week_by_day);
        $week_by_day = array_values($week_by_day);

        // Lead Pay Logic
        $lead_pay_amt = 0;
        if (function_exists('get_lead_pay_amount')) {
            $lead_pay_amt = get_lead_pay_amount($db);
        }
        $has_billable = false;
        if (function_exists('has_billable_work')) {
            $has_billable = has_billable_work($db, $user_id, $start_of_week, $end_of_week);
        }
        $final_lead_pay = ($has_billable && $lead_pay_amt > 0) ? $lead_pay_amt : 0;
        ?>
        var dayBreakdown = <?= json_encode($day_breakdown) ?>;
        var dayStdPd = <?= json_encode($day_std_pd) ?>;
        var dayExtPd = <?= json_encode($day_ext_pd) ?>;
        var dayTotal = <?= json_encode($today_total) ?>;
        var weekByDay = <?= json_encode($week_by_day) ?>;
        var weekTotal = <?= json_encode($week_total) ?>;
        var leadPay = <?= json_encode($final_lead_pay) ?>;

        function openTallyModal(type) {
            var modal = document.getElementById('tallyModal');
            var title = document.getElementById('tallyModalTitle');
            var content = document.getElementById('tallyModalContent');

            title.innerHTML = type === 'day' ? '📅 Day Breakdown' : '📆 Week Breakdown';

            var html = '';
            var grandJobsTotal = 0;
            var grandPdTotal = 0;

            if (type === 'day') {
                // Daily breakdown - simple list of jobs
                if (dayBreakdown.length === 0) {
                    html += '<div style="text-align:center; color:var(--text-muted); padding:20px;">No jobs found</div>';
                } else {
                    dayBreakdown.forEach(function (job) {
                        grandJobsTotal += job.total;
                        html += renderJobCard(job);
                    });
                }

                // Per diem section for day
                var totalPd = dayStdPd + dayExtPd;
                if (totalPd > 0) {
                    html += '<div style="background:var(--bg-input); border-radius:8px; padding:12px; margin-bottom:10px; border:1px solid var(--primary); border-left:3px solid var(--primary);">';
                    html += '<div style="font-size:0.85rem; color:var(--primary); font-weight:bold;">Per Diem</div>';
                    html += '<div style="font-size:0.8rem; margin-top:5px;">';
                    if (dayStdPd > 0) html += '<div style="display:flex; justify-content:space-between;"><span>Standard</span><span>$' + dayStdPd.toFixed(2) + '</span></div>';
                    if (dayExtPd > 0) html += '<div style="display:flex; justify-content:space-between;"><span>Extra PD</span><span>$' + dayExtPd.toFixed(2) + '</span></div>';
                    html += '</div></div>';
                    grandPdTotal = totalPd;
                }

                // Summary
                html += '<div style="border-top:2px solid var(--border); margin-top:15px; padding-top:15px;">';
                html += '<div style="display:flex; justify-content:space-between; padding:4px 0; font-size:0.9rem;"><span>Jobs</span><span>$' + grandJobsTotal.toFixed(2) + '</span></div>';
                if (grandPdTotal > 0) html += '<div style="display:flex; justify-content:space-between; padding:4px 0; font-size:0.9rem; color:var(--primary);"><span>Per Diem</span><span>$' + grandPdTotal.toFixed(2) + '</span></div>';
                html += '<div style="display:flex; justify-content:space-between; padding:8px 0; font-size:1.1rem; font-weight:bold; border-top:1px solid var(--border); margin-top:8px;">';
                html += '<span>Total</span><span style="color:var(--primary);">$' + dayTotal.toFixed(2) + '</span></div></div>';

            } else {
                // Weekly breakdown - grouped by day
                if (weekByDay.length === 0) {
                    html += '<div style="text-align:center; color:var(--text-muted); padding:20px;">No jobs found</div>';
                } else {
                    weekByDay.forEach(function (day) {
                        html += '<div style="margin-bottom:15px;">';
                        html += '<div style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px; background:linear-gradient(135deg, var(--primary), #1e40af); color:white; border-radius:8px 8px 0 0; font-weight:bold;">';
                        html += '<span>' + day.date_display + '</span>';
                        html += '<span>$' + day.day_total.toFixed(2) + '</span>';
                        html += '</div>';
                        html += '<div style="background:var(--bg-input); border:1px solid var(--border); border-top:none; border-radius:0 0 8px 8px; padding:10px;">';

                        // Jobs for this day
                        day.jobs.forEach(function (job) {
                            grandJobsTotal += job.total;
                            html += '<div style="display:flex; justify-content:space-between; padding:4px 0; font-size:0.9rem;">';
                            html += '<span><span style="color:var(--primary); font-weight:600;">' + job.ticket + '</span> <span style="color:var(--text-muted); font-size:0.8rem;">(' + job.type + ')</span></span>';
                            html += '<span style="font-weight:bold;">$' + job.total.toFixed(2) + '</span>';
                            html += '</div>';

                            // Code breakdown under each job
                            if (job.items && job.items.length > 0) {
                                html += '<div style="padding-left:10px; margin-bottom:5px;">';
                                job.items.forEach(function (item) {
                                    html += '<div style="display:flex; justify-content:space-between; font-size:0.75rem; color:var(--text-muted);">';
                                    html += '<span>' + item.code + ' × ' + item.qty + '</span>';
                                    html += '<span>$' + item.total.toFixed(2) + '</span></div>';
                                });
                                html += '</div>';
                            }
                        });

                        // Per diem for this day
                        var dayPd = day.std_pd + day.ext_pd;
                        if (dayPd > 0) {
                            grandPdTotal += dayPd;
                            html += '<div style="border-top:1px dashed var(--border); margin-top:5px; padding-top:5px;">';
                            if (day.std_pd > 0) html += '<div style="display:flex; justify-content:space-between; font-size:0.85rem; color:var(--primary);"><span>Per Diem</span><span>$' + day.std_pd.toFixed(2) + '</span></div>';
                            if (day.ext_pd > 0) html += '<div style="display:flex; justify-content:space-between; font-size:0.85rem; color:var(--primary);"><span>Extra PD</span><span>$' + day.ext_pd.toFixed(2) + '</span></div>';
                            html += '</div>';
                        }

                        html += '</div></div>';
                    });
                }

                // Weekly summary
                html += '<div style="border-top:2px solid var(--border); margin-top:15px; padding-top:15px;">';
                html += '<div style="display:flex; justify-content:space-between; padding:4px 0; font-size:0.9rem;"><span>Jobs Subtotal</span><span>$' + grandJobsTotal.toFixed(2) + '</span></div>';
                if (grandPdTotal > 0) html += '<div style="display:flex; justify-content:space-between; padding:4px 0; font-size:0.9rem; color:var(--primary);"><span>Per Diem Total</span><span>$' + grandPdTotal.toFixed(2) + '</span></div>';
                if (leadPay > 0) html += '<div style="display:flex; justify-content:space-between; padding:4px 0; font-size:0.9rem; color:var(--text-muted);"><span>Lead Pay</span><span>$' + leadPay.toFixed(2) + '</span></div>';
                html += '<div style="display:flex; justify-content:space-between; padding:8px 0; font-size:1.1rem; font-weight:bold; border-top:1px solid var(--border); margin-top:8px;">';
                html += '<span>Week Total</span><span style="color:var(--success-text);">$' + weekTotal.toFixed(2) + '</span></div></div>';
            }

            content.innerHTML = html;
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function renderJobCard(job) {
            var html = '<div style="background:var(--bg-input); border-radius:8px; padding:12px; margin-bottom:10px; border:1px solid var(--border);">';
            html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">';
            html += '<div><span style="font-weight:bold; color:var(--primary);">' + job.ticket + '</span>';
            html += '<div style="font-size:0.8rem; color:var(--text-muted);">' + job.type + '</div></div>';
            html += '<div style="font-weight:bold; color:var(--success-text);">$' + job.total.toFixed(2) + '</div>';
            html += '</div>';

            if (job.items && job.items.length > 0) {
                html += '<div style="font-size:0.8rem; border-top:1px dashed var(--border); padding-top:8px;">';
                job.items.forEach(function (item) {
                    html += '<div style="display:flex; justify-content:space-between; padding:2px 0;">';
                    html += '<span style="color:var(--text-muted);">' + item.code + ' × ' + item.qty + '</span>';
                    html += '<span>$' + item.total.toFixed(2) + '</span></div>';
                });
                html += '</div>';
            }
            html += '</div>';
            return html;
        }

        function closeTallyModal() {
            document.getElementById('tallyModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
    </script>
</body>

</html>