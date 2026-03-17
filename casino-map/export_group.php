<?php
session_start();

if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}

include("config.php");

if(!isset($_GET['group_id'])){
    die("Grup ID gerekli!");
}

$group_id = intval($_GET['group_id']);

$group = $conn->query("SELECT * FROM machine_groups WHERE id = $group_id")->fetch_assoc();
if(!$group){
    die("Grup bulunamadı!");
}

$machines = $conn->query("
    SELECT m.* 
    FROM machines m
    INNER JOIN machine_group_relations mgr ON m.id = mgr.machine_id
    WHERE mgr.group_id = $group_id
    ORDER BY m.machine_no
");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $group['group_name'] . '_' . date('Y-m-d') . '.csv"');

echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Makine No',
    'IP Adresi',
    'MAC Adresi',
    'Z Katmanı',
    'X Koordinatı',
    'Y Koordinatı',
    'Döndürme',
    'Not'
]);

$z_levels = [
    0 => 'Yüksek Tavan',
    1 => 'Alçak Tavan',
    2 => 'Yüksek Tavan 2',
    3 => 'Alt Salon'
];

while($row = $machines->fetch_assoc()){
    fputcsv($output, [
        $row['machine_no'],
        $row['ip'],
        $row['mac'],
        $z_levels[$row['pos_z']] ?? 'Kat ' . $row['pos_z'],
        $row['pos_x'],
        $row['pos_y'],
        $row['rotation'] . '°',
        $row['note']
    ]);
}

fclose($output);
exit;
?>