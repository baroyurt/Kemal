<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['group_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing group_id']);
    exit;
}

$group_id = intval($_POST['group_id']);
$updates = [];
$params = [];
$types = '';

if (isset($_POST['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'])) {
    $updates[] = "color = ?";
    $params[] = $_POST['color'];
    $types .= 's';
}

if (isset($_POST['group_name']) && trim($_POST['group_name']) !== '') {
    $updates[] = "group_name = ?";
    $params[] = trim($_POST['group_name']);
    $types .= 's';
}

if (empty($updates)) {
    echo json_encode(['success' => false, 'error' => 'Nothing to update']);
    exit;
}

$params[] = $group_id;
$types .= 'i';

$sql = "UPDATE machine_groups SET " . implode(', ', $updates) . " WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
?>
