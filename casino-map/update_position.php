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

if (isset($_POST['id'])) {
    $id = intval($_POST['id']);
    
    if (isset($_POST['rotation']) && !isset($_POST['x']) && !isset($_POST['y']) && !isset($_POST['z'])) {
        $rotation = intval($_POST['rotation']);
        $stmt = $conn->prepare("UPDATE machines SET rotation = ? WHERE id = ?");
        $stmt->bind_param("ii", $rotation, $id);
        $stmt->execute();
        $stmt->close();
        echo "OK";
        exit;
    }
    
    if (isset($_POST['x'], $_POST['y'])) {
        $x = intval($_POST['x']);
        $y = intval($_POST['y']);
        $z = isset($_POST['z']) ? intval($_POST['z']) : 0;
        $rotation = isset($_POST['rotation']) ? intval($_POST['rotation']) : 0;
        
        $stmt = $conn->prepare("UPDATE machines SET pos_x = ?, pos_y = ?, pos_z = ?, rotation = ? WHERE id = ?");
        $stmt->bind_param("iiiii", $x, $y, $z, $rotation, $id);
        $stmt->execute();
        $stmt->close();
        echo "OK";
        exit;
    }
}

http_response_code(400);
echo "Geçersiz istek";
?>