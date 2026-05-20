<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['user_id'] = $_POST['user_id'] ?? 'test-user';
    header('Location: test-login.php');
    exit;
}

$loggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Test - Login</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 2rem auto; padding: 0 1rem; }
        .status { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .logged-in { background: #d4edda; color: #155724; }
        .logged-out { background: #f8d7da; color: #721c24; }
        form { margin-top: 1rem; }
        input, button { padding: 0.5rem; font-size: 1rem; }
        .note { background: #fff3cd; padding: 1rem; border-radius: 8px; margin-top: 2rem; font-size: 0.9rem; }
    </style>
</head>
<body>
    <h1>Recorder Upload Test</h1>

    <?php if ($loggedIn): ?>
        <div class="status logged-in">
            Logged in as <strong><?= htmlspecialchars($_SESSION['user_id']) ?></strong>
        </div>
        <p>The session cookie is now set. The recorder will send preflight requests
        to this PHP server with your session, allowing it to request an upload token
        from the Go microservice.</p>
        <form method="POST">
            <button type="submit" name="logout" value="1">Logout</button>
        </form>

        <?php
        if (isset($_POST['logout'])) {
            session_destroy();
            header('Location: test-login.php');
            exit;
        }
        ?>

        <div class="note">
            <strong>Next step:</strong> Open the recorder app at
            <code>http://localhost:5173</code> with the upload config set
            (see docs/local-testing.md).
        </div>
    <?php else: ?>
        <div class="status logged-out">
            Not logged in. Enter any user ID to simulate a login.
        </div>
        <form method="POST">
            <input type="text" name="user_id" placeholder="User ID" value="test-user" required>
            <button type="submit">Login</button>
        </form>
    <?php endif; ?>
</body>
</html>
