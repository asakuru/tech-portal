<?php
// db_update.php
// Run this file once in your browser (e.g., localhost/db_update.php)

require 'config.php';
$db = getDB();

echo "<h3>Checking Database Structure...</h3>";

try {
    // 1. Get the current columns in 'daily_logs'
    $current_columns = [];
    $stmt = $db->query("PRAGMA table_info(daily_logs)");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $current_columns[] = $row['name'];
    }

    // 2. Check for 'gallons'
    if (!in_array('gallons', $current_columns)) {
        // SQLite uses REAL for decimal numbers
        $db->exec("ALTER TABLE daily_logs ADD COLUMN gallons REAL DEFAULT 0");
        echo "✅ Column 'gallons' added successfully.<br>";
    } else {
        echo "ℹ️ Column 'gallons' already exists. No changes needed.<br>";
    }

    // 3. Double check 'fuel_cost' just in case
    if (!in_array('fuel_cost', $current_columns)) {
        $db->exec("ALTER TABLE daily_logs ADD COLUMN fuel_cost REAL DEFAULT 0");
        echo "✅ Column 'fuel_cost' added successfully.<br>";
    } else {
        echo "ℹ️ Column 'fuel_cost' already exists.<br>";
    }

    echo "<hr><strong>Database update complete. You can now use the Truck Log.</strong>";
    echo "<br><a href='index.php'>Go back to Dashboard</a>";

} catch (Exception $e) {
    echo "❌ Error updating database: " . $e->getMessage();
}
?>