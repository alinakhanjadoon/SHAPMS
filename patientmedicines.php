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
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

$patient_id = $_SESSION['user_id'];
$success = "";
$error = "";


/* ---------- BUY MEDICINE ---------- */
if (isset($_POST['buy_medicine'])) {

    $medicine_id = (int)$_POST['medicine_id'];
    $qty = (int)$_POST['quantity'];

    if ($qty <= 0) {
        $error = "Invalid quantity selected.";
    } else {

        $stmt = $conn->prepare("
            SELECT medicine_id,name,price,quantity,expiry_date
            FROM medicines
            WHERE medicine_id=?
        ");
        $stmt->bind_param("i", $medicine_id);
        $stmt->execute();
        $medicine = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$medicine) {
            $error = "Medicine not found.";
        }
        elseif (strtotime($medicine['expiry_date']) < strtotime(date('Y-m-d'))) {
            $error = "This medicine is expired.";
        }
        elseif ($medicine['quantity'] < $qty) {
            $error = "Only {$medicine['quantity']} units available.";
        }
        else {

            $total = $medicine['price'] * $qty;

            /* ---------- LOYALTY DISCOUNT CHECK ---------- */
            /* If this patient has already purchased from this pharmacy before,
               give them a 10% discount on this order. */
            $discount_applied = false;

            $checkStmt = $conn->prepare("
                SELECT COUNT(*) FROM medicine_orders WHERE patient_id=?
            ");
            $checkStmt->bind_param("i", $patient_id);
            $checkStmt->execute();
            $previous_orders = $checkStmt->get_result()->fetch_row()[0];
            $checkStmt->close();

            if ($previous_orders > 0) {
                $total = $total * 0.90; // 10% loyalty discount
                $discount_applied = true;
            }

            $stmt = $conn->prepare("
                INSERT INTO medicine_orders
                (patient_id, medicine_id, quantity, total_price, status)
                VALUES (?, ?, ?, ?, 'Pending')
            ");
            $stmt->bind_param("iiid", $patient_id, $medicine_id, $qty, $total);
            $stmt->execute();
            $stmt->close();

            if ($discount_applied) {
                $success = "Medicine request submitted successfully. A 10% loyalty discount was applied — total: Rs " . number_format($total, 2) . ". Waiting for pharmacist verification.";
            } else {
                $success = "Medicine request submitted successfully. Waiting for pharmacist verification.";
            }
        }
    }
}


/* ---------- FETCH MEDICINES ---------- */
$medicines = $conn->query("
    SELECT *
    FROM medicines
    WHERE quantity > 0
    AND expiry_date >= CURDATE()
    ORDER BY name ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Patient Medicines | Earthen Luxe</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
:root{
    --alabaster:#F5F2EB;
    --cream:#FDF9F2;
    --silken:#D4C5B0;
    --taupe:#9B8B7A;
    --moss:#7A9B7E;
    --moss-deep:#5E7D62;
    --juniper:#2D4A3B;
    --juniper-dark:#1E3328;
    --onyx:#1E201E;
    --bronze:#C9A96E;
    --shadow-sm:0 4px 20px rgba(30,32,30,.06);
    --shadow-md:0 8px 30px rgba(30,32,30,.10);
    --shadow-lg:0 15px 35px rgba(30,32,30,.12);
}

body{
    font-family:'Inter',sans-serif;
    background:var(--alabaster);
    padding:28px;
    color:var(--onyx);
}

.header{
    background:linear-gradient(135deg,var(--juniper),var(--juniper-dark));
    color:white;
    padding:30px;
    border-radius:24px;
    margin-bottom:30px;
    box-shadow:var(--shadow-lg);
}

.header h2{
    font-family:'Cormorant Garamond',serif;
    font-weight:700;
    margin:0;
}

.header small{
    color:var(--silken);
}

.medicine-card{
    background:var(--cream);
    border-radius:24px;
    overflow:hidden;
    box-shadow:var(--shadow-sm);
    transition:.3s;
    border:1px solid rgba(155,139,122,.12);
    height:100%;
}

.medicine-card:hover{
    transform:translateY(-6px);
    box-shadow:var(--shadow-md);
}

.medicine-img{
    width:100%;
    height:180px;
    object-fit:contain;
    padding:20px;
    background:var(--alabaster);
}

.medicine-body{
    padding:20px;
}

.medicine-name{
    font-family:'Cormorant Garamond',serif;
    font-size:24px;
    font-weight:700;
    color:var(--juniper);
}

.desc{
    font-size:13px;
    color:var(--taupe);
    margin-bottom:15px;
}

.price{
    color:var(--bronze);
    font-weight:700;
    font-size:18px;
}

.stock{
    color:var(--moss);
    font-weight:600;
    font-size:13px;
}

.form-control{
    border-radius:12px;
    padding:10px;
}

.btn-buy{
    background:var(--moss);
    border:none;
    color:white;
    border-radius:40px;
    padding:10px;
    width:100%;
    font-weight:600;
}

.btn-buy:hover{
    background:var(--moss-deep);
}

.alert{
    border-radius:14px;
}
</style>
</head>
<body>

<div class="header">
    <h2><i class="fa-solid fa-capsules"></i> Buy Medicines</h2>
    <small>Select medicines and send purchase request for pharmacist approval</small>
</div>

<?php if($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<?php if($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<div class="row g-4">

<?php if($medicines->num_rows > 0): ?>
<?php while($m = $medicines->fetch_assoc()): ?>

<div class="col-md-6 col-lg-4 col-xl-3">
    <div class="medicine-card">

        <img src="<?= htmlspecialchars($m['image']) ?>" class="medicine-img">

        <div class="medicine-body">

            <div class="medicine-name">
                <?= htmlspecialchars($m['name']) ?>
            </div>

            <div class="desc">
                <?= htmlspecialchars($m['description']) ?>
            </div>

            <div class="d-flex justify-content-between mb-2">
                <span class="price">Rs <?= number_format($m['price'],2) ?></span>
                <span class="stock"><?= $m['quantity'] ?> in stock</span>
            </div>

            <small class="text-muted d-block mb-3">
                Exp: <?= date('M d, Y', strtotime($m['expiry_date'])) ?>
            </small>

            <form method="POST">
                <input type="hidden" name="medicine_id" value="<?= $m['medicine_id'] ?>">

                <input
                    type="number"
                    name="quantity"
                    min="1"
                    max="<?= $m['quantity'] ?>"
                    required
                    class="form-control mb-3"
                    placeholder="Enter quantity"
                >

                <button type="submit" name="buy_medicine" class="btn-buy">
                    <i class="fa-solid fa-cart-shopping"></i>
                    Buy Medicine
                </button>
            </form>

        </div>
    </div>
</div>

<?php endwhile; ?>
<?php else: ?>

<div class="col-12">
    <div class="alert alert-warning text-center">
        No medicines available right now.
    </div>
</div>

<?php endif; ?>

</div>

</body>
</html>