<?php
require_once 'config.php';
require_once 'functions.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die('<div class="alert danger">Session expired. Please login.</div>');
}

$db = getDB();
$user_id = $_GET['user_id'] ?? $_SESSION['user_id'];
$range = $_GET['range'] ?? 'day'; // 'day' or 'week'
$today = date('Y-m-d');

// --- 1. GATHER DATA ---
$days_to_show = [];

if ($range === 'day') {
    $days_to_show[] = calculate_daily_payroll($db, $user_id, $today);
} else {
    // Week view
    $ts = strtotime($today);
    $start_of_week = (date('N', $ts) == 1) ? $today : date('Y-m-d', strtotime('last monday', $ts));
    $end_of_week = date('Y-m-d', strtotime($start_of_week . ' +6 days'));

    $weekly = calculate_weekly_payroll($db, $user_id, $start_of_week, $end_of_week);
    $days_to_show = $weekly['days'];
}

// --- 2. RENDER ---
?>

<div class="tally-breakdown">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3 style="margin:0;">
            <?= $range === 'day' ? "Today's Breakdown" : "Weekly Breakdown" ?>
        </h3>
        <div style="font-size:0.8rem; color:var(--text-muted);">
            <?= date('M j, Y') ?>
        </div>
    </div>

    <?php
    $grand_total = 0;
    foreach ($days_to_show as $day):
        $grand_total += $day['total'] ?? 0;
        if (empty($day['jobs']) && $day['std_pd'] == 0 && $day['ext_pd'] == 0)
            continue;
        ?>
        <div class="day-section" style="margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid var(--border);">
            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:12px;">
                <div style="font-weight:700; color:var(--text-main);">
                    <?= date('l, M j', strtotime($day['date'])) ?>
                </div>
                <div style="font-weight:800; color:var(--primary);">$
                    <?= number_format($day['total'], 2) ?>
                </div>
            </div>

            <!-- Jobs -->
            <?php foreach ($day['jobs'] as $job): ?>
                <?php
                $job_pay = (float) ($job['pay_amount'] ?? 0);
                ?>
                <div
                    style="display:flex; justify-content:space-between; font-size:0.9rem; padding:4px 0; color:var(--text-main);">
                    <span>
                        <?= htmlspecialchars($job['ticket_number']) ?> (
                        <?= $job['install_type'] ?>)
                    </span>
                    <span style="color:var(--success-text);">$
                        <?= number_format($job_pay, 2) ?>
                    </span>
                </div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px; padding-left:10px;">
                    <?= htmlspecialchars($job['cust_lname']) ?> •
                    <?= htmlspecialchars($job['cust_city']) ?>
                </div>
            <?php endforeach; ?>

            <!-- Per Diem -->
            <?php if ($day['std_pd'] > 0): ?>
                <div
                    style="display:flex; justify-content:space-between; font-size:0.9rem; padding:4px 0; color:var(--text-main); font-style:italic;">
                    <span>Standard Per Diem</span>
                    <span>$
                        <?= number_format($day['std_pd'], 2) ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($day['ext_pd'] > 0): ?>
                <div
                    style="display:flex; justify-content:space-between; font-size:0.9rem; padding:4px 0; color:var(--text-main); font-style:italic;">
                    <span>Extra Per Diem</span>
                    <span>$
                        <?= number_format($day['ext_pd'], 2) ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div
        style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; padding:15px; background:rgba(255,255,255,0.03); border-radius:8px;">
        <div style="font-weight:700; text-transform:uppercase; font-size:0.8rem; color:var(--text-muted);">Grand Total
        </div>
        <div style="font-size:1.5rem; font-weight:800; color:var(--primary);">$
            <?= number_format($grand_total, 2) ?>
        </div>
    </div>
</div>