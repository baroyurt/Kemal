<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['group_id']) || !isset($_POST['machine_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$group_id = intval($_POST['group_id']);
$machine_id = intval($_POST['machine_id']);

$check = $conn->query("SELECT * FROM machine_group_relations WHERE group_id = $group_id AND machine_id = $machine_id");
if ($check->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'Machine already in group']);
    exit;
}

$conn->query("INSERT INTO machine_group_relations (machine_id, group_id) VALUES ($machine_id, $group_id)");

if ($conn->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>