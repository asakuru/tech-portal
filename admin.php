<?php
require 'config.php';

// --- SESSION START ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- AUTH CHECK ---
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

// --- ADMIN ROLE CHECK ---
if (!is_admin()) {
    header('Location: index.php');
    exit;
}

// --- CSRF PROTECTION ---
csrf_check();

$db = getDB();

// --- 1. HANDLE USER ACTIONS ---

// Create User
if (isset($_POST['create_user'])) {
    $new_user = trim($_POST['new_username']);
    $new_pass = $_POST['new_password'];
    $role = $_POST['new_role'];

    if ($new_user && $new_pass) {
        try {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt->execute([$new_user, $hash, $role]);
            $msg = "User '$new_user' created successfully.";
        } catch (PDOException $e) {
            $msg = "Error: Username likely already exists.";
        }
    }
}

// UPDATE USER (Rename & Password Reset)
if (isset($_POST['save_user'])) {
    $target_id = $_POST['target_id'];
    $new_name = trim($_POST['edit_username']);
    $new_pass = trim($_POST['edit_password']);

    try {
        $sql = "UPDATE users SET username = ? WHERE id = ?";
        $params = [$new_name, $target_id];

        if (!empty($new_pass)) {
            $sql = "UPDATE users SET username = ?, password = ? WHERE id = ?";
            $params = [$new_name, password_hash($new_pass, PASSWORD_DEFAULT), $target_id];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $msg = "✅ User updated successfully.";

        if ($target_id == $_SESSION['user_id']) {
            $_SESSION['username'] = $new_name;
        }

    } catch (PDOException $e) {
        $msg = "❌ Error: Username '$new_name' is likely already taken.";
    }
}

// Delete User
if (isset($_POST['delete_user'])) {
    if ($_POST['target_id'] != $_SESSION['user_id']) {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$_POST['target_id']]);
        $msg = "User deleted.";
    } else {
        $msg = "You cannot delete your own account!";
    }
}

// Change My Own Password
if (isset($_POST['change_my_password'])) {
    $current_pass = $_POST['current_pass'];
    $new_pass = $_POST['new_pass'];

    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $stored_hash = $stmt->fetchColumn();

    if (password_verify($current_pass, $stored_hash)) {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $update = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([$new_hash, $_SESSION['user_id']]);
        $msg = "✅ Your password has been updated.";
    } else {
        $msg = "❌ Current password was incorrect.";
    }
}

// --- 2. HANDLE JOB ACTIONS ---

if (isset($_POST['reopen_date'])) {
    $d = $_POST['reopen_date'];
    $lines = file_exists(CLOSED_FILE) ? file(CLOSED_FILE, FILE_IGNORE_NEW_LINES) : [];
    $new_lines = array_diff($lines, [$d]);
    file_put_contents(CLOSED_FILE, implode("\n", $new_lines));
    $msg = "Date re-opened.";
}

if (isset($_POST['delete_id'])) {
    $stmt = $db->prepare("DELETE FROM jobs WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    $msg = "Job deleted.";
}

// --- 3. VIEW PARAMETERS & DATA ---
$view = $_GET['view'] ?? 'daily';
$selected_date = $_GET['date'] ?? date('Y-m-d');

$dates_stmt = $db->query("SELECT DISTINCT install_date FROM jobs ORDER BY install_date DESC");
$available_dates = $dates_stmt->fetchAll(PDO::FETCH_COLUMN);

$start_ts = 0;
$end_ts = 0;
$date_title = "";

if ($view === 'daily') {
    $start_ts = strtotime($selected_date);
    $end_ts = $start_ts;
    $date_title = date('D, M j, Y', $start_ts);
} elseif ($view === 'weekly') {
    $ts = strtotime($selected_date);
    $start_ts = (date('N', $ts) == 1) ? $ts : strtotime('last monday', $ts);
    $end_ts = strtotime('+6 days', $start_ts);
    $date_title = "Week: " . date('M j', $start_ts) . " - " . date('M j', $end_ts);
} elseif ($view === 'monthly') {
    $ts = strtotime($selected_date);
    $start_ts = strtotime(date('Y-m-01', $ts));
    $end_ts = strtotime(date('Y-m-t', $ts));
    $date_title = date('F Y', $start_ts);
}

$sql = "SELECT * FROM jobs WHERE install_date BETWEEN ? AND ? ORDER BY install_date DESC";
$stmt = $db->prepare($sql);
$stmt->execute([date('Y-m-d', $start_ts), date('Y-m-d', $end_ts)]);
$display_jobs = $stmt->fetchAll();

$users = $db->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
$is_closed = ($view === 'daily') && in_array($selected_date, file_exists(CLOSED_FILE) ? file(CLOSED_FILE, FILE_IGNORE_NEW_LINES) : []);

// --- 4. STATS ---
$stats = ['job_total' => 0, 'extra_pd_total' => 0, 'base_pd_total' => 0, 'lead_pay' => 0, 'grand_total' => 0];
$unique_worked_dates = [];

foreach ($display_jobs as $job) {
    $stats['job_total'] += (float) $job['pay_amount'];
    if (($job['extra_per_diem'] ?? 'No') === 'Yes')
        $stats['extra_pd_total'] += $rates['per_diem'];
    if ($job['install_type'] !== 'DO')
        $unique_worked_dates[$job['install_date']] = true;
}

$curr = $start_ts;
$calendar_days = [];
while ($curr <= $end_ts) {
    $loopDate = date('Y-m-d', $curr);
    $dayOfWeek = date('w', $curr);

    $isExplicitDayOff = false;
    $dayJobPay = 0;
    $dayExtraPD = 0;
    foreach ($display_jobs as $dj) {
        if ($dj['install_date'] === $loopDate) {
            if ($dj['install_type'] === 'DO')
                $isExplicitDayOff = true;
            $dayJobPay += (float) $dj['pay_amount'];
            if (($dj['extra_per_diem'] ?? 'No') === 'Yes')
                $dayExtraPD += $rates['per_diem'];
        }
    }

    $hasWork = isset($unique_worked_dates[$loopDate]);
    $dailyBase = 0;
    if ($dayOfWeek == 0) {
        $dailyBase = $rates['per_diem'];
    } else {
        if ($hasWork && !$isExplicitDayOff) {
            $dailyBase = $rates['per_diem'];
        }
    }

    $stats['base_pd_total'] += $dailyBase;
    if ($view === 'monthly') {
        $calendar_days[date('j', $curr)] = ['total' => $dayJobPay + $dayExtraPD + $dailyBase, 'base' => $dailyBase, 'is_off' => $isExplicitDayOff, 'has_work' => $hasWork];
    }
    $curr = strtotime('+1 day', $curr);
}

if ($view === 'weekly' && !empty($display_jobs)) {
    $stats['lead_pay'] = $lead_pay_amount;
}
$stats['grand_total'] = $stats['job_total'] + $stats['extra_pd_total'] + $stats['base_pd_total'] + $stats['lead_pay'];

// Render Helper
function renderRow($job, $install_names, $rates, $index)
{
    $id = $job['id'];
    $name = $job['cust_name'] ?? '';
    $dateDisplay = date('D m/d', strtotime($job['install_date']));
    $visualCodes = [];
    if (!empty($job['install_type'])) {
        $p = $rates[$job['install_type']] ?? 0;
        $visualCodes[] = "<b>{$job['install_type']}</b> $" . number_format($p, 2);
    }
    if (($job['spans'] ?? 0) > 0)
        $visualCodes[] = "Spans({$job['spans']})";
    if (($job['conduit_ft'] ?? 0) > 0)
        $visualCodes[] = "Cond({$job['conduit_ft']}')";
    if (($job['jacks_installed'] ?? 0) > 1)
        $visualCodes[] = "Jacks({$job['jacks_installed']})";
    if (($job['extra_per_diem'] ?? 'No') === 'Yes')
        $visualCodes[] = "<span style='color:var(--success-text); font-weight:bold;'>+Extra PD</span>";

    $codeStr = implode(", ", $visualCodes);

    return "<tr>
        <td><small style='color:var(--text-muted); font-weight:600;'>$dateDisplay</small></td>
        <td><b>" . htmlspecialchars($job['ticket_number']) . "</b><br><span style='color:var(--text-muted);font-size:0.85rem;'>$name</span></td>
        <td><small style='color:var(--text-secondary);'>$codeStr</small></td>
        <td style='text-align:right'>
            <b>$" . htmlspecialchars(number_format($job['pay_amount'], 2)) . "</b><br>
            <div style='margin-top:5px; display:flex; gap:5px; justify-content:flex-end;'>
                <a href='edit_job.php?id=$id' class='btn btn-small btn-secondary' style='text-decoration:none;'>Edit</a>
                <form method='post' onsubmit=\"return confirm('Delete this job?');\" style='display:inline;'>
                    " . csrf_field() . "
                    <input type='hidden' name='delete_id' value='$id'>
                    <button class='btn btn-small btn-danger'>X</button>
                </form>
            </div>
        </td>
    </tr>";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .selector {
            padding: 8px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--bg-input);
            color: var(--text-main);
            font-size: 0.9rem;
        }

        .admin-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-bottom: 20px;
        }

        .admin-cal-head {
            text-align: center;
            font-weight: bold;
            font-size: 0.8rem;
            color: var(--text-muted);
            padding-bottom: 5px;
        }

        .admin-cal-day {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            min-height: 60px;
            padding: 4px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .admin-cal-day.empty {
            background: transparent;
            border: none;
        }

        .user-list-row {
            display: grid;
            grid-template-columns: 1fr 80px 1.5fr;
            gap: 10px;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid var(--border);
        }

        .user-list-row:last-child {
            border-bottom: none;
        }

        @media (max-width: 600px) {
            .user-list-row {
                grid-template-columns: 1fr;
                gap: 5px;
                background: var(--bg-input);
                margin-bottom: 10px;
                border-radius: 8px;
                border: 1px solid var(--border);
            }
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <div class="controls-row">
            <h2 style="margin:0;"><?= $date_title ?></h2>
            <form method="get" style="display:flex; gap:10px;">
                <select name="date" class="selector" onchange="this.form.submit()">
                    <?php foreach ($available_dates as $d): ?>
                        <option value="<?= $d ?>" <?= $d === $selected_date ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="view" class="selector" onchange="this.form.submit()">
                    <option value="daily" <?= $view === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly" <?= $view === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                    <option value="monthly" <?= $view === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </form>
        </div>

        <div class="box" style="padding: 15px; margin-bottom: 20px;">
            <input type="text" id="tableSearch" onkeyup="filterTable()" placeholder="Search ticket, name, address..."
                style="width: 100%; padding: 12px; font-size: 1rem; border: 1px solid var(--border); border-radius: 6px; background: var(--bg-input); color: var(--text-main); box-sizing: border-box;">
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-label">Jobs</div>
                <div class="stat-val">$<?= number_format($stats['job_total'], 2) ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Base PD</div>
                <div class="stat-val">$<?= number_format($stats['base_pd_total'], 2) ?></div>
            </div>
            <?php if ($view !== 'daily'): ?>
                <div class="stat-box" style="border-color:var(--accent);">
                    <div class="stat-label" style="color:var(--accent);">Lead Pay</div>
                    <div class="stat-val" style="color:var(--accent);">$<?= number_format($stats['lead_pay'], 2) ?></div>
                </div>
            <?php endif; ?>
            <div class="stat-box" style="background:var(--success-bg); border-color:var(--success-text);">
                <div class="stat-label" style="color:var(--success-text);">Total</div>
                <div class="stat-val" style="color:var(--success-text);">$<?= number_format($stats['grand_total'], 2) ?>
                </div>
            </div>
        </div>

        <?php if ($view === 'monthly'): ?>
            <div class="box" style="padding:10px; overflow:hidden;">
                <div class="admin-cal-grid">
                    <div class="admin-cal-head">M</div>
                    <div class="admin-cal-head">T</div>
                    <div class="admin-cal-head">W</div>
                    <div class="admin-cal-head">T</div>
                    <div class="admin-cal-head">F</div>
                    <div class="admin-cal-head">S</div>
                    <div class="admin-cal-head">S</div>
                    <?php
                    $first_of_month = date('Y-m-01', strtotime($selected_date));
                    $day_of_week = date('N', strtotime($first_of_month));
                    $days_in_month = date('t', strtotime($selected_date));
                    for ($x = 1; $x < $day_of_week; $x++)
                        echo "<div class='admin-cal-day empty'></div>";
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $info = $calendar_days[$day] ?? ['total' => 0, 'is_off' => false, 'has_work' => false, 'base' => 0];
                        $badges = "";
                        if ($info['is_off'])
                            $badges .= "🚫";
                        elseif ($info['has_work'])
                            $badges .= "🔵";
                        $totalDisplay = $info['total'] > 0 ? "$" . round($info['total']) : "-";
                        echo "<div class='admin-cal-day'><div style='display:flex; justify-content:space-between;'><span style='font-weight:bold; font-size:0.8rem; color:var(--text-muted);'>$day</span><span>$badges</span></div><div style='text-align:right; font-size:0.8rem; font-weight:bold; color:var(--success-text);'>$totalDisplay</div></div>";
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'daily' && isset($is_closed) && $is_closed): ?>
            <div style="margin-bottom:20px; text-align:center;">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="reopen_date" value="<?= $selected_date ?>">
                    <button
                        style="background:#f59e0b; padding:10px 20px; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">Reopen
                        This Day</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="box" style="padding:0; overflow:hidden;">
            <div style="overflow-x:auto;">
                <table id="jobsTable" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--bg-input); text-align:left;">
                            <th style="padding:10px;">Date</th>
                            <th style="padding:10px;">Job</th>
                            <th style="padding:10px;">Codes</th>
                            <th style="padding:10px; text-align:right;">Pay & Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($display_jobs as $index => $job): ?>
                            <?= renderRow($job, $install_names, $rates, $index) ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (empty($display_jobs)): ?>
                <p style="text-align:center; color:var(--text-muted); padding:20px;">No jobs found.</p><?php endif; ?>
        </div>

        <div class="box" style="margin-top:30px; border-top: 4px solid var(--primary);">
            <h3>👥 Account & Users</h3>
            <?php if (isset($msg)): ?>
                <div class="alert"><?= $msg ?></div><?php endif; ?>

            <form method="post"
                style="background:var(--bg-input); padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid var(--border);">
                <?= csrf_field() ?>
                <div style="font-weight:bold; margin-bottom:10px; color:var(--primary);">Change My Password</div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <input type="password" name="current_pass" placeholder="Current Password" required
                        style="flex:1; padding:8px; border-radius:4px; border:1px solid var(--border);">
                    <input type="password" name="new_pass" placeholder="New Password" required
                        style="flex:1; padding:8px; border-radius:4px; border:1px solid var(--border);">
                    <button type="submit" name="change_my_password" class="btn btn-small">Update</button>
                </div>
            </form>

            <form method="post"
                style="background:var(--bg-input); padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid var(--border);">
                <?= csrf_field() ?>
                <div style="font-weight:bold; margin-bottom:10px;">Add New Tech</div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <input type="text" name="new_username" placeholder="Username" required
                        style="flex:1; padding:8px; border-radius:4px; border:1px solid var(--border);">
                    <input type="password" name="new_password" placeholder="Password" required
                        style="flex:1; padding:8px; border-radius:4px; border:1px solid var(--border);">
                    <select name="new_role" style="padding:8px; border-radius:4px; border:1px solid var(--border);">
                        <option value="tech">Tech</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button type="submit" name="create_user" class="btn btn-small"
                        style="background:var(--success-bg); color:var(--success-text);">Add User</button>
                </div>
            </form>

            <div style="border:1px solid var(--border); border-radius:8px; overflow:hidden;">
                <div class="user-list-row"
                    style="background:var(--bg-input); font-weight:bold; border-bottom:2px solid var(--border);">
                    <div>Username</div>
                    <div>Role</div>
                    <div>Action</div>
                </div>

                <?php foreach ($users as $u): ?>
                    <form method="post" class="user-list-row">
                        <?= csrf_field() ?>
                        <input type="hidden" name="target_id" value="<?= $u['id'] ?>">

                        <div>
                            <input type="text" name="edit_username" value="<?= htmlspecialchars($u['username']) ?>" required
                                style="width:100%; padding:5px; border:1px solid var(--border); border-radius:4px; background:var(--bg-card); color:var(--text-main);">
                        </div>

                        <div>
                            <span class="badge"
                                style="background:<?= $u['role'] == 'admin' ? 'var(--primary)' : 'var(--text-muted)' ?>; color:white; padding:2px 6px; border-radius:4px; font-size:0.8rem;">
                                <?= strtoupper($u['role']) ?>
                            </span>
                        </div>

                        <div style="display:flex; gap:5px; align-items:center;">
                            <input type="text" name="edit_password" placeholder="New Pass (Optional)"
                                style="flex:1; padding:5px; border:1px solid var(--border); border-radius:4px; background:var(--bg-card); color:var(--text-main); font-size:0.9rem;">

                            <button type="submit" name="save_user" class="btn btn-small" title="Save Changes">💾</button>

                            <?php if ($u['id'] != ($_SESSION['user_id'] ?? 0)): ?>
                                <button type="submit" name="delete_user" class="btn btn-small btn-danger"
                                    onclick="return confirm('Delete user <?= $u['username'] ?>?');"
                                    title="Delete User">❌</button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
        function toggleTheme() { document.body.classList.toggle('dark-mode'); localStorage.setItem('theme', document.body.classList.contains('dark-mode') ? 'dark' : 'light'); }
        function filterTable() { var input = document.getElementById("tableSearch"); var filter = input.value.toUpperCase(); var table = document.getElementById("jobsTable"); var tr = table.getElementsByTagName("tr"); for (var i = 1; i < tr.length; i++) { if (tr[i]) { var txtValue = tr[i].textContent || tr[i].innerText; tr[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? "" : "none"; } } }
    </script>
</body>

</html>