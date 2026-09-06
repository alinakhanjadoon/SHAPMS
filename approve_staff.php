<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- ADMIN CHECK ---------- */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/adminlogin.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli('localhost','root','','SHAPMS');
if ($conn->connect_error) {
    die("DB Connection Failed: " . $conn->connect_error);
}

/* ---------- PROCESS FORM ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate inputs
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $action  = $_POST['action'] ?? '';

    if (!$user_id || !in_array($action, ['approve','reject'])) {
        die("Invalid request");
    }

    /* ---------- REJECT USER ---------- */
    if ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE users SET status='rejected' WHERE user_id=?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        header("Location: admindashboard.php");
        exit();
    }

    /* ---------- GET USER ROLE ---------- */
    $stmt = $conn->prepare("SELECT role, full_name, email, contact FROM users WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        die("User not found");
    }

    $role = $user['role'];

    /* ---------- APPROVE USER ---------- */
    $stmt = $conn->prepare("UPDATE users SET status='active' WHERE user_id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    /* ---------- INSERT INTO ROLE TABLE ---------- */
    $role_table_map = [
        'doctor' => 'doctors',
        'nurse' => 'nurses',
        'pharmacist' => 'pharmacists',
        'receptionist' => 'receptionists'
    ];

    if (array_key_exists($role, $role_table_map)) {
        $table = $role_table_map[$role];
        $stmt = $conn->prepare("INSERT IGNORE INTO $table (user_id) VALUES (?)");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }

    /* ---------- SEND EMAIL NOTIFICATION ---------- */
    $to = $user['email'];
    $subject = "Account Approved | SHAPMS";
    $message = "Hello ".$user['full_name'].",\n\nYour account has been approved by the admin.\nYou can now log in and access your dashboard.\n\nThanks,\nSHAPMS Team";
    $headers = "From: no-reply@shapms.com";
    @mail($to, $subject, $message, $headers); // suppress errors with @

    // Optional: SMS can be added here if API available

    header("Location: admindashboard.php");
    exit();
}
?>
