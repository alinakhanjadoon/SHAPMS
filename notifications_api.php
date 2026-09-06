<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { echo json_encode(['error' => 'unauthorized']); exit(); }

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) { echo json_encode(['error' => 'db']); exit(); }

$user_id = (int)$_SESSION['user_id'];
$action  = $_GET['action'] ?? 'fetch';

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['ok' => true]);
    exit();
}

if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit();
}

$stmt = $conn->prepare("SELECT id, type, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) $items[] = $row;
$stmt->close();

$countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
$countStmt->bind_param("i", $user_id);
$countStmt->execute();
$unread = $countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

echo json_encode(['items' => $items, 'unread' => (int)$unread]);
?>