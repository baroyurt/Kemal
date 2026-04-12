<?php
// modules/auth/layout.php
// Sidebar + topbar şablonu — diğer sayfalar include eder
// Kullanım: $page_title ve $active_nav tanımlandıktan sonra include et
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h($page_title ?? 'CRM Hotspot'); ?> — Otel Yönetim Sistemi</title>
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/style.css">
</head>
<body>

<nav class="sidebar">
    <div class="sidebar-brand">
        <span>🏨</span> Otel CRM
    </div>
    <div class="sidebar-nav">
        <a href="<?php echo APP_URL; ?>/dashboard.php"
           class="<?php echo ($active_nav??'') === 'dashboard' ? 'active' : ''; ?>">
            📊 Dashboard
        </a>

        <div class="nav-section">Misafir</div>
        <a href="<?php echo APP_URL; ?>/modules/guests/index.php"
           class="<?php echo ($active_nav??'') === 'guests' ? 'active' : ''; ?>">
            👥 Misafirler / CRM
        </a>
        <a href="<?php echo APP_URL; ?>/modules/whatsapp/index.php"
           class="<?php echo ($active_nav??'') === 'whatsapp' ? 'active' : ''; ?>">
            💬 WhatsApp Mesajları
        </a>
        <a href="<?php echo APP_URL; ?>/modules/requests/index.php"
           class="<?php echo ($active_nav??'') === 'requests' ? 'active' : ''; ?>">
            🎫 Arıza &amp; Talepler
        </a>

        <div class="nav-section">Altyapı</div>
        <a href="<?php echo APP_URL; ?>/modules/hotspot/index.php"
           class="<?php echo ($active_nav??'') === 'hotspot' ? 'active' : ''; ?>">
            📶 Hotspot Oturumları
        </a>
        <a href="<?php echo APP_URL; ?>/modules/fidelio/index.php"
           class="<?php echo ($active_nav??'') === 'fidelio' ? 'active' : ''; ?>">
            🔄 Fidelio PMS
        </a>

        <div class="nav-section">Yönetim</div>
        <?php if (has_role('admin','it_staff')): ?>
        <a href="<?php echo APP_URL; ?>/modules/reports/index.php"
           class="<?php echo ($active_nav??'') === 'reports' ? 'active' : ''; ?>">
            📈 Raporlar
        </a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <a href="<?php echo APP_URL; ?>/modules/staff/index.php"
           class="<?php echo ($active_nav??'') === 'staff' ? 'active' : ''; ?>">
            👤 Personel
        </a>
        <?php endif; ?>
    </div>
    <div class="sidebar-footer">
        👤 <?php echo h($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?><br>
        <span style="font-size:10px; opacity:.6;"><?php echo h($_SESSION['role'] ?? ''); ?></span><br>
        <a href="<?php echo APP_URL; ?>/logout.php" style="display:inline-block; margin-top:6px;">🚪 Çıkış</a>
    </div>
</nav>

<div class="main-content">
    <div class="topbar">
        <h1><?php echo h($page_title ?? ''); ?></h1>
        <div class="topbar-right">
            <span style="font-size:13px; color:#90a4ae;">
                <?php echo date('d.m.Y H:i'); ?>
            </span>
        </div>
    </div>
