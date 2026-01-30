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

    // Cleanup old generic items if they exist?
    // $db->exec("DELETE FROM inventory WHERE item_key IN ('DROP', 'JUMPER', 'NID')");

} catch (Exception $e) {
    die("DB Init Error: " . $e->getMessage());
}

// --- HANDLE POST (ADJUST STOCK) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['item_key'];
    $change = (float) $_POST['change']; // +1, -1, etc.

    // allow setting exact value or specific add/subtract?
    // simple increment for now
    $stmt = $db->prepare("UPDATE inventory SET qty = MAX(0, qty + ?) WHERE user_id = ? AND item_key = ?");
    $stmt->execute([$change, $user_id, $key]);

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

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css?v=1.1">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <?php include 'head_pwa.php'; ?>
    <style>
        .inv-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        /* Progress Bar for Stock Level */
        .stock-level {
            height: 6px;
            background: var(--bg-input);
            border-radius: 3px;
            margin-top: 10px;
            overflow: hidden;
        }

        .stock-fill {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width 0.5s ease;
        }

        .stock-fill.low {
            background: var(--danger-text);
        }

        .stock-fill.good {
            background: var(--success-text);
        }

        .inv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .inv-name {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--text-main);
        }

        .inv-unit {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .inv-qty {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--text-main);
            margin: 10px 0;
            cursor: pointer;
        }

        .inv-actions {
            display: flex;
            gap: 10px;
        }

        .btn-inv {
            flex: 1;
            padding: 8px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg-body);
            color: var(--text-main);
            font-weight: bold;
            cursor: pointer;
            transition: 0.1s;
        }

        .btn-inv:active {
            transform: scale(0.95);
        }

        .cat-header {
            grid-column: span 12;
            font-size: 1.2rem;
            font-weight: 700;
            margin-top: 20px;
            margin-bottom: 5px;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border);
            padding-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
    </style>
</head>

<body>
    <div class="app-container">
        <?php include 'nav.php'; ?>
        <main class="main-content">
            <div class="welcome-banner">
                <h2 style="font-weight: 800;">📦 My Inventory</h2>
                <div style="font-size: 0.9rem; opacity: 0.7;">Auto-deducts when you save jobs</div>
            </div>

            <div class="bento-grid">
                <?php foreach ($categorized as $cat => $cat_items): ?>
                    <?php if (count($cat_items) > 0): ?>
                        <div class="cat-header"><?= $cat ?></div>
                        <?php foreach ($cat_items as $item):
                            $pct = ($item['qty'] / max(1, $item['par_level'])) * 100;
                            $status = ($pct < 30) ? 'low' : (($pct > 80) ? 'good' : 'mid');
                            ?>
                            <div class="inv-card"
                                style="grid-column: span 12; @media(min-width: 768px) { grid-column: span 6; } @media(min-width: 1024px) { grid-column: span 4; }">
                                <!-- Replaced inline grid-span with media query equivalent via style/class later? For now, forcing span 4 (approx 3 per row on desktop) -->
                                <!-- Actually, inline media queries don't work. Let's use a class. -->

                                <div class="inv-header">
                                    <div>
                                        <div class="inv-name">
                                            <?= htmlspecialchars($item['item_name']) ?>
                                        </div>
                                        <div class="inv-unit">Target:
                                            <?= htmlspecialchars($item['par_level']) ?>
                                            <?= htmlspecialchars($item['unit']) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="inv-qty" data-key="<?= $item['item_key'] ?>">
                                    <?= number_format($item['qty']) ?>
                                </div>

                                <div class="stock-level">
                                    <div class="stock-fill <?= $status ?>" style="width: <?= min(100, $pct) ?>%"></div>
                                </div>

                                <div class="inv-actions" style="margin-top: 15px;">
                                    <form method="POST" style="flex:1;">
                                        <input type="hidden" name="item_key" value="<?= $item['item_key'] ?>">
                                        <input type="hidden" name="change" value="-1">
                                        <button class="btn-inv">-</button>
                                    </form>
                                    <form method="POST" style="flex:1;">
                                        <input type="hidden" name="item_key" value="<?= $item['item_key'] ?>">
                                        <input type="hidden" name="change" value="1">
                                        <button class="btn-inv">+</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div
                style="margin-top: 40px; padding: 20px; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border);">
                <h3>💡 Smart Tracking Active</h3>
                <p style="color: var(--text-muted);">
                    When you enter a job with an <strong>ONT Serial</strong>, <strong>Jacks</strong>, or <strong>Drop
                        Length</strong>,
                    the system will automatically deduct from these totals.
                </p>
                <button class="btn btn-secondary" onclick="alert('Coming Soon: Edit Par Levels')">Settings</button>
            </div>
        </main>
    </div>
</body>

</html>