<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* AUTH CHECK */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../login.php");
    exit();
}

/* DB CONNECTION */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("Database Connection Failed");

/* GET doctor_id */
$stmt = $conn->prepare("SELECT doctor_id FROM doctors WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($doctor_id);
$stmt->fetch();
$stmt->close();

/* HANDLE BILL GENERATION */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $appointment_id = intval($_POST['appointment_id']);
    $fee = floatval($_POST['fee']);

    if ($fee <= 0) die("Invalid fee amount");

    /* CHECK IF BILL EXISTS */
    $check = $conn->prepare("SELECT bill_id FROM billing WHERE appointment_id=?");
    $check->bind_param("i", $appointment_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows == 0) {

        /* GET PATIENT ID */
        $stmt = $conn->prepare("SELECT patient_id FROM appointments WHERE appointment_id=?");
        $stmt->bind_param("i", $appointment_id);
        $stmt->execute();
        $stmt->bind_result($patient_id);
        $stmt->fetch();
        $stmt->close();

        /* INSERT BILL */
        $stmt = $conn->prepare("
            INSERT INTO billing (patient_id, appointment_id, amount, payment_status, verification_status)
            VALUES (?, ?, ?, 'unpaid', 'pending')
        ");
        $stmt->bind_param("iid", $patient_id, $appointment_id, $fee);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: docbilling.php");
    exit();
}

/* FETCH DATA */
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.appointment_date,
           u.full_name,
           b.bill_id, b.amount, b.payment_status
    FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN billing b ON a.appointment_id = b.appointment_id
    WHERE a.doctor_id = ? AND a.status = 'completed'
    ORDER BY a.appointment_date DESC
");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();

// Get doctor name for sidebar
$doc_name = "Doctor";
$name_stmt = $conn->prepare("SELECT u.full_name FROM doctors d JOIN users u ON d.user_id = u.user_id WHERE d.user_id = ?");
$name_stmt->bind_param("i", $_SESSION['user_id']);
$name_stmt->execute();
$name_result = $name_stmt->get_result();
if ($name_result && $row = $name_result->fetch_assoc()) {
    $doc_name = $row['full_name'];
}
$name_stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Zaman Medical | Billing Management</title>

<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<style>
:root {
    --b1: #0a1628;
    --b2: #0f1c3d;
    --b3: #1a2a52;
    --b4: #1e3a8a;
    --b5: #2563eb;
    --b6: #3b82f6;
    --b7: #60a5fa;
    --b8: #93c5fd;
    --acc: #1d4ed8;
    --acc2: #2563eb;
    --glow: rgba(37,99,235,.5);
    --glass: rgba(255,255,255,.045);
    --gb: rgba(255,255,255,.09);
    --gh: rgba(255,255,255,.07);
    --txt: #e0e7ff;
    --mut: #7c8db5;
    --sw: 268px;
    --r: 20px;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Outfit', sans-serif;
    background: var(--b1);
    color: var(--txt);
    min-height: 100vh;
    overflow-x: hidden;
}

/* DEEP BACKGROUND */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: 
        radial-gradient(ellipse 90% 70% at 10% 10%, rgba(37,99,235,.2) 0%, transparent 55%),
        radial-gradient(ellipse 70% 60% at 90% 80%, rgba(29,78,216,.15) 0%, transparent 55%),
        radial-gradient(ellipse 50% 50% at 55% 45%, rgba(26,42,82,.4) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
}

body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image: 
        linear-gradient(rgba(255,255,255,.015) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.015) 1px, transparent 1px);
    background-size: 52px 52px;
    pointer-events: none;
    z-index: 0;
}

/* SIDEBAR */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: var(--sw);
    height: 100vh;
    background: rgba(11,22,44,.88);
    backdrop-filter: blur(32px);
    -webkit-backdrop-filter: blur(32px);
    border-right: 1px solid var(--gb);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    z-index: 1000;
    scrollbar-width: none;
}

.sidebar::-webkit-scrollbar {
    display: none;
}

.sb-logo {
    padding: 26px 22px 20px;
    border-bottom: 1px solid var(--gb);
}

.sb-brand {
    font-family: 'Instrument Serif', serif;
    font-size: 18px;
    color: #fff;
    line-height: 1.3;
}

.sb-sub {
    font-size: 9.5px;
    color: var(--b6);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .13em;
    margin-top: 5px;
}

.sb-sec {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--mut);
    padding: 16px 22px 6px;
}

.sb-nav a {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 11px 14px 11px 22px;
    color: var(--mut);
    text-decoration: none;
    font-size: 13.5px;
    font-weight: 500;
    border-left: 2px solid transparent;
    margin-right: 10px;
    border-radius: 0 10px 10px 0;
    transition: all .2s;
}

.sb-nav a i {
    width: 17px;
    text-align: center;
    font-size: 14px;
}

.sb-nav a:hover {
    color: var(--b8);
    background: rgba(37,99,235,.12);
    border-left-color: var(--b6);
}

.sb-nav a.active-link {
    color: #fff;
    background: linear-gradient(90deg, rgba(37,99,235,.38), rgba(37,99,235,.06));
    border-left-color: var(--acc);
    font-weight: 600;
}

.sb-nav a.active-link i {
    color: var(--b6);
}

.sb-foot {
    padding: 15px 22px;
    border-top: 1px solid var(--gb);
    font-size: 11px;
    color: var(--mut);
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: auto;
}

.ldot {
    width: 7px;
    height: 7px;
    background: #3b82f6;
    border-radius: 50%;
    box-shadow: 0 0 10px #3b82f6;
    animation: pulseDot 1.8s infinite;
}

@keyframes pulseDot {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.7); opacity: .45; }
}

/* MAIN CONTENT */
.main {
    margin-left: var(--sw);
    padding: 26px 28px 44px;
    min-height: 100vh;
    position: relative;
    z-index: 2;
}

/* TOPBAR */
.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    gap: 14px;
    flex-wrap: wrap;
}

.tl {
    display: flex;
    align-items: center;
    gap: 15px;
}

.doc-av {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    object-fit: cover;
    border: 2px solid var(--b5);
    box-shadow: 0 0 0 4px rgba(37,99,235,.22), 0 0 28px rgba(37,99,235,.35);
}

.g-text {
    font-size: 20px;
    font-weight: 800;
    color: #fff;
    letter-spacing: -.025em;
    line-height: 1.2;
}

.sp-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(37,99,235,.2);
    border: 1px solid rgba(37,99,235,.38);
    color: var(--b7);
    font-size: 11px;
    font-weight: 600;
    padding: 3px 12px;
    border-radius: 99px;
    margin-top: 5px;
}

.btnt {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: all .2s;
    font-family: 'Outfit', sans-serif;
}

.btnt.lg {
    background: rgba(239,68,68,.18);
    color: #fca5a5;
    border: 1px solid rgba(239,68,68,.3);
}

.btnt.lg:hover {
    background: rgba(239,68,68,.32);
    color: #fff;
}

/* HERO SECTION */
.hero {
    background: linear-gradient(120deg, rgba(22,38,74,.9) 0%, rgba(11,22,44,.88) 100%);
    border: 1px solid var(--gb);
    backdrop-filter: blur(22px);
    border-radius: var(--r);
    padding: 26px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 50px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.06);
}

.hero::before {
    content: '';
    position: absolute;
    top: -80px;
    right: -80px;
    width: 320px;
    height: 320px;
    background: radial-gradient(circle, rgba(37,99,235,.22) 0%, transparent 65%);
    border-radius: 50%;
    pointer-events: none;
}

.hero::after {
    content: '';
    position: absolute;
    bottom: -60px;
    left: 220px;
    width: 180px;
    height: 180px;
    background: radial-gradient(circle, rgba(29,78,216,.15) 0%, transparent 65%);
    border-radius: 50%;
    pointer-events: none;
}

.hero-ttl {
    font-family: 'Instrument Serif', serif;
    font-size: 23px;
    color: #fff;
    margin-bottom: 6px;
    letter-spacing: -.02em;
}

.hero-s {
    font-size: 13px;
    color: var(--mut);
}

.hero-time {
    margin-top: 14px;
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 12px;
    color: var(--b7);
    font-weight: 500;
}

.ck {
    background: rgba(37,99,235,.22);
    border: 1px solid rgba(37,99,235,.38);
    padding: 5px 15px;
    border-radius: 99px;
    font-weight: 800;
    font-size: 13px;
    color: #fff;
    letter-spacing: .04em;
    box-shadow: 0 0 16px rgba(37,99,235,.3);
}

.hico {
    display: flex;
    gap: 12px;
    align-items: center;
}

.hic {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.04);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    color: rgba(255,255,255,.28);
}

.hic.lg {
    width: 70px;
    height: 70px;
    font-size: 28px;
    border-color: rgba(37,99,235,.38);
    color: rgba(59,130,246,.45);
    box-shadow: 0 0 32px rgba(37,99,235,.2);
}

/* GLASS CARD */
.glass-card {
    background: rgba(255,255,255,.04);
    border: 1px solid var(--gb);
    backdrop-filter: blur(22px);
    border-radius: var(--r);
    padding: 24px;
    transition: all .25s;
}

.glass-card:hover {
    box-shadow: 0 26px 65px rgba(0,0,0,.4);
}

.card-header-custom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--gb);
}

.card-header-custom h4 {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    color: #fff;
}

/* TABLE STYLES */
.table-custom {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.table-custom thead th {
    background: rgba(37,99,235,.2);
    color: var(--b8);
    font-weight: 600;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 14px 12px;
    border-bottom: 1px solid var(--gb);
}

.table-custom tbody td {
    padding: 14px 12px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    color: var(--txt);
    font-size: 14px;
}

.table-custom tbody tr:hover {
    background: rgba(255,255,255,.03);
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.status-paid { background: rgba(74,222,128,.2); color: #4ade80; }
.status-unpaid { background: rgba(248,113,113,.2); color: #f87171; }

/* Form controls */
.form-control-custom {
    background: rgba(255,255,255,.08);
    border: 1px solid var(--gb);
    border-radius: 12px;
    padding: 8px 14px;
    color: #fff;
    font-size: 13px;
    font-weight: 500;
    outline: none;
    transition: all .2s;
}

.form-control-custom:focus {
    border-color: var(--b6);
    background: rgba(255,255,255,.12);
    box-shadow: 0 0 0 3px rgba(59,130,246,.2);
}

.btn-custom {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    transition: all .2s;
    border: none;
    cursor: pointer;
}

.btn-generate {
    background: linear-gradient(105deg, #0f3f55, #1b789b);
    color: #fff;
}

.btn-generate:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(27,120,155,.3);
}

.status-text {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
}

.status-text i {
    font-size: 12px;
}

/* Animations */
@keyframes fadeSlide {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.animated {
    animation: fadeSlide .5s ease-out forwards;
}

/* Responsive */
@media (max-width: 992px) {
    .sidebar {
        transform: translateX(-100%);
    }
    .main {
        margin-left: 0;
        padding: 20px;
    }
}

@media (max-width: 768px) {
    .hero {
        flex-direction: column;
        text-align: center;
        gap: 16px;
    }
    .hero-time {
        justify-content: center;
    }
    .topbar {
        flex-direction: column;
        align-items: flex-start;
    }
    .table-custom {
        font-size: 12px;
    }
    .form-control-custom {
        width: 100px;
    }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sb-logo">
        <div class="sb-brand">Zaman Medical<br>Hospital</div>
        <div class="sb-sub">Doctor Portal</div>
    </div>
    <nav class="sb-nav">
        <div class="sb-sec">Navigation</div>
        <a href="doctordashboard.php"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
        <a href="docadddiagnosis.php"><i class="fa-solid fa-file-medical"></i> Diagnosis</a>
        <a href="docappointment.php"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
        <a href="docpatientrecord.php"><i class="fa-solid fa-users"></i> Patients</a>
        <a href="docprescriptions.php"><i class="fa-solid fa-pills"></i> Prescriptions</a>
        <a href="doctoravailability.php"><i class="fa-solid fa-clock"></i> Availability</a>
        <a href="docreports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
        <a href="docbilling.php" class="active-link"><i class="fa-solid fa-file-invoice-dollar"></i> Billing</a>
    </nav>
    <div class="sb-foot">
        <span class="ldot"></span> System Online &nbsp;·&nbsp; v3.0
        <span style="margin-left: auto; font-size: 9px;" id="liveClock"></span>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">
    
    <!-- TOPBAR -->
    <div class="topbar animated">
        <div class="tl">
            <img src="https://ui-avatars.com/api/?name=<?= urlencode($doc_name) ?>&background=1e3a8a&color=fff&size=80&bold=true" 
                 class="doc-av" alt="doctor avatar">
            <div>
                <div class="g-text">Billing Management 💰</div>
                <span class="sp-pill"><i class="fa-solid fa-stethoscope"></i> Generate & Track Payments</span>
            </div>
        </div>
        <div class="tr">
            <a href="logout.php" class="btnt lg"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <!-- HERO SECTION -->
    <div class="hero animated" style="animation-delay: 0.05s;">
        <div>
            <div class="hero-ttl">📋 Billing Overview</div>
            <div class="hero-s">Manage invoices and payment collection</div>
            <div class="hero-time">
                <span><i class="fa-regular fa-calendar-days me-1"></i><?= date('l, d F Y') ?></span>
                <span class="ck" id="heroClock">--:--:--</span>
            </div>
        </div>
        <div class="hico">
            <div class="hic"><i class="fa-solid fa-coins"></i></div>
            <div class="hic lg"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            <div class="hic"><i class="fa-solid fa-credit-card"></i></div>
        </div>
    </div>

    <!-- BILLING TABLE CARD -->
    <div class="animated" style="animation-delay: 0.1s;">
        <div class="glass-card">
            <div class="card-header-custom">
                <h4><i class="fa-solid fa-list-ul me-2"></i>Completed Appointments Billing</h4>
                <?php 
                $result->data_seek(0);
                $total_rows = $result->num_rows;
                ?>
                <span class="sp-pill" style="margin-top:0;">Total: <?= $total_rows ?> Appointments</span>
            </div>
            
            <div style="overflow-x: auto;">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th><i class="fa-regular fa-user me-1"></i> Patient</th>
                            <th><i class="fa-regular fa-calendar me-1"></i> Date & Time</th>
                            <th><i class="fa-solid fa-dollar-sign me-1"></i> Amount</th>
                            <th><i class="fa-solid fa-circle-info me-1"></i> Payment Status</th>
                            <th><i class="fa-solid fa-gear me-1"></i> Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $result->data_seek(0);
                        $has_records = false;
                        while($row = $result->fetch_assoc()): 
                            $has_records = true;
                        ?>
                        <tr>
                            <td>
                                <i class="fa-regular fa-user-circle me-2"></i>
                                <?= htmlspecialchars($row['full_name']) ?>
                            </td>
                            <td><?= date('Y-m-d', strtotime($row['appointment_date'])) ?><br>
                                <small style="color:var(--mut);"><?= date('h:i A', strtotime($row['appointment_date'])) ?></small>
                            </td>
                            <td>
                                <?php if($row['amount']): ?>
                                    <span style="font-weight:700; color:#fff;">Rs. <?= number_format($row['amount'], 0) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--mut);">— Not generated</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($row['bill_id']): ?>
                                    <?php if($row['payment_status'] == 'paid'): ?>
                                        <span class="status-badge status-paid">
                                            <i class="fa-solid fa-circle-check"></i> Paid
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-unpaid">
                                            <i class="fa-regular fa-circle-xmark"></i> Unpaid
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:var(--mut);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!$row['bill_id']): ?>
                                    <form method="POST" class="d-flex gap-2" style="align-items:center;">
                                        <input type="hidden" name="appointment_id" value="<?= $row['appointment_id'] ?>">
                                        <input type="number" name="fee" value="500" class="form-control-custom" style="width: 100px;" required>
                                        <button type="submit" class="btn-custom btn-generate">
                                            <i class="fa-solid fa-file-invoice"></i> Generate
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php if($row['payment_status'] == 'paid'): ?>
                                        <span class="status-text" style="color: #4ade80;">
                                            <i class="fa-solid fa-check-circle"></i> Completed
                                        </span>
                                    <?php else: ?>
                                        <span class="status-text" style="color: #f87171;">
                                            <i class="fa-regular fa-hourglass-half"></i> Awaiting Payment
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if(!$has_records): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding: 60px 20px; color:var(--mut);">
                                <i class="fa-regular fa-folder-open fa-3x mb-3 d-block"></i>
                                No completed appointments found<br>
                                <small>Billing will appear here after appointments are completed</small>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Live clock
function updateClock() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
    const heroClock = document.getElementById('heroClock');
    const liveClock = document.getElementById('liveClock');
    if (heroClock) heroClock.textContent = timeStr;
    if (liveClock) liveClock.textContent = timeStr;
}
updateClock();
setInterval(updateClock, 1000);
</script>

</body>
</html>