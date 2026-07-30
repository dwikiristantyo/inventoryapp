<?php
// modules/auth/login.php
session_start();
require_once __DIR__ . '/../../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id  = strtolower(trim($_POST['user_id'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (!empty($user_id) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM sysuser WHERE LOWER(user_id) = :uid AND user_password = :pwd");
        $stmt->execute([':uid' => $user_id, ':pwd' => $password]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['user_name'] = $user['user_name'];
            $_SESSION['group_id']  = $user['group_id'];

            header("Location: ../../index.php");
            exit;
        } else {
            $error = "User ID atau Password salah!";
        }
    } else {
        $error = "Harap isi semua kolom!";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Login - Inventory System</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #e9ecef; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); width: 100%; max-width: 360px; }
        .login-card h2 { text-align: center; margin-top: 0; margin-bottom: 20px; color: #212529; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 5px; color: #495057; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
        .btn-login { width: 100%; padding: 10px; background: #0d6efd; border: none; border-radius: 4px; color: #fff; font-weight: bold; cursor: pointer; font-size: 14px; margin-top: 10px; }
        .btn-login:hover { background: #0b5ed7; }
        .alert { background: #f8d7da; color: #842029; padding: 10px; border-radius: 4px; font-size: 13px; margin-bottom: 15px; text-align: center; }
    </style>
</head>
<body>

<div class="login-card">
    <h2>INVENTORY SYSTEM</h2>
    
    <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>User ID</label>
            <input type="text" name="user_id" placeholder="Masukkan User ID" required autofocus>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Masukkan Password" required>
        </div>
        <button type="submit" class="btn-login">LOG IN</button>
    </form>
</div>

</body>
</html>