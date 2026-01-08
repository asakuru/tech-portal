<?php
require 'config.php';

// --- SESSION & AUTH ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php'); exit;
}

// --- ROBUST ADMIN CHECK ---
$user_id = $_SESSION['user_id'] ?? 0;
$role = strtolower($_SESSION['role'] ?? '');
$is_admin = ($role === 'admin' || $role === 'administrator' || $user_id == 1);

if (!$is_admin) {
    die("❌ Access Denied.");
}

// --- CONFIG ---
if (!defined('DB_FILE')) define('DB_FILE', __DIR__ . '/tech_portal.db');

$backup_dir = __DIR__ . '/backups';
if (!is_dir($backup_dir)) { mkdir($backup_dir, 0755, true); }

$msg = ""; $error = "";

// --- ACTIONS ---

// 1. CREATE SNAPSHOT (DB ONLY)
if (isset($_POST['create_db_backup'])) {
    if (file_exists(DB_FILE)) {
        $filename = 'db_snapshot_' . date('Y-m-d_Hi') . '.db';
        if (copy(DB_FILE, $backup_dir . '/' . $filename)) {
            $msg = "✅ Database Snapshot created.";
        } else {
            $error = "❌ Failed to copy database.";
        }
    } else {
        $error = "❌ Source database (tech_portal.db) not found.";
    }
}

// 2. CREATE FULL BACKUP (ZIP)
if (isset($_POST['create_full_backup'])) {
    if (!class_exists('ZipArchive')) {
        $error = "❌ Error: ZipArchive extension not enabled.";
    } else {
        $zip = new ZipArchive();
        $zipname = 'full_backup_' . date('Y-m-d_Hi') . '.zip';
        $zippath = $backup_dir . '/' . $zipname;

        if ($zip->open($zippath, ZipArchive::CREATE) === TRUE) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen(__DIR__) + 1);

                    if (strpos($relativePath, 'backups') === 0) continue;
                    if (strpos($relativePath, '.git') === 0) continue;
                    if (basename($relativePath) == 'tech_portal.db') continue; 

                    $zip->addFile($filePath, $relativePath);
                }
            }
            if (file_exists(DB_FILE)) {
                $zip->addFile(DB_FILE, 'tech_portal.db');
            }
            $zip->close();
            $msg = "📦 Full Site Backup (.zip) created.";
        } else {
            $error = "❌ Failed to create Zip file.";
        }
    }
}

// 3. RESTORE DATABASE (NEW FEATURE)
if (isset($_POST['restore_file'])) {
    $target_backup = $backup_dir . '/' . basename($_POST['restore_file']);
    
    // Security checks
    if (file_exists($target_backup) && pathinfo($target_backup, PATHINFO_EXTENSION) === 'db') {
        
        // A. CREATE SAFETY NET (Backup current live data before killing it)
        $safety_name = 'safety_net_before_restore_' . date('Y-m-d_Hi') . '.db';
        copy(DB_FILE, $backup_dir . '/' . $safety_name);
        
        // B. OVERWRITE LIVE DB
        if (copy($target_backup, DB_FILE)) {
            $msg = "✅ System Restored! (A safety backup was created: $safety_name)";
        } else {
            $error = "❌ Restore Failed: Could not overwrite live database. Check permissions.";
        }
    } else {
        $error = "❌ Invalid backup file selected.";
    }
}

// 4. DELETE
if (isset($_POST['delete_file'])) {
    $file = $backup_dir . '/' . basename($_POST['delete_file']);
    if (file_exists($file)) {
        unlink($file);
        $msg = "🗑️ Backup deleted.";
    }
}

// 5. DOWNLOAD
if (isset($_GET['download'])) {
    $file = $backup_dir . '/' . basename($_GET['download']);
    if (file_exists($file)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// --- GET FILE LIST ---
$files = glob($backup_dir . '/*.{db,zip}', GLOB_BRACE);
if($files) {
    usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
} else {
    $files = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Backups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .btn-small { padding: 4px 10px; font-size: 0.8rem; border-radius: 4px; border: none; cursor: pointer; }
        .btn-secondary { background: var(--text-muted); color: white; text-decoration: none; display: inline-block; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-warning { background: #f59e0b; color: #fff; } /* Orange for Restore */
        
        .badge { font-size: 0.75rem; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
        .badge-db { background: #dbeafe; color: #1e40af; }
        .badge-zip { background: #dcfce7; color: #166534; }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2>🛡️ Backup Manager</h2>
        <a href="settings.php" class="btn" style="background:var(--bg-input); color:var(--text-main);">Settings</a>
    </div>

    <?php if($msg): ?><div class="alert" style="border-left:4px solid var(--success-text);"><?=$msg?></div><?php endif; ?>
    <?php if($error): ?><div class="alert" style="background:var(--danger-bg); color:var(--danger-text); border:none;"><?=$error?></div><?php endif; ?>

    <div class="box" style="text-align:center; padding:30px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:20px;">
            <div>
                <h3 style="margin:0 0 10px 0;">Database Snapshot</h3>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:15px;">Backs up only your data (Jobs, Customers, Settings).</p>
                <form method="post">
                    <button type="submit" name="create_db_backup" class="btn btn-full" style="background:var(--primary);">💾 Backup Data Only (.db)</button>
                </form>
            </div>
            <div style="border-left:1px solid var(--border); padding-left:20px;">
                <h3 style="margin:0 0 10px 0;">Full Site Backup</h3>
                <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:15px;">Backs up everything: Data + PHP Code + Styles.</p>
                <form method="post">
                    <button type="submit" name="create_full_backup" class="btn btn-full" style="background:#000;">📦 Backup Everything (.zip)</button>
                </form>
            </div>
        </div>
    </div>

    <div class="box" style="padding:0; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:var(--bg-input); text-align:left;">
                    <th style="padding:15px;">Type</th>
                    <th style="padding:15px;">Filename</th>
                    <th style="padding:15px;">Date</th>
                    <th style="padding:15px; text-align:right;">Size</th>
                    <th style="padding:15px; text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($files)): ?>
                    <tr><td colspan="5" style="padding:20px; text-align:center; color:var(--text-muted);">No backups found.</td></tr>
                <?php else: ?>
                    <?php foreach($files as $f): 
                        $name = basename($f);
                        $ext = pathinfo($f, PATHINFO_EXTENSION);
                        $date = date('M j, Y H:i', filemtime($f));
                        $size = number_format(filesize($f) / 1024, 2) . ' KB';
                        $badge = ($ext=='zip') ? '<span class="badge badge-zip">ZIP</span>' : '<span class="badge badge-db">DB</span>';
                        $is_db = ($ext === 'db');
                    ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:15px;"><?= $badge ?></td>
                        <td style="padding:15px; font-weight:bold; font-size:0.9rem; word-break:break-all;"><?= $name ?></td>
                        <td style="padding:15px; color:var(--text-muted); font-size:0.85rem;"><?= $date ?></td>
                        <td style="padding:15px; text-align:right; font-family:monospace;"><?= $size ?></td>
                        <td style="padding:15px; text-align:right; white-space:nowrap;">
                            
                            <?php if($is_db): ?>
                            <form method="post" onsubmit="return confirm('⚠️ WARNING: This will OVERWRITE your current live data with this backup.\n\nAny data entered AFTER this backup date will be LOST.\n\nAre you sure?');" style="display:inline;">
                                <input type="hidden" name="restore_file" value="<?= $name ?>">
                                <button type="submit" class="btn-small btn-warning" title="Restore this backup">↺ Restore</button>
                            </form>
                            <?php endif; ?>

                            <a href="?download=<?= $name ?>" class="btn-small btn-secondary" title="Download">⬇️</a>
                            
                            <form method="post" onsubmit="return confirm('Delete this backup?');" style="display:inline;">
                                <input type="hidden" name="delete_file" value="<?= $name ?>">
                                <button type="submit" class="btn-small btn-danger" style="margin-left:5px;">✖</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>if(localStorage.getItem('theme')==='dark'){document.body.classList.add('dark-mode');}</script>
</body>
</html>