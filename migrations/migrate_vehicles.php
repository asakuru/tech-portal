<?php
/**
 * Vehicle Management Database Migration
 * Run this once to create necessary tables
 */
require_once 'config.php';

$db = getDB();

$migrations = [
    // VEHICLES - Core vehicle profile
    "CREATE TABLE IF NOT EXISTS vehicles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        
        -- Identification
        vin TEXT,
        license_plate TEXT,
        state_province TEXT,
        
        -- Core Specs
        year INTEGER,
        make TEXT NOT NULL,
        model TEXT NOT NULL,
        trim TEXT,
        body_style TEXT,
        drivetrain TEXT CHECK(drivetrain IN ('FWD', 'RWD', 'AWD', '4WD')),
        
        -- Technical Codes
        engine_code TEXT,
        engine_displacement REAL,
        engine_cylinders INTEGER,
        engine_aspiration TEXT DEFAULT 'Natural',
        transmission_code TEXT,
        transmission_type TEXT DEFAULT 'Automatic',
        transmission_speeds INTEGER,
        
        -- Exterior
        exterior_color TEXT,
        paint_code TEXT,
        interior_color TEXT,
        interior_material TEXT,
        
        -- Tires
        oem_tire_size_front TEXT,
        oem_tire_size_rear TEXT,
        tire_pressure_front_psi INTEGER,
        tire_pressure_rear_psi INTEGER,
        spare_tire_size TEXT,
        spare_tire_pressure_psi INTEGER,
        wheel_torque_spec_ftlb INTEGER,
        
        -- Ownership
        purchase_date TEXT,
        purchase_mileage INTEGER,
        purchase_price REAL,
        seller_type TEXT,
        is_primary_vehicle INTEGER DEFAULT 0,
        nickname TEXT,
        
        -- Status
        current_mileage INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        sold_date TEXT,
        sold_price REAL,
        sold_mileage INTEGER,
        
        -- Meta
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        
        FOREIGN KEY (user_id) REFERENCES users(id)
    )",

    // VEHICLE FLUIDS - Fluid specs and filter part numbers
    "CREATE TABLE IF NOT EXISTS vehicle_fluids (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL UNIQUE,
        
        -- Engine Oil
        oil_type TEXT,
        oil_weight TEXT,
        oil_capacity_qts REAL,
        oil_capacity_wo_filter_qts REAL,
        oil_brand_preferred TEXT,
        oil_filter_part_number TEXT,
        oil_filter_brand TEXT,
        oil_drain_plug_torque_ftlb INTEGER,
        oil_filter_torque_spec TEXT,
        oil_change_interval_miles INTEGER DEFAULT 5000,
        oil_change_interval_months INTEGER DEFAULT 6,
        
        -- Air Filters
        engine_air_filter_part TEXT,
        engine_air_filter_brand TEXT,
        cabin_air_filter_part TEXT,
        cabin_air_filter_brand TEXT,
        cabin_filter_location TEXT,
        
        -- Fuel System
        fuel_filter_part TEXT,
        fuel_filter_brand TEXT,
        fuel_type_required TEXT DEFAULT 'Regular 87',
        fuel_tank_capacity_gal REAL,
        
        -- Transmission
        trans_fluid_type TEXT,
        trans_fluid_capacity_qts REAL,
        trans_drain_refill_qts REAL,
        trans_filter_part TEXT,
        trans_pan_gasket_part TEXT,
        trans_service_interval_miles INTEGER DEFAULT 60000,
        
        -- Transfer Case
        transfer_case_fluid_type TEXT,
        transfer_case_capacity_qts REAL,
        transfer_case_interval_miles INTEGER,
        
        -- Differentials
        front_diff_fluid_type TEXT,
        front_diff_capacity_qts REAL,
        rear_diff_fluid_type TEXT,
        rear_diff_capacity_qts REAL,
        diff_service_interval_miles INTEGER,
        
        -- Cooling
        coolant_type TEXT,
        coolant_color TEXT,
        coolant_capacity_qts REAL,
        thermostat_temp_f INTEGER,
        coolant_change_interval_miles INTEGER DEFAULT 100000,
        
        -- Brake
        brake_fluid_type TEXT DEFAULT 'DOT 3',
        brake_fluid_change_interval_miles INTEGER DEFAULT 30000,
        
        -- Power Steering
        power_steering_fluid_type TEXT,
        power_steering_capacity_oz REAL,
        
        -- Wipers
        wiper_blade_size_driver TEXT,
        wiper_blade_size_passenger TEXT,
        wiper_blade_size_rear TEXT,
        
        -- Meta
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )",

    // TIRE SETS - Track multiple sets of tires
    "CREATE TABLE IF NOT EXISTS vehicle_tire_sets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        
        -- Tire Info
        brand TEXT,
        model TEXT,
        size TEXT,
        load_rating TEXT,
        speed_rating TEXT,
        tire_type TEXT DEFAULT 'All-Season',
        
        -- Purchase
        purchase_date TEXT,
        purchase_mileage INTEGER,
        purchase_price_per_tire REAL,
        purchase_price_total REAL,
        vendor TEXT,
        warranty_miles INTEGER,
        warranty_months INTEGER,
        
        -- Status
        position TEXT DEFAULT 'Installed' CHECK(position IN ('Installed', 'Storage', 'Sold', 'Disposed')),
        install_date TEXT,
        install_mileage INTEGER,
        is_current INTEGER DEFAULT 1,
        
        -- Rotation
        last_rotation_date TEXT,
        last_rotation_mileage INTEGER,
        rotation_interval_miles INTEGER DEFAULT 5000,
        
        -- Disposal
        removal_date TEXT,
        removal_mileage INTEGER,
        removal_reason TEXT,
        total_miles_used INTEGER,
        
        notes TEXT,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )",

    // TREAD DEPTH LOGS
    "CREATE TABLE IF NOT EXISTS vehicle_tread_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tire_set_id INTEGER NOT NULL,
        log_date TEXT NOT NULL,
        mileage INTEGER,
        
        -- Measurements in 32nds
        lf_outer INTEGER, lf_center INTEGER, lf_inner INTEGER,
        rf_outer INTEGER, rf_center INTEGER, rf_inner INTEGER,
        lr_outer INTEGER, lr_center INTEGER, lr_inner INTEGER,
        rr_outer INTEGER, rr_center INTEGER, rr_inner INTEGER,
        
        notes TEXT,
        
        FOREIGN KEY (tire_set_id) REFERENCES vehicle_tire_sets(id) ON DELETE CASCADE
    )",

    // BRAKE SETS
    "CREATE TABLE IF NOT EXISTS vehicle_brake_sets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        axle TEXT NOT NULL CHECK(axle IN ('Front', 'Rear')),
        
        -- Pads
        pad_brand TEXT,
        pad_model TEXT,
        pad_part_number TEXT,
        pad_compound TEXT,
        pad_install_date TEXT,
        pad_install_mileage INTEGER,
        pad_purchase_price REAL,
        
        -- Rotors
        rotor_brand TEXT,
        rotor_part_number TEXT,
        rotor_type TEXT DEFAULT 'Vented',
        rotor_install_date TEXT,
        rotor_install_mileage INTEGER,
        rotor_thickness_new_mm REAL,
        rotor_min_thickness_mm REAL,
        rotor_purchase_price REAL,
        
        is_current INTEGER DEFAULT 1,
        notes TEXT,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )",

    // BRAKE WEAR LOGS
    "CREATE TABLE IF NOT EXISTS vehicle_brake_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        brake_set_id INTEGER NOT NULL,
        log_date TEXT NOT NULL,
        mileage INTEGER,
        
        left_pad_outer REAL,
        left_pad_inner REAL,
        right_pad_outer REAL,
        right_pad_inner REAL,
        
        left_rotor_thickness_mm REAL,
        right_rotor_thickness_mm REAL,
        rotor_condition TEXT,
        
        estimated_pad_life_remaining_pct INTEGER,
        notes TEXT,
        
        FOREIGN KEY (brake_set_id) REFERENCES vehicle_brake_sets(id) ON DELETE CASCADE
    )",

    // SERVICE LOGS - The core maintenance tracker
    "CREATE TABLE IF NOT EXISTS vehicle_service_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        
        -- When & Where
        service_date TEXT NOT NULL,
        mileage INTEGER,
        
        -- What
        service_type TEXT NOT NULL,
        service_category TEXT DEFAULT 'Scheduled' CHECK(service_category IN ('Scheduled', 'Repair', 'Recall', 'Upgrade', 'Inspection')),
        description TEXT,
        
        -- Who
        performed_by TEXT DEFAULT 'Self' CHECK(performed_by IN ('Self', 'Dealer', 'Independent Shop', 'Chain Shop', 'Mobile Mechanic')),
        shop_name TEXT,
        shop_address TEXT,
        shop_phone TEXT,
        technician_name TEXT,
        
        -- Cost
        cost_parts REAL DEFAULT 0,
        cost_labor REAL DEFAULT 0,
        cost_tax REAL DEFAULT 0,
        cost_fees REAL DEFAULT 0,
        cost_total REAL DEFAULT 0,
        
        -- Warranty
        warranty_parts_months INTEGER,
        warranty_parts_miles INTEGER,
        warranty_labor_months INTEGER,
        warranty_labor_miles INTEGER,
        warranty_expiry_date TEXT,
        warranty_expiry_mileage INTEGER,
        is_warranty_work INTEGER DEFAULT 0,
        warranty_claim_number TEXT,
        
        -- Next Due
        next_due_date TEXT,
        next_due_mileage INTEGER,
        
        -- Documentation
        receipt_path TEXT,
        invoice_number TEXT,
        
        -- Status
        is_complete INTEGER DEFAULT 1,
        is_recurring INTEGER DEFAULT 0,
        
        -- Meta
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )",

    // SERVICE PARTS - Parts used in service
    "CREATE TABLE IF NOT EXISTS vehicle_service_parts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_log_id INTEGER NOT NULL,
        
        part_number TEXT,
        part_description TEXT,
        brand TEXT,
        quantity INTEGER DEFAULT 1,
        unit_price REAL,
        total_price REAL,
        part_category TEXT,
        vendor TEXT,
        vendor_order_number TEXT,
        
        FOREIGN KEY (service_log_id) REFERENCES vehicle_service_logs(id) ON DELETE CASCADE
    )",

    // FUEL LOGS
    "CREATE TABLE IF NOT EXISTS vehicle_fuel_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        
        -- When & Where
        fill_date TEXT NOT NULL,
        fill_time TEXT,
        station_name TEXT,
        station_address TEXT,
        
        -- Odometer
        odometer INTEGER NOT NULL,
        trip_miles REAL,
        
        -- Fuel
        gallons REAL NOT NULL,
        price_per_gallon REAL,
        total_cost REAL,
        octane TEXT,
        fuel_brand TEXT,
        is_full_tank INTEGER DEFAULT 1,
        
        -- Calculated
        mpg REAL,
        cost_per_mile REAL,
        
        -- Trip Tagging
        trip_type TEXT DEFAULT 'Personal' CHECK(trip_type IN ('Personal', 'Business', 'Mixed')),
        trip_purpose TEXT,
        is_reimbursable INTEGER DEFAULT 0,
        
        -- Payment
        payment_method TEXT,
        receipt_path TEXT,
        
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )",

    // TRIP LOGS
    "CREATE TABLE IF NOT EXISTS vehicle_trip_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        
        trip_date TEXT NOT NULL,
        start_odometer INTEGER,
        end_odometer INTEGER,
        total_miles REAL,
        
        trip_type TEXT DEFAULT 'Personal',
        purpose TEXT,
        
        start_location TEXT,
        end_location TEXT,
        
        client_name TEXT,
        project_code TEXT,
        is_reimbursable INTEGER DEFAULT 0,
        reimbursement_rate REAL,
        reimbursement_amount REAL,
        
        is_reimbursed INTEGER DEFAULT 0,
        reimbursed_date TEXT,
        
        notes TEXT,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )",

    // VEHICLE DOCUMENTS
    "CREATE TABLE IF NOT EXISTS vehicle_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL UNIQUE,
        
        -- Insurance
        insurance_provider TEXT,
        insurance_policy_number TEXT,
        insurance_start_date TEXT,
        insurance_expiry_date TEXT,
        insurance_premium_monthly REAL,
        insurance_deductible_collision REAL,
        insurance_deductible_comprehensive REAL,
        insurance_agent_name TEXT,
        insurance_agent_phone TEXT,
        insurance_card_path TEXT,
        
        -- Registration
        registration_state TEXT,
        registration_expiry_date TEXT,
        registration_cost REAL,
        registration_doc_path TEXT,
        
        -- Inspection
        inspection_type TEXT,
        inspection_expiry_date TEXT,
        inspection_cost REAL,
        inspection_station TEXT,
        inspection_doc_path TEXT,
        
        -- Title
        title_state TEXT,
        title_number TEXT,
        title_status TEXT DEFAULT 'Clean',
        lienholder_name TEXT,
        lienholder_address TEXT,
        lien_payoff_amount REAL,
        title_doc_path TEXT,
        
        -- Loan
        loan_provider TEXT,
        loan_account_number TEXT,
        loan_start_date TEXT,
        loan_end_date TEXT,
        loan_payment_monthly REAL,
        loan_interest_rate REAL,
        loan_remaining_balance REAL,
        
        -- Extended Warranty
        ext_warranty_provider TEXT,
        ext_warranty_policy_number TEXT,
        ext_warranty_start_date TEXT,
        ext_warranty_expiry_date TEXT,
        ext_warranty_expiry_mileage INTEGER,
        ext_warranty_deductible REAL,
        ext_warranty_doc_path TEXT,
        
        -- Meta
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        notes TEXT,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )",

    // UPLOADED DOCUMENTS
    "CREATE TABLE IF NOT EXISTS vehicle_uploads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        
        document_type TEXT NOT NULL,
        title TEXT,
        description TEXT,
        file_path TEXT NOT NULL,
        file_name TEXT,
        file_type TEXT,
        file_size_bytes INTEGER,
        
        linked_service_id INTEGER,
        linked_date TEXT,
        
        upload_date TEXT DEFAULT CURRENT_TIMESTAMP,
        tags TEXT,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
        FOREIGN KEY (linked_service_id) REFERENCES vehicle_service_logs(id) ON DELETE SET NULL
    )",

    // SERVICE REMINDERS
    "CREATE TABLE IF NOT EXISTS vehicle_reminders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_id INTEGER NOT NULL,
        
        service_type TEXT NOT NULL,
        description TEXT,
        
        due_date TEXT,
        due_mileage INTEGER,
        advance_warning_days INTEGER DEFAULT 14,
        advance_warning_miles INTEGER DEFAULT 500,
        
        is_recurring INTEGER DEFAULT 1,
        recur_interval_months INTEGER,
        recur_interval_miles INTEGER,
        
        is_active INTEGER DEFAULT 1,
        is_dismissed INTEGER DEFAULT 0,
        last_completed_date TEXT,
        last_completed_mileage INTEGER,
        
        notify_email INTEGER DEFAULT 0,
        notify_push INTEGER DEFAULT 1,
        
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    )"
];

// Run migrations
echo "<h2>🚗 Vehicle Management Database Migration</h2>";
echo "<pre style='background:#1a1a2e; color:#00ff88; padding:20px; border-radius:8px;'>";

$success = 0;
$failed = 0;

foreach ($migrations as $sql) {
    // Extract table name for display
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $matches);
    $table_name = $matches[1] ?? 'Unknown';

    try {
        $db->exec($sql);
        echo "✅ Created/verified table: <strong>$table_name</strong>\n";
        $success++;
    } catch (Exception $e) {
        echo "❌ Failed: $table_name - " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Migration Complete!\n";
echo "✅ Success: $success tables\n";
if ($failed > 0) {
    echo "❌ Failed: $failed tables\n";
}
echo "</pre>";

echo "<p><a href='vehicles.php' style='padding:10px 20px; background:#6366f1; color:white; text-decoration:none; border-radius:6px;'>→ Go to Vehicle Management</a></p>";
?>