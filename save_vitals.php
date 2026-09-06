<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ---------------- Authentication ----------------
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ---------------- Database Connection ----------------
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB Connection failed: '.$conn->connect_error]);
    exit();
}

// ---------------- Only Handle POST ----------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// ---------------- Sanitize & Prepare Data ----------------
$weight = isset($_POST['weight']) && $_POST['weight'] !== '' ? floatval($_POST['weight']) : null;
$calories = isset($_POST['calories']) && $_POST['calories'] !== '' ? intval($_POST['calories']) : null;
$blood_sugar = isset($_POST['blood_sugar']) && $_POST['blood_sugar'] !== '' ? floatval($_POST['blood_sugar']) : null;
$heart_rate = isset($_POST['heart_rate']) && $_POST['heart_rate'] !== '' ? intval($_POST['heart_rate']) : null;
$blood_pressure = $_POST['blood_pressure'] ?? null;
$hemoglobin = isset($_POST['hemoglobin']) && $_POST['hemoglobin'] !== '' ? floatval($_POST['hemoglobin']) : null;

$today = date('Y-m-d');

// ---------------- Prepare INSERT ----------------
$stmt = $conn->prepare("
    INSERT INTO patient_vitals 
    (user_id, date, weight, calories, blood_sugar, heart_rate, blood_pressure, hemoglobin)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: '.$conn->error]);
    exit();
}

// ---------------- Bind Params ----------------
$stmt->bind_param(
    "isdiissd",
    $_SESSION['user_id'], 
    $today, 
    $weight, 
    $calories, 
    $blood_sugar, 
    $heart_rate, 
    $blood_pressure, 
    $hemoglobin
);

// ---------------- Execute & Respond ----------------
if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'vitals' => [
            'day' => date('M d'),
            'weight' => $weight,
            'calories' => $calories,
            'blood_sugar' => $blood_sugar,
            'heart_rate' => $heart_rate,
            'blood_pressure' => $blood_pressure,
            'hemoglobin' => $hemoglobin
        ]
    ]);
} else {
    // Log the error to a file for debugging
    file_put_contents('vitals_error.log', date('Y-m-d H:i:s').' - '.$stmt->error.PHP_EOL, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Execute failed: '.$stmt->error]);
}

$stmt->close();
$conn->close();
?>