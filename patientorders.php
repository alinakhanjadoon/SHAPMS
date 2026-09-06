<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------- AUTH CHECK ---------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'patient') {
    header("Location: ../login.php");
    exit();
}

/* ---------- DB CONNECTION ---------- */
$conn = new mysqli('localhost', 'root', '', 'SHAPMS');
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$patient_id = $_SESSION['user_id'];

/* ---------- FETCH PATIENT ORDERS ---------- */
$stmt = $conn->prepare("
    SELECT mo.*, m.name AS medicine_name
    FROM medicine_orders mo
    JOIN medicines m ON mo.medicine_id = m.medicine_id
    WHERE mo.patient_id = ?
    ORDER BY mo.ordered_at DESC
");
$stmt->bind_param("i", $patient_id);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Medicine Orders</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<style>
:root{
    --bg:#f3f0fb;
    --bg2:#ece8f8;
    --purple-deep:#7c3aed;
    --purple-mid:#a78bfa;
    --purple-light:#ddd6fe;
    --purple-soft:#ede9fe;
    --lilac:#c4b5fd;
    --accent-pink:#f472b6;
    --text-dark:#1e1b3a;
    --text-mid:#5b5278;
    --text-light:#9c8fc0;
    --border:#e8e2f8;
    --shadow:rgba(124,58,237,0.10);
    --success:#10b981;
    --warning:#f59e0b;
    --danger:#ef4444;
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    background:var(--bg);
    font-family:'DM Sans',sans-serif;
    padding:30px;
    color:var(--text-dark);
    min-height:100vh;
    position:relative;
}

/* Background Glow */
body::before{
    content:'';
    position:fixed;
    inset:0;
    background:
        radial-gradient(circle at 20% 30%, rgba(167,139,250,0.15) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(244,114,182,0.12) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(124,58,237,0.08) 0%, transparent 60%);
    z-index:-1;
}

/* HEADER */
.header{
    background:rgba(255,255,255,0.9);
    backdrop-filter:blur(12px);
    border:1px solid var(--border);
    border-radius:24px;
    padding:28px;
    margin-bottom:28px;
    box-shadow:0 10px 30px var(--shadow);
}

.header h3{
    font-size:28px;
    font-weight:700;
    color:var(--text-dark);
    margin-bottom:8px;
    display:flex;
    align-items:center;
    gap:12px;
}

.header h3 i{
    color:var(--purple-deep);
}

.header small{
    color:var(--text-mid);
    font-size:14px;
}

/* TITLE */
.table-title{
    color:var(--text-dark);
    margin-bottom:20px;
    font-weight:700;
    font-size:22px;
}

/* CARD */
.card{
    background:rgba(255,255,255,0.95);
    border-radius:24px;
    padding:24px;
    margin-bottom:20px;
    border:1px solid var(--border);
    box-shadow:0 10px 30px var(--shadow);
    transition:all .25s ease;
}

.card:hover{
    transform:translateY(-3px);
    box-shadow:0 18px 40px rgba(124,58,237,0.15);
}

.card b{
    color:var(--text-dark);
}

.card hr{
    border:none;
    border-top:1px solid var(--border);
    margin:18px 0;
}

/* STATUS */
.status{
    padding:6px 14px;
    border-radius:40px;
    font-size:12px;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:90px;
}

/* Order Status */
.Pending{
    background:rgba(245,158,11,0.12);
    color:var(--warning);
}

.Approved{
    background:rgba(16,185,129,0.12);
    color:var(--success);
}

.Rejected{
    background:rgba(239,68,68,0.12);
    color:var(--danger);
}

/* Payment Status */
.Paid{
    background:rgba(124,58,237,0.12);
    color:var(--purple-deep);
}

/* ICON COLORS */
.fa-pills{
    color:var(--purple-deep);
}

.fa-calendar{
    color:var(--purple-mid);
}

/* EMPTY STATE */
.text-center{
    text-align:center;
}

.text-center i{
    color:var(--purple-mid);
    opacity:.7;
}

.text-center{
    color:var(--text-mid);
    padding:50px 20px;
}

/* SMALL TEXT */
small{
    color:var(--text-mid);
    font-size:13px;
}

/* RESPONSIVE */
@media(max-width:768px){

    body{
        padding:18px;
    }

    .header{
        padding:22px;
    }

    .header h3{
        font-size:22px;
    }

    .card{
        padding:20px;
    }

    .row > div{
        margin-bottom:12px;
    }
}
</style>
</head>

<body>

<div class="header">
    <h3><i class="fa-solid fa-box"></i> My Medicine Orders</h3>
    <small>Track your orders, approvals & payments</small>
</div>

<h4 class="table-title">Order History</h4>

<?php if($orders->num_rows > 0): ?>

    <?php while($o = $orders->fetch_assoc()): ?>

        <div class="card">

            <div class="row">

                <div class="col-md-3">
                    <b><i class="fa fa-pills"></i> <?= htmlspecialchars($o['medicine_name']) ?></b>
                </div>

                <div class="col-md-2">
                    Qty: <b><?= $o['quantity'] ?></b>
                </div>

                <div class="col-md-2">
                    Total: <b>Rs <?= $o['total_price'] ?></b>
                </div>

                <div class="col-md-3">
                    Status:
                    <span class="status <?= $o['status'] ?>">
                        <?= $o['status'] ?>
                    </span>
                </div>

                <div class="col-md-2">
                    Payment:
                    <span class="status <?= $o['payment_status'] ?>">
                        <?= $o['payment_status'] ?>
                    </span>
                </div>

            </div>

            <hr>

            <small>
                <i class="fa fa-calendar"></i>
                Ordered on: <?= date('F j, Y - h:i A', strtotime($o['ordered_at'])) ?>
            </small>

        </div>

    <?php endwhile; ?>

<?php else: ?>

    <div class="card text-center">
        <i class="fa fa-box-open fa-2x"></i>
        <br><br>
        No orders found.
    </div>

<?php endif; ?>

</body>
</html>