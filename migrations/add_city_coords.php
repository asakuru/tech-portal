<?php
/**
 * Migration: Add city_coords table for geocoding cache
 * Run once to create the table
 */

require_once __DIR__ . '/../config.php';

$db = getDB();

try {
    // Create city_coords table
    $db->exec("
        CREATE TABLE IF NOT EXISTS city_coords (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            city TEXT NOT NULL,
            state TEXT NOT NULL,
            lat REAL,
            lng REAL,
            UNIQUE(city, state)
        )
    ");
    echo "✅ Created city_coords table\n";

    // Add home_base setting to rate_card if not exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM rate_card WHERE rate_key = 'HOME_LAT'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO rate_card (rate_key, amount, description) VALUES ('HOME_LAT', 0, 'Home Base Latitude')");
        $db->exec("INSERT INTO rate_card (rate_key, amount, description) VALUES ('HOME_LNG', 0, 'Home Base Longitude')");
        echo "✅ Added HOME_LAT and HOME_LNG settings\n";
    } else {
        echo "ℹ️ Home base settings already exist\n";
    }

    echo "\n✅ Migration complete!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>