<?php
require 'config.php';
require 'functions.php';

// --- AUTH CHECK ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$parsed_jobs = [];
$error_msg = "";
$success_msg = "";

// --- HANDLE DIRECT IMPORT ---
if (isset($_POST['import_jobs']) && isset($_POST['jobs'])) {

    // Check for target user (Admin only)
    $target_user_id = $_SESSION['user_id'];
    if (is_admin() && !empty($_POST['target_user'])) {
        $target_user_id = $_POST['target_user'];
    }

    $jobs_to_import = json_decode($_POST['jobs'], true);
    $count = 0;

    if (is_array($jobs_to_import)) {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO jobs (
                id, user_id, install_date, ticket_number, install_type, 
                cust_fname, cust_lname, cust_street, cust_city, cust_state, cust_zip, cust_phone,
                spans, conduit_ft, jacks_installed, drop_length,
                soft_jumper, ont_serial, eeros_serial, cat6_lines,
                wifi_name, wifi_pass, addtl_work, pay_amount,
                extra_per_diem, nid_installed, exterior_sealed, copper_removed
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            $count_skipped = 0;
            foreach ($jobs_to_import as $job) {
                // Check dup (Ticket + Date + Type)
                $chk = $db->prepare("SELECT id FROM jobs WHERE ticket_number=? AND install_date=? AND install_type=? AND user_id=?");
                $chk->execute([$job['ticket'], $job['date'], $job['type'] ?? 'F011', $target_user_id]);
                if ($chk->fetch()) {
                    $count_skipped++;
                    continue;
                }

                // Skip if invalid
                if (empty($job['ticket'])) 
                    continue;

                // Parse Name
                $parts = explode(' ', trim($job['name'] ?? ''));
                $fname = array_shift($parts) ?? '';
                $lname = implode(' ', $parts);

                // Parse Address (Simple heuristic)
                $addr = $job['address'] ?? '';
                $street = $addr;
                $city = ''; $state = ''; $zip = '';
                
                // Try to parse "Street, City, ST Zip"
                if (preg_match('/^(.*),\s*([^,]+),\s*([A-Z]{2})\s*(\d{5}(?:-\d{4})?)$/i', $addr, $m)) {
                    $street = trim($m[1]);
                    $city = trim($m[2]);
                    $state = strtoupper(trim($m[3]));
                    $zip = trim($m[4]);
                }

                $stmt->execute([
                    uniqid('imp_'),
                    $target_user_id,
                    $job['date'],
                    $job['ticket'],
                    $job['type'] ?? 'Imported',
                    $fname, $lname, 
                    $street, $city, $state, $zip, 
                    $job['phone'] ?? '',
                    $job['spans'] ?? 0,
                    0, // conduit
                    0, // jacks (unless parsed?)
                    $job['drop'] ?? 0,
                    '', // soft jumper
                    $job['ont'] ?? '',
                    $job['eero'] ?? '',
                    '', // cat6
                    $job['wifi_name'] ?? '',
                    $job['wifi_pass'] ?? '',
                    $job['notes'] ?? '',
                    0.00, // pay (could verify codes to calc)
                    'No', // extra PD
                    'No', // NID
                    'No', // Seal
                    'No'  // Copper
                ]);
                $count++;
            }
            $db->commit();
            $success_msg = "✅ Successfully imported $count jobs!" . ($count_skipped > 0 ? " ($count_skipped duplicates skipped)" : "");
            // clear processed jobs
            $parsed_jobs = [];
        } catch (Exception $e) {
            $db->rollBack();
            $error_msg = "Import Failed: " . $e->getMessage();
            // Keep parsed jobs so user doesn't lose them
            $parsed_jobs = $jobs_to_import;
        }
    }
}

// --- HANDLE PARSING ---
if (isset($_POST['parse_text'])) {
    $raw_text = $_POST['raw_text'] ?? '';
    $default_date = $_POST['default_date'] ?? date('Y-m-d');

    // Normalization
    $text = str_replace(["\r\n", "\r"], "\n", $raw_text);

    // REGEX STRATEGY:
    // Split by Ticket Number (9 digits starting with distinct boundary)
    // We look for 9 digits on their own line or start of line

    // Step 1: Find all offsets of ticket numbers
    preg_match_all('/\b\d{9}\b/', $text, $matches, PREG_OFFSET_CAPTURE);

    if (empty($matches[0])) {
        $error_msg = "No ticket numbers (9 digits) found in text.";
    } else {
        $chunks = [];
        $offsets = $matches[0];

        for ($i = 0; $i < count($offsets); $i++) {
            $start = $offsets[$i][1];
            $end = isset($offsets[$i + 1]) ? $offsets[$i + 1][1] : strlen($text);
            $length = $end - $start;
            $chunks[] = substr($text, $start, $length);
        }

        foreach ($chunks as $chunk) {
            $job = [
                'date' => $default_date,
                'ticket' => '',
                'name' => '',
                'address' => '',
                'phone' => '',
                'type' => 'F011', // Default
                'ont' => '',
                'eero' => '',
                'drop' => 0,
                'spans' => 0,
                'wifi_name' => '',
                'wifi_pass' => '',
                'notes' => ''
            ];

            $lines = explode("\n", trim($chunk));
            $lines = array_map('trim', $lines);
            $lines = array_values(array_filter($lines)); // Remove empty lines

            if (empty($lines))
                continue;

            // Line 0 is Ticket (guaranteed by split)
            if (preg_match('/\d{9}/', $lines[0], $m)) {
                $job['ticket'] = $m[0];
            }

            // Heuristic Parsing
            // We assume:
            // Line 1: Name
            // Lines 2 -> (Phone Match): Address

            $phone_idx = -1;

            // Find Phone Line
            foreach ($lines as $idx => $line) {
                // Phone: 10 digits, maybe with dashes
                // Clean dashes/parens
                $clean = preg_replace('/[^0-9]/', '', $line);
                if (strlen($clean) === 10 && $idx > 0 && $idx < 6) { // Optimization: Phone usually near top
                    $job['phone'] = $line; // Keep format or clean? Keep original for now
                    $phone_idx = $idx;
                    break;
                }
            }

            if ($phone_idx > -1) {
                // Name is line 1 (if exists)
                if (isset($lines[1]))
                    $job['name'] = $lines[1];

                // Address is between Name (1) and Phone ($phone_idx)
                // e.g. Name=1, Phone=3 -> Address is 2
                $addr_parts = [];
                for ($k = 2; $k < $phone_idx; $k++) {
                    $addr_parts[] = $lines[$k];
                }
                $job['address'] = implode(", ", $addr_parts);
            } else {
                // Fallback if no phone parsing
                if (isset($lines[1]))
                    $job['name'] = $lines[1];
                if (isset($lines[2]))
                    $job['address'] = $lines[2];
            }

            // Scan Body (After Phone) for Codes and Details
            $start_body = ($phone_idx > -1) ? $phone_idx + 1 : 1;
            $notes_arr = [];
            $found_codes = [];

            for ($k = $start_body; $k < count($lines); $k++) {
                $line = $lines[$k];

                // 1. Job Types (F-codes)
                // Regex: F followed by digits/dashes. e.g. F011, F014-1
                if (preg_match_all('/\bF\d{3}(?:-\d+)?\b/i', $line, $fm)) {
                    foreach ($fm[0] as $code)
                        $found_codes[] = strtoupper($code);
                    // Don't add simple code lines to notes? Or keep them?
                    // Let's keep distinct code lines out of notes if they ONLY contain codes
                    if (trim(str_replace($fm[0], '', $line)) == '')
                        continue;
                }

                // 2. ONT Serial
                if (preg_match('/(?:ONT|FTRO)[(\s:-]*([A-Z0-9]{10,})/i', $line, $m)) {
                    $job['ont'] = $m[1];
                }

                // 3. Eero Serial
                if (preg_match('/(?:Eero|GGC)[(\s:-]*([A-Z0-9]{10,})/i', $line, $m)) {
                    // Could be multiple Eeros comma separated in parens
                    $job['eero'] = $m[1];
                }

                // 4. Drop Length
                if (preg_match('/(\d+)\s*\'?\s*drop/i', $line, $m)) {
                    $job['drop'] = (int) $m[1];
                }

                // 5. Spans
                if (preg_match('/(\d+)\s*spans?/i', $line, $m)) {
                    $job['spans'] = (int) $m[1];
                }

                // 6. Wifi
                // Matches patterns like "Wifi: MyNet / Pass: 123" or separate lines
                // Weak detection for now, maybe look for common phrases or just add to notes.
                // Example 2 has "steves wifi" then "Lescowitch33!"

                $notes_arr[] = $line;
            }

            // Determine Primary Type (First found or F011)
            if (!empty($found_codes)) {
                $job['type'] = $found_codes[0]; // Take first
                // Append other codes to notes if multiple
                if (count($found_codes) > 1) {
                    $notes_arr[] = "[Codes: " . implode(", ", $found_codes) . "]";
                }
            }

            $job['notes'] = implode("\n", $notes_arr);
            $parsed_jobs[] = $job;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Text to Job Converter</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="favicon.png?v=2">
    <link rel="shortcut icon" href="favicon.ico?v=2">
    <link rel="apple-touch-icon" href="favicon.png">
    <style>
        .split-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        @media(min-width: 900px) {
            .split-layout {
                grid-template-columns: 1fr 1.5fr;
            }
        }

        textarea {
            width: 100%;
            height: 300px;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-family: monospace;
            font-size: 0.9rem;
            resize: vertical;
        }

        .job-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }

        .job-card h4 {
            margin: 0 0 5px;
            color: var(--primary);
            display: flex;
            justify-content: space-between;
        }

        .job-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 5px;
        }

        .job-notes {
            background: var(--bg-input);
            padding: 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            white-space: pre-wrap;
        }
    </style>
</head>

<body>
    <?php include 'nav.php'; ?>

    <div class="container">
        <h2>🔄 Text to Job Converter</h2>
        <p style="color:var(--text-muted); margin-bottom:20px;">Paste copied job text (emails, app history) to
            automatically extract job details.</p>

        <?php if ($error_msg): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert">
                <?= htmlspecialchars($success_msg) ?>
            </div>
        <?php endif; ?>

        <div class="split-layout">
            <!-- INPUT COLUMN -->
            <div>
                <div class="box">
                    <form method="post">
                        <div style="margin-bottom:15px;">
                            <label>Default Date for Jobs</label>
                            <input type="date" name="default_date" value="<?= date('Y-m-d') ?>" class="input-field">
                        </div>

                        <label>Raw Text Input</label>
                        <textarea name="raw_text"
                            placeholder="Paste your text block here...&#10;&#10;123456789&#10;John Doe&#10;123 Main St&#10;555-555-5555&#10;F011&#10;Notes..."><?= isset($_POST['raw_text']) ? htmlspecialchars($_POST['raw_text']) : '' ?></textarea>

                        <button type="submit" name="parse_text" class="btn btn-full" style="margin-top:10px;">🔍 Parse
                            Text</button>
                        <a href="converter.php" class="btn btn-secondary btn-full"
                            style="display:block; text-align:center; margin-top:5px; text-decoration:none;">Clear</a>
                    </form>
                </div>

                <div class="box" style="margin-top:20px;">
                    <h4 style="margin-top:0;">Tips</h4>
                    <ul style="padding-left:20px; font-size:0.9rem; color:var(--text-muted);">
                        <li>Jobs must have a 9-digit <strong>Ticket Number</strong>.</li>
                        <li>Standard format: Ticket, then Name, then Address, then Phone.</li>
                        <li>Parser looks for "F" codes (e.g. F011) automatically.</li>
                        <li>Extracts "ONT", "Eero", "Drop" length from notes.</li>
                    </ul>
                </div>
            </div>

            <!-- PREVIEW COLUMN -->
            <div>
                <?php if (!empty($parsed_jobs)): ?>
                    <?php
                    // Pre-check duplicates
                    foreach ($parsed_jobs as &$pj) {
                        $chk = $db->prepare("SELECT id FROM jobs WHERE ticket_number=? AND install_date=? AND install_type=? AND user_id=?");
                        $chk->execute([$pj['ticket'], $pj['date'], $pj['type'], $_SESSION['user_id']]);
                        $pj['is_dup'] = (bool) $chk->fetch();
                    }
                    unset($pj);
                    ?>
                    <div class="box" style="border-left: 4px solid var(--success-text);">
                        <div
                            style="background:var(--bg-card); padding:10px; border-bottom:1px solid var(--border); margin:-10px -10px 10px -10px; border-radius:6px 6px 0 0;">
                            <strong>Import Date:</strong> <?= htmlspecialchars($parsed_jobs[0]['date']) ?>
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <h3 style="margin:0;">Preview:
                                <?= count($parsed_jobs) ?> Jobs Found
                            </h3>
                            <form method="post">
                                <input type="hidden" name="jobs" value="<?= htmlspecialchars(json_encode($parsed_jobs)) ?>">
                                <?php if (is_admin()): ?>
                                    <select name="target_user"
                                        style="padding:5px; border-radius:4px; border:1px solid var(--border);">
                                        <option value="<?= $_SESSION['user_id'] ?>">My Account</option>
                                        <?php
                                        $users_list = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
                                        foreach ($users_list as $u):
                                            if ($u['id'] == $_SESSION['user_id'])
                                                continue;
                                            ?>
                                            <option value="<?= $u['id'] ?>">
                                                <?= htmlspecialchars($u['username']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <button type="submit" name="import_jobs" class="btn">📥 Import All</button>
                            </form>
                        </div>

                        <div style="max-height: 500px; overflow-y: auto;">
                                <?php foreach ($parsed_jobs as $job):
                                    $is_dup = $job['is_dup'] ?? false;
                                    ?>
                        <div class="job-card"
                                    style="<?= $is_dup ? 'border-left:4px solid var(--danger-text); opacity:0.8;' : '' ?>">
                                    <h4>
                                        <span>#
                                            <?= htmlspecialchars($job['ticket']) ?>
                                            <?php if ($is_dup): ?>
                                                <span
                                                    style="font-size:0.7rem; background:var(--danger-text); color:#fff; padding:2px 4px; border-radius:4px; margin-left:5px;">DUPLICATE</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="tag"
                                            style="background:#eee; color:#333; font-size:0.8rem; padding:2px 6px; border-radius:4px;">
                                            <?= htmlspecialchars($job['type']) ?>
                                        </span>
                                    </h4>
                                    <div class="job-details">
                                        <div><strong>Name:</strong>
                                            <?= htmlspecialchars($job['name'] ?: 'Unknown') ?>
                                        </div>
                                        <div><strong>Phone:</strong>
                                            <?= htmlspecialchars($job['phone']) ?>
                                        </div>
                                        <div style="grid-column: span 2;"><strong>Addr:</strong>
                                            <?= htmlspecialchars($job['address']) ?>
                                        </div>
                                    </div>
                                    <div class="job-details" style="font-size:0.8rem; color:#666;">
                                        <?php if ($job['ont']): ?>
                                            <div><strong>ONT:</strong>
                                                <?= htmlspecialchars($job['ont']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($job['eero']): ?>
                                            <div><strong>Eero:</strong>
                                                <?= htmlspecialchars($job['eero']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($job['drop']): ?>
                                            <div><strong>Drop:</strong>
                                                <?= htmlspecialchars($job['drop']) ?>'
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="job-notes">
                                        <?= nl2br(htmlspecialchars($job['notes'])) ?>
                                    </div>
                                             <div style="margin-top:10px; text-align:right;">
                                                <?php if ($is_dup): ?>
                                                        <span style="color:var(--danger-text); font-size:0.85rem; font-weight:bold;">Already Imported</span>
                                                <?php else: ?>
                                                        <form method="post" style="display:inline;">
                                                            <input type="hidden" name="jobs" value="<?= htmlspecialchars(json_encode([$job])) ?>">
                                                            <button type="submit" name="import_jobs" class="btn btn-small">Import This Job</button>
                                                        </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif (isset($_POST['parse_text'])): ?>
                    <div class="box" style="text-align:center; color:var(--text-muted); padding:40px;">
                        No valid jobs found. Make sure ticket numbers are 9 digits.
                    </div>
                <?php else: ?>
                    <div class="box" style="text-align:center; color:var(--text-muted); padding:40px;">
                        Parsed result preview will appear here.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
    </script>
</body>

</html>