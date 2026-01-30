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

$db = getDB();
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';

// --- INITIALIZE TABLE ---
try {
    $db->exec("CREATE TABLE IF NOT EXISTS inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        item_key TEXT NOT NULL,
        item_name TEXT NOT NULL,
        qty REAL DEFAULT 0,
        par_level REAL DEFAULT 10,
        unit TEXT DEFAULT 'pcs',
        UNIQUE(user_id, item_key)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS inventory_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        item_key TEXT NOT NULL,
        change_amount REAL NOT NULL,
        new_qty REAL NOT NULL,
        reason TEXT,
        log_date DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed Defaults
    $defaults = [
        'ONT' => ['name' => 'ONTs', 'par' => 10, 'unit' => 'pcs'],
        'EERO-6E' => ['name' => 'Eero Pro 6e', 'par' => 5, 'unit' => 'pcs'],
        'EERO-P7' => ['name' => 'Eero Pro 7', 'par' => 5, 'unit' => 'pcs'],
        'EERO-7MAX' => ['name' => 'Eero 7 Max', 'par' => 2, 'unit' => 'pcs'],
        'JACK' => ['name' => 'Jacks (Wall)', 'par' => 20, 'unit' => 'pcs'],

        // NIDs
        'NID-SM' => ['name' => 'NID (Small)', 'par' => 5, 'unit' => 'pcs'],
        'NID-MD' => ['name' => 'NID (Medium)', 'par' => 5, 'unit' => 'pcs'],
        'NID-LG' => ['name' => 'NID (Large)', 'par' => 5, 'unit' => 'pcs'],

        // Soft Jumpers
        'JUMP-25' => ['name' => 'Jumper 25\'', 'par' => 5, 'unit' => 'pcs'],
        'JUMP-50' => ['name' => 'Jumper 50\'', 'par' => 5, 'unit' => 'pcs'],
        'JUMP-100' => ['name' => 'Jumper 100\'', 'par' => 5, 'unit' => 'pcs'],

        // Drops (Precise Lengths)
        'DROP-100' => ['name' => 'Drop 100\'', 'par' => 2, 'unit' => 'pcs'],
        'DROP-120' => ['name' => 'Drop 120\'', 'par' => 2, 'unit' => 'pcs'],
        'DROP-125' => ['name' => 'Drop 125\'', 'par' => 2, 'unit' => 'pcs'],
        'DROP-150' => ['name' => 'Drop 150\'', 'par' => 2, 'unit' => 'pcs'],
        'DROP-200' => ['name' => 'Drop 200\'', 'par' => 2, 'unit' => 'pcs'],
        'DROP-250' => ['name' => 'Drop 250\'', 'par' => 2, 'unit' => 'pcs'],
        'DROP-300' => ['name' => 'Drop 300\'', 'par' => 2, 'unit' => 'pcs'],
        'DROP-350' => ['name' => 'Drop 350\'', 'par' => 1, 'unit' => 'pcs'],
        'DROP-400' => ['name' => 'Drop 400\'', 'par' => 1, 'unit' => 'pcs'],
        'DROP-500' => ['name' => 'Drop 500\'', 'par' => 1, 'unit' => 'pcs'],
        'DROP-600' => ['name' => 'Drop 600\'', 'par' => 1, 'unit' => 'pcs'],
        'DROP-700' => ['name' => 'Drop 700\'', 'par' => 1, 'unit' => 'pcs'],
        'DROP-1000' => ['name' => 'Drop 1000\'', 'par' => 1, 'unit' => 'pcs'],
    ];

    foreach ($defaults as $key => $def) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO inventory (user_id, item_key, item_name, qty, par_level, unit) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $key, $def['name'], $def['par'], $def['par'], $def['unit']]);
    }

} catch (Exception $e) {
    die("DB Init Error: " . $e->getMessage());
}

// --- HANDLE POST (ADJUST STOCK) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['item_key'];

    // Get current qty first
    $stmt = $db->prepare("SELECT qty FROM inventory WHERE user_id = ? AND item_key = ?");
    $stmt->execute([$user_id, $key]);
    $curr = $stmt->fetchColumn();

    $new_qty = $curr;
    $change = 0;

    if (isset($_POST['set_qty'])) {
        // Direct Set
        $new_qty = (float) $_POST['set_qty'];
        $change = $new_qty - $curr;
        $reason = "Manual Update";
    } elseif (isset($_POST['change'])) {
        // Increment/Decrement
        $change = (float) $_POST['change'];
        $new_qty = max(0, $curr + $change);
        $reason = "Quick Adjust";
    }

    if ($change != 0) {
        $stmt = $db->prepare("UPDATE inventory SET qty = ? WHERE user_id = ? AND item_key = ?");
        $stmt->execute([$new_qty, $user_id, $key]);

        // Log it
        $stmt = $db->prepare("INSERT INTO inventory_logs (user_id, item_key, change_amount, new_qty, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $key, $change, $new_qty, $reason]);
    }

    header("Location: inventory.php");
    exit;
}

// --- FETCH DATA ---
$items = [];
$stmt = $db->prepare("SELECT * FROM inventory WHERE user_id = ? ORDER BY item_key ASC");
$stmt->execute([$user_id]);
$raw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Categorize
$categorized = [
    'Equipment' => [],
    'NIDs' => [],
    'Jumpers' => [],
    'Drop Wire' => []
];

foreach ($raw_items as $item) {
    if (strpos($item['item_key'], 'DROP-') === 0) {
        $categorized['Drop Wire'][] = $item;
    } elseif (strpos($item['item_key'], 'JUMP-') === 0) {
        $categorized['Jumpers'][] = $item;
    } elseif (strpos($item['item_key'], 'NID-') === 0) {
        $categorized['NIDs'][] = $item;
    } elseif (strpos($item['item_key'], 'EERO-') === 0) {
        // Eeros now grouped
    } else {
        $categorized['Equipment'][] = $item;
    }
}

// --- FETCH LOGS ---
$logs = [];
try {
    $stmt = $db->prepare("SELECT * FROM inventory_logs WHERE user_id = ? ORDER BY log_date DESC LIMIT 20");
    $stmt->execute([$user_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css?v=1.1">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <?php include 'head_pwa.php'; ?>
</head>

<body>
    <div class="app-container">
        <?php include 'nav.php'; ?>
        <main class="main-content">
            <div class="welcome-banner">
                <h2 style="font-weight: 800;">📦 My Inventory</h2>
                <div style="font-size: 0.9rem; opacity: 0.7;">Auto-deducts when you save jobs</div>
            </div>

            <div style="max-width: 1200px; margin: 0 auto; padding-bottom: 60px;">

                <h3 style="font-weight: 800; margin-bottom: 20px;">📦 Consolidated Categories</h3>
                <div class="inv-group-grid">
                    <?php
                    $groups = [
                        'NIDs' => ['icon' => '🏠', 'key' => 'NID-'],
                        'Jumpers' => ['icon' => '🔌', 'key' => 'JUMP-'],
                        'Drop Wire' => ['icon' => '➰', 'key' => 'DROP-'],
                        'Eeros' => ['icon' => '📟', 'key' => 'EERO-']
                    ];

                    foreach ($groups as $name => $g):
                        $count = 0;
                        $total_qty = 0;
                        $low_stock = false;
                        foreach ($raw_items as $item) {
                            if (strpos($item['item_key'], $g['key']) === 0) {
                                $count++;
                                $total_qty += $item['qty'];
                                if (($item['qty'] / max(1, $item['par_level'])) < 0.3) {
                                    $low_stock = true;
                                }
                            }
                        }
                        if ($count > 0):
                            ?>
                            <a href="inventory_detail.php?cat=<?= urlencode($name) ?>" class="inv-group-card">
                                <div class="badge-qty"><?= number_format($total_qty) ?></div>
                                <div class="inv-group-icon"><?= $g['icon'] ?></div>
                                <div class="inv-group-name"><?= $name ?></div>
                                <div class="inv-group-count"><?= $count ?> sizes tracked</div>
                                <?php if ($low_stock): ?>
                                    <div class="inv-group-status status-alert">⚠️ Low Stock Inside</div>
                                <?php else: ?>
                                    <div class="inv-group-status status-ok">All Normal</div>
                                <?php endif; ?>
                            </a>
                        <?php
                        endif;
                    endforeach;
                    ?>
                </div>

                <h3 style="font-weight: 800; margin-top: 40px; margin-bottom: 20px;">📟 Standard Equipment</h3>
                <div class="inv-group-grid">
                    <?php foreach ($categorized['Equipment'] as $item):
                        $pct = ($item['qty'] / max(1, $item['par_level'])) * 100;
                        $is_low = ($pct < 30);
                        ?>
                        <div class="inv-group-card single-unit"
                            onclick="openUpdateModal('<?= $item['item_key'] ?>', '<?= htmlspecialchars($item['item_name']) ?>', '<?= $item['qty'] ?>', '📟')">
                            <div class="inv-group-icon">📟</div>
                            <div class="inv-group-name"><?= htmlspecialchars($item['item_name']) ?></div>
                            <div class="inv-group-count"><?= number_format($item['qty']) ?></div>
                            <div class="inv-group-status <?= $is_low ? 'status-alert' : 'status-ok' ?>">
                                <?= $is_low ? '⚠️ Low Stock' : 'Stock OK' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- MODAL OVERLAY -->
            <div id="updateModal" class="modal-overlay">
                <div class="modal-content-tech">
                    <div class="modal-header">
                        <div id="modalIcon" class="modal-icon">📟</div>
                        <div>
                            <h3 id="modalTitle" class="modal-title">Update Stock</h3>
                            <div id="modalItemKey"
                                style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700;">ITEM_KEY</div>
                        </div>
                        <button class="modal-close" onclick="closeUpdateModal()">&times;</button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" id="modalInputKey" name="item_key">

                            <div>
                                <label>Direct Quantity Update</label>
                                <input type="number" step="0.1" name="set_qty" id="modalInputQty" class="form-control"
                                    placeholder="0.0">
                                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 8px;">Enter the
                                    exact amount currently in stock.</p>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <button type="button" class="btn btn-secondary"
                                    style="background: var(--danger-text); color: white; border: none;"
                                    onclick="adjustBy(-1)">-1 Quick</button>
                                <button type="button" class="btn btn-secondary"
                                    style="background: var(--success-text); color: white; border: none;"
                                    onclick="adjustBy(1)">+1 Quick</button>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" style="flex: 1;"
                                onclick="closeUpdateModal()">Cancel</button>
                            <button type="submit" class="btn"
                                style="flex: 2; background: var(--primary); color: white;">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TRANSACTION LOG (Kept as list for history) -->
            <h3 style="font-weight: 800; margin-top: 40px; margin-bottom: 15px;">📜 History</h3>
            <div
                style="background:var(--bg-card); border:1px solid var(--border); border-radius:12px; overflow:hidden; margin-bottom: 60px;">
                <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                    <thead>
                        <tr style="background:var(--bg-input); color:var(--text-muted); text-align:left;">
                            <th style="padding:10px;">Time</th>
                            <th style="padding:10px;">Item</th>
                            <th style="padding:10px;">Change</th>
                            <th style="padding:10px;">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="4" style="padding:20px; text-align:center; color:var(--text-muted);">No
                                    activity recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr style="border-bottom:1px solid var(--border);">
                                    <td style="padding:10px;"><?= date('M j, g:i a', strtotime($l['log_date'])) ?></td>
                                    <td style="padding:10px; font-weight:bold;"><?= htmlspecialchars($l['item_key']) ?></td>
                                    <td
                                        style="padding:10px; color: <?= $l['change_amount'] < 0 ? 'var(--danger-text)' : 'var(--success-text)' ?>;">
                                        <?= $l['change_amount'] > 0 ? '+' : '' ?>        <?= $l['change_amount'] ?>
                                    </td>
                                    <td style="padding:10px; color:var(--text-muted);"><?= htmlspecialchars($l['reason']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </main>
    </div>

    <script>
        function openUpdateModal(key, name, qty, icon) {
            document.getElementById('modalTitle').innerText = name;
            document.getElementById('modalItemKey').innerText = key;
            document.getElementById('modalInputKey').value = key;
            document.getElementById('modalInputQty').value = qty;
            document.getElementById('modalIcon').innerText = icon;
            document.getElementById('updateModal').classList.add('active');
        }

        function closeUpdateModal() {
            document.getElementById('updateModal').classList.remove('active');
        }

        function adjustBy(amt) {
            let input = document.getElementById('modalInputQty');
            let val = parseFloat(input.value) || 0;
            input.value = Math.max(0, val + amt).toFixed(1);
        }

        // Close on backdrop click
        window.onclick = function (event) {
            let modal = document.getElementById('updateModal');
            if (event.target == modal) {
                closeUpdateModal();
            }
        }
    </script>
</body>

</html>