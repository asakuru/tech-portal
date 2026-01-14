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

// Today's jobs
$stmt = $db->prepare("SELECT * FROM jobs WHERE user_id = ? AND install_date = ? ORDER BY id DESC");
$stmt->execute([$user_id, $today]);
$today_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today_job_pay = 0;
$has_work_today = false;
foreach ($today_jobs as $j) {
    $today_job_pay += $j['pay_amount'];
    if ($j['install_type'] !== 'DO' && $j['install_type'] !== 'ND') {
        $has_work_today = true;
    }
}

// Today's per diem
$is_sunday = (date('w') == 0);
$today_pd = ($is_sunday || $has_work_today) ? $std_pd_rate : 0;

// Check for extra PD
$stmt = $db->prepare("SELECT extra_per_diem FROM daily_logs WHERE user_id = ? AND log_date = ?");
$stmt->execute([$user_id, $today]);
$log = $stmt->fetch();
if ($log && $log['extra_per_diem'] == 1) {
    $today_pd += (float) ($rates['extra_pd'] ?? 0);
}

$today_total = $today_job_pay + $today_pd;

// This week's data
$ts = strtotime($today);
$start_of_week = (date('N', $ts) == 1) ? $today : date('Y-m-d', strtotime('last monday', $ts));
$end_of_week = date('Y-m-d', strtotime($start_of_week . ' +6 days'));

$stmt = $db->prepare("SELECT SUM(pay_amount) as total, COUNT(*) as count FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? AND install_type NOT IN ('DO', 'ND')");
$stmt->execute([$user_id, $start_of_week, $end_of_week]);
$week_data = $stmt->fetch();
$week_jobs = (int) ($week_data['count'] ?? 0);
$week_pay = (float) ($week_data['total'] ?? 0);

// Estimate week PD (days with work + Sundays)
$stmt = $db->prepare("SELECT COUNT(DISTINCT install_date) as days FROM jobs WHERE user_id = ? AND install_date BETWEEN ? AND ? AND install_type NOT IN ('DO')");
$stmt->execute([$user_id, $start_of_week, $end_of_week]);
$week_days = (int) ($stmt->fetch()['days'] ?? 0);

$week_pd = $week_days * $std_pd_rate;
// Add Sunday if in range
$sunday = date('Y-m-d', strtotime('sunday this week', $ts));
if ($sunday >= $start_of_week && $sunday <= $end_of_week && $sunday <= $today) {
    $week_pd += $std_pd_rate;
}

$week_total = $week_pay + $week_pd;
if ($week_pay > 0)
    $week_total += $lead_pay_rate;

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
            <div class="summary-card today">
                <div class="title">Today</div>
                <div class="value" style="color: var(--primary);">$<?= number_format($today_total, 2) ?></div>
                <div class="sub"><?= count($today_jobs) ?> job<?= count($today_jobs) != 1 ? 's' : '' ?></div>
            </div>
            <div class="summary-card week">
                <div class="title">This Week</div>
                <div class="value" style="color: var(--success-text);">$<?= number_format($week_total, 2) ?></div>
                <div class="sub"><?= $week_jobs ?> job<?= $week_jobs != 1 ? 's' : '' ?></div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="recent-section">
            <h3>📋 Recent Activity</h3>
            <?php if (empty($recent_jobs)): ?>
                <div class="no-jobs">
                    No jobs entered yet. <a href="entry.php" style="color: var(--primary);">Add your first job →</a>
                </div>
            <?php else: ?>
                <?php foreach ($recent_jobs as $job): ?>
                    <div class="recent-job" onclick="window.location='edit_job.php?id=<?= $job['id'] ?>'">
                        <div class="info">
                            <div class="ticket"><?= htmlspecialchars($job['ticket_number']) ?></div>
                            <div class="meta"><?= date('M j', strtotime($job['install_date'])) ?> •
                                <?= htmlspecialchars($job['install_type']) ?> • <?= htmlspecialchars($job['cust_city']) ?></div>
                        </div>
                        <div class="pay">$<?= number_format($job['pay_amount'], 2) ?></div>
                    </div>
                <?php endforeach; ?>
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

</body>

</html>