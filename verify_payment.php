<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK (DOCTOR OR ADMIN) ---------- */
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['doctor','admin'])) {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB ---------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error");

/* ---------- HANDLE ACTIONS ---------- */
if (isset($_GET['action'], $_GET['bill_id'])) {

    $bill_id = intval($_GET['bill_id']);
    $action = $_GET['action'];

    if ($action === 'approve') {

        $stmt = $conn->prepare("
            UPDATE billing 
            SET verification_status='verified' 
            WHERE bill_id=?
        ");
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $stmt->close();

    } elseif ($action === 'reject') {

        $stmt = $conn->prepare("
            UPDATE billing 
            SET 
                payment_status='unpaid',
                verification_status='pending',
                receipt_image=NULL,
                payment_date=NULL
            WHERE bill_id=?
        ");
        $stmt->bind_param("i", $bill_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: verify_payment.php");
    exit();
}

/* ---------- FETCH BILLS WITH RECEIPTS ---------- */
$result = $conn->query("
    SELECT 
        b.bill_id,
        b.amount,
        b.payment_status,
        b.verification_status,
        b.receipt_image,
        a.appointment_date,
        u.full_name AS patient_name
    FROM billing b
    JOIN patients p ON b.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN appointments a ON b.appointment_id = a.appointment_id
    WHERE b.payment_status='paid'
    ORDER BY b.payment_date DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Verify Payments</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">

<style>
body{
    font-family:'Poppins',sans-serif;
    background:#eef3f9;
}

.container{
    max-width:1100px;
    margin:40px auto;
}

.card{
    background:#fff;
    padding:20px;
    border-radius:12px;
    margin-bottom:15px;
    box-shadow:0 8px 20px rgba(0,0,0,0.1);
}

.row{
    display:flex;
    justify-content:space-between;
    flex-wrap:wrap;
}

.badge{
    padding:5px 10px;
    border-radius:8px;
    color:#fff;
    font-size:12px;
}

.pending{background:#f39c12;}
.verified{background:#2ecc71;}

.btn{
    padding:6px 12px;
    border-radius:6px;
    text-decoration:none;
    color:#fff;
    margin:3px;
    display:inline-block;
}

.approve{background:#2ecc71;}
.reject{background:#e74c3c;}

img{
    max-width:200px;
    margin-top:10px;
    border-radius:8px;
}
</style>
</head>

<body>

<div class="container">

<h2>🧾 Verify Patient Payments</h2>

<?php if($result->num_rows > 0): ?>

<?php while($row = $result->fetch_assoc()): ?>

<div class="card">

<div class="row">
<div>
    <h4>Bill #<?= $row['bill_id'] ?></h4>
    <p><b>Patient:</b> <?= htmlspecialchars($row['patient_name']) ?></p>
    <p><b>Appointment:</b> 
        <?= date('d M Y, h:i A', strtotime($row['appointment_date'])) ?>
    </p>
</div>

<div>
    <h3>Rs. <?= $row['amount'] ?></h3>
</div>
</div>

<hr>

<p>
<b>Status:</b> 
<span class="badge <?= $row['verification_status'] ?>">
<?= ucfirst($row['verification_status']) ?>
</span>
</p>

<!-- RECEIPT -->
<?php if(!empty($row['receipt_image'])): ?>
    <p><b>Receipt:</b></p>
    <img src="../uploads/receipts/<?= htmlspecialchars($row['receipt_image']) ?>">
<?php endif; ?>

<!-- ACTIONS -->
<?php if($row['verification_status']=='pending'): ?>

<a class="btn approve"
href="verify_payment.php?action=approve&bill_id=<?= $row['bill_id'] ?>"
onclick="return confirm('Approve this payment?')">
✔ Approve
</a>

<a class="btn reject"
href="verify_payment.php?action=reject&bill_id=<?= $row['bill_id'] ?>"
onclick="return confirm('Reject this payment?')">
✖ Reject
</a>

<?php else: ?>
✔ Already Verified
<?php endif; ?>

</div>

<?php endwhile; ?>

<?php else: ?>

<div class="card text-center">
    <h4>No Payments to Verify</h4>
</div>

<?php endif; ?>

</div>

</body>
</html>