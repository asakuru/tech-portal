<?php
// --- DATABASE SETTINGS (SQLite) ---
define('DB_FILE', __DIR__ . '/tech_portal.db');

// --- DB CONNECTION FACTORY ---
function getDB()
{
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO("sqlite:" . DB_FILE);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec("PRAGMA foreign_keys = ON;");
        } catch (PDOException $e) {
            die("Database Connection Error: " . $e->getMessage());
        }
    }
    return $db;
}

$db = getDB();

// --- SESSION SECURITY ---
ini_set('session.cookie_httponly', 1);  // Prevent JS access to session cookie
ini_set('session.use_strict_mode', 1);  // Reject uninitialized session IDs
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);  // Only send cookie over HTTPS
}

// --- START SESSION ---
// Essential for the login system to remember you
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- LOAD LOGIC CORE ---
require_once 'functions.php';

// --- FETCH ACTIVE RATES ---
$rates = [];
if (function_exists('get_active_rates')) {
    $rates = get_active_rates($db);
}

// Fallback Defaults (prevents crash if DB is empty)
if (empty($rates)) {
    $rates = [
        'F001' => 154.00,
        'F002' => 110.00,
        'F003' => 119.00,
        'span_price' => 24.00,
        'conduit_per_ft' => 0.55,
        'jack_1st_add' => 37.00,
        'jack_next_add' => 19.00,
        'copper_remove' => 19.50,
        'extra_pd' => 50.00,
        'per_diem' => 125.00
    ];
}

// --- DROPDOWN LABELS ---
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
?>