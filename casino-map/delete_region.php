<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['id'])) {
    echo json_encode(['success' => false, 'error' => 'Eksik parametre']);
    exit;
}

$id   = intval($_POST['id']);
$stmt = $conn->prepare("DELETE FROM regions WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
?>
