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
$cat = $_GET['cat'] ?? '';

if (empty($cat)) {
    header("Location: inventory.php");
    exit;
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
        $new_qty = (float) $_POST['set_qty'];
        $change = $new_qty - $curr;
        $reason = "Manual Update";
    } elseif (isset($_POST['change'])) {
        $change = (float) $_POST['change'];
        $new_qty = max(0, $curr + $change);
        $reason = "Quick Adjust";
    }

    if ($change != 0) {
        $stmt = $db->prepare("UPDATE inventory SET qty = ? WHERE user_id = ? AND item_key = ?");
        $stmt->execute([$new_qty, $user_id, $key]);

        $stmt = $db->prepare("INSERT INTO inventory_logs (user_id, item_key, change_amount, new_qty, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $key, $change, $new_qty, $reason]);
    }

    header("Location: inventory_detail.php?cat=" . urlencode($cat));
    exit;
}

// --- FETCH DATA ---
$items = [];
$pattern = '';
if ($cat === 'Drop Wire')
    $pattern = 'DROP-%';
if ($cat === 'Jumpers')
    $pattern = 'JUMP-%';
if ($cat === 'NIDs')
    $pattern = 'NID-%';
if ($cat === 'Eeros')
    $pattern = 'EERO-%';

if (empty($pattern)) {
    header("Location: inventory.php");
    exit;
}

$stmt = $db->prepare("SELECT * FROM inventory WHERE user_id = ? AND item_key LIKE ? ORDER BY item_key ASC");
$stmt->execute([$user_id, $pattern]);
$cat_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$icon = '📦';
if ($cat == 'Drop Wire')
    $icon = '➰';
if ($cat == 'Jumpers')
    $icon = '🔌';
if ($cat == 'NIDs')
    $icon = '🏠';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>
        <?= htmlspecialchars($cat) ?> - Inventory
    </title>
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
                <div style="display:flex; align-items:center; gap:15px;">
                    <a href="inventory.php" class="btn btn-secondary"
                        style="padding: 8px 12px; border-radius: 50%;">&larr;</a>
                    <div>
                        <h2 style="font-weight: 800;">
                            <?= $icon ?>
                            <?= htmlspecialchars($cat) ?>
                        </h2>
                        <div style="font-size: 0.9rem; opacity: 0.7;">Managing individual sizes</div>
                    </div>
                </div>
            </div>

            <div style="max-width: 1200px; margin: 0 auto; padding-bottom: 60px;">
                <div class="inv-group-grid">
                    <?php foreach ($cat_items as $item):
                        $pct = ($item['qty'] / max(1, $item['par_level'])) * 100;
                        $is_low = ($pct < 30);
                        ?>
                        <div class="inv-group-card single-unit"
                            onclick="openUpdateModal('<?= $item['item_key'] ?>', '<?= htmlspecialchars($item['item_name']) ?>', '<?= $item['qty'] ?>', '<?= $icon ?>')">
                            <div class="inv-group-icon"><?= $icon ?></div>
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
                        <div id="modalIcon" class="modal-icon"><?= $icon ?></div>
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
                                <button type="button" class="btn btn-danger" onclick="adjustBy(-1)">-1 Quick</button>
                                <button type="button" class="btn btn-success" onclick="adjustBy(1)">+1 Quick</button>
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