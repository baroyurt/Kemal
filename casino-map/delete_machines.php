<?php
session_start();
if (!isset($_SESSION['login']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

include("config.php");

if (!isset($_POST['ids'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$ids = explode(',', $_POST['ids']);
$ids = array_map('intval', $ids);
$ids_string = implode(',', $ids);

$conn->query("DELETE FROM machines WHERE id IN ($ids_string)");

if ($conn->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'No machines deleted']);
}
?>