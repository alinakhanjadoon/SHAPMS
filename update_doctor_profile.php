<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}

$user_id = (int)$_SESSION['user_id'];

$full_name      = trim($_POST['full_name'] ?? '');
$specialty      = trim($_POST['specialty'] ?? '');
$contact        = trim($_POST['contact'] ?? '');
$experience     = trim($_POST['experience'] ?? '');
$qualifications = trim($_POST['qualifications'] ?? '');

/* update users */
$stmt = $conn->prepare("UPDATE users SET full_name=? WHERE user_id=?");
$stmt->bind_param("si", $full_name, $user_id);
$stmt->execute();
$stmt->close();

/* update doctors */
$stmt = $conn->prepare("
    UPDATE doctors 
    SET specialty=?, contact=?, experience=?, qualifications=? 
    WHERE user_id=?
");
$stmt->bind_param(
    "ssssi",
    $specialty,
    $contact,
    $experience,
    $qualifications,
    $user_id
);
$stmt->execute();
$stmt->close();

/* upload doctor image */
if (!empty($_FILES['profile_picture']['name'])) {

    $dir = "uploads/doctors/";

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
    $filename = "doctor_" . $user_id . "_" . time() . "." . $ext;

    move_uploaded_file(
        $_FILES['profile_picture']['tmp_name'],
        $dir . $filename
    );

    $stmt = $conn->prepare("
        UPDATE doctors
        SET profile_picture=?
        WHERE user_id=?
    ");
    $stmt->bind_param("si", $filename, $user_id);
    $stmt->execute();
    $stmt->close();
}

$conn->close();

header("Location: doctordashboard.php");
exit();
?>