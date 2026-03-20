<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['name']) || trim($_POST['name']) === '') {
    echo json_encode(['success' => false, 'error' => 'Bölge adı gerekli']);
    exit;
}

$name  = trim($_POST['name']);
$color = isset($_POST['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'])
       ? $_POST['color']
       : '#607D8B';

$stmt = $conn->prepare("INSERT INTO regions (name, color) VALUES (?, ?)");
$stmt->bind_param("ss", $name, $color);
$stmt->execute();
$id = $conn->insert_id;
$stmt->close();

echo json_encode(['success' => true, 'id' => $id]);
?>
