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
$vehicle_id = $_GET['id'] ?? 0;
$active_tab = $_GET['tab'] ?? 'profile';

// Verify ownership
$stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
$stmt->execute([$vehicle_id, $user_id]);
$vehicle = $stmt->fetch();

if (!$vehicle) {
    header('Location: vehicles.php');
    exit;
}

// Fetch fluids
$stmt = $db->prepare("SELECT * FROM vehicle_fluids WHERE vehicle_id = ?");
$stmt->execute([$vehicle_id]);
$fluids = $stmt->fetch() ?: [];

// Fetch documents
$stmt = $db->prepare("SELECT * FROM vehicle_documents WHERE vehicle_id = ?");
$stmt->execute([$vehicle_id]);
$documents = $stmt->fetch() ?: [];

// --- HANDLE PROFILE UPDATE ---
if (isset($_POST['update_profile'])) {
    try {
        $sql = "UPDATE vehicles SET
            vin = ?, license_plate = ?, state_province = ?, year = ?, make = ?, model = ?, trim = ?,
            body_style = ?, drivetrain = ?, engine_code = ?, engine_displacement = ?, engine_cylinders = ?,
            transmission_code = ?, transmission_type = ?, exterior_color = ?, paint_code = ?,
            oem_tire_size_front = ?, tire_pressure_front_psi = ?, tire_pressure_rear_psi = ?,
            purchase_date = ?, purchase_mileage = ?, purchase_price = ?, current_mileage = ?,
            is_primary_vehicle = ?, nickname = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $_POST['vin'],
            $_POST['license_plate'],
            $_POST['state_province'],
            $_POST['year'],
            $_POST['make'],
            $_POST['model'],
            $_POST['trim'],
            $_POST['body_style'],
            $_POST['drivetrain'],
            $_POST['engine_code'],
            $_POST['engine_displacement'] ?: null,
            $_POST['engine_cylinders'] ?: null,
            $_POST['transmission_code'],
            $_POST['transmission_type'],
            $_POST['exterior_color'],
            $_POST['paint_code'],
            $_POST['oem_tire_size'],
            $_POST['tire_pressure_front'],
            $_POST['tire_pressure_rear'],
            $_POST['purchase_date'] ?: null,
            $_POST['purchase_mileage'],
            $_POST['purchase_price'] ?: null,
            $_POST['current_mileage'],
            isset($_POST['is_primary']) ? 1 : 0,
            $_POST['nickname'],
            $_POST['notes'],
            $vehicle_id
        ]);
        $msg = "✅ Vehicle updated!";

        // Refresh vehicle data
        $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $vehicle = $stmt->fetch();
    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// --- HANDLE FLUIDS UPDATE ---
if (isset($_POST['update_fluids'])) {
    try {
        $sql = "UPDATE vehicle_fluids SET
            oil_type = ?, oil_weight = ?, oil_capacity_qts = ?, oil_brand_preferred = ?,
            oil_filter_part_number = ?, oil_filter_brand = ?, oil_change_interval_miles = ?,
            engine_air_filter_part = ?, engine_air_filter_brand = ?,
            cabin_air_filter_part = ?, cabin_air_filter_brand = ?,
            fuel_filter_part = ?, fuel_type_required = ?, fuel_tank_capacity_gal = ?,
            trans_fluid_type = ?, trans_fluid_capacity_qts = ?, trans_service_interval_miles = ?,
            coolant_type = ?, coolant_color = ?, coolant_capacity_qts = ?,
            brake_fluid_type = ?, power_steering_fluid_type = ?,
            wiper_blade_size_driver = ?, wiper_blade_size_passenger = ?,
            updated_at = CURRENT_TIMESTAMP, notes = ?
            WHERE vehicle_id = ?";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $_POST['oil_type'],
            $_POST['oil_weight'],
            $_POST['oil_capacity_qts'],
            $_POST['oil_brand_preferred'],
            $_POST['oil_filter_part_number'],
            $_POST['oil_filter_brand'],
            $_POST['oil_change_interval_miles'],
            $_POST['engine_air_filter_part'],
            $_POST['engine_air_filter_brand'],
            $_POST['cabin_air_filter_part'],
            $_POST['cabin_air_filter_brand'],
            $_POST['fuel_filter_part'],
            $_POST['fuel_type_required'],
            $_POST['fuel_tank_capacity_gal'],
            $_POST['trans_fluid_type'],
            $_POST['trans_fluid_capacity_qts'],
            $_POST['trans_service_interval_miles'],
            $_POST['coolant_type'],
            $_POST['coolant_color'],
            $_POST['coolant_capacity_qts'],
            $_POST['brake_fluid_type'],
            $_POST['power_steering_fluid_type'],
            $_POST['wiper_blade_size_driver'],
            $_POST['wiper_blade_size_passenger'],
            $_POST['fluids_notes'],
            $vehicle_id
        ]);
        $msg = "✅ Fluids & Filters updated!";

        // Refresh
        $stmt = $db->prepare("SELECT * FROM vehicle_fluids WHERE vehicle_id = ?");
        $stmt->execute([$vehicle_id]);
        $fluids = $stmt->fetch();
    } catch (Exception $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// --- HANDLE ADD SERVICE ---
if (isset($_POST['add_service'])) {
    try {
        $cost_total = floatval($_POST['cost_parts']) + floatval($_POST['cost_labor']) + floatval($_POST['cost_tax']);

        $sql = "INSERT INTO vehicle_service_logs (
            vehicle_id, service_date, mileage, service_type, service_category,
            description, performed_by, shop_name, cost_parts, cost_labor, cost_tax, cost_total,
            warranty_parts_months, warranty_parts_miles, next_due_date, next_due_mileage, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $vehicle_id,
            $_POST['service_date'],
            $_POST['service_mileage'],
            $_POST['service_type'],
            $_POST['service_category'],
            $_POST['service_description'],
            $_POST['performed_by'],
            $_POST['shop_name'],
            $_POST['cost_parts'],
            $_POST['cost_labor'],
            $_POST['cost_tax'],
            $cost_total,
            $_POST['warranty_parts_months'] ?: null,
            $_POST['warranty_parts_miles'] ?: null,
            $_POST['next_due_date'] ?: null,
            $_POST['next_due_mileage'] ?: null,
            $_POST['service_notes']
        ]);

        // Update vehicle mileage if higher
        if ($_POST['service_mileage'] > $vehicle['current_mileage']) {
            $db->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?")->execute([$_POST['service_mileage'], $vehicle_id]);
        }

        $msg = "✅ Service logged!";
    } catch (Exception $e) {
        $error = "Failed to add service: " . $e->getMessage();
    }
}

// --- HANDLE ADD FUEL ---
if (isset($_POST['add_fuel'])) {
    try {
        // Get previous fuel log to calculate MPG
        $stmt = $db->prepare("SELECT odometer FROM vehicle_fuel_logs WHERE vehicle_id = ? ORDER BY fill_date DESC, id DESC LIMIT 1");
        $stmt->execute([$vehicle_id]);
        $prev = $stmt->fetch();
        $prev_odo = $prev ? $prev['odometer'] : 0;

        $current_odo = floatval($_POST['odometer']);
        $gallons = floatval($_POST['gallons']);
        $trip_miles = $prev_odo > 0 ? ($current_odo - $prev_odo) : 0;
        $mpg = ($gallons > 0 && $trip_miles > 0 && isset($_POST['is_full_tank'])) ? ($trip_miles / $gallons) : null;
        $total_cost = $gallons * floatval($_POST['price_per_gallon']);
        $cost_per_mile = ($mpg && $mpg > 0) ? ($_POST['price_per_gallon'] / $mpg) : null;

        $sql = "INSERT INTO vehicle_fuel_logs (
            vehicle_id, fill_date, station_name, odometer, trip_miles, gallons,
            price_per_gallon, total_cost, octane, is_full_tank, mpg, cost_per_mile,
            trip_type, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            $vehicle_id,
            $_POST['fill_date'],
            $_POST['station_name'],
            $current_odo,
            $trip_miles,
            $gallons,
            $_POST['price_per_gallon'],
            $total_cost,
            $_POST['octane'],
            isset($_POST['is_full_tank']) ? 1 : 0,
            $mpg,
            $cost_per_mile,
            $_POST['trip_type'],
            $_POST['fuel_notes']
        ]);

        // Update vehicle mileage
        if ($current_odo > $vehicle['current_mileage']) {
            $db->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?")->execute([$current_odo, $vehicle_id]);
        }

        $msg = "✅ Fuel log added!" . ($mpg ? " (" . number_format($mpg, 1) . " MPG)" : "");
    } catch (Exception $e) {
        $error = "Failed to add fuel log: " . $e->getMessage();
    }
}

// --- DELETE SERVICE ---
if (isset($_GET['delete_service'])) {
    $db->prepare("DELETE FROM vehicle_service_logs WHERE id = ? AND vehicle_id = ?")->execute([$_GET['delete_service'], $vehicle_id]);
    header("Location: vehicle_edit.php?id=$vehicle_id&tab=service");
    exit;
}

// --- DELETE FUEL ---
if (isset($_GET['delete_fuel'])) {
    $db->prepare("DELETE FROM vehicle_fuel_logs WHERE id = ? AND vehicle_id = ?")->execute([$_GET['delete_fuel'], $vehicle_id]);
    header("Location: vehicle_edit.php?id=$vehicle_id&tab=fuel");
    exit;
}

// Fetch service logs
$stmt = $db->prepare("SELECT * FROM vehicle_service_logs WHERE vehicle_id = ? ORDER BY service_date DESC");
$stmt->execute([$vehicle_id]);
$services = $stmt->fetchAll();

// Fetch fuel logs from daily_logs table (linked by user_id)
$fuel_logs = [];
$fuel_weekly = [];
$fuel_monthly = [];
$fuel_yearly = [];
$fuel_stats = ['total_fuel' => 0, 'total_gallons' => 0, 'avg_mpg' => 0, 'total_miles' => 0];

try {
    // Get fuel data from daily_logs (existing truck log data)
    $stmt = $db->prepare("SELECT log_date, odometer, mileage, gallons, fuel_cost 
                          FROM daily_logs 
                          WHERE user_id = ? AND (gallons > 0 OR fuel_cost > 0)
                          ORDER BY log_date DESC LIMIT 50");
    $stmt->execute([$user_id]);
    $daily_fuel = $stmt->fetchAll();

    // Calculate stats from daily_logs
    $stmt = $db->prepare("SELECT 
                            SUM(fuel_cost) as total_fuel, 
                            SUM(gallons) as total_gallons,
                            SUM(mileage) as total_miles,
                            MAX(odometer) as latest_odo
                          FROM daily_logs 
                          WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();

    $fuel_stats['total_fuel'] = floatval($stats['total_fuel'] ?? 0);
    $fuel_stats['total_gallons'] = floatval($stats['total_gallons'] ?? 0);
    $fuel_stats['total_miles'] = floatval($stats['total_miles'] ?? 0);

    // Calculate average MPG
    if ($fuel_stats['total_gallons'] > 0 && $fuel_stats['total_miles'] > 0) {
        $fuel_stats['avg_mpg'] = $fuel_stats['total_miles'] / $fuel_stats['total_gallons'];
    }

    // Update vehicle's current mileage from latest odometer reading
    $latest_odo = floatval($stats['latest_odo'] ?? 0);
    if ($latest_odo > floatval($vehicle['current_mileage'])) {
        $db->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?")->execute([$latest_odo, $vehicle_id]);
        $vehicle['current_mileage'] = $latest_odo;
    }

    // Format daily logs for display
    foreach ($daily_fuel as $df) {
        $mpg = ($df['gallons'] > 0 && $df['mileage'] > 0) ? ($df['mileage'] / $df['gallons']) : null;
        $cost_per_gal = ($df['gallons'] > 0) ? ($df['fuel_cost'] / $df['gallons']) : null;

        $fuel_logs[] = [
            'fill_date' => $df['log_date'],
            'odometer' => $df['odometer'],
            'gallons' => $df['gallons'],
            'price_per_gallon' => $cost_per_gal,
            'total_cost' => $df['fuel_cost'],
            'mpg' => $mpg,
            'trip_miles' => $df['mileage'],
            'source' => 'daily_log'
        ];
    }
    
    // Weekly aggregations (using strftime for SQLite)
    $stmt = $db->prepare("SELECT 
                            strftime('%Y-%W', log_date) as week_key,
                            MIN(log_date) as week_start,
                            SUM(mileage) as miles,
                            SUM(gallons) as gallons,
                            SUM(fuel_cost) as cost
                          FROM daily_logs 
                          WHERE user_id = ? AND (gallons > 0 OR mileage > 0)
                          GROUP BY strftime('%Y-%W', log_date)
                          ORDER BY week_key DESC
                          LIMIT 12");
    $stmt->execute([$user_id]);
    $weekly_data = $stmt->fetchAll();
    foreach ($weekly_data as $w) {
        $mpg = ($w['gallons'] > 0) ? ($w['miles'] / $w['gallons']) : 0;
        $fuel_weekly[] = [
            'period' => 'Week of ' . date('M j', strtotime($w['week_start'])),
            'miles' => floatval($w['miles']),
            'gallons' => floatval($w['gallons']),
            'cost' => floatval($w['cost']),
            'mpg' => $mpg
        ];
    }
    
    // Monthly aggregations
    $stmt = $db->prepare("SELECT 
                            strftime('%Y-%m', log_date) as month_key,
                            SUM(mileage) as miles,
                            SUM(gallons) as gallons,
                            SUM(fuel_cost) as cost
                          FROM daily_logs 
                          WHERE user_id = ? AND (gallons > 0 OR mileage > 0)
                          GROUP BY strftime('%Y-%m', log_date)
                          ORDER BY month_key DESC
                          LIMIT 12");
    $stmt->execute([$user_id]);
    $monthly_data = $stmt->fetchAll();
    foreach ($monthly_data as $m) {
        $mpg = ($m['gallons'] > 0) ? ($m['miles'] / $m['gallons']) : 0;
        $fuel_monthly[] = [
            'period' => date('F Y', strtotime($m['month_key'] . '-01')),
            'miles' => floatval($m['miles']),
            'gallons' => floatval($m['gallons']),
            'cost' => floatval($m['cost']),
            'mpg' => $mpg
        ];
    }
    
    // Yearly aggregations
    $stmt = $db->prepare("SELECT 
                            strftime('%Y', log_date) as year_key,
                            SUM(mileage) as miles,
                            SUM(gallons) as gallons,
                            SUM(fuel_cost) as cost
                          FROM daily_logs 
                          WHERE user_id = ? AND (gallons > 0 OR mileage > 0)
                          GROUP BY strftime('%Y', log_date)
                          ORDER BY year_key DESC
                          LIMIT 5");
    $stmt->execute([$user_id]);
    $yearly_data = $stmt->fetchAll();
    foreach ($yearly_data as $y) {
        $mpg = ($y['gallons'] > 0) ? ($y['miles'] / $y['gallons']) : 0;
        $fuel_yearly[] = [
            'period' => $y['year_key'],
            'miles' => floatval($y['miles']),
            'gallons' => floatval($y['gallons']),
            'cost' => floatval($y['cost']),
            'mpg' => $mpg
        ];
    }
    
} catch (Exception $e) {
    // daily_logs table might not have expected columns
}
?>



<!DOCTYPE html>
<html lang="en">

<head>
    <title>Edit Vehicle -
        <?= htmlspecialchars($vehicle['year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model']) ?>
    </title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .tabs {
            display: flex;
            gap: 5px;
            border-bottom: 2px solid var(--border);
            margin-bottom: 25px;
            overflow-x: auto;
        }

        .tab {
            padding: 12px 20px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            white-space: nowrap;
            text-decoration: none;
        }

        .tab:hover {
            color: var(--text-main);
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .service-card,
        .fuel-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }

        .service-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .service-type {
            font-weight: 700;
            font-size: 1rem;
        }

        .service-cost {
            font-weight: 700;
            color: var(--danger-text);
        }

        .service-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: var(--bg-card);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid var(--border);
        }

        .stat-box .value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
        }

        .stat-box .label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        @media (max-width: 600px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <div style="margin-bottom:20px;">
            <a href="vehicles.php" class="btn btn-small btn-secondary">&larr; Back to Vehicles</a>
        </div>

        <?php if ($msg): ?>
            <div class="alert" style="border-left:4px solid var(--success-text); margin-bottom:20px;">
                <?= $msg ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert" style="background:var(--danger-bg); color:var(--danger-text); margin-bottom:20px;">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <div style="margin-bottom:25px;">
            <h2 style="margin:0;">
                <?= htmlspecialchars($vehicle['year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model']) ?>
            </h2>
            <p style="color:var(--text-muted); margin:5px 0 0;">
                <?= htmlspecialchars($vehicle['trim']) ?>
                <?php if ($vehicle['nickname']): ?> • "
                    <?= htmlspecialchars($vehicle['nickname']) ?>"
                <?php endif; ?>
                •
                <?= number_format(floatval($vehicle['current_mileage'])) ?> miles
            </p>
        </div>

        <div class="tabs">
            <a href="?id=<?= $vehicle_id ?>&tab=profile" class="tab <?= $active_tab == 'profile' ? 'active' : '' ?>">📋
                Profile</a>
            <a href="?id=<?= $vehicle_id ?>&tab=fluids" class="tab <?= $active_tab == 'fluids' ? 'active' : '' ?>">🛢️
                Fluids</a>
            <a href="?id=<?= $vehicle_id ?>&tab=service" class="tab <?= $active_tab == 'service' ? 'active' : '' ?>">🔧
                Service</a>
            <a href="?id=<?= $vehicle_id ?>&tab=fuel" class="tab <?= $active_tab == 'fuel' ? 'active' : '' ?>">⛽
                Fuel</a>
            <a href="?id=<?= $vehicle_id ?>&tab=docs" class="tab <?= $active_tab == 'docs' ? 'active' : '' ?>">📄
                Docs</a>
        </div>

        <!-- PROFILE TAB -->
        <div class="tab-content <?= $active_tab == 'profile' ? 'active' : '' ?>">
            <form method="post">
                <?= csrf_field() ?>

                <div class="form-section">
                    <h4>🚙 Basic Info</h4>
                    <div class="form-grid">
                        <div><label>Year</label><input type="number" name="year" value="<?= $vehicle['year'] ?>"
                                required></div>
                        <div><label>Make</label><input type="text" name="make"
                                value="<?= htmlspecialchars($vehicle['make']) ?>" required></div>
                        <div><label>Model</label><input type="text" name="model"
                                value="<?= htmlspecialchars($vehicle['model']) ?>" required></div>
                        <div><label>Trim</label><input type="text" name="trim"
                                value="<?= htmlspecialchars($vehicle['trim']) ?>"></div>
                        <div><label>Nickname</label><input type="text" name="nickname"
                                value="<?= htmlspecialchars($vehicle['nickname']) ?>"></div>
                        <div><label>Body Style</label>
                            <select name="body_style">
                                <option value="">Select...</option>
                                <?php foreach (['Sedan', 'Coupe', 'Hatchback', 'SUV', 'Crossover', 'Truck', 'Van', 'Wagon', 'Convertible'] as $b): ?>
                                    <option value="<?= $b ?>" <?= $vehicle['body_style'] == $b ? 'selected' : '' ?>>
                                        <?= $b ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>🔧 Technical</h4>
                    <div class="form-grid">
                        <div><label>VIN</label><input type="text" name="vin"
                                value="<?= htmlspecialchars($vehicle['vin']) ?>" maxlength="17"></div>
                        <div><label>License Plate</label><input type="text" name="license_plate"
                                value="<?= htmlspecialchars($vehicle['license_plate']) ?>"></div>
                        <div><label>State</label><input type="text" name="state_province"
                                value="<?= htmlspecialchars($vehicle['state_province']) ?>"></div>
                        <div><label>Drivetrain</label>
                            <select name="drivetrain">
                                <?php foreach (['FWD', 'RWD', 'AWD', '4WD'] as $d): ?>
                                    <option value="<?= $d ?>" <?= $vehicle['drivetrain'] == $d ? 'selected' : '' ?>>
                                        <?= $d ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Engine Code</label><input type="text" name="engine_code"
                                value="<?= htmlspecialchars($vehicle['engine_code']) ?>"></div>
                        <div><label>Displacement (L)</label><input type="number" name="engine_displacement" step="0.1"
                                value="<?= $vehicle['engine_displacement'] ?>"></div>
                        <div><label>Cylinders</label><input type="number" name="engine_cylinders"
                                value="<?= $vehicle['engine_cylinders'] ?>"></div>
                        <div><label>Trans Code</label><input type="text" name="transmission_code"
                                value="<?= htmlspecialchars($vehicle['transmission_code']) ?>"></div>
                        <div><label>Trans Type</label>
                            <select name="transmission_type">
                                <?php foreach (['Automatic', 'Manual', 'CVT', 'DCT'] as $t): ?>
                                    <option value="<?= $t ?>" <?= $vehicle['transmission_type'] == $t ? 'selected' : '' ?>>
                                        <?= $t ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Color</label><input type="text" name="exterior_color"
                                value="<?= htmlspecialchars($vehicle['exterior_color']) ?>"></div>
                        <div><label>Paint Code</label><input type="text" name="paint_code"
                                value="<?= htmlspecialchars($vehicle['paint_code']) ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>💵 Ownership</h4>
                    <div class="form-grid">
                        <div><label>Purchase Date</label><input type="date" name="purchase_date"
                                value="<?= $vehicle['purchase_date'] ?>"></div>
                        <div><label>Purchase Price</label><input type="number" name="purchase_price" step="0.01"
                                value="<?= $vehicle['purchase_price'] ?>"></div>
                        <div><label>Purchase Mileage</label><input type="number" name="purchase_mileage"
                                value="<?= $vehicle['purchase_mileage'] ?>"></div>
                        <div><label>Current Mileage</label><input type="number" name="current_mileage"
                                value="<?= $vehicle['current_mileage'] ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>🛞 Tires</h4>
                    <div class="form-grid">
                        <div><label>Tire Size</label><input type="text" name="oem_tire_size"
                                value="<?= htmlspecialchars($vehicle['oem_tire_size_front']) ?>"></div>
                        <div><label>Front PSI</label><input type="number" name="tire_pressure_front"
                                value="<?= $vehicle['tire_pressure_front_psi'] ?>"></div>
                        <div><label>Rear PSI</label><input type="number" name="tire_pressure_rear"
                                value="<?= $vehicle['tire_pressure_rear_psi'] ?>"></div>
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:flex; align-items:center; gap:10px;">
                        <input type="checkbox" name="is_primary" <?= $vehicle['is_primary_vehicle'] ? 'checked' : '' ?>>
                        Primary vehicle
                    </label>
                </div>

                <div><label>Notes</label><textarea name="notes"
                        rows="2"><?= htmlspecialchars($vehicle['notes']) ?></textarea></div>

                <div style="margin-top:20px;">
                    <button type="submit" name="update_profile" class="btn">💾 Save Changes</button>
                </div>
            </form>
        </div>

        <!-- FLUIDS TAB -->
        <div class="tab-content <?= $active_tab == 'fluids' ? 'active' : '' ?>">
            <form method="post">
                <?= csrf_field() ?>

                <div class="form-section">
                    <h4>🛢️ Engine Oil</h4>
                    <div class="form-grid">
                        <div><label>Oil Type</label>
                            <select name="oil_type">
                                <option value="">Select...</option>
                                <?php foreach (['Conventional', 'Synthetic Blend', 'Full Synthetic', 'High Mileage'] as $o): ?>
                                    <option value="<?= $o ?>" <?= ($fluids['oil_type'] ?? '') == $o ? 'selected' : '' ?>>
                                        <?= $o ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Oil Weight</label><input type="text" name="oil_weight" placeholder="e.g., 5W-30"
                                value="<?= htmlspecialchars($fluids['oil_weight'] ?? '') ?>"></div>
                        <div><label>Capacity (qts)</label><input type="number" name="oil_capacity_qts" step="0.1"
                                value="<?= $fluids['oil_capacity_qts'] ?? '' ?>"></div>
                        <div><label>Preferred Brand</label><input type="text" name="oil_brand_preferred"
                                placeholder="e.g., Mobil 1"
                                value="<?= htmlspecialchars($fluids['oil_brand_preferred'] ?? '') ?>"></div>
                        <div><label>Oil Filter Part #</label><input type="text" name="oil_filter_part_number"
                                placeholder="e.g., K&N HP-1010"
                                value="<?= htmlspecialchars($fluids['oil_filter_part_number'] ?? '') ?>"></div>
                        <div><label>Oil Filter Brand</label><input type="text" name="oil_filter_brand"
                                value="<?= htmlspecialchars($fluids['oil_filter_brand'] ?? '') ?>"></div>
                        <div><label>Change Interval (mi)</label><input type="number" name="oil_change_interval_miles"
                                value="<?= $fluids['oil_change_interval_miles'] ?? 5000 ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>🌬️ Filters</h4>
                    <div class="form-grid">
                        <div><label>Engine Air Filter #</label><input type="text" name="engine_air_filter_part"
                                value="<?= htmlspecialchars($fluids['engine_air_filter_part'] ?? '') ?>"></div>
                        <div><label>Air Filter Brand</label><input type="text" name="engine_air_filter_brand"
                                value="<?= htmlspecialchars($fluids['engine_air_filter_brand'] ?? '') ?>"></div>
                        <div><label>Cabin Air Filter #</label><input type="text" name="cabin_air_filter_part"
                                value="<?= htmlspecialchars($fluids['cabin_air_filter_part'] ?? '') ?>"></div>
                        <div><label>Cabin Filter Brand</label><input type="text" name="cabin_air_filter_brand"
                                value="<?= htmlspecialchars($fluids['cabin_air_filter_brand'] ?? '') ?>"></div>
                        <div><label>Fuel Filter #</label><input type="text" name="fuel_filter_part"
                                value="<?= htmlspecialchars($fluids['fuel_filter_part'] ?? '') ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>⛽ Fuel System</h4>
                    <div class="form-grid">
                        <div><label>Required Fuel</label>
                            <select name="fuel_type_required">
                                <?php foreach (['Regular 87', 'Mid-Grade 89', 'Premium 91+', 'Diesel', 'E85 Flex'] as $f): ?>
                                    <option value="<?= $f ?>" <?= ($fluids['fuel_type_required'] ?? '') == $f ? 'selected' : '' ?>>
                                        <?= $f ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Tank Size (gal)</label><input type="number" name="fuel_tank_capacity_gal" step="0.1"
                                value="<?= $fluids['fuel_tank_capacity_gal'] ?? '' ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>⚙️ Transmission</h4>
                    <div class="form-grid">
                        <div><label>Fluid Type</label><input type="text" name="trans_fluid_type"
                                placeholder="e.g., Dexron VI"
                                value="<?= htmlspecialchars($fluids['trans_fluid_type'] ?? '') ?>"></div>
                        <div><label>Capacity (qts)</label><input type="number" name="trans_fluid_capacity_qts"
                                step="0.1" value="<?= $fluids['trans_fluid_capacity_qts'] ?? '' ?>"></div>
                        <div><label>Service Interval (mi)</label><input type="number"
                                name="trans_service_interval_miles"
                                value="<?= $fluids['trans_service_interval_miles'] ?? 60000 ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>❄️ Cooling & Brakes</h4>
                    <div class="form-grid">
                        <div><label>Coolant Type</label><input type="text" name="coolant_type"
                                placeholder="e.g., Toyota Long Life"
                                value="<?= htmlspecialchars($fluids['coolant_type'] ?? '') ?>"></div>
                        <div><label>Coolant Color</label>
                            <select name="coolant_color">
                                <option value="">Select...</option>
                                <?php foreach (['Green', 'Orange/Dexcool', 'Pink', 'Blue', 'Yellow'] as $c): ?>
                                    <option value="<?= $c ?>" <?= ($fluids['coolant_color'] ?? '') == $c ? 'selected' : '' ?>>
                                        <?= $c ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Coolant Capacity (qts)</label><input type="number" name="coolant_capacity_qts"
                                step="0.1" value="<?= $fluids['coolant_capacity_qts'] ?? '' ?>"></div>
                        <div><label>Brake Fluid</label>
                            <select name="brake_fluid_type">
                                <?php foreach (['DOT 3', 'DOT 4', 'DOT 5', 'DOT 5.1'] as $b): ?>
                                    <option value="<?= $b ?>" <?= ($fluids['brake_fluid_type'] ?? '') == $b ? 'selected' : '' ?>>
                                        <?= $b ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label>Power Steering Fluid</label><input type="text" name="power_steering_fluid_type"
                                value="<?= htmlspecialchars($fluids['power_steering_fluid_type'] ?? '') ?>"></div>
                    </div>
                </div>

                <div class="form-section">
                    <h4>🧹 Wipers</h4>
                    <div class="form-grid">
                        <div><label>Driver Blade Size</label><input type="text" name="wiper_blade_size_driver"
                                placeholder='e.g., 24"'
                                value="<?= htmlspecialchars($fluids['wiper_blade_size_driver'] ?? '') ?>"></div>
                        <div><label>Passenger Blade Size</label><input type="text" name="wiper_blade_size_passenger"
                                placeholder='e.g., 18"'
                                value="<?= htmlspecialchars($fluids['wiper_blade_size_passenger'] ?? '') ?>"></div>
                    </div>
                </div>

                <div><label>Notes</label><textarea name="fluids_notes"
                        rows="2"><?= htmlspecialchars($fluids['notes'] ?? '') ?></textarea></div>

                <div style="margin-top:20px;">
                    <button type="submit" name="update_fluids" class="btn">💾 Save Fluids & Filters</button>
                </div>
            </form>
        </div>

        <!-- SERVICE TAB -->
        <div class="tab-content <?= $active_tab == 'service' ? 'active' : '' ?>">

            <div class="box" style="margin-bottom:25px;">
                <h3 style="margin-top:0;">➕ Log Service</h3>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div><label>Date *</label><input type="date" name="service_date" value="<?= date('Y-m-d') ?>"
                                required></div>
                        <div><label>Mileage *</label><input type="number" name="service_mileage"
                                value="<?= $vehicle['current_mileage'] ?>" required></div>
                        <div><label>Service Type *</label>
                            <select name="service_type" required>
                                <option value="">Select...</option>
                                <option value="Oil Change">Oil Change</option>
                                <option value="Tire Rotation">Tire Rotation</option>
                                <option value="Tire Replacement">Tire Replacement</option>
                                <option value="Brake Service">Brake Service</option>
                                <option value="Transmission Service">Transmission Service</option>
                                <option value="Coolant Flush">Coolant Flush</option>
                                <option value="Air Filter">Air Filter</option>
                                <option value="Cabin Filter">Cabin Filter</option>
                                <option value="Spark Plugs">Spark Plugs</option>
                                <option value="Battery Replacement">Battery</option>
                                <option value="Alignment">Alignment</option>
                                <option value="Inspection">Inspection</option>
                                <option value="Unscheduled Repair">Unscheduled Repair</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div><label>Category</label>
                            <select name="service_category">
                                <option value="Scheduled">Scheduled</option>
                                <option value="Repair">Repair</option>
                                <option value="Upgrade">Upgrade</option>
                                <option value="Inspection">Inspection</option>
                            </select>
                        </div>
                        <div><label>Performed By</label>
                            <select name="performed_by">
                                <option value="Self">Self (DIY)</option>
                                <option value="Dealer">Dealer</option>
                                <option value="Independent Shop">Independent Shop</option>
                                <option value="Chain Shop">Chain Shop</option>
                            </select>
                        </div>
                        <div><label>Shop Name</label><input type="text" name="shop_name" placeholder="Optional"></div>
                    </div>
                    <div class="form-grid" style="margin-top:15px;">
                        <div><label>Parts Cost</label><input type="text" name="cost_parts" class="money-input"
                                value="$0.00">
                        </div>
                        <div><label>Labor Cost</label><input type="text" name="cost_labor" class="money-input"
                                value="$0.00">
                        </div>
                        <div><label>Tax</label><input type="text" name="cost_tax" class="money-input" value="$0.00">
                        </div>
                        <div><label>Warranty (months)</label><input type="number" name="warranty_parts_months"
                                placeholder="0"></div>
                        <div><label>Warranty (miles)</label><input type="number" name="warranty_parts_miles"
                                placeholder="0"></div>
                        <div><label>Next Due Date</label><input type="date" name="next_due_date"></div>
                        <div><label>Next Due Mileage</label><input type="number" name="next_due_mileage"></div>
                    </div>
                    <div style="margin-top:15px;">
                        <label>Description</label><textarea name="service_description" rows="2"
                            placeholder="What was done?"></textarea>
                    </div>
                    <div><label>Notes</label><textarea name="service_notes" rows="2"
                            placeholder="Parts used, observations..."></textarea></div>
                    <div style="margin-top:15px;">
                        <button type="submit" name="add_service" class="btn">💾 Log Service</button>
                    </div>
                </form>
            </div>

            <h3>📋 Service History</h3>
            <?php if (empty($services)): ?>
                <p style="color:var(--text-muted); text-align:center; padding:40px;">No services logged yet.</p>
            <?php else: ?>
                <?php foreach ($services as $s): ?>
                    <div class="service-card">
                        <div class="service-header">
                            <div>
                                <div class="service-type">
                                    <?= htmlspecialchars($s['service_type']) ?>
                                </div>
                                <div class="service-meta">
                                    <span>📅
                                        <?= date('M j, Y', strtotime($s['service_date'])) ?>
                                    </span>
                                    <span>📍
                                        <?= number_format($s['mileage']) ?> mi
                                    </span>
                                    <span>👷
                                        <?= htmlspecialchars($s['performed_by']) ?>
                                    </span>
                                    <?php if ($s['shop_name']): ?><span>@
                                            <?= htmlspecialchars($s['shop_name']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div class="service-cost">$
                                    <?= number_format($s['cost_total'], 2) ?>
                                </div>
                                <a href="?id=<?= $vehicle_id ?>&tab=service&delete_service=<?= $s['id'] ?>"
                                    onclick="return confirm('Delete this service?')"
                                    style="font-size:0.8rem; color:var(--danger-text);">Delete</a>
                            </div>
                        </div>
                        <?php if ($s['description']): ?>
                            <div style="font-size:0.9rem; margin-top:10px;">
                                <?= nl2br(htmlspecialchars($s['description'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($s['next_due_date'] || $s['next_due_mileage']): ?>
                            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:10px;">
                                Next due:
                                <?= $s['next_due_date'] ? date('M j, Y', strtotime($s['next_due_date'])) : '' ?>
                                <?= $s['next_due_mileage'] ? '@ ' . number_format($s['next_due_mileage']) . ' mi' : '' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- FUEL TAB -->
        <div class="tab-content <?= $active_tab == 'fuel' ? 'active' : '' ?>">

            <div class="stat-grid">
                <div class="stat-box">
                    <div class="value">
                        <?= floatval($fuel_stats['avg_mpg']) > 0 ? number_format(floatval($fuel_stats['avg_mpg']), 1) : '--' ?>
                    </div>
                    <div class="label">Avg MPG</div>
                </div>
                <div class="stat-box">
                    <div class="value">$<?= number_format(floatval($fuel_stats['total_fuel'])) ?></div>
                    <div class="label">Total Fuel Cost</div>
                </div>
                <div class="stat-box">
                    <div class="value"><?= number_format(floatval($fuel_stats['total_gallons']), 1) ?></div>
                    <div class="label">Gallons</div>
                </div>
                <div class="stat-box">
                    <div class="value"><?= number_format(floatval($fuel_stats['total_miles'])) ?></div>
                    <div class="label">Total Miles</div>
                </div>
            </div>

            <div class="alert"
                style="background: var(--bg-input); border-left: 4px solid var(--primary); margin-bottom: 25px;">
                <strong>💡 Fuel data synced from Daily Truck Logs</strong><br>
                <span style="color: var(--text-muted);">
                    Add fuel info via your daily job entry form on the main page.
                    Odometer, mileage, gallons, and fuel cost are automatically tracked here.
                </span>
                <div style="margin-top: 10px;">
                    <a href="index.php" class="btn btn-small">→ Go to Daily Entry</a>
                </div>
            </div>


            <h3>📋 Fuel History</h3>
            
            <!-- Period Toggle -->
            <div style="display:flex; gap:5px; margin-bottom:15px;">
                <button class="btn btn-small fuel-period-btn active" onclick="showFuelPeriod('weekly')">Weekly</button>
                <button class="btn btn-small btn-secondary fuel-period-btn" onclick="showFuelPeriod('monthly')">Monthly</button>
                <button class="btn btn-small btn-secondary fuel-period-btn" onclick="showFuelPeriod('yearly')">Yearly</button>
                <button class="btn btn-small btn-secondary fuel-period-btn" onclick="showFuelPeriod('daily')">Daily</button>
            </div>
            
            <!-- Weekly View (Default) -->
            <div id="fuel-weekly" class="fuel-period-view">
                <?php if (empty($fuel_weekly)): ?>
                    <p style="color:var(--text-muted); text-align:center; padding:40px;">No weekly data yet.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse:collapse;">
                        <tr style="text-align:left; color:var(--text-muted); border-bottom:2px solid var(--border);">
                            <th style="padding:10px;">Week</th><th>Miles</th><th>Gallons</th><th>Cost</th><th>MPG</th>
                        </tr>
                        <?php foreach ($fuel_weekly as $w): ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:10px;"><?= htmlspecialchars($w['period']) ?></td>
                                <td><?= number_format($w['miles']) ?></td>
                                <td><?= number_format($w['gallons'], 1) ?></td>
                                <td style="font-weight:bold;">$<?= number_format($w['cost'], 2) ?></td>
                                <td style="color:var(--primary); font-weight:bold;"><?= $w['mpg'] > 0 ? number_format($w['mpg'], 1) : '--' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Monthly View -->
            <div id="fuel-monthly" class="fuel-period-view" style="display:none;">
                <?php if (empty($fuel_monthly)): ?>
                    <p style="color:var(--text-muted); text-align:center; padding:40px;">No monthly data yet.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse:collapse;">
                        <tr style="text-align:left; color:var(--text-muted); border-bottom:2px solid var(--border);">
                            <th style="padding:10px;">Month</th><th>Miles</th><th>Gallons</th><th>Cost</th><th>MPG</th>
                        </tr>
                        <?php foreach ($fuel_monthly as $m): ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:10px;"><?= htmlspecialchars($m['period']) ?></td>
                                <td><?= number_format($m['miles']) ?></td>
                                <td><?= number_format($m['gallons'], 1) ?></td>
                                <td style="font-weight:bold;">$<?= number_format($m['cost'], 2) ?></td>
                                <td style="color:var(--primary); font-weight:bold;"><?= $m['mpg'] > 0 ? number_format($m['mpg'], 1) : '--' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Yearly View -->
            <div id="fuel-yearly" class="fuel-period-view" style="display:none;">
                <?php if (empty($fuel_yearly)): ?>
                    <p style="color:var(--text-muted); text-align:center; padding:40px;">No yearly data yet.</p>
                <?php else: ?>
                    <table style="width:100%; border-collapse:collapse;">
                        <tr style="text-align:left; color:var(--text-muted); border-bottom:2px solid var(--border);">
                            <th style="padding:10px;">Year</th><th>Miles</th><th>Gallons</th><th>Cost</th><th>MPG</th>
                        </tr>
                        <?php foreach ($fuel_yearly as $y): ?>
                            <tr style="border-bottom:1px solid var(--border);">
                                <td style="padding:10px; font-weight:bold;"><?= htmlspecialchars($y['period']) ?></td>
                                <td><?= number_format($y['miles']) ?></td>
                                <td><?= number_format($y['gallons'], 1) ?></td>
                                <td style="font-weight:bold;">$<?= number_format($y['cost'], 2) ?></td>
                                <td style="color:var(--primary); font-weight:bold;"><?= $y['mpg'] > 0 ? number_format($y['mpg'], 1) : '--' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Daily View -->
            <div id="fuel-daily" class="fuel-period-view" style="display:none;">
            <?php if (empty($fuel_logs)): ?>
                <p style="color:var(--text-muted); text-align:center; padding:40px;">No fuel logs yet. Add fuel data in your daily truck log.</p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse;">
                    <tr style="text-align:left; color:var(--text-muted); border-bottom:2px solid var(--border);">
                        <th style="padding:10px;">Date</th>
                        <th>Odometer</th>
                        <th>Miles</th>
                        <th>Gallons</th>
                        <th>Cost</th>
                        <th>MPG</th>
                    </tr>
                    <?php foreach ($fuel_logs as $f): ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:10px;"><?= date('M j', strtotime($f['fill_date'])) ?></td>
                            <td><?= number_format(floatval($f['odometer'])) ?></td>
                            <td><?= floatval($f['trip_miles']) > 0 ? number_format(floatval($f['trip_miles'])) : '--' ?></td>
                            <td><?= floatval($f['gallons']) > 0 ? number_format(floatval($f['gallons']), 2) : '--' ?></td>
                            <td style="font-weight:bold;">$<?= number_format(floatval($f['total_cost']), 2) ?></td>
                            <td style="color:var(--primary); font-weight:bold;">
                                <?= floatval($f['mpg']) > 0 ? number_format(floatval($f['mpg']), 1) : '--' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            </div><!-- end fuel-daily -->
        </div><!-- end fuel tab -->

        <!-- DOCS TAB -->
        <div class="tab-content <?= $active_tab == 'docs' ? 'active' : '' ?>">
            <div class="form-section">
                <h4>🛡️ Insurance</h4>
                <div class="form-grid">
                    <div><label>Provider</label><input type="text"
                            value="<?= htmlspecialchars($documents['insurance_provider'] ?? '') ?>" disabled></div>
                    <div><label>Policy #</label><input type="text"
                            value="<?= htmlspecialchars($documents['insurance_policy_number'] ?? '') ?>" disabled></div>
                    <div><label>Expiry</label><input type="text"
                            value="<?= $documents['insurance_expiry_date'] ?? 'Not set' ?>" disabled></div>
                </div>
            </div>

            <div class="form-section">
                <h4>📋 Registration</h4>
                <div class="form-grid">
                    <div><label>State</label><input type="text"
                            value="<?= htmlspecialchars($documents['registration_state'] ?? $vehicle['state_province'] ?? '') ?>"
                            disabled></div>
                    <div><label>Expiry</label><input type="text"
                            value="<?= $documents['registration_expiry_date'] ?? 'Not set' ?>" disabled></div>
                </div>
            </div>

            <p style="color:var(--text-muted); text-align:center; padding:20px;">
                Document management coming soon. For now, you can store key info in vehicle notes.
            </p>
        </div>

    </div>

</body>

<script>
    // Auto-format money inputs on blur
    document.querySelectorAll('.money-input').forEach(function (input) {
        input.addEventListener('blur', function () {
            // Strip non-numeric except decimal
            let val = this.value.replace(/[^0-9.]/g, '');
            let num = parseFloat(val) || 0;
            this.value = '$' + num.toFixed(2);
        });
        input.addEventListener('focus', function () {
            // Strip $ for easier editing
            let val = this.value.replace(/[^0-9.]/g, '');
            this.value = val;
            this.select();
        });
    });
    
    // Toggle fuel period views
    function showFuelPeriod(period) {
        // Hide all views
        document.querySelectorAll('.fuel-period-view').forEach(v => v.style.display = 'none');
        // Show selected view
        document.getElementById('fuel-' + period).style.display = 'block';
        // Update button states
        document.querySelectorAll('.fuel-period-btn').forEach(btn => {
            btn.classList.remove('active');
            btn.classList.add('btn-secondary');
        });
        event.target.classList.add('active');
        event.target.classList.remove('btn-secondary');
    }
</script>

</html>