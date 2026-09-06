
<?php
session_start();
header('Content-Type: application/json');

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Please login again'
    ]);
    exit();
}

$user_id = $_SESSION['user_id'];

/* ---------- FILE CHECK ---------- */
if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'success' => false,
        'message' => 'No file uploaded or upload error'
    ]);
    exit();
}

$file = $_FILES['profile_image'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

$allowed = ['jpg', 'jpeg', 'png', 'gif'];

if (!in_array($ext, $allowed)) {
    echo json_encode([
        'success' => false,
        'message' => 'Only JPG, JPEG, PNG, GIF allowed'
    ]);
    exit();
}

/* ---------- UPLOAD DIRECTORY (SAFE STRUCTURE) ---------- */
$uploadDir = __DIR__ . "/uploads/profile/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

/* ---------- UNIQUE FILE NAME (FIXES SHAKING/CACHING) ---------- */
$filename = "user_" . $user_id . "_" . time() . "." . $ext;

$targetPath = $uploadDir . $filename;

/* ---------- MOVE FILE ---------- */
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save file'
    ]);
    exit();
}

/* ---------- DATABASE UPDATE ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");

if ($conn->connect_error) {
    echo json_encode([
        'success' => false,
        'message' => 'DB connection failed'
    ]);
    exit();
}

/* PATH STORED IN DB */
$relativePath = "uploads/profile/" . $filename;

$stmt = $conn->prepare("UPDATE users SET profile_image=? WHERE user_id=?");
$stmt->bind_param("si", $relativePath, $user_id);

/* ---------- RESPONSE ---------- */
if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'url' => $relativePath . "?v=" . time()
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>