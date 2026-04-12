<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_role('admin');

$page_title = 'Personel Yönetimi';
$active_nav = 'staff';
$message = '';
$error   = '';

$ROLES = ['admin' => 'Admin', 'reception' => 'Resepsiyon', 'it_staff' => 'BT Personeli', 'readonly' => 'Salt Okunur'];

// --- Yeni Personel Ekle ---
if (isset($_POST['create_user'])) {
    csrf_verify();
    $username  = trim($_POST['new_username']);
    $full_name = trim($_POST['new_full_name']);
    $email     = trim($_POST['new_email']);
    $phone     = trim($_POST['new_phone']);
    $password  = $_POST['new_password'];
    $role      = array_key_exists($_POST['new_role'], $ROLES) ? $_POST['new_role'] : 'reception';

    if (strlen($username) < 3) {
        $error = 'Kullanıcı adı en az 3 karakter olmalıdır.';
    } elseif (strlen($password) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır.';
    } else {
        $chk = $conn->prepare('SELECT id FROM users WHERE username=?');
        $chk->bind_param('s', $username);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $error = 'Bu kullanıcı adı zaten kullanılıyor.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $st   = $conn->prepare('INSERT INTO users (username,full_name,email,phone,password,role) VALUES (?,?,?,?,?,?)');
            $st->bind_param('ssssss', $username, $full_name, $email, $phone, $hash, $role);
            $st->execute();
            $new_id = (int)$conn->insert_id;
            $st->close();
            audit_log($conn, 'create_user', 'user', $new_id, "Yeni personel: $username ($role)");
            $message = "Personel '$username' oluşturuldu.";
        }
        $chk->close();
    }
}

// --- Şifre Değiştir ---
if (isset($_POST['change_password'])) {
    csrf_verify();
    $uid  = (int)$_POST['user_id'];
    $pass = $_POST['new_password_change'];
    if (strlen($pass) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır.';
    } else {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $st   = $conn->prepare('UPDATE users SET password=? WHERE id=?');
        $st->bind_param('si', $hash, $uid);
        $st->execute();
        $st->close();
        audit_log($conn, 'change_password', 'user', $uid);
        $message = 'Şifre güncellendi.';
    }
}

// --- Rol Değiştir ---
if (isset($_POST['change_role'])) {
    csrf_verify();
    $uid  = (int)$_POST['user_id_role'];
    $role = array_key_exists($_POST['user_role'], $ROLES) ? $_POST['user_role'] : 'reception';
    // Kendi rolünü düşürme koruması
    if ($uid === (int)$_SESSION['user_id'] && $role !== 'admin') {
        $error = 'Kendi admin rolünüzü düşüremezsiniz.';
    } else {
        $st = $conn->prepare('UPDATE users SET role=? WHERE id=?');
        $st->bind_param('si', $role, $uid);
        $st->execute();
        $st->close();
        audit_log($conn, 'change_role', 'user', $uid, "Yeni rol: $role");
        $message = 'Kullanıcı rolü güncellendi.';
    }
}

// --- Aktif/Pasif Değiştir ---
if (isset($_POST['toggle_active'])) {
    csrf_verify();
    $uid    = (int)$_POST['toggle_user_id'];
    $active = (int)$_POST['toggle_value'];
    if ($uid === (int)$_SESSION['user_id']) {
        $error = 'Kendi hesabınızı devre dışı bırakamazsınız.';
    } else {
        $st = $conn->prepare('UPDATE users SET is_active=? WHERE id=?');
        $st->bind_param('ii', $active, $uid);
        $st->execute();
        $st->close();
        audit_log($conn, 'toggle_active', 'user', $uid, "is_active=$active");
        $message = 'Durum güncellendi.';
    }
}

// --- Kullanıcı Sil ---
if (isset($_POST['delete_user'])) {
    csrf_verify();
    $uid = (int)$_POST['delete_user_id'];
    if ($uid === (int)$_SESSION['user_id']) {
        $error = 'Kendi hesabınızı silemezsiniz.';
    } else {
        $st = $conn->prepare('DELETE FROM users WHERE id=?');
        $st->bind_param('i', $uid);
        $st->execute();
        $st->close();
        audit_log($conn, 'delete_user', 'user', $uid);
        header('Location: index.php?deleted=1');
        exit;
    }
}

$users = $conn->query('SELECT id,username,full_name,email,phone,role,is_active,created_at FROM users ORDER BY username');

include __DIR__ . '/../../modules/auth/layout.php';
?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">✅ Kullanıcı silindi.</div>
<?php endif; ?>
<?php if ($message): ?><div class="alert alert-success">✅ <?php echo h($message); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">❌ <?php echo h($error); ?></div><?php endif; ?>

<!-- Yeni Personel Ekle -->
<div class="card">
    <div class="card-title">➕ Yeni Personel Ekle</div>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div class="form-row">
            <div class="form-group">
                <label>Kullanıcı Adı *</label>
                <input type="text" name="new_username" class="form-control" required minlength="3" placeholder="kullanici_adi">
            </div>
            <div class="form-group">
                <label>Ad Soyad *</label>
                <input type="text" name="new_full_name" class="form-control" required placeholder="Ad Soyad">
            </div>
            <div class="form-group">
                <label>E-posta</label>
                <input type="email" name="new_email" class="form-control" placeholder="ornek@otel.com">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Telefon</label>
                <input type="text" name="new_phone" class="form-control" placeholder="+90...">
            </div>
            <div class="form-group">
                <label>Şifre * (en az 6 karakter)</label>
                <input type="password" name="new_password" class="form-control" required minlength="6">
            </div>
            <div class="form-group" style="max-width:180px;">
                <label>Rol *</label>
                <select name="new_role" class="form-control">
                    <?php foreach ($ROLES as $k => $v): ?>
                        <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="max-width:120px; justify-content:flex-end; display:flex; align-items:flex-end;">
                <button type="submit" name="create_user" class="btn btn-primary">Ekle</button>
            </div>
        </div>
    </form>
</div>

<!-- Personel Listesi -->
<div class="card">
    <div class="card-title">👥 Personel Listesi</div>
    <div class="table-wrapper">
    <table>
        <thead><tr>
            <th>ID</th><th>Kullanıcı Adı</th><th>Ad Soyad</th>
            <th>E-posta</th><th>Telefon</th><th>Rol</th><th>Durum</th><th>Kayıt</th><th>İşlemler</th>
        </tr></thead>
        <tbody>
        <?php while ($u = $users->fetch_assoc()): ?>
        <tr>
            <td><?php echo $u['id']; ?></td>
            <td>
                <?php echo h($u['username']); ?>
                <?php if ($u['id'] == $_SESSION['user_id']): ?>
                    <span style="color:#90a4ae; font-size:10px;">(siz)</span>
                <?php endif; ?>
            </td>
            <td><?php echo h($u['full_name']); ?></td>
            <td><?php echo h($u['email'] ?? ''); ?></td>
            <td><?php echo h($u['phone'] ?? ''); ?></td>
            <td>
                <?php
                $role_colors = ['admin'=>'danger','reception'=>'info','it_staff'=>'warning','readonly'=>'secondary'];
                $rc = $role_colors[$u['role']] ?? 'secondary';
                echo '<span class="badge badge-' . $rc . '">' . h($ROLES[$u['role']] ?? $u['role']) . '</span>';
                ?>
            </td>
            <td>
                <?php if ($u['is_active']): ?>
                    <span class="badge badge-success">Aktif</span>
                <?php else: ?>
                    <span class="badge badge-danger">Pasif</span>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap;"><?php echo date('d.m.Y', strtotime($u['created_at'])); ?></td>
            <td style="white-space:nowrap;">
                <!-- Şifre -->
                <button class="btn btn-info btn-sm"
                        onclick="openPassModal(<?php echo $u['id']; ?>, '<?php echo h($u['username']); ?>')">
                    🔑
                </button>

                <!-- Rol -->
                <form method="post" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="user_id_role" value="<?php echo $u['id']; ?>">
                    <select name="user_role" class="form-control" style="display:inline-block;width:auto;padding:3px 6px;font-size:12px;">
                        <?php foreach ($ROLES as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $u['role']===$k?'selected':''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="change_role" class="btn btn-warning btn-sm">Kaydet</button>
                </form>

                <!-- Aktif/Pasif toggle -->
                <?php if ($u['id'] != $_SESSION['user_id']): ?>
                <form method="post" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="toggle_user_id" value="<?php echo $u['id']; ?>">
                    <input type="hidden" name="toggle_value" value="<?php echo $u['is_active'] ? 0 : 1; ?>">
                    <button type="submit" name="toggle_active"
                            class="btn btn-sm <?php echo $u['is_active'] ? 'btn-secondary' : 'btn-success'; ?>">
                        <?php echo $u['is_active'] ? '⏸ Pasif' : '▶ Aktif'; ?>
                    </button>
                </form>

                <!-- Sil -->
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Bu personeli silmek istiyor musunuz?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="delete_user_id" value="<?php echo $u['id']; ?>">
                    <button type="submit" name="delete_user" class="btn btn-danger btn-sm">🗑</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- İşlem Kayıtları -->
<?php
$logs = $conn->query(
    "SELECT l.*, u.username FROM staff_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.created_at DESC LIMIT 20"
);
?>
<div class="card">
    <div class="card-title">📋 Son İşlem Kayıtları</div>
    <div class="table-wrapper">
    <table>
        <thead><tr><th>Zaman</th><th>Personel</th><th>İşlem</th><th>Hedef</th><th>Açıklama</th><th>IP</th></tr></thead>
        <tbody>
        <?php while ($l = $logs->fetch_assoc()): ?>
        <tr>
            <td style="white-space:nowrap;"><?php echo date('d.m.Y H:i', strtotime($l['created_at'])); ?></td>
            <td><?php echo h($l['username'] ?? 'Sistem'); ?></td>
            <td><?php echo h($l['action']); ?></td>
            <td><?php echo h($l['target_type'] . ($l['target_id'] ? ' #' . $l['target_id'] : '')); ?></td>
            <td><?php echo h($l['description'] ?? ''); ?></td>
            <td><?php echo h($l['ip_address'] ?? ''); ?></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Şifre Değiştir Modal -->
<div class="modal-overlay" id="passModal">
    <div class="modal-box" style="width:360px;">
        <div class="modal-title">🔑 Şifre Değiştir: <span id="modalUsername"></span></div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="user_id" id="modalUserId">
            <div class="form-group">
                <label>Yeni Şifre (en az 6 karakter)</label>
                <input type="password" name="new_password_change" class="form-control" required minlength="6">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePassModal()">İptal</button>
                <button type="submit" name="change_password" class="btn btn-primary">Kaydet</button>
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
    if (e.target === this) closePassModal();
});
</script>

</div></body></html>
