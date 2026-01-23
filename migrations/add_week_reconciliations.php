<?php
/**
 * Migration: Add week_reconciliations table
 * Tracks which weeks have been reconciled and stores CSV filenames
 */

require_once __DIR__ . '/../config.php';

$db = getDB();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS week_reconciliations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            week INTEGER NOT NULL,
            year INTEGER NOT NULL,
            csv_filename TEXT,
            reconciled_at TEXT NOT NULL,
            UNIQUE(user_id, week, year)
        )
    ");
    echo "✅ Created week_reconciliations table successfully.\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
?>