<?php
require 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: index.php");
    exit;
}

$error = "";
$db = getDB();

// --- CSRF PROTECTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate()) {
    $error = "Session expired. Please try again.";
}

// Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$_POST['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($_POST['password'], $user['password'])) {
            // Success
            $_SESSION['loggedin'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Login - Tech Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background: var(--bg-body);
        }

        .login-box {
            width: 100%;
            max-width: 320px;
            padding: 30px 20px;
            background: var(--bg-card);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        h2 {
            text-align: center;
            margin-top: 0;
            color: var(--text-main);
        }

        input {
            width: 100%;
            padding: 12px;
            margin: 10px 0 20px;
            border: 1px solid var(--border);
            border-radius: 6px;
            box-sizing: border-box;
            background: var(--bg-input);
            color: var(--text-main);
            font-size: 16px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
        }

        button:hover {
            opacity: 0.9;
        }

        label {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
    </style>
</head>

<body>

    <div class="login-box">
        <h2>Tech Portal</h2>

        <?php if ($error): ?>
            <div
                style="background:var(--danger-bg); color:var(--danger-text); padding:10px; border-radius:4px; margin-bottom:15px; text-align:center;">
                <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <label>Username</label>
            <input type="text" name="username" required autofocus>

            <label>Password</label>
            <input type="password" name="password" required>

            <button type="submit">Log In</button>
        </form>
    </div>

    <script>
        if (localStorage.getItem('theme') === 'dark') { document.body.classList.add('dark-mode'); }
    </script>
</body>

</html>