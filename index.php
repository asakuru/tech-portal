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
    <link rel="stylesheet" href="style.css?v=1.1">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <link rel="shortcut icon" href="favicon.ico?v=2">
    <link rel="apple-touch-icon" href="favicon.png">
    <?php include 'head_pwa.php'; ?>
</head>

<body>
    <div class="app-container">
        <?php include 'nav.php'; ?>

        <main class="main-content">
            <!-- Glass Header Section -->
            <div class="welcome-banner">
                <h2 style="font-weight: 800; letter-spacing: -0.03em;">👋 Hello,
                    <?= htmlspecialchars(ucfirst($username)) ?>
                </h2>
                <div class="date" style="font-weight: 500; opacity: 0.8;"><?= $today_formatted ?></div>
            </div>

            <div class="bento-grid">
                <!-- SECTION: QUICK ACTIONS (TOP ROW) -->
                <div class="section-header">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                    Quick Actions
                </div>

                <a href="entry.php" class="ha-card tile-card" style="text-decoration: none;">
                    <div class="tile-icon" style="background: rgba(3, 169, 244, 0.1); color: #03a9f4;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                    </div>
                    <div class="tile-info">
                        <span class="tile-value">New Job</span>
                        <span class="tile-label">Manual entry</span>
                    </div>
                </a>

                <a href="smart_entry.php" class="ha-card tile-card" style="text-decoration: none;">
                    <div class="tile-icon" style="background: rgba(255, 152, 0, 0.1); color: #ff9800;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
                        </svg>
                    </div>
                    <div class="tile-info">
                        <span class="tile-value">Smart Paste</span>
                        <span class="tile-label">From text/clipboard</span>
                    </div>
                </a>

                <a href="tools.php" class="ha-card tile-card" style="text-decoration: none;">
                    <div class="tile-icon" style="background: rgba(156, 39, 176, 0.1); color: #9c27b0;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </div>
                    <div class="tile-info">
                        <span class="tile-value">Search</span>
                        <span class="tile-label">Find history</span>
                    </div>
                </a>

                <a href="entry.php?date=<?= $today ?>" class="ha-card tile-card" style="text-decoration: none;">
                    <div class="tile-icon"
                        style="background: <?= $is_today_locked ? 'rgba(76, 175, 80, 0.1)' : 'rgba(255, 255, 255, 0.05)' ?>; color: <?= $is_today_locked ? '#81c784' : 'var(--text-muted)' ?>;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round"><?= $is_today_locked ? '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>' : '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>' ?></svg>
                    </div>
                    <div class="tile-info">
                        <span class="tile-value"><?= $is_today_locked ? 'Locked' : 'Review' ?></span>
                        <span class="tile-label">Today's tally</span>
                    </div>
                </a>

                <!-- SECTION: STATS (KPIs) -->
                <div class="section-header">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    Performance Summary
                </div>

                <!-- Today Card -->
                <div class="ha-card" style="grid-column: span 6;" onclick="openTallyModal('day')">
                    <div class="ha-card-header">TODAY'S EARNINGS</div>
                    <div style="display:flex; align-items:flex-end; gap:12px;">
                        <div class="stat-value" style="color: var(--primary);">
                            $<?= number_format($today_total, 2) ?></div>
                        <div style="font-size: 1.1rem; color: var(--text-muted); padding-bottom: 5px;">/
                            <?= count($today_jobs) ?> jobs
                        </div>
                    </div>
                    <div style="margin-top: 15px; height: 4px; background: rgba(255,255,255,0.05); border-radius: 2px;">
                        <div
                            style="width: min(100%, <?= (count($today_jobs) / 10) * 100 ?>%); height: 100%; background: var(--primary); border-radius: 2px;">
                        </div>
                    </div>
                </div>

                <!-- Week Card -->
                <div class="ha-card" style="grid-column: span 6;" onclick="openTallyModal('week')">
                    <div class="ha-card-header">THIS WEEK</div>
                    <div style="display:flex; align-items:flex-end; gap:12px;">
                        <div class="stat-value" style="color: var(--success-text);">
                            $<?= number_format($week_total, 2) ?></div>
                        <div style="font-size: 1.1rem; color: var(--text-muted); padding-bottom: 5px;">/
                            <?= $week_jobs ?> jobs
                        </div>
                    </div>
                    <div style="margin-top: 15px; height: 4px; background: rgba(255,255,255,0.05); border-radius: 2px;">
                        <div
                            style="width: min(100%, <?= ($week_jobs / 40) * 100 ?>%); height: 100%; background: var(--success-text); border-radius: 2px;">
                        </div>
                    </div>
                </div>

                <!-- SECTION: RECENT ACTIVITY -->
                <div class="section-header">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    Recent Jobs
                </div>

                <div class="ha-card" style="grid-column: span 12;">
                    <div class="entity-list">
                        <?php if (empty($recent_jobs)): ?>
                            <div style="padding: 20px; text-align: center; color: var(--text-muted);">No recent jobs found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_jobs as $job):
                                $job_pay = calculate_job_pay($job, $rates);
                                ?>
                                <a href="edit_job.php?id=<?= $job['id'] ?>" class="entity-row" style="text-decoration: none;">
                                    <div class="entity-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="9" cy="7" r="4"></circle>
                                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                        </svg>
                                    </div>
                                    <div class="entity-name">
                                        <div style="font-weight: 600; color: var(--text-main);">
                                            <?= htmlspecialchars($job['ticket_number']) ?>
                                        </div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);">
                                            <?= htmlspecialchars($job['cust_lname']) ?>,
                                            <?= htmlspecialchars($job['cust_city']) ?>
                                        </div>
                                    </div>
                                    <div class="entity-state" style="color: var(--success-text);">
                                        $<?= number_format($job_pay, 2) ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Footer / Secondary Stats -->
                <div class="ha-card"
                    style="grid-column: span 12; background: transparent; border: none; box-shadow: none; text-align: center; margin-top: 40px;">
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        System v2.5 | PWA Enabled | Lovelace UI Engine
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Tally Breakdown Modal -->
    <div id="tallyModal" class="modal"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:2000; align-items:center; justify-content:center; padding:20px;">
        <div class="modal-content"
            style="background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); max-width: 600px; width:100%; max-height: 90vh; overflow-y:auto; position:relative;">
            <button onclick="document.getElementById('tallyModal').style.display='none'"
                style="position:absolute; top:15px; right:15px; background:transparent; border:none; color:var(--text-muted); cursor:pointer;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
            <div id="tallyModalContent" style="padding: 24px;">
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                    <p>Calculating details...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        async function openTallyModal(range) {
            const modal = document.getElementById('tallyModal');
            const content = document.getElementById('tallyModalContent');
            modal.style.display = 'flex';
            content.innerHTML = '<div style="padding: 40px; text-align: center; color: var(--text-muted);"><p>Calculating details...</p></div>';

            try {
                const response = await fetch(`get_daily_tally.php?range=${range}&user_id=<?= $user_id ?>`);
                const html = await response.text();
                content.innerHTML = html;
            } catch (e) {
                content.innerHTML = '<div class="alert danger">Failed to load data.</div>';
            }
        }

        window.onclick = function (event) {
            if (event.target == document.getElementById('tallyModal')) {
                document.getElementById('tallyModal').style.display = 'none';
            }
        }
    </script>
</body>

</html>