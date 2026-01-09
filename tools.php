<?php
require_once 'config.php';
// require_once 'functions.php'; // Not strictly needed for search, but good practice

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$user_id = $_SESSION['user_id'];

// --- ROBUST ADMIN CHECK ---
// Uses centralized function from functions.php
$is_admin = is_admin();

$results = [];
$term = "";

if (isset($_GET['q'])) {
    $term = trim($_GET['q']);
    if (strlen($term) > 0) {

        // Search Logic: Unique bindings to prevent PDO driver issues
        $search_str = "%$term%";
        $params = [];
        $clauses = [];
        
        // Detect Driver for Compatibility (SQLite uses ||, MySQL uses CONCAT)
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $is_sqlite = ($driver === 'sqlite');
        
        // Helper to add clause
        $bind_idx = 0;
        $addClause = function($col) use (&$clauses, &$params, &$bind_idx, $search_str) {
            $bind_idx++;
            $key = ":t$bind_idx";
            $clauses[] = "$col LIKE $key";
            $params[$key] = $search_str;
        };

        $addClause("ticket_number");
        $addClause("cust_fname");
        $addClause("cust_lname");
        
        // Full Name Check (Driver Aware)
        $bind_idx++;
        $key = ":t$bind_idx";
        $concat_sql = $is_sqlite 
            ? "(COALESCE(cust_fname,'') || ' ' || COALESCE(cust_lname,''))" 
            : "CONCAT(COALESCE(cust_fname,''), ' ', COALESCE(cust_lname,''))";
            
        $clauses[] = "$concat_sql LIKE $key";
        $params[$key] = $search_str;
        
        // Legacy Fields (Only check if NOT SQLite, assuming Prod/MySQL has them, or remove entirely if unused)
        if (!$is_sqlite) {
            $addClause("cust_name");
            $addClause("cust_address");
        }

        $addClause("cust_street");
        $addClause("cust_city");
        $addClause("addtl_work");

        $sql = "SELECT * FROM jobs WHERE (" . implode(" OR ", $clauses) . ")";

        // Restrict to User unless Admin
        if (!$is_admin) {
            $sql .= " AND user_id = :uid";
            $params[':uid'] = $user_id;
        }

        $sql .= " ORDER BY install_date DESC LIMIT 50";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { 
            // Reveal error for debugging
            echo "<div class='alert' style='background:var(--danger-bg); color:var(--danger-text);'>Search Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Job Search</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .search-box {
            background: var(--bg-card);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
        }

        .search-input {
            width: 100%;
            padding: 12px 15px;
            font-size: 1.1rem;
            border: 2px solid var(--border);
            border-radius: 6px;
            background: var(--bg-input);
            color: var(--text-main);
            outline: none;
            transition: border-color 0.2s;
        }

        .search-input:focus {
            border-color: var(--primary);
        }

        .result-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-decoration: none;
            color: inherit;
            transition: transform 0.1s, box-shadow 0.1s;
        }

        .result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-color: var(--primary);
        }

        .res-date {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: bold;
            margin-bottom: 2px;
        }

        .res-title {
            font-weight: bold;
            font-size: 1rem;
            color: var(--primary);
        }

        .res-sub {
            font-size: 0.9rem;
            color: var(--text-main);
            margin-top: 2px;
        }

        .res-addr {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .tag {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
            background: #eee;
            color: #555;
            display: inline-block;
            margin-left: 5px;
        }
    </style>
</head>

<body>

    <?php include 'nav.php'; ?>

    <div class="container">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2>🔍 Job Search</h2>
        </div>

        <form method="get" class="search-box">
            <input type="text" name="q" class="search-input" placeholder="Search ticket #, name, address..."
                value="<?= htmlspecialchars($term) ?>" autocomplete="off" autofocus onfocus="this.select()">
            <button type="submit" class="btn btn-full" style="margin-top:10px;">Search Records</button>
        </form>

        <?php if (!empty($term)): ?>
            <h3 style="margin-bottom:15px; color:var(--text-muted);">
                Results for "<?= htmlspecialchars($term) ?>"
                <span style="font-weight:normal; font-size:0.9rem;">(<?= count($results) ?> found)</span>
            </h3>

            <div class="results-list">
                <?php if (empty($results)): ?>
                    <div
                        style="text-align:center; padding:40px; color:var(--text-muted); background:var(--bg-input); border-radius:8px;">
                        No jobs found matching your search.
                    </div>
                <?php else: ?>
                    <?php foreach ($results as $r):
                        // Format Date
                        $d = date('M j, Y', strtotime($r['install_date']));

                        // Handle Name (New fields vs Old fields)
                        $name = $r['cust_fname'] . ' ' . $r['cust_lname'];
                        if (trim($name) === '')
                            $name = $r['cust_name']; // Fallback to old field
                        if (empty(trim($name)))
                            $name = "Unknown Customer";

                        // Handle Address (New fields vs Old fields)
                        $addr = $r['cust_street'] . ' ' . $r['cust_city'];
                        if (trim($addr) === '')
                            $addr = $r['cust_address']; // Fallback to old field
                        ?>
                        <a href="edit_job.php?id=<?= htmlspecialchars($r['id']) ?>" class="result-card">
                            <div>
                                <div class="res-date">
                                    <?= htmlspecialchars($d) ?>
                                    <span class="tag"><?= htmlspecialchars($r['install_type']) ?></span>
                                </div>
                                <div class="res-title">
                                    <?= htmlspecialchars($r['ticket_number']) ?>
                                </div>
                                <div class="res-sub">
                                    <?= htmlspecialchars($name) ?>
                                </div>
                                <div class="res-addr">
                                    📍 <?= htmlspecialchars($addr) ?>
                                </div>
                            </div>
                            <div>
                                <span style="font-size:1.5rem; color:var(--text-muted);">›</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
    </script>
</body>

</html>