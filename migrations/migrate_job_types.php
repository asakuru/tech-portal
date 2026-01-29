<?php
/**
 * Migration: Migrate Job Types
 * Seeds labels from config.php into rate_card table
 */

require_once __DIR__ . '/../config.php';

$db = getDB();
$install_names = [
    'F001' => 'Triple Play (Voice/Data/Video)',
    'F002' => 'Double Play (Voice/Data)',
    'F014-1' => 'Single Play (Data Only)',
    'F003' => 'Internet & Video',
    'F019' => 'Tel Only',
    'F021' => 'Video Only',
    'F015' => 'Reconnect/Add Triple',
    'F016' => 'Reconnect/Add Double',
    'F004' => 'Video Add/Reconnect',
    'F005' => 'Addl Set Top Box',
    'F008' => 'Trouble Call',
    'F010' => 'Trouble Follow Up',
    'F009' => 'Refer to Maintenance',
    'F011' => 'Trip Charge',
    'F012' => 'Hourly Rate',
    'F014-17' => 'BBU Trouble',
    'DO' => 'Day Off',
    'ND' => 'Not Designated'
];

$count_updated = 0;
$count_inserted = 0;

foreach ($install_names as $key => $label) {
    try {
        // Check if key exists
        $stmt = $db->prepare("SELECT rate_key FROM rate_card WHERE rate_key = ?");
        $stmt->execute([$key]);
        if ($stmt->fetch()) {
            // Update description
            $db->prepare("UPDATE rate_card SET description = ? WHERE rate_key = ?")->execute([$label, $key]);
            $count_updated++;
        } else {
            // Insert new (default amount 0 if not one of the main ones)
            $db->prepare("INSERT INTO rate_card (rate_key, description, amount) VALUES (?, ?, ?)")->execute([$key, $label, 0]);
            $count_inserted++;
        }
    } catch (Exception $e) {
        echo "Error migrating $key: " . $e->getMessage() . "\n";
    }
}

echo "✅ Migration Complete!\n";
echo "Updated: $count_updated entries.\n";
echo "Inserted: $count_inserted entries.\n";
