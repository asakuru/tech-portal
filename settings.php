<?php
require_once 'config.php';
require_once 'functions.php'; // <--- LOAD THE BRAIN

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$msg = "";

// --- SELF-CORRECTION: ENSURE FINANCIAL RATES EXIST ---
try {
    $keys_to_check = [
        'IRS_MILEAGE' => [0.67, 'IRS Mileage Rate ($)'],
        'TAX_PERCENT' => [0.25, 'Est. Tax Rate (Decimal)'],
        'LEAD_PAY' => [500.00, 'Weekly Lead Pay ($)']
    ];

    foreach ($keys_to_check as $key => $vals) {
        $check = $db->prepare("SELECT count(*) FROM rate_card WHERE rate_key=?");
        $check->execute([$key]);
        if ($check->fetchColumn() == 0) {
            $ins = $db->prepare("INSERT INTO rate_card (rate_key, amount, description, sort_order) VALUES (?, ?, ?, 99)");
            $ins->execute([$key, $vals[0], $vals[1]]);
        }
    }
} catch (Exception $e) { /* Ignore */
}

// --- CSRF PROTECTION ---
csrf_check();

// --- HANDLE SAVE ---
if (isset($_POST['save_rates'])) {
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE rate_card SET amount = ? WHERE rate_key = ?");

        foreach ($_POST['rates'] as $key => $amount) {
            // Basic validation
            $amt = (float) $amount;
            $stmt->execute([$amt, $key]);
        }

        $db->commit();
        $msg = "✅ Rates Updated Successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $msg = "❌ Error: " . $e->getMessage();
    }
}

// --- FETCH RATES ---
// Using raw query here is fine because we need specific ordering for the UI
// Sorted alphabetically so F-codes come first, then System codes (I, L, T, etc.)
$rates_list = $db->query("SELECT * FROM rate_card ORDER BY rate_key ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Settings - Rates</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .rate-grid {
            display: grid;
            grid-template-columns: 85px 1fr 100px;
            gap: 15px;
            align-items: center;
            padding: 12px 10px;
            border-bottom: 1px solid var(--border);
        }

        .rate-grid:last-child {
            border-bottom: none;
        }

        .rate-key {
            font-weight: bold;
            color: var(--primary);
            font-family: monospace;
            font-size: 1.05rem;
        }

        .rate-desc {
            font-size: 0.9rem;
            color: var(--text-main);
            line-height: 1.3;
        }

        .rate-input input {
            width: 100%;
            padding: 8px;
            text-align: right;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: var(--bg-input);
            color: var(--text-main);
            font-weight: bold;
        }

        .header-row {
            background: var(--bg-input);
            font-weight: bold;
            color: var(--text-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            border-bottom: 2px solid var(--border);
            position: sticky;
            top: 0;
        }

        /* Section Separator for System Keys */
        .separator {
            background: var(--bg-card);
            padding: 15px 10px 5px;
            font-weight: bold;
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--border);
            margin-top: 10px;
        }

        .backup-section {
            margin-top: 30px;
            padding: 20px;
            border: 1px dashed var(--border);
            text-align: center;
            border-radius: 8px;
            background: var(--bg-card);
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2>⚙️ Rate Settings</h2>
            <a href="index.php" class="btn"
                style="background:var(--bg-input); color:var(--text-main); border:1px solid var(--border);">Back</a>
        </div>

        <?php if ($msg): ?>
            <div class="alert" style="border-left: 4px solid var(--success-text);"><?= $msg ?></div><?php endif; ?>

        <div class="box" style="padding:0; overflow:hidden;">
            <form method="post">
                <?= csrf_field() ?>

                <div class="rate-grid header-row">
                    <div>Code</div>
                    <div>Description</div>
                    <div style="text-align:right;">Rate ($)</div>
                </div>

                <?php
                $system_started = false;
                foreach ($rates_list as $r):
                    // Visual Separator logic:
                    // If it's NOT an F-code (starts with F followed by number usually), and NOT a numeric code (like 1-F014...)
                    // We assume it is a System Setting (IRS, Tax, Lead Pay)
                    $is_f_code = (strpos($r['rate_key'], 'F') === 0 || is_numeric($r['rate_key'][0]));

                    if (!$system_started && !$is_f_code) {
                        $system_started = true;
                        echo '<div class="separator">Calculator Settings (System Use)</div>';
                    }
                    ?>
                    <div class="rate-grid">
                        <div class="rate-key"><?= htmlspecialchars($r['rate_key']) ?></div>
                        <div class="rate-desc"><?= htmlspecialchars($r['description']) ?></div>
                        <div class="rate-input">
                            <input type="text" inputmode="decimal" name="rates[<?= $r['rate_key'] ?>]"
                                value="<?= number_format($r['amount'], (strpos($r['rate_key'], 'TAX') !== false ? 2 : 2)) ?>">
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="padding:20px; background:var(--bg-card); border-top:1px solid var(--border);">
                    <button type="submit" name="save_rates" class="btn btn-full">💾 Save Changes</button>
                </div>
            </form>
        </div>

        <div class="backup-section">
            <h3 style="margin-top:0;">🛡️ System Backup</h3>
            <p style="color:var(--text-muted); font-size:0.9rem;">Manage database snapshots to keep your records safe.
            </p>
            <a href="backup.php" class="btn"
                style="background:#000; color:#fff; text-decoration:none; display:inline-block; padding:10px 20px;">Open
                Backup Manager</a>
        </div>

    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
    </script>
</body>

</html>