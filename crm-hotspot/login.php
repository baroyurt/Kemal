<?php
session_start();
require_once __DIR__ . '/config/config.php';

// Zaten giriş yaptıysa yönlendir
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$error = '';
if (isset($_POST['username'])) {
    csrf_verify();
    $username = trim($_POST['username']);
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare('SELECT id, username, full_name, password, role, is_active FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $error = 'Kullanıcı adı veya şifre hatalı.';
    } elseif (!$user['is_active']) {
        $error = 'Hesabınız devre dışı bırakılmıştır.';
    } elseif (!password_verify($password, $user['password'])) {
        $error = 'Kullanıcı adı veya şifre hatalı.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['last_activity'] = time();
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş — Otel CRM Hotspot</title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-box">
        <div class="login-logo">🏨</div>
        <div class="login-title">Otel CRM &amp; Hotspot</div>

        <?php if (isset($_GET['timeout'])): ?>
            <div class="alert alert-warning">Oturum zaman aşımına uğradı. Lütfen tekrar giriş yapın.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Kullanıcı Adı</label>
                <input type="text" name="username" class="form-control" required autofocus
                       value="<?php echo h($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Şifre</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:10px;">
                🔐 Giriş Yap
            </button>
        </form>

        <p style="text-align:center; font-size:12px; color:#90a4ae; margin-top:18px;">
            Varsayılan: admin / admin123
        </p>
    </div>
</div>
</body>
</html>
