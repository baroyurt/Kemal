<?php
session_start();
if (!isset($_SESSION['login'])) {
    http_response_code(403);
    echo "Yetkisiz erişim";
    exit;
}

if ($_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo "Bu işlem için yetkiniz yok";
    exit;
}

include("config.php");

if (isset($_POST['id']) && isset($_POST['note'])) {
    $id = intval($_POST['id']);
    $note = $conn->real_escape_string($_POST['note']);
    
    if (empty($note)) {
        $note = NULL;
    }
    
    $stmt = $conn->prepare("UPDATE machines SET note = ? WHERE id = ?");
    $stmt->bind_param("si", $note, $id);
    
    if ($stmt->execute()) {
        echo "OK";
    } else {
        http_response_code(500);
        echo "Veritabanı hatası";
    }
    
    $stmt->close();
} else {
    http_response_code(400);
    echo "Geçersiz istek";
}
?>