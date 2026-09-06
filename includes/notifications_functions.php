<?php
function createNotification($conn, $user_id, $role, $type, $title, $message, $link = null) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, role, type, title, message, link) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssss", $user_id, $role, $type, $title, $message, $link);
    $stmt->execute();
    $stmt->close();
}

function notifyRole($conn, $role, $type, $title, $message, $link = null) {
    $res = $conn->prepare("SELECT user_id FROM users WHERE role = ?");
    $res->bind_param("s", $role);
    $res->execute();
    $result = $res->get_result();
    while ($row = $result->fetch_assoc()) {
        createNotification($conn, $row['user_id'], $role, $type, $title, $message, $link);
    }
    $res->close();
}
?>