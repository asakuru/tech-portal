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
csrf_check();

$msg = "";
$error = "";

// --- HANDLE DELETE ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['delete'], $user_id]);
        $msg = "Vehicle deleted successfully.";
    } catch (Exception $e) {
        $error = "Delete failed: " . $e->getMessage();
    }
}

// --- HANDLE ADD VEHICLE ---
if (isset($_POST['add_vehicle'])) {
    try {
        $sql = "INSERT INTO vehicles (
            user_id, vin, license_plate, state_province, year, make, model, trim,
            body_style, drivetrain, engine_code, engine_displacement, engine_cylinders,
            transmission_code, transmission_type, exterior_color, paint_code,
            oem_tire_size_front, tire_pressure_front_psi, tire_pressure_rear_psi,
            purchase_date, purchase_mileage, purchase_price, current_mileage,
            is_primary_vehicle, nickname, notes
        ) VALUES (
            :user_id, :vin, :plate, :state, :year, :make, :model, :trim,
            :body, :drive, :engine_code, :displacement, :cylinders,
            :trans_code, :trans_type, :color, :paint,
            :tire_size, :psi_front, :psi_rear,
            :purchase_date, :purchase_miles, :purchase_price, :current_miles,
            :is_primary, :nickname, :notes
        )";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user_id' => $user_id,
            ':vin' => $_POST['vin'] ?? '',
            ':plate' => $_POST['license_plate'] ?? '',
            ':state' => $_POST['state_province'] ?? '',
            ':year' => $_POST['year'] ?? date('Y'),
            ':make' => $_POST['make'] ?? '',
            ':model' => $_POST['model'] ?? '',
            ':trim' => $_POST['trim'] ?? '',
            ':body' => $_POST['body_style'] ?? '',
            ':drive' => $_POST['drivetrain'] ?? 'FWD',
            ':engine_code' => $_POST['engine_code'] ?? '',
            ':displacement' => $_POST['engine_displacement'] ?? null,
            ':cylinders' => $_POST['engine_cylinders'] ?? null,
            ':trans_code' => $_POST['transmission_code'] ?? '',
            ':trans_type' => $_POST['transmission_type'] ?? 'Automatic',
            ':color' => $_POST['exterior_color'] ?? '',
            ':paint' => $_POST['paint_code'] ?? '',
            ':tire_size' => $_POST['oem_tire_size'] ?? '',
            ':psi_front' => $_POST['tire_pressure_front'] ?? 32,
            ':psi_rear' => $_POST['tire_pressure_rear'] ?? 32,
            ':purchase_date' => $_POST['purchase_date'] ?? null,
            ':purchase_miles' => $_POST['purchase_mileage'] ?? 0,
            ':purchase_price' => $_POST['purchase_price'] ?? null,
            ':current_miles' => $_POST['current_mileage'] ?? $_POST['purchase_mileage'] ?? 0,
            ':is_primary' => isset($_POST['is_primary']) ? 1 : 0,
            ':nickname' => $_POST['nickname'] ?? '',
            ':notes' => $_POST['notes'] ?? ''
        ]);

        $new_id = $db->lastInsertId();

        // Create default fluids record
        $db->prepare("INSERT INTO vehicle_fluids (vehicle_id) VALUES (?)")->execute([$new_id]);

        // Create default documents record  
        $db->prepare("INSERT INTO vehicle_documents (vehicle_id) VALUES (?)")->execute([$new_id]);

        header("Location: vehicle_edit.php?id=" . $new_id . "&tab=fluids");
        exit;

    } catch (Exception $e) {
        $error = "Failed to add vehicle: " . $e->getMessage();
    }
}

// --- FETCH USER'S VEHICLES ---
$vehicles = [];
try {
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE user_id = ? AND is_active = 1 ORDER BY is_primary_vehicle DESC, year DESC");
    $stmt->execute([$user_id]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tables might not exist yet
    $error = "Database tables not found. Please run the migration first.";
}

// --- CALCULATE STATS FOR EACH VEHICLE ---
foreach ($vehicles as &$v) {
    // Get total service cost
    $stmt = $db->prepare("SELECT COALESCE(SUM(cost_total), 0) as total FROM vehicle_service_logs WHERE vehicle_id = ?");
    $stmt->execute([$v['id']]);
    $v['total_service_cost'] = $stmt->fetchColumn();

    // Get total fuel cost
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_cost), 0) as total, COALESCE(AVG(mpg), 0) as avg_mpg FROM vehicle_fuel_logs WHERE vehicle_id = ? AND mpg > 0");
    $stmt->execute([$v['id']]);
    $fuel = $stmt->fetch();
    $v['total_fuel_cost'] = $fuel['total'];
    $v['avg_mpg'] = $fuel['avg_mpg'];

    // Count services
    $stmt = $db->prepare("SELECT COUNT(*) FROM vehicle_service_logs WHERE vehicle_id = ?");
    $stmt->execute([$v['id']]);
    $v['service_count'] = $stmt->fetchColumn();

    // Get last service
    $stmt = $db->prepare("SELECT service_type, service_date FROM vehicle_service_logs WHERE vehicle_id = ? ORDER BY service_date DESC LIMIT 1");
    $stmt->execute([$v['id']]);
    $v['last_service'] = $stmt->fetch();
}
unset($v);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Vehicle Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .vehicle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .vehicle-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            position: relative;
            transition: all 0.2s ease;
        }

        .vehicle-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.15);
        }

        .vehicle-card.primary {
            border-left: 4px solid var(--primary);
        }

        .vehicle-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .vehicle-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
        }

        .vehicle-subtitle {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-top: 4px;
        }

        .vehicle-nickname {
            background: var(--primary);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .vehicle-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 15px 0;
            padding: 15px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .vehicle-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 15px;
        }

        .vehicle-actions {
            display: flex;
            gap: 8px;
        }

        .vehicle-actions .btn {
            flex: 1;
            text-align: center;
            padding: 8px;
            font-size: 0.85rem;
        }

        .add-vehicle-form {
            display: none;
        }

        .add-vehicle-form.active {
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .form-section {
            background: var(--bg-input);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .form-section h4 {
            margin: 0 0 15px 0;
            color: var(--primary);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-card);
            border: 2px dashed var(--border);
            border-radius: 12px;
        }

        .empty-state h3 {
            margin: 20px 0 10px;
            color: var(--text-main);
        }

        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 20px;
        }

        .cost-badge {
            background: var(--danger-bg);
            color: var(--danger-text);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .cost-badge.fuel {
            background: var(--warning-bg);
            color: var(--warning-text);
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <?php if ($msg): ?>
            <div class="alert" style="border-left:4px solid var(--success-text); margin-bottom:20px;">
                <?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert" style="background:var(--danger-bg); color:var(--danger-text); margin-bottom:20px;">
                <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
            <div>
                <h2 style="margin:0;">🚗 My Vehicles</h2>
                <p style="color:var(--text-muted); margin:5px 0 0;">Track maintenance, fuel, and total cost of ownership
                </p>
            </div>
            <button class="btn" onclick="toggleAddForm()" id="addBtn">+ Add Vehicle</button>
        </div>

        <!-- ADD VEHICLE FORM -->
        <div class="box add-vehicle-form" id="addForm">
            <h3 style="margin-top:0;">Add New Vehicle</h3>
            <form method="post">
                <?= csrf_field() ?>

                <div class="form-section">
                    <h4>🚙 Basic Info</h4>
                    <div class="form-grid">
                        <div>
                            <label>Year *</label>
                            <input type="number" name="year" min="1900" max="2030" value="<?= date('Y') ?>" required>
                        </div>
                        <div>
                            <label>Make *</label>
                            <input type="text" name="make" placeholder="e.g., Toyota" required>
                        </div>
                        <div>
                            <label>Model *</label>
                            <input type="text" name="model" placeholder="e.g., Tacoma" required>
                        </div>
                        <div>
                            <label>Trim</label>
                            <input type="text" name="trim" placeholder="e.g., TRD Off-Road">
                        </div>
                        <div>
                            <label>Nickname</label>
                            <input type="text" name="nickname" placeholder="e.g., Work Truck">
                        </div>
                        <div>
                            <label>Body Style</label>
                            <select name="body_style">
                                <option value="">Select...</option>
                                <option value="Sedan">Sedan</option>
                                <option value="Coupe">Coupe</option>
                                <option value="Hatchback">Hatchback</option>
                                <option value="SUV">SUV</option>
                                <option value="Crossover">Crossover</option>
                                <option value="Truck">Truck</option>
                                <option value="Van">Van</option>
                                <option value="Wagon">Wagon</option>
                                <option value="Convertible">Convertible</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>🔧 Technical Specs</h4>
                    <div class="form-grid">
                        <div>
                            <label>VIN</label>
                            <input type="text" name="vin" maxlength="17" placeholder="17-character VIN">
                        </div>
                        <div>
                            <label>License Plate</label>
                            <input type="text" name="license_plate" placeholder="ABC-1234">
                        </div>
                        <div>
                            <label>State/Province</label>
                            <input type="text" name="state_province" placeholder="e.g., NY">
                        </div>
                        <div>
                            <label>Drivetrain</label>
                            <select name="drivetrain">
                                <option value="FWD">FWD</option>
                                <option value="RWD">RWD</option>
                                <option value="AWD">AWD</option>
                                <option value="4WD">4WD</option>
                            </select>
                        </div>
                        <div>
                            <label>Engine Code</label>
                            <input type="text" name="engine_code" placeholder="e.g., 1GR-FE">
                        </div>
                        <div>
                            <label>Displacement (L)</label>
                            <input type="number" name="engine_displacement" step="0.1" placeholder="e.g., 3.5">
                        </div>
                        <div>
                            <label>Cylinders</label>
                            <input type="number" name="engine_cylinders" min="1" max="16" placeholder="e.g., 6">
                        </div>
                        <div>
                            <label>Transmission</label>
                            <select name="transmission_type">
                                <option value="Automatic">Automatic</option>
                                <option value="Manual">Manual</option>
                                <option value="CVT">CVT</option>
                                <option value="DCT">DCT</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>💵 Purchase & Mileage</h4>
                    <div class="form-grid">
                        <div>
                            <label>Purchase Date</label>
                            <input type="date" name="purchase_date">
                        </div>
                        <div>
                            <label>Purchase Price</label>
                            <input type="number" name="purchase_price" step="0.01" placeholder="$0.00">
                        </div>
                        <div>
                            <label>Purchase Mileage</label>
                            <input type="number" name="purchase_mileage" placeholder="0">
                        </div>
                        <div>
                            <label>Current Mileage</label>
                            <input type="number" name="current_mileage" placeholder="0">
                        </div>
                        <div>
                            <label>Exterior Color</label>
                            <input type="text" name="exterior_color" placeholder="e.g., Silver">
                        </div>
                        <div>
                            <label>Paint Code</label>
                            <input type="text" name="paint_code" placeholder="e.g., 1G3">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>🛞 Tire Info</h4>
                    <div class="form-grid">
                        <div>
                            <label>Tire Size</label>
                            <input type="text" name="oem_tire_size" placeholder="e.g., 265/70R17">
                        </div>
                        <div>
                            <label>Front PSI</label>
                            <input type="number" name="tire_pressure_front" value="32">
                        </div>
                        <div>
                            <label>Rear PSI</label>
                            <input type="number" name="tire_pressure_rear" value="32">
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="is_primary" value="1">
                        <span>Set as primary vehicle</span>
                    </label>
                </div>

                <div>
                    <label>Notes</label>
                    <textarea name="notes" rows="2" placeholder="Any additional notes..."></textarea>
                </div>

                <div style="margin-top:20px; display:flex; gap:10px;">
                    <button type="submit" name="add_vehicle" class="btn">💾 Save Vehicle</button>
                    <button type="button" class="btn btn-secondary" onclick="toggleAddForm()">Cancel</button>
                </div>
            </form>
        </div>

        <!-- VEHICLE CARDS -->
        <?php if (empty($vehicles)): ?>
            <div class="empty-state">
                <div style="font-size:4rem;">🚗</div>
                <h3>No Vehicles Yet</h3>
                <p>Add your first vehicle to start tracking maintenance, fuel economy, and costs.</p>
                <button class="btn" onclick="toggleAddForm()">+ Add Your First Vehicle</button>
            </div>
        <?php else: ?>
            <div class="vehicle-grid">
                <?php foreach ($vehicles as $v): ?>
                    <div class="vehicle-card <?= $v['is_primary_vehicle'] ? 'primary' : '' ?>">
                        <div class="vehicle-header">
                            <div>
                                <h3 class="vehicle-title">
                                    <?= htmlspecialchars($v['year'] . ' ' . $v['make'] . ' ' . $v['model']) ?></h3>
                                <div class="vehicle-subtitle">
                                    <?= htmlspecialchars($v['trim']) ?>
                                    <?php if ($v['engine_displacement']): ?>
                                        • <?= $v['engine_displacement'] ?>L
                                    <?php endif; ?>
                                    <?php if ($v['drivetrain']): ?>
                                        • <?= $v['drivetrain'] ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($v['nickname']): ?>
                                <span class="vehicle-nickname"><?= htmlspecialchars($v['nickname']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="vehicle-meta">
                            <?php if ($v['license_plate']): ?>
                                <span>🪪 <?= htmlspecialchars($v['license_plate']) ?></span>
                            <?php endif; ?>
                            <span>📍 <?= number_format(floatval($v['current_mileage'])) ?> mi</span>
                        </div>

                        <div class="vehicle-stats">
                            <div class="stat-item">
                                <div class="stat-value"><?= $v['service_count'] ?></div>
                                <div class="stat-label">Services</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">
                                    <?= floatval($v['avg_mpg']) > 0 ? number_format(floatval($v['avg_mpg']), 1) : '--' ?></div>
                                <div class="stat-label">Avg MPG</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">
                                    $<?= number_format(floatval($v['total_service_cost']) + floatval($v['total_fuel_cost'])) ?>
                                </div>
                                <div class="stat-label">Total Cost</div>
                            </div>
                        </div>

                        <?php if ($v['last_service']): ?>
                            <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:15px;">
                                Last: <?= htmlspecialchars($v['last_service']['service_type']) ?>
                                (<?= date('M j', strtotime($v['last_service']['service_date'])) ?>)
                            </div>
                        <?php endif; ?>

                        <div class="vehicle-actions">
                            <a href="vehicle_edit.php?id=<?= $v['id'] ?>" class="btn btn-small">✏️ Edit</a>
                            <a href="vehicle_edit.php?id=<?= $v['id'] ?>&tab=service" class="btn btn-small btn-secondary">🔧
                                Service</a>
                            <a href="vehicle_edit.php?id=<?= $v['id'] ?>&tab=fuel" class="btn btn-small btn-secondary">⛽
                                Fuel</a>
                            <a href="?delete=<?= $v['id'] ?>"
                                onclick="return confirm('Delete this vehicle and all its records?')"
                                class="btn btn-small btn-danger">🗑️</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <script>
        function toggleAddForm() {
            const form = document.getElementById('addForm');
            const btn = document.getElementById('addBtn');
            form.classList.toggle('active');
            btn.style.display = form.classList.contains('active') ? 'none' : 'inline-block';
        }
    </script>

</body>

</html>