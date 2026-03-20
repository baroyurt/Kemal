<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Yetkisiz erişim.']);
    exit;
}

include("config.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Geçersiz istek.']);
    exit;
}

csrf_verify();

$machine_no = trim($_POST['machine_no'] ?? '');
$ip         = trim($_POST['ip']         ?? '');
$mac        = trim($_POST['mac']        ?? '');
$pos_z      = intval($_POST['pos_z']    ?? 0);
$note       = trim($_POST['note']       ?? '');

if ($machine_no === '' || $ip === '' || $mac === '') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Makine No, IP Adresi ve MAC Adresi zorunludur.']);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO machines (machine_no, ip, mac, pos_z, pos_x, pos_y, rotation, note) VALUES (?, ?, ?, ?, 0, 0, 0, ?)"
);
$stmt->bind_param("sssis", $machine_no, $ip, $mac, $pos_z, $note);

if ($stmt->execute()) {
    $new_id = $conn->insert_id;
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode([
        'success'    => true,
        'id'         => $new_id,
        'machine_no' => $machine_no,
        'ip'         => $ip,
        'mac'        => $mac,
        'pos_z'      => $pos_z,
        'note'       => $note,
    ]);
} else {
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Veritabanı hatası: ' . $conn->error]);
}
