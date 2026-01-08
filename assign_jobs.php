<?php
require 'config.php';

// Check if logged in (Safety)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die("Please log in first.");
}

$db = getDB();
echo "<h2>Database Upgrade Log</h2>";

// 1. ENSURE AARON EXISTS
$username = 'aaron'; // Desired username
$password = 'password'; // Default password
$role = 'tech';

// Check if he exists
$stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
$user_id = $stmt->fetchColumn();

if (!$user_id) {
    // Create him if missing
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->execute([$username, $hash, $role]);
    $user_id = $db->lastInsertId();
    echo "✅ Created user 'Aaron Roberts' (username: <strong>$username</strong>, password: <strong>$password</strong>)<br>";
} else {
    echo "ℹ️ User 'Aaron Roberts' ($username) already exists. ID: $user_id<br>";
}

// 2. ADD COLUMN TO JOBS TABLE
// SQLite doesn't have "IF NOT EXISTS" for columns, so we try/catch
try {
    $db->exec("ALTER TABLE jobs ADD COLUMN user_id INTEGER");
    echo "✅ Added 'user_id' column to jobs table.<br>";
} catch (PDOException $e) {
    // If error contains "duplicate column", it means we already ran this. Ignore.
    if (strpos($e->getMessage(), 'duplicate column') !== false) {
        echo "ℹ️ 'user_id' column already exists.<br>";
    } else {
        echo "⚠️ Note: " . $e->getMessage() . "<br>";
    }
}

// 3. ASSIGN ALL JOBS TO AARON
try {
    $stmt = $db->prepare("UPDATE jobs SET user_id = ? WHERE user_id IS NULL OR user_id = ''");
    $stmt->execute([$user_id]);
    $count = $stmt->rowCount();
    echo "✅ <strong>Success!</strong> Assigned $count existing jobs to Aaron Roberts.<br>";
} catch (Exception $e) {
    echo "❌ Error updating jobs: " . $e->getMessage();
}

echo "<br><a href='index.php'>Return to Home</a>";
?>