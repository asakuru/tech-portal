<?php
/**
 * component: job_summary_card
 * @param array $job
 * @param bool $showDate (optional)
 * @param string $actions (optional, HTML for action buttons)
 */
if (!isset($job))
    return;
?>
<div class="box" style="margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;">
    <div onclick="window.location='edit_job.php?id=<?= $job['id'] ?>'" style="cursor:pointer; flex:1;">
        <div style="font-weight:700; font-size:1rem; margin-bottom:2px; color:var(--primary);">
            <?= $job['ticket_number'] ?>
        </div>
        <div style="font-size:0.85rem; color:var(--text-muted); font-weight:500;">
            <?= $job['install_type'] ?> • <?= htmlspecialchars($job['cust_city']) ?>
            <?= isset($showDate) && $showDate ? ' • ' . date('M j', strtotime($job['install_date'])) : '' ?>
        </div>
    </div>
    <div style="text-align:right;">
        <div
            style="font-weight:800; color:var(--success-text); font-size:1.1rem; margin-bottom:<?= isset($actions) ? '5px' : '0' ?>;">
            $<?= number_format($job['pay_amount'], 2) ?>
        </div>
        <?php if (isset($actions)): ?>
            <div class="actions">
                <?= $actions ?>
            </div>
        <?php endif; ?>
    </div>
</div>