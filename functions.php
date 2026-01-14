<?php
/**
 * functions.php
 * Centralized logic for the Tech Portal.
 * All functions wrapped with function_exists to prevent redeclaration errors.
 */

// Prevent multiple includes
if (defined('FUNCTIONS_INCLUDED'))
    return;
define('FUNCTIONS_INCLUDED', true);

// --- 0. ADMIN CHECK ---
if (!function_exists('is_admin')) {
    function is_admin()
    {
        $user_id = $_SESSION['user_id'] ?? 0;
        $role = strtolower($_SESSION['role'] ?? '');
        return ($role === 'admin' || $role === 'administrator' || $user_id == 1);
    }
}

// --- 0.5 CSRF PROTECTION ---
if (!function_exists('csrf_token')) {
    function csrf_token()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field()
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate()
    {
        if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate()) {
            die('❌ Security Error: Invalid or missing CSRF token. Please refresh and try again.');
        }
    }
}

// --- 1. RATE FETCHER ---
if (!function_exists('get_active_rates')) {
    function get_active_rates($db)
    {
        $rates = [];
        try {
            $stmt = $db->query("SELECT rate_key, amount FROM rate_card");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rates[$row['rate_key']] = (float) $row['amount'];
            }
        } catch (Exception $e) {
        }
        return $rates;
    }
}

// --- 2. RATE DESCRIPTIONS ---
if (!function_exists('get_rate_descriptions')) {
    function get_rate_descriptions($db)
    {
        $names = [];
        try {
            $stmt = $db->query("SELECT rate_key, description FROM rate_card");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $names[$row['rate_key']] = $row['description'];
            }
        } catch (Exception $e) {
        }
        return $names;
    }
}

// --- 3. DETAILED JOB BREAKDOWN ---
if (!function_exists('calculate_job_details')) {
    function calculate_job_details($data, $rates)
    {
        $items = [];

        $add = function ($code, $qty, $desc_fallback, $rate_key_override = null) use (&$items, $rates) {
            if ($qty <= 0)
                return;

            $rate = 0.00;
            if ($rate_key_override && isset($rates[$rate_key_override])) {
                $rate = $rates[$rate_key_override];
            } elseif (isset($rates[$code])) {
                $rate = $rates[$code];
            }

            $items[] = [
                'code' => $code,
                'qty' => $qty,
                'rate' => $rate,
                'total' => $qty * $rate,
                'desc' => $desc_fallback
            ];
        };

        // Base Pay
        $type = $data['install_type'] ?? '';
        if (!empty($type) && $type !== 'DO' && $type !== 'ND') {
            $add($type, 1, 'Base Job');
        }

        // Spans
        $spans = (int) ($data['spans'] ?? 0);
        $add('F006', $spans, 'Aerial Span', 'span_price');

        // Conduit
        $conduit = (int) ($data['conduit_ft'] ?? 0);
        $add('F014-10', $conduit, 'Underground Conduit (ft)', 'conduit_per_ft');

        // Jacks
        $jacks = (int) ($data['jacks_installed'] ?? 0);
        if ($jacks >= 2) {
            $add('1-F014-5', 1, 'First Addtl Jack', 'jack_1st_add');
            if ($jacks > 2) {
                $extra = $jacks - 2;
                $add('2-F014-5', $extra, 'Addtl Jack', 'jack_next_add');
            }
        }

        // Copper Removal
        $copper = $data['copper_removed'] ?? 'No';
        if ($copper === 'Yes' || $copper === 1 || $copper === '1' || $copper === 'on') {
            $add('F014-7', 1, 'Copper Drop Removal', 'copper_remove');
        }

        // Extra PD
        $extra_pd = $data['extra_per_diem'] ?? 'No';
        if ($extra_pd === 'Yes' || $extra_pd === 1 || $extra_pd === '1' || $extra_pd === 'on') {
            $add('Legacy-PD', 1, 'Job Extra PD', 'extra_pd');
        }

        return $items;
    }
}

// --- 4. TOTAL CALCULATOR ---
if (!function_exists('calculate_job_pay')) {
    function calculate_job_pay($data, $rates)
    {
        $items = calculate_job_details($data, $rates);
        $total = 0.00;
        foreach ($items as $item) {
            $total += $item['total'];
        }
        return $total;
    }
}

// --- 5. HELPERS ---
if (!function_exists('get_lead_pay_amount')) {
    function get_lead_pay_amount($db)
    {
        try {
            $stmt = $db->prepare("SELECT amount FROM rate_card WHERE rate_key = 'LEAD_PAY'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            return $val ? (float) $val : 500.00;
        } catch (Exception $e) {
            return 500.00;
        }
    }
}

if (!function_exists('has_billable_work')) {
    function has_billable_work($db, $user_id, $start_date, $end_date)
    {
        try {
            $sql = "SELECT COUNT(*) FROM jobs 
                    WHERE user_id = ? 
                    AND install_date BETWEEN ? AND ? 
                    AND install_type NOT IN ('DO', 'ND')";
            $stmt = $db->prepare($sql);
            $stmt->execute([$user_id, $start_date, $end_date]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>