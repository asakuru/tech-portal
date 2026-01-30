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
        'EERO' => ['name' => 'Eeros (Routers)', 'par' => 5, 'unit' => 'pcs'],
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

            <div style="max-width: 1000px; margin: 0 auto;">
                <?php foreach ($categorized as $cat => $cat_items): ?>
                    <?php if (count($cat_items) > 0): ?>
                        <div class="cat-section">
                            <div class="cat-header"><?= $cat ?></div>
                            <div class="cat-grid">
                                <?php foreach ($cat_items as $item):
                                    $pct = ($item['qty'] / max(1, $item['par_level'])) * 100;
                                    $status = ($pct < 30) ? 'low' : (($pct > 80) ? 'good' : 'mid');
                                    ?>
                                    <div class="inv-item-compact">

                                        <!-- Left: Info -->
                                        <div class="inv-info">
                                            <div class="inv-name-sm"><?= htmlspecialchars($item['item_name']) ?></div>
                                            <div class="inv-meta-sm">
                                                <div class="stock-indicator <?= $status ?>"></div>
                                                <span>Target: <?= htmlspecialchars($item['par_level']) ?></span>
                                            </div>
                                        </div>

                                        <!-- Right: Controls -->
                                        <div class="inv-controls">
                                            <form method="POST">
                                                <input type="hidden" name="item_key" value="<?= $item['item_key'] ?>">
                                                <input type="hidden" name="change" value="-1">
                                                <button class="btn-inv-sm">−</button>
                                            </form>

                                            <div class="inv-qty-sm" data-key="<?= $item['item_key'] ?>">
                                                <?= number_format($item['qty']) ?>
                                            </div>

                                            <form method="POST">
                                                <input type="hidden" name="item_key" value="<?= $item['item_key'] ?>">
                                                <input type="hidden" name="change" value="1">
                                                <button class="btn-inv-sm">+</button>
                                            </form>
                                        </div>

                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- TRANSACTION LOG -->
            <h3 style="margin-top:40px; margin-bottom:15px; font-weight:800;">📜 History</h3>
            <div
                style="background:var(--bg-card); border:1px solid var(--border); border-radius:12px; overflow:hidden;">
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

            <div
                style="margin-top: 40px; padding: 20px; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border);">
                <h3>💡 How to Update</h3>
                <p style="color: var(--text-muted); margin-bottom:15px;">
                    Use the <strong>+ / -</strong> buttons for quick changes. To set an exact number (e.g. after a
                    restock), click the number.
                </p>
            </div>
        </main>
    </div>

    <script>
        // Simple prompt for direct edit (can be enhanced to modal later)
        document.querySelectorAll('.inv-qty-sm').forEach(el => {
            el.style.cursor = 'pointer';
            el.title = "Click to set exact quantity";
            el.onclick = function () {
                const key = this.dataset.key;
                const current = this.innerText;
                const newVal = prompt("Set exact quantity for " + key + ":", current);
                if (newVal !== null && !isNaN(newVal)) {
                    // Create hidden form to submit property
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="item_key" value="${key}">
                                      <input type="hidden" name="set_qty" value="${newVal}">`;
                    document.body.appendChild(form);
                    form.submit();
                }
            };
        });
    </script>
</body>

</html>