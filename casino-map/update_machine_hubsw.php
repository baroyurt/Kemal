<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['machine_id'])) {
    echo json_encode(['success' => false, 'error' => 'Missing machine_id']);
    exit;
}

$machine_id = intval($_POST['machine_id']);
$hub_sw = isset($_POST['hub_sw']) ? intval($_POST['hub_sw']) : 0;
$hub_sw_cable = isset($_POST['hub_sw_cable']) ? $conn->real_escape_string(trim($_POST['hub_sw_cable'])) : '';

$stmt = $conn->prepare("UPDATE machines SET hub_sw = ?, hub_sw_cable = ? WHERE id = ?");
$stmt->bind_param("isi", $hub_sw, $hub_sw_cable, $machine_id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
?>
