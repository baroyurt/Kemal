<?php
session_start();

if(!isset($_SESSION['login']) || $_SESSION['role'] != 'admin'){
    header("Location: login.php");
    exit;
}

include("config.php");

$message = '';
$error   = '';

// Kullanıcı ekle
if(isset($_POST['create_user'])){
    csrf_verify();
    $username = trim($_POST['new_username']);
    $password = $_POST['new_password'];
    $role     = in_array($_POST['new_role'], ['admin','personel']) ? $_POST['new_role'] : 'personel';

    if(strlen($username) < 3){
        $error = "Kullanıcı adı en az 3 karakter olmalıdır.";
    } elseif(strlen($password) < 6){
        $error = "Şifre en az 6 karakter olmalıdır.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $check->store_result();
        if($check->num_rows > 0){
            $error = "Bu kullanıcı adı zaten kullanılıyor.";
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $hash, $role);
            $stmt->execute();
            $stmt->close();
            $message = "Kullanıcı '" . htmlspecialchars($username) . "' oluşturuldu.";
        }
        $check->close();
    }
}

// Şifre değiştir
if(isset($_POST['change_password'])){
    csrf_verify();
    $uid          = intval($_POST['user_id']);
    $new_password = $_POST['new_password_change'];
    if(strlen($new_password) < 6){
        $error = "Şifre en az 6 karakter olmalıdır.";
    } else {
        $hash = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $uid);
        $stmt->execute();
        $stmt->close();
        $message = "Şifre güncellendi.";
    }
}

// Rol değiştir
if(isset($_POST['change_role'])){
    csrf_verify();
    $uid      = intval($_POST['user_id_role']);
    $new_role = in_array($_POST['user_role'], ['admin','personel']) ? $_POST['user_role'] : 'personel';
    // Kendi rolünü düşürme koruması
    if($_SESSION['username'] && $uid){
        $me = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $me->bind_param("i", $uid);
        $me->execute();
        $me_row = $me->get_result()->fetch_assoc();
        $me->close();
        if($me_row && $me_row['username'] === $_SESSION['username'] && $new_role !== 'admin'){
            $error = "Kendi admin rolünüzü düşüremezsiniz.";
        } else {
            $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param("si", $new_role, $uid);
            $stmt->execute();
            $stmt->close();
            $message = "Kullanıcı rolü güncellendi.";
        }
    }
}

// Kullanıcı sil (POST + CSRF koruması)
if(isset($_POST['delete_user'])){
    csrf_verify();
    $uid = intval($_POST['delete_user_id']);
    // Kendini silme koruması
    $self_check = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $self_check->bind_param("i", $uid);
    $self_check->execute();
    $self_row = $self_check->get_result()->fetch_assoc();
    $self_check->close();
    if($self_row && $self_row['username'] === $_SESSION['username']){
        $error = "Kendi hesabınızı silemezsiniz.";
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        header("Location: users.php?deleted=1");
        exit;
    }
}

$users = $conn->query("SELECT id, username, role, created_at FROM users ORDER BY username");
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kullanıcı Yönetimi</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        h2 { margin: 0; color: #333; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h3 { margin-top: 0; color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .form-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 10px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 140px; }
        label { font-size: 13px; color: #666; font-weight: bold; }
        input[type="text"], input[type="password"], select {
            padding: 8px 10px; border: 1px solid #ddd; border-radius: 5px;
            font-size: 14px; box-sizing: border-box; width: 100%;
        }
        button { padding: 8px 18px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; white-space: nowrap; }
        button:hover { background: #45a049; }
        .btn-danger { background: #f44336; }
        .btn-danger:hover { background: #c62828; }
        .btn-info { background: #2196F3; }
        .btn-info:hover { background: #1565C0; }
        .message { background: #e8f5e9; color: #2e7d32; padding: 10px 16px; border-radius: 5px; margin-bottom: 15px; border-left: 4px solid #4CAF50; }
        .error-msg { background: #ffebee; color: #b71c1c; padding: 10px 16px; border-radius: 5px; margin-bottom: 15px; border-left: 4px solid #f44336; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #4CAF50; color: white; padding: 10px 12px; text-align: left; font-size: 13px; }
        td { padding: 10px 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        tr:hover { background: #f9f9f9; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; }
        .badge.admin { background: #4CAF50; color: white; }
        .badge.personel { background: #2196F3; color: white; }
        .inline-form { display: inline-flex; gap: 6px; align-items: center; }
        .inline-form select { padding: 4px 8px; font-size: 12px; }
        .inline-form button { padding: 4px 10px; font-size: 12px; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 999; justify-content: center; align-items: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: white; border-radius: 10px; padding: 24px; width: 360px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
        .modal h4 { margin: 0 0 16px; }
        .modal input { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 12px; box-sizing: border-box; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .modal-actions button { padding: 8px 18px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h2>👤 Kullanıcı Yönetimi</h2>
        <a href="dashboard.php" style="color:#666; text-decoration:none;">← Panoya Dön</a>
    </div>

    <?php if(!empty($message)): ?>
        <div class="message"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?>
        <div class="message">Kullanıcı silindi.</div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Yeni Kullanıcı Ekle -->
    <div class="card">
        <h3>➕ Yeni Kullanıcı Ekle</h3>
        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Kullanıcı Adı</label>
                    <input type="text" name="new_username" placeholder="kullanici_adi" required minlength="3">
                </div>
                <div class="form-group">
                    <label>Şifre</label>
                    <input type="password" name="new_password" placeholder="En az 6 karakter" required minlength="6">
                </div>
                <div class="form-group" style="max-width:160px;">
                    <label>Rol</label>
                    <select name="new_role">
                        <option value="personel">Personel</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group" style="max-width:120px; justify-content:flex-end;">
                    <button type="submit" name="create_user">Ekle</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Kullanıcı Listesi -->
    <div class="card">
        <h3>📋 Mevcut Kullanıcılar</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Kullanıcı Adı</th>
                    <th>Rol</th>
                    <th>Oluşturulma</th>
                    <th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
            <?php while($u = $users->fetch_assoc()): ?>
                <tr>
                    <td><?php echo intval($u['id']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($u['username']); ?>
                        <?php if($u['username'] === $_SESSION['username']): ?>
                            <span style="color:#999; font-size:11px;">(siz)</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo htmlspecialchars($u['role']); ?>"><?php echo htmlspecialchars($u['role']); ?></span></td>
                    <td><?php echo htmlspecialchars($u['created_at'] ?? '-'); ?></td>
                    <td>
                        <!-- Şifre değiştir butonu -->
                        <button class="btn-info" style="font-size:12px; padding:4px 10px;" onclick="openPassModal(<?php echo intval($u['id']); ?>, '<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>')">🔑 Şifre</button>
                        
                        <!-- Rol değiştir -->
                        <form method="post" class="inline-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="user_id_role" value="<?php echo intval($u['id']); ?>">
                            <select name="user_role">
                                <option value="personel" <?php echo $u['role']==='personel'?'selected':''; ?>>Personel</option>
                                <option value="admin" <?php echo $u['role']==='admin'?'selected':''; ?>>Admin</option>
                            </select>
                            <button type="submit" name="change_role" style="font-size:12px; padding:4px 10px; background:#FF9800;">Rol Güncelle</button>
                        </form>

                        <!-- Sil -->
                        <?php if($u['username'] !== $_SESSION['username']): ?>
                            <form method="post" class="inline-form" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_user_id" value="<?php echo intval($u['id']); ?>">
                                <button type="submit" name="delete_user" class="btn-danger" style="font-size:12px; padding:4px 10px;">🗑️ Sil</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Şifre Değiştir Modal -->
<div class="modal-overlay" id="passModal">
    <div class="modal">
        <h4>🔑 Şifre Değiştir: <span id="modalUsername"></span></h4>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="user_id" id="modalUserId">
            <input type="password" name="new_password_change" placeholder="Yeni şifre (en az 6 karakter)" required minlength="6">
            <div class="modal-actions">
                <button type="button" onclick="closePassModal()" style="background:#999;">İptal</button>
                <button type="submit" name="change_password">Kaydet</button>
            </div>
        </form>
    </div>
</div>
<script>
function openPassModal(id, username) {
    document.getElementById('modalUserId').value = id;
    document.getElementById('modalUsername').textContent = username;
    document.getElementById('passModal').classList.add('open');
}
function closePassModal() {
    document.getElementById('passModal').classList.remove('open');
}
document.getElementById('passModal').addEventListener('click', function(e){
    if(e.target === this) closePassModal();
});
</script>
</body>
</html>
