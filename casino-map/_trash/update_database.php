<?php
/**
 * update_database.php
 * Kullanıcı yönetimine yönlendirildi.
 */
session_start();

if (!isset($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}

// Bu sayfa artık kullanıcı yönetimine yönlendirildi
header("Location: users.php");
exit;
