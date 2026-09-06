<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../auth/login.php");
    exit();
}

/* ---------- DB ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error");

/* ---------- GET patient_id ---------- */
$stmt = $conn->prepare("SELECT patient_id FROM patients WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($patient_id);
$stmt->fetch();
$stmt->close();

/* ---------- GET BILL ---------- */
if (!isset($_GET['bill_id'])) die("Invalid request");

$bill_id = intval($_GET['bill_id']);

$stmt = $conn->prepare("
    SELECT * FROM billing 
    WHERE bill_id=? AND patient_id=?
");
$stmt->bind_param("ii", $bill_id, $patient_id);
$stmt->execute();
$bill = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bill) die("Unauthorized access");

/* ---------- HANDLE UPLOAD ---------- */
$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === 0) {

        $allowed = ['jpg','jpeg','png','webp'];
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $message = "❌ Only JPG, PNG, WEBP allowed";
        } else {

            $dir = __DIR__ . "/../uploads/receipts/";

            /* ENSURE DIRECTORY EXISTS */
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            /* DEBUG (uncomment if needed) */
            // echo "DIR: $dir <br>";
            // echo is_writable($dir) ? "Writable" : "Not Writable";
            // print_r($_FILES);
            // exit;

            $file_name = "receipt_" . $bill_id . "_" . time() . "." . $ext;
            $target = $dir . $file_name;

            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $target)) {

                /* UPDATE DB */
                $stmt = $conn->prepare("
                    UPDATE billing 
                    SET receipt_image=?, 
                        payment_status='paid',
                        verification_status='pending',
                        payment_date=NOW()
                    WHERE bill_id=? AND patient_id=?
                ");
                $stmt->bind_param("sii", $file_name, $bill_id, $patient_id);
                $stmt->execute();
                $stmt->close();

                header("Location: billing.php");
                exit();

            } else {
                $message = "❌ Upload failed (move_uploaded_file error)";
            }
        }

    } else {
        $message = "❌ File upload error or no file selected";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Upload Receipt</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

<style>
body{
    font-family:'Poppins',sans-serif;
    background:linear-gradient(135deg,#667eea,#764ba2);
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    margin:0;
}
.card{
    background:#fff;
    padding:30px;
    border-radius:15px;
    width:400px;
    text-align:center;
    box-shadow:0 10px 25px rgba(0,0,0,0.2);
}
.amount{
    font-size:20px;
    color:#28a745;
    margin-bottom:20px;
}
.msg{
    color:red;
    margin-top:10px;
}
.preview{
    margin-top:10px;
    max-width:100%;
    border-radius:10px;
}
button{
    background:#28a745;
    color:#fff;
    border:none;
    padding:10px 20px;
    border-radius:8px;
    cursor:pointer;
}
</style>
</head>

<body>

<div class="card">

<h3>Upload Payment Receipt</h3>

<p class="amount">Bill #<?= $bill_id ?> | Rs. <?= $bill['amount'] ?></p>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="receipt" accept="image/*" required>
    <button type="submit">Upload</button>
</form>

<?php if($message): ?>
<div class="msg"><?= $message ?></div>
<?php endif; ?>

<?php if(!empty($bill['receipt_image'])): ?>
    <p>Current Receipt:</p>
    <img src="../uploads/receipts/<?= htmlspecialchars($bill['receipt_image']) ?>" class="preview">
<?php endif; ?>

<br><br>
<a href="billing.php">⬅ Back to Billing</a>

</div>

</body>
</html>