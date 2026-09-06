<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lab') {
    http_response_code(403);
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connection_failed']);
    exit();
}

$uid = intval($_SESSION['user_id']);

$my_total     = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid")->fetch_row()[0] ?? 0;
$my_pending   = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND status='pending'")->fetch_row()[0] ?? 0;
$my_completed = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND status='completed'")->fetch_row()[0] ?? 0;
$my_today     = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND DATE(uploaded_at)=CURDATE()")->fetch_row()[0] ?? 0;

$daily_data = [];
$dres = $conn->query("SELECT DATE_FORMAT(uploaded_at,'%b %d') as day, COUNT(*) as total
                      FROM lab_reports
                      WHERE uploaded_by=$uid AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                      GROUP BY DATE(uploaded_at), DATE_FORMAT(uploaded_at,'%b %d')
                      ORDER BY DATE(uploaded_at)");
if ($dres) while ($row = $dres->fetch_assoc()) $daily_data[] = $row;

$test_type_data = [];
$tres = $conn->query("SELECT test_name, COUNT(*) as total FROM lab_reports WHERE uploaded_by=$uid GROUP BY test_name ORDER BY total DESC LIMIT 6");
if ($tres) while ($row = $tres->fetch_assoc()) $test_type_data[] = $row;

$conn->close();

echo json_encode([
    'my_total'     => intval($my_total),
    'my_pending'   => intval($my_pending),
    'my_completed' => intval($my_completed),
    'my_today'     => intval($my_today),
    'days'         => empty($daily_data) ? [] : array_column($daily_data, 'day'),
    'day_vals'     => empty($daily_data) ? [] : array_map('intval', array_column($daily_data, 'total')),
    'test_labels'  => empty($test_type_data) ? ['No Data'] : array_column($test_type_data, 'test_name'),
    'test_counts'  => empty($test_type_data) ? [1] : array_map('intval', array_column($test_type_data, 'total')),
]);