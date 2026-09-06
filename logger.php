<?php

/**
 * SHAPMS - Audit Logger
 * Records all important user actions for hospital accountability
 */

function logAction($conn, $user_id, $role, $action)
{
    // Get user IP address (important for hospital audit trails)
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    // Optional: sanitize action text
    $action = trim($action);

    // Insert log into database
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (user_id, role, action, ip_address)
        VALUES (?, ?, ?, ?)
    ");

    if (!$stmt) {
        // If logging fails, don't break system
        return false;
    }

    $stmt->bind_param("isss", $user_id, $role, $action, $ip_address);
    $stmt->execute();
    $stmt->close();

    return true;
}

?>