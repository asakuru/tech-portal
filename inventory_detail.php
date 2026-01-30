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

            <div style="max-width: 800px; margin: 0 auto; padding-bottom: 60px;">
                <div class="cat-grid">
                    <?php foreach ($cat_items as $item):
                        $pct = ($item['qty'] / max(1, $item['par_level'])) * 100;
                        $status = ($pct < 30) ? 'status-low' : (($pct > 80) ? 'status-good' : 'status-mid');
                        ?>
                        <div class="inv-item-compact <?= $status ?>" style="margin-bottom: 12px;">
                            <div class="inv-info">
                                <div class="inv-name-sm">
                                    <?= htmlspecialchars($item['item_name']) ?>
                                </div>
                                <div class="inv-meta-sm">
                                    <span>Target:
                                        <?= htmlspecialchars($item['par_level']) ?>
                                        <?= $item['unit'] ?>
                                    </span>
                                </div>
                            </div>

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
        </main>
    </div>

    <script>
        document.querySelectorAll('.inv-qty-sm').forEach(el => {
            el.style.cursor = 'pointer';
            el.title = "Click to set exact quantity";
            el.onclick = function () {
                const key = this.dataset.key;
                const current = this.innerText.trim();
                const newVal = prompt("Set exact quantity for " + key + ":", current);
                if (newVal !== null && !isNaN(newVal)) {
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