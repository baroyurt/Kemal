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
$conn->query("DELETE FROM machine_groups WHERE id = $group_id");

echo json_encode(['success' => true]);
?>