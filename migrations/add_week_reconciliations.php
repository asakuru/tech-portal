<?php
/**
 * Migration: Add week_reconciliations table
 * Tracks which weeks have been reconciled and stores CSV filenames
 * 
 * Can be run from browser (admin only) or CLI
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

// --- AUTH CHECK (browser only) ---
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['loggedin']) || !is_admin()) {
        die('❌ Access denied. Admin login required.');
    }
    // HTML output for browser
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>Migration</title></head><body style="font-family:sans-serif; padding:20px;">';
}

$db = getDB();
$messages = [];

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
    $messages[] = "✅ Created week_reconciliations table successfully.";
} catch (Exception $e) {
    $messages[] = "❌ Migration failed: " . $e->getMessage();
}

// Output messages
foreach ($messages as $msg) {
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        echo "<p style='font-size:1.2rem;'>$msg</p>";
    }
}

// Browser footer
if (php_sapi_name() !== 'cli') {
    echo '<p><a href="../reconcile.php" style="color:#2563eb;">← Back to Reconcile</a></p>';
    echo '</body></html>';
}
?>