<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'receptionist') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

/* ---------- Current receptionist (for topbar avatar) ---------- */
$user = ['full_name' => 'Receptionist', 'email' => ''];
$stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($fn, $em);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Receptionist', 'email' => $em ?: ''];
$stmt->close();
$initials = strtoupper(substr($user['full_name'], 0, 2));

/* ---------- Flash ---------- */
$flash = null;
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

/* ---------- Detect optional columns on billing table (so this works whether
   or not verified_by / verified_at / rejection_reason exist) ---------- */
function colExists($conn, $table, $col) {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return $r && $r->num_rows > 0;
}
$has_verified_by  = colExists($conn, 'billing', 'verified_by');
$has_verified_at  = colExists($conn, 'billing', 'verified_at');
$has_reject_reason= colExists($conn, 'billing', 'rejection_reason');

/* ---------- ACTION: Verify / Reject a bill (AJAX POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bill_action'])) {
    header('Content-Type: application/json');

    $bill_id = intval($_POST['bill_id'] ?? 0);
    $action  = $_POST['bill_action'];

    if (!$bill_id || !in_array($action, ['verify', 'reject'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit();
    }

    if ($action === 'verify') {
        $sets  = ["payment_status = 'paid'", "verification_status = 'verified'"];
        $types = '';
        $vals  = [];
        if ($has_verified_by) { $sets[] = "verified_by = ?"; $types .= 'i'; $vals[] = $_SESSION['user_id']; }
        if ($has_verified_at) { $sets[] = "verified_at = NOW()"; }
        if ($has_reject_reason) { $sets[] = "rejection_reason = NULL"; }

        $sql = "UPDATE billing SET " . implode(', ', $sets) . " WHERE bill_id = ?";
        $types .= 'i';
        $vals[] = $bill_id;

        $upd = $conn->prepare($sql);
        $upd->bind_param($types, ...$vals);
        $ok = $upd->execute();
        $upd->close();

        echo json_encode(['success' => (bool)$ok, 'error' => $ok ? null : 'Could not verify bill.', 'new_payment_status' => 'paid', 'new_verification_status' => 'verified']);
        exit();
    }

    if ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? 'Receipt could not be verified. Please re-upload.');

        $sets  = ["payment_status = 'unpaid'", "verification_status = 'rejected'"];
        $types = '';
        $vals  = [];
        if ($has_reject_reason) { $sets[] = "rejection_reason = ?"; $types .= 's'; $vals[] = $reason; }

        $sql = "UPDATE billing SET " . implode(', ', $sets) . " WHERE bill_id = ?";
        $types .= 'i';
        $vals[] = $bill_id;

        $upd = $conn->prepare($sql);
        $upd->bind_param($types, ...$vals);
        $ok = $upd->execute();
        $upd->close();

        echo json_encode(['success' => (bool)$ok, 'error' => $ok ? null : 'Could not reject bill.', 'new_payment_status' => 'unpaid', 'new_verification_status' => 'rejected']);
        exit();
    }
}

/* ---------- Filters ---------- */
$filter = $_GET['filter'] ?? 'pending'; // pending | all | unpaid | paid | verified | rejected
$search = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
$types  = '';

if ($filter === 'pending')  { $where[] = "b.verification_status = 'pending'"; }
elseif ($filter === 'unpaid')   { $where[] = "b.payment_status = 'unpaid'"; }
elseif ($filter === 'paid')     { $where[] = "b.payment_status = 'paid'"; }
elseif ($filter === 'verified') { $where[] = "b.verification_status = 'verified'"; }
elseif ($filter === 'rejected') { $where[] = "b.verification_status = 'rejected'"; }
// 'all' -> no extra where

if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR b.bill_id = ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = is_numeric($search) ? intval($search) : 0;
    $types .= 'ssi';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        b.*,
        a.appointment_date,
        u.full_name  AS patient_name,
        u.email      AS patient_email,
        du.full_name AS doctor_name
    FROM billing b
    JOIN patients p       ON b.patient_id = p.patient_id
    JOIN users u          ON p.user_id = u.user_id
    LEFT JOIN appointments a ON b.appointment_id = a.appointment_id
    LEFT JOIN doctors d      ON a.doctor_id = d.doctor_id
    LEFT JOIN users du       ON d.user_id = du.user_id
    $whereSql
    ORDER BY
        FIELD(b.verification_status,'pending','rejected','verified','none') ,
        b.created_at DESC
";
$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ---------- Counts for tabs ---------- */
$counts = ['all' => 0, 'pending' => 0, 'unpaid' => 0, 'paid' => 0, 'verified' => 0, 'rejected' => 0];
$cr = $conn->query("SELECT payment_status, verification_status, COUNT(*) c FROM billing GROUP BY payment_status, verification_status");
if ($cr) {
    while ($row = $cr->fetch_assoc()) {
        $counts['all'] += $row['c'];
        if ($row['verification_status'] === 'pending')  $counts['pending']  += $row['c'];
        if ($row['verification_status'] === 'verified') $counts['verified'] += $row['c'];
        if ($row['verification_status'] === 'rejected') $counts['rejected'] += $row['c'];
        if ($row['payment_status'] === 'unpaid') $counts['unpaid'] += $row['c'];
        if ($row['payment_status'] === 'paid')   $counts['paid']   += $row['c'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Billing Verification — SHAPMS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --sky:       #0ea5e9;
  --sky-light: #38bdf8;
  --sky-dark:  #0284c7;
  --sky-pale:  #e0f2fe;
  --sky-pale2: #bae6fd;
  --bg:        #f0f7ff;
  --white:     #ffffff;
  --ink:       #0c2d3f;
  --ink70:     rgba(12,45,63,0.70);
  --ink40:     rgba(12,45,63,0.45);
  --border:    rgba(14,165,233,0.14);
  --border2:   rgba(14,165,233,0.22);
  --green:     #10b981;
  --yellow:    #f59e0b;
  --red:       #ef4444;
  --sw:        210px;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; font-size: 13px; overflow-x: hidden; width: 100%; }
.layout { display: flex; min-height: 100vh; width: 100%; max-width: 100vw; overflow: hidden; }

/* SIDEBAR */
.sidebar { width: var(--sw); background: var(--white); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; }
.sidebar-logo { display: flex; align-items: center; gap: 10px; padding: 20px 16px 16px; border-bottom: 1px solid var(--border); }
.logo-box { width: 34px; height: 34px; background: var(--sky); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: white; flex-shrink: 0; }
.logo-name { font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.1; }
.logo-sub  { font-size: 9px; color: var(--sky-dark); letter-spacing: 1.2px; text-transform: uppercase; font-weight: 600; }
.nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink40); padding: 16px 16px 6px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 4px 0; }
.sidebar-nav a { display: flex; align-items: center; gap: 9px; padding: 9px 16px; color: var(--ink70); text-decoration: none; font-size: 12.5px; font-weight: 500; border-left: 2px solid transparent; transition: all 0.15s; }
.sidebar-nav a i { width: 15px; text-align: center; font-size: 13px; }
.sidebar-nav a:hover  { background: var(--sky-pale); color: var(--sky-dark); }
.sidebar-nav a.active { background: var(--sky-pale); color: var(--sky-dark); border-left-color: var(--sky); font-weight: 600; }
.sidebar-bottom { padding: 14px 16px 18px; border-top: 1px solid var(--border); }
.sidebar-bottom a { display: flex; align-items: center; gap: 9px; color: var(--red); font-size: 12.5px; font-weight: 600; text-decoration: none; }

/* MAIN */
.main { margin-left: var(--sw); flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-x: hidden; width: calc(100% - var(--sw)); }

/* TOPBAR */
.topbar { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 24px; height: 58px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-breadcrumb { font-size: 9.5px; color: var(--ink40); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; font-weight: 600; }
.topbar-title { font-size: 17px; font-weight: 700; color: var(--ink); }
.avatar-pill { display: flex; align-items: center; gap: 8px; background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 5px 12px 5px 5px; }
.avatar-circle { width: 28px; height: 28px; border-radius: 50%; background: var(--sky); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: white; }
.avatar-name { font-size: 11.5px; font-weight: 700; color: var(--ink); line-height: 1.2; }
.avatar-role { font-size: 9.5px; color: var(--sky-dark); font-weight: 600; }

/* PAGE BODY */
.page-body { padding: 22px 24px 50px; display: flex; flex-direction: column; gap: 20px; }

/* FLASH */
.flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 600; }
.flash-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.flash-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* TABS */
.tab-row { display: flex; gap: 8px; flex-wrap: wrap; }
.tab-btn { display: flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 99px; border: 1.5px solid var(--border); background: var(--white); color: var(--ink70); font-size: 12px; font-weight: 700; text-decoration: none; transition: all 0.15s; }
.tab-btn:hover { border-color: var(--sky-pale2); color: var(--sky-dark); }
.tab-btn.active { background: var(--sky); border-color: var(--sky); color: white; }
.tab-count { background: rgba(0,0,0,0.1); padding: 1px 7px; border-radius: 99px; font-size: 10px; }
.tab-btn.active .tab-count { background: rgba(255,255,255,0.25); }

/* SEARCH */
.search-row { display: flex; gap: 10px; }
.search-input { flex: 1; max-width: 340px; padding: 9px 14px 9px 36px; border: 1px solid var(--border); border-radius: 10px; font-size: 12.5px; font-family: inherit; background: var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%230284c7' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0'/%3E%3C/svg%3E") no-repeat 12px center; outline: none; }
.search-input:focus { border-color: var(--sky); }

/* BILL CARD */
.bill-card { background: var(--white); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
.bill-hdr { padding: 16px 20px; background: linear-gradient(135deg, var(--sky-pale), rgba(14,165,233,0.06)); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.bill-hdr-left { display: flex; align-items: center; gap: 12px; }
.pat-av { width: 38px; height: 38px; border-radius: 50%; background: var(--sky-pale2); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: var(--sky-dark); flex-shrink: 0; }
.bill-pat-name { font-size: 13.5px; font-weight: 700; color: var(--ink); }
.bill-pat-email { font-size: 10.5px; color: var(--ink40); }
.bill-amount { font-size: 20px; font-weight: 700; color: var(--sky-dark); }
.bill-amount small { font-size: 11px; font-weight: 600; color: var(--ink40); }

.bill-body { padding: 18px 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.bill-meta { display: flex; flex-direction: column; gap: 12px; }
.meta-row { display: flex; justify-content: space-between; gap: 10px; padding-bottom: 10px; border-bottom: 1px dashed var(--border); font-size: 12px; }
.meta-row:last-child { border-bottom: none; padding-bottom: 0; }
.meta-label { color: var(--ink40); font-weight: 600; text-transform: uppercase; font-size: 10px; letter-spacing: 0.4px; }
.meta-val { color: var(--ink); font-weight: 600; text-align: right; }

.badge { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:99px; font-size:10.5px; font-weight:700; }
.b-unpaid   { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
.b-paid     { background:#d1fae5; color:#047857; border:1px solid #a7f3d0; }
.b-pending  { background:#fef3c7; color:#b45309; border:1px solid #fde68a; }
.b-verified { background:var(--sky-pale); color:var(--sky-dark); border:1px solid var(--sky-pale2); }
.b-rejected { background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
.b-none     { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }

.receipt-box { display: flex; flex-direction: column; gap: 10px; }
.receipt-label { font-size: 10px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.5px; }
.receipt-img { max-width: 100%; max-height: 220px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(14,165,233,0.08); cursor: zoom-in; object-fit: cover; }
.no-receipt { display: flex; align-items: center; justify-content: center; height: 140px; background: var(--bg); border: 1.5px dashed var(--border2); border-radius: 12px; color: var(--ink40); font-size: 12px; gap: 8px; }

.action-row { display: flex; gap: 10px; padding: 0 20px 18px; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; border: none; font-family: inherit; transition: all 0.15s; }
.btn-verify { background: var(--green); color: white; }
.btn-verify:hover { background: #059669; }
.btn-reject { background: var(--white); color: var(--red); border: 1.5px solid #fecaca; }
.btn-reject:hover { background: #fee2e2; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }
.reject-reason-note { font-size: 11px; color: var(--red); padding: 0 20px 16px; margin-top: -6px; }

.empty-state { text-align:center; padding:60px 20px; background: var(--white); border: 1px solid var(--border); border-radius: 16px; color: var(--ink40); }
.empty-state i { font-size: 40px; margin-bottom: 12px; display: block; color: var(--sky-pale2); }

/* LIGHTBOX */
.lightbox { display: none; position: fixed; inset: 0; background: rgba(12,45,63,0.85); z-index: 999; align-items: center; justify-content: center; padding: 30px; cursor: zoom-out; }
.lightbox img { max-width: 90%; max-height: 90%; border-radius: 12px; }

@media(max-width:768px) {
  :root { --sw:56px; }
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span { display:none; }
  .sidebar-logo { padding:16px 8px; justify-content:center; }
  .sidebar-nav a { padding:11px; justify-content:center; }
  .page-body { padding:14px; }
  .bill-body { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-concierge-bell"></i></div>
    <div><div class="logo-name">Zaman Medical Center</div><div class="logo-sub">Reception</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="receptionistdasboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="receptionistappointments.php"><i class="fas fa-calendar-check"></i><span>Appointments</span></a>
    <a href="receptionistpatients.php"><i class="fas fa-user-injured"></i><span>Patients</span></a>
    <a href="receptionistdoctors.php"><i class="fas fa-user-doctor"></i><span>Doctors</span></a>
    <a href="receptionistbilling.php" class="active"><i class="fas fa-file-invoice-dollar"></i><span>Billing</span></a>
    <div class="nav-section">Account</div>
    <a href="receptionistprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="patientchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
  </nav>
  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">

  <header class="topbar">
    <div>
      <div class="topbar-breadcrumb">Front Desk / Billing</div>
      <div class="topbar-title">Billing Verification</div>
    </div>
    <div class="avatar-pill">
      <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
      <div>
        <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="avatar-role">Receptionist</div>
      </div>
    </div>
  </header>

  <div class="page-body">

    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
      <i class="fas <?= $flash['type']==='success'?'fa-circle-check':'fa-circle-xmark' ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <div id="ajaxFlash"></div>

    <!-- TABS -->
    <div class="tab-row">
      <a href="?filter=pending" class="tab-btn <?= $filter==='pending'?'active':'' ?>"><i class="fas fa-hourglass-half"></i> Needs Review <span class="tab-count"><?= $counts['pending'] ?></span></a>
      <a href="?filter=unpaid" class="tab-btn <?= $filter==='unpaid'?'active':'' ?>"><i class="fas fa-circle-exclamation"></i> Unpaid <span class="tab-count"><?= $counts['unpaid'] ?></span></a>
      <a href="?filter=paid" class="tab-btn <?= $filter==='paid'?'active':'' ?>"><i class="fas fa-circle-check"></i> Paid <span class="tab-count"><?= $counts['paid'] ?></span></a>
      <a href="?filter=verified" class="tab-btn <?= $filter==='verified'?'active':'' ?>"><i class="fas fa-shield-check"></i> Verified <span class="tab-count"><?= $counts['verified'] ?></span></a>
      <a href="?filter=rejected" class="tab-btn <?= $filter==='rejected'?'active':'' ?>"><i class="fas fa-ban"></i> Rejected <span class="tab-count"><?= $counts['rejected'] ?></span></a>
      <a href="?filter=all" class="tab-btn <?= $filter==='all'?'active':'' ?>"><i class="fas fa-list"></i> All <span class="tab-count"><?= $counts['all'] ?></span></a>
    </div>

    <!-- SEARCH -->
    <form method="GET" class="search-row">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <input type="text" name="q" class="search-input" placeholder="Search patient name, email, or bill #..." value="<?= htmlspecialchars($search) ?>">
    </form>

    <!-- BILLS -->
    <?php if (empty($bills)): ?>
      <div class="empty-state">
        <i class="fas fa-file-invoice-dollar"></i>
        <p>No bills found for this filter.</p>
      </div>
    <?php else: ?>
      <?php foreach ($bills as $row):
        $ini = strtoupper(substr($row['patient_name'] ?? 'PA', 0, 2));
        $pay = $row['payment_status'];
        $ver = $row['verification_status'];
        $payBadge = ['unpaid'=>'b-unpaid','pending'=>'b-pending','paid'=>'b-paid'][$pay] ?? 'b-none';
        $verBadge = ['pending'=>'b-pending','verified'=>'b-verified','rejected'=>'b-rejected'][$ver] ?? 'b-none';
        $canAct = $row['payment_status'] !== 'paid' || $row['verification_status'] === 'pending';
      ?>
      <div class="bill-card" id="bill-<?= $row['bill_id'] ?>">
        <div class="bill-hdr">
          <div class="bill-hdr-left">
            <div class="pat-av"><?= htmlspecialchars($ini) ?></div>
            <div>
              <div class="bill-pat-name"><?= htmlspecialchars($row['patient_name']) ?></div>
              <div class="bill-pat-email"><?= htmlspecialchars($row['patient_email']) ?></div>
            </div>
          </div>
          <div class="bill-amount">Rs. <?= number_format($row['amount'], 0) ?> <small>PKR • Bill #<?= $row['bill_id'] ?></small></div>
        </div>

        <div class="bill-body">
          <div class="bill-meta">
            <div class="meta-row">
              <span class="meta-label">Doctor</span>
              <span class="meta-val"><?= htmlspecialchars($row['doctor_name'] ?? '—') ?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Appointment Date</span>
              <span class="meta-val"><?= $row['appointment_date'] ? date('d M Y, h:i A', strtotime($row['appointment_date'])) : '—' ?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Bill Date</span>
              <span class="meta-val"><?= date('d M Y', strtotime($row['created_at'])) ?></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Payment Status</span>
              <span class="meta-val"><span class="badge <?= $payBadge ?>" id="pay-badge-<?= $row['bill_id'] ?>"><?= ucfirst($pay) ?></span></span>
            </div>
            <div class="meta-row">
              <span class="meta-label">Verification</span>
              <span class="meta-val"><span class="badge <?= $verBadge ?>" id="ver-badge-<?= $row['bill_id'] ?>"><?= ucfirst($ver ?: 'None') ?></span></span>
            </div>
            <?php if (!empty($row['rejection_reason'])): ?>
            <div class="meta-row">
              <span class="meta-label">Rejection Note</span>
              <span class="meta-val" style="color:var(--red);"><?= htmlspecialchars($row['rejection_reason']) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <div class="receipt-box">
            <div class="receipt-label"><i class="fas fa-receipt"></i> Uploaded Receipt</div>
            <?php if (!empty($row['receipt_image'])): ?>
              <img src="../uploads/receipts/<?= htmlspecialchars($row['receipt_image']) ?>" class="receipt-img" onclick="openLightbox(this.src)" alt="Receipt">
            <?php else: ?>
              <div class="no-receipt"><i class="fas fa-image-slash"></i> No receipt uploaded yet</div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($canAct): ?>
        <div class="action-row">
          <button class="btn btn-verify" onclick="actOnBill(<?= $row['bill_id'] ?>, 'verify', this)">
            <i class="fas fa-check"></i> Verify Payment
          </button>
          <button class="btn btn-reject" onclick="actOnBill(<?= $row['bill_id'] ?>, 'reject', this)">
            <i class="fas fa-xmark"></i> Reject Receipt
          </button>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" onclick="this.style.display='none'">
  <img id="lightboxImg" src="" alt="Receipt full view">
</div>

<script>
function openLightbox(src) {
  document.getElementById('lightboxImg').src = src;
  document.getElementById('lightbox').style.display = 'flex';
}

function showAjaxFlash(type, msg) {
  const box = document.getElementById('ajaxFlash');
  box.innerHTML = `<div class="flash flash-${type}"><i class="fas ${type==='success'?'fa-circle-check':'fa-circle-xmark'}"></i> ${msg}</div>`;
  setTimeout(() => { box.innerHTML = ''; }, 4000);
}

function actOnBill(billId, action, btnEl) {
  let reason = null;
  if (action === 'reject') {
    reason = prompt('Reason for rejecting this receipt (shown to the patient):', 'Receipt image is unclear or does not match the billed amount. Please re-upload.');
    if (reason === null) return; // cancelled
  } else {
    if (!confirm('Confirm this payment has been received and verified?')) return;
  }

  const card = document.getElementById('bill-' + billId);
  const buttons = card.querySelectorAll('.btn');
  buttons.forEach(b => b.disabled = true);

  const body = new URLSearchParams({ bill_action: action, bill_id: billId });
  if (reason !== null) body.append('reason', reason);

  fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        showAjaxFlash('danger', data.error || 'Action failed.');
        buttons.forEach(b => b.disabled = false);
        return;
      }

      const payBadge = document.getElementById('pay-badge-' + billId);
      const verBadge = document.getElementById('ver-badge-' + billId);

      payBadge.textContent = data.new_payment_status.charAt(0).toUpperCase() + data.new_payment_status.slice(1);
      payBadge.className = 'badge ' + (data.new_payment_status === 'paid' ? 'b-paid' : 'b-unpaid');

      verBadge.textContent = data.new_verification_status.charAt(0).toUpperCase() + data.new_verification_status.slice(1);
      verBadge.className = 'badge ' + (data.new_verification_status === 'verified' ? 'b-verified' : (data.new_verification_status === 'rejected' ? 'b-rejected' : 'b-pending'));

      if (action === 'verify') {
        showAjaxFlash('success', 'Bill #' + billId + ' marked as paid and verified.');
        const actionRow = card.querySelector('.action-row');
        if (actionRow) actionRow.remove();
      } else {
        showAjaxFlash('success', 'Bill #' + billId + ' receipt rejected. Patient will need to re-upload.');
        buttons.forEach(b => b.disabled = false);
      }
    })
    .catch(() => {
      showAjaxFlash('danger', 'Network error. Please try again.');
      buttons.forEach(b => b.disabled = false);
    });
}
</script>
</body>
</html>