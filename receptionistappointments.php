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

/* Slot generator — same helper used by the patient booking page and the
   receptionist dashboard's walk-in booking. Adjust the path if needed. */
require_once 'generate_slots.php';

// ── Current receptionist ──
$user = ['full_name' => 'Receptionist', 'email' => '', 'profile_image' => ''];
$stmt = $conn->prepare("SELECT full_name, email, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($fn, $em, $pi);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Receptionist', 'email' => $em ?: '', 'profile_image' => $pi ?: ''];
$stmt->close();
$initials = strtoupper(substr($user['full_name'], 0, 2));

// ── Flash ──
$flash = null;
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

// ── Does appointments table have optional columns? ──
$has_type_col      = $conn->query("SHOW COLUMNS FROM appointments LIKE 'appointment_type'")->num_rows > 0;

// ── POST: update appointment status (complete / cancel) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $appt_id    = intval($_POST['appointment_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $redirect_qs = $_POST['redirect_qs'] ?? '';
    $allowed = ['completed', 'cancelled', 'approved'];

    if ($appt_id && in_array($new_status, $allowed, true)) {
        // If cancelling, free up the underlying slot so it can be rebooked
        if ($new_status === 'cancelled') {
            $sres = $conn->prepare("SELECT slot_id FROM appointments WHERE appointment_id=?");
            $sres->bind_param("i", $appt_id);
            $sres->execute();
            $sres->bind_result($slot_id_to_free);
            $sres->fetch();
            $sres->close();
            if (!empty($slot_id_to_free)) {
                $fre = $conn->prepare("UPDATE appointment_slots SET is_booked=0, status='available' WHERE slot_id=?");
                $fre->bind_param("i", $slot_id_to_free);
                $fre->execute();
                $fre->close();
            }
        }
        $upd = $conn->prepare("UPDATE appointments SET status=? WHERE appointment_id=?");
        $upd->bind_param("si", $new_status, $appt_id);
        $upd->execute();
        $upd->close();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Appointment status updated.'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid status update.'];
    }

    header("Location: receptionistappointments.php" . ($redirect_qs ? '?' . $redirect_qs : ''));
    exit();
}

// ── Filters ──
$f_status = $_GET['status'] ?? 'all';
$f_doctor = isset($_GET['doctor']) ? (int)$_GET['doctor'] : 0;
$f_date   = trim($_GET['date'] ?? '');
$f_search = trim($_GET['search'] ?? '');
$current_qs = $_SERVER['QUERY_STRING'] ?? '';

// ── Doctors (for filter dropdown + slot graph) ──
$doctors = [];
$dr = $conn->query("
    SELECT d.doctor_id, u.full_name, u.status
    FROM doctors d JOIN users u ON d.user_id = u.user_id
    ORDER BY u.full_name
");
if ($dr) while ($row = $dr->fetch_assoc()) $doctors[] = $row;

// ── Distinct statuses present in the table (for filter dropdown + donut) ──
$statuses = [];
$str = $conn->query("SELECT DISTINCT status FROM appointments WHERE status IS NOT NULL AND status <> '' ORDER BY status");
if ($str) while ($row = $str->fetch_assoc()) $statuses[] = $row['status'];

// ── Status counts (for donut + stat cards) ──
$status_counts = [];
$scq = $conn->query("SELECT status, COUNT(*) as cnt FROM appointments GROUP BY status");
if ($scq) while ($row = $scq->fetch_assoc()) $status_counts[$row['status']] = (int)$row['cnt'];
$total_appts     = array_sum($status_counts);
$completed_appts = $status_counts['completed'] ?? 0;
$cancelled_appts = $status_counts['cancelled'] ?? 0;
$upcoming_appts  = $total_appts - $completed_appts - $cancelled_appts;

$today_appts = $conn->query("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date)=CURDATE()")->fetch_row()[0] ?? 0;

$walkin_appts = 0;
if ($has_type_col) {
    $walkin_appts = $conn->query("SELECT COUNT(*) FROM appointments WHERE appointment_type='walk-in'")->fetch_row()[0] ?? 0;
}

// ── Monthly trend (last 6 months) ──
$monthly_data = [];
$mr = $conn->query("SELECT DATE_FORMAT(created_at,'%b') as m, COUNT(*) as total
                    FROM appointments WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY MONTH(created_at), DATE_FORMAT(created_at,'%b')
                    ORDER BY MONTH(created_at)");
if ($mr) while ($row = $mr->fetch_assoc()) $monthly_data[] = $row;
$m_labels = empty($monthly_data) ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data, 'm');
$m_totals = empty($monthly_data) ? [0,0,0,0,0,0] : array_map('intval', array_column($monthly_data, 'total'));

// ── Appointments by doctor (top 8) ──
$doc_appt_counts = [];
$dcq = $conn->query("
    SELECT du.full_name as doc_name, COUNT(*) as cnt
    FROM appointments a
    JOIN doctors d ON a.doctor_id = d.doctor_id
    JOIN users du  ON d.user_id = du.user_id
    GROUP BY a.doctor_id, du.full_name
    ORDER BY cnt DESC
    LIMIT 8
");
if ($dcq) while ($row = $dcq->fetch_assoc()) $doc_appt_counts[] = $row;

// ── Slot availability graph (only when a doctor AND a date are both selected) ──
$slot_free = $slot_booked = $slot_total = 0;
$show_slot_graph = ($f_doctor && $f_date !== '');
if ($show_slot_graph) {
    generateSlots($conn, $f_doctor, $f_date);
    $ssq = $conn->prepare("SELECT is_booked FROM appointment_slots WHERE doctor_id=? AND slot_date=? AND status IN ('available','booked')");
    $ssq->bind_param("is", $f_doctor, $f_date);
    $ssq->execute();
    $srows = $ssq->get_result()->fetch_all(MYSQLI_ASSOC);
    $ssq->close();
    $slot_total  = count($srows);
    $slot_booked = count(array_filter($srows, fn($s) => (int)$s['is_booked'] === 1));
    $slot_free   = $slot_total - $slot_booked;
}

// ── Appointments table (filtered) ──
$where  = "WHERE 1=1";
$params = [];
$types  = '';

if ($f_status !== 'all' && $f_status !== '') {
    $where   .= " AND a.status = ?";
    $params[] = $f_status;
    $types   .= 's';
}
if ($f_doctor) {
    $where   .= " AND a.doctor_id = ?";
    $params[] = $f_doctor;
    $types   .= 'i';
}
if ($f_date !== '') {
    $where   .= " AND DATE(a.appointment_date) = ?";
    $params[] = $f_date;
    $types   .= 's';
}
if ($f_search !== '') {
    $like     = '%' . $f_search . '%';
    $where   .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$type_col_sql = $has_type_col ? "a.appointment_type" : "NULL";

$sql = "
    SELECT a.appointment_id, a.appointment_date, a.status, a.reason,
           u.full_name as patient_name, u.email as patient_email,
           du.full_name as doctor_name,
           $type_col_sql as appointment_type
    FROM appointments a
    JOIN patients p      ON a.patient_id = p.patient_id
    JOIN users u         ON p.user_id = u.user_id
    LEFT JOIN doctors d  ON a.doctor_id = d.doctor_id
    LEFT JOIN users du   ON d.user_id = du.user_id
    $where
    ORDER BY a.appointment_date DESC
    LIMIT 200
";
$stmt2 = $conn->prepare($sql);
if (!empty($params)) $stmt2->bind_param($types, ...$params);
$stmt2->execute();
$appointments = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

$conn->close();

$badge_map = [
    'pending'   => 'b-pending',
    'scheduled' => 'b-pending',
    'approved'  => 'b-approved',
    'completed' => 'b-completed',
    'cancelled' => 'b-cancelled',
];
$status_palette = ['#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b', '#ec4899', '#14b8a6'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments — SHAPMS</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

.sidebar { width: var(--sw); background: var(--white); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; bottom: 0; z-index: 100; }
.sidebar-logo { display: flex; align-items: center; gap: 10px; padding: 20px 16px 16px; border-bottom: 1px solid var(--border); }
.logo-box { width: 34px; height: 34px; background: var(--sky); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 14px; color: white; flex-shrink: 0; }
.logo-name { font-size: 14px; font-weight: 700; color: var(--ink); line-height: 1.1; }
.logo-sub  { font-size: 9px; color: var(--sky-dark); letter-spacing: 1.2px; text-transform: uppercase; font-weight: 600; }
.nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink40); padding: 16px 16px 6px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 4px 0; }
.sidebar-nav::-webkit-scrollbar { width: 0; }
.sidebar-nav a { display: flex; align-items: center; gap: 9px; padding: 9px 16px; color: var(--ink70); text-decoration: none; font-size: 12.5px; font-weight: 500; border-left: 2px solid transparent; transition: all 0.15s; }
.sidebar-nav a i { width: 15px; text-align: center; font-size: 13px; }
.sidebar-nav a:hover  { background: var(--sky-pale); color: var(--sky-dark); }
.sidebar-nav a.active { background: var(--sky-pale); color: var(--sky-dark); border-left-color: var(--sky); font-weight: 600; }
.sidebar-bottom { padding: 14px 16px 18px; border-top: 1px solid var(--border); }
.sidebar-bottom a { display: flex; align-items: center; gap: 9px; color: var(--red); font-size: 12.5px; font-weight: 600; text-decoration: none; }

.main { margin-left: var(--sw); flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-x: hidden; width: calc(100% - var(--sw)); }

.topbar { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 24px; height: 58px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-breadcrumb { font-size: 9.5px; color: var(--ink40); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; font-weight: 600; }
.topbar-title { font-size: 17px; font-weight: 700; color: var(--ink); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.live-badge { display: flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid rgba(16,185,129,0.25); color: #059669; padding: 5px 11px; border-radius: 99px; font-size: 10.5px; font-weight: 700; }
.live-dot { width: 5px; height: 5px; background: var(--green); border-radius: 50%; animation: lp 1.5s infinite; }
@keyframes lp { 0%,100%{opacity:1;}50%{opacity:0.4;} }
.avatar-pill { display: flex; align-items: center; gap: 8px; background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 5px 12px 5px 5px; }
.avatar-circle { width: 28px; height: 28px; border-radius: 50%; background: var(--sky); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: white; }
.avatar-name { font-size: 11.5px; font-weight: 700; color: var(--ink); line-height: 1.2; }
.avatar-role { font-size: 9.5px; color: var(--sky-dark); font-weight: 600; }

.page-body { padding: 22px 24px 50px; display: flex; flex-direction: column; gap: 20px; }

.flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 600; }
.flash-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.flash-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.flash-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

.stats-row { display: grid; grid-template-columns: repeat(6,1fr); gap: 12px; }
.stat-card { border-radius: 14px; padding: 14px 16px 12px; position: relative; overflow: hidden; color: white; min-height: 108px; display: flex; flex-direction: column; justify-content: space-between; }
.stat-card::after { content:''; position: absolute; right:-18px; bottom:-18px; width:70px; height:70px; border-radius:50%; background:rgba(255,255,255,0.12); }
.sc1{background:#0ea5e9;} .sc2{background:#38bdf8;} .sc3{background:#7dd3fc;color:#0c4a6e;}
.sc4{background:#0284c7;} .sc5{background:#075985;} .sc6{background:#0369a1;}
.stat-icon { width:28px; height:28px; border-radius:7px; background:rgba(255,255,255,0.22); display:flex; align-items:center; justify-content:center; font-size:12px; position:relative; z-index:2; }
.stat-label { font-size:8.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; opacity:0.9; margin-top:10px; position:relative; z-index:2; }
.stat-val   { font-size:26px; font-weight:700; line-height:1; margin-top:2px; position:relative; z-index:2; }
.stat-sub   { font-size:9px; opacity:0.82; margin-top:2px; position:relative; z-index:2; }

.section-label { display:flex; align-items:center; gap:8px; font-size:9.5px; font-weight:700; color:var(--ink40); text-transform:uppercase; letter-spacing:1.2px; }
.section-label::before { content:''; width:12px; height:2px; background:var(--sky); border-radius:2px; }
.section-label::after  { content:''; flex:1; height:1px; background:var(--border); }

.card { background:var(--white); border:1px solid var(--border); border-radius:14px; padding:18px 20px; }
.card-title { font-size:13px; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:7px; margin-bottom:2px; }
.card-title i { color:var(--sky); font-size:13px; }
.card-sub { font-size:10.5px; color:var(--ink40); margin-bottom:14px; }
.card-hdr { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:4px; }
.card-chip { background:var(--sky-pale); border:1px solid var(--border2); border-radius:99px; padding:3px 10px; font-size:9.5px; font-weight:700; color:var(--sky-dark); }

.charts-row   { display: grid; grid-template-columns: 1.5fr 1fr; gap: 14px; }
.charts-row2  { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.legend-row  { display: flex; gap: 14px; margin-bottom: 10px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--ink70); }
.legend-dot  { width: 8px; height: 8px; border-radius: 2px; }
.donut-wrap  { display: flex; align-items: center; gap: 18px; padding-top: 6px; }
.donut-stats { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.ds-row      { display: flex; align-items: center; justify-content: space-between; }
.ds-label    { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--ink70); }
.ds-dot      { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }
.ds-val      { font-size: 13px; font-weight: 700; color: var(--ink); }
.ds-total    { margin-top: 6px; padding-top: 8px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.ds-total-label { font-size: 10px; color: var(--ink40); }
.ds-total-val   { font-size: 14px; font-weight: 700; color: var(--ink); }

.filter-form { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-label { font-size: 10px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.5px; }
.form-control { padding: 8px 12px; border: 1px solid var(--border); border-radius: 9px; font-size: 12px; color: var(--ink); background: var(--white); outline: none; font-family: inherit; min-width: 150px; }
.form-control:focus { border-color: var(--sky); box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
.btn-filter { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; background: var(--sky); color: white; border: none; border-radius: 9px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
.btn-filter:hover { background: var(--sky-dark); }
.btn-clear { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: var(--bg); color: var(--ink70); border: 1px solid var(--border); border-radius: 9px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; }
.btn-clear:hover { background: var(--sky-pale); }

.table-card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.table-head-row { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--border); }
.table-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; }
.table-title i { color: var(--sky); font-size: 13px; }
.table-count { font-size: 11px; color: var(--ink40); font-weight: 600; }
.tbl-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 9px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.6px; padding: 10px 16px; border-bottom: 1px solid var(--border); background: #fafcff; white-space: nowrap; }
td { padding: 12px 16px; font-size: 12.5px; color: var(--ink); border-bottom: 1px solid var(--border); vertical-align: middle; white-space: nowrap; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8fcff; }

.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:99px; font-size:9.5px; font-weight:700; }
.b-pending   { background:#fef3c7; color:#b45309;  border:1px solid #fde68a; }
.b-approved  { background:var(--sky-pale); color:var(--sky-dark); border:1px solid var(--sky-pale2); }
.b-completed { background:#d1fae5; color:#047857;  border:1px solid #a7f3d0; }
.b-cancelled { background:#fee2e2; color:#b91c1c;  border:1px solid #fecaca; }
.b-walkin    { background:#f3e8ff; color:#7c3aed;  border:1px solid #ddd6fe; }

.pat-cell { display:flex; align-items:center; gap:8px; }
.pat-av   { width:28px; height:28px; border-radius:50%; background:var(--sky-pale); display:flex; align-items:center; justify-content:center; font-size:9.5px; font-weight:700; color:var(--sky-dark); flex-shrink:0; }
.pat-name  { font-size:12px; font-weight:600; color:var(--ink); }
.pat-email { font-size:10px; color:var(--ink40); }

.action-group { display: flex; gap: 5px; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: 7px; font-size: 11px; font-weight: 600; cursor: pointer; border: none; font-family: inherit; transition: all 0.15s; text-decoration: none; white-space: nowrap; }
.btn-complete { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
.btn-complete:hover { background: #a7f3d0; }
.btn-cancel   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.btn-cancel:hover { background: #fecaca; }

.empty-state { text-align: center; padding: 48px 20px; color: var(--ink40); }
.empty-state i { font-size: 32px; margin-bottom: 12px; display: block; color: var(--sky-pale2); }
.empty-state p { font-size: 13px; margin-bottom: 4px; }
.empty-state small { font-size: 11.5px; }

@media(max-width:1100px) {
  .stats-row { grid-template-columns: repeat(3,1fr); }
  .charts-row, .charts-row2 { grid-template-columns: 1fr; }
}
@media(max-width:768px) {
  :root { --sw: 56px; }
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span { display: none; }
  .sidebar-logo { padding: 16px 8px; justify-content: center; }
  .sidebar-nav a { padding: 11px; justify-content: center; }
  .page-body { padding: 14px; }
  .stats-row { grid-template-columns: repeat(2,1fr); }
  .filter-form { flex-direction: column; align-items: stretch; }
  .form-control { min-width: 0; }
}
</style>
</head>
<body>
<div class="layout">

<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-concierge-bell"></i></div>
    <div><div class="logo-name">Zaman Medical Center</div><div class="logo-sub">Reception</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="receptionistdasboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="receptionistappointments.php" class="active"><i class="fas fa-calendar-check"></i><span>Appointments</span></a>
    
    
    <a href="receptionistprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="receptionistchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
  </nav>
  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<div class="main">

  <header class="topbar">
    <div>
      <div class="topbar-breadcrumb">Front Desk / Appointments</div>
      <div class="topbar-title">Appointments</div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="avatar-pill">
        <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="avatar-role">Receptionist</div>
        </div>
      </div>
    </div>
  </header>

  <div class="page-body">

    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
      <i class="fas <?= $flash['type']==='success'?'fa-circle-check':($flash['type']==='warning'?'fa-triangle-exclamation':'fa-circle-xmark') ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="stat-card sc1"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="stat-label">Total</div><div class="stat-val"><?= $total_appts ?></div><div class="stat-sub">All-time</div></div></div>
      <div class="stat-card sc2"><div class="stat-icon"><i class="fas fa-calendar-day"></i></div><div><div class="stat-label">Today</div><div class="stat-val"><?= $today_appts ?></div><div class="stat-sub">Scheduled today</div></div></div>
      <div class="stat-card sc3"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-label">Upcoming</div><div class="stat-val"><?= $upcoming_appts ?></div><div class="stat-sub">Not yet resolved</div></div></div>
      <div class="stat-card sc4"><div class="stat-icon"><i class="fas fa-star"></i></div><div><div class="stat-label">Completed</div><div class="stat-val"><?= $completed_appts ?></div><div class="stat-sub">Done</div></div></div>
      <div class="stat-card sc5"><div class="stat-icon"><i class="fas fa-ban"></i></div><div><div class="stat-label">Cancelled</div><div class="stat-val"><?= $cancelled_appts ?></div><div class="stat-sub">Did not proceed</div></div></div>
      <div class="stat-card sc6"><div class="stat-icon"><i class="fas fa-person-walking-arrow-right"></i></div><div><div class="stat-label">Walk-ins</div><div class="stat-val"><?= $walkin_appts ?></div><div class="stat-sub">Front-desk booked</div></div></div>
    </div>

    <div class="section-label"><i class="fas fa-chart-bar"></i> Analytics</div>

    <!-- CHARTS ROW 1: Monthly trend + Status breakdown -->
    <div class="charts-row">
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;">
          <div>
            <div class="card-title"><i class="fas fa-chart-bar"></i> Monthly Appointments</div>
            <div class="card-sub">Booking volume — last 6 months</div>
          </div>
          <div class="card-chip">6 Months</div>
        </div>
        <div style="position:relative;width:100%;height:160px;">
          <canvas id="monthlyChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;">
          <div>
            <div class="card-title"><i class="fas fa-circle-half-stroke"></i> Status Breakdown</div>
            <div class="card-sub">All appointments, by status</div>
          </div>
          <div class="card-chip">Live</div>
        </div>
        <div class="donut-wrap">
          <div style="position:relative;width:100px;height:100px;flex-shrink:0;">
            <canvas id="statusDonut"></canvas>
          </div>
          <div class="donut-stats">
            <?php foreach ($status_counts as $i => $cnt): endforeach; $i = 0; ?>
            <?php foreach ($status_counts as $st => $cnt): ?>
              <div class="ds-row">
                <div class="ds-label"><div class="ds-dot" style="background:<?= $status_palette[$i % count($status_palette)] ?>"></div><?= htmlspecialchars(ucfirst($st)) ?></div>
                <div class="ds-val"><?= $cnt ?></div>
              </div>
            <?php $i++; endforeach; ?>
            <div class="ds-total">
              <div class="ds-total-label">Total</div>
              <div class="ds-total-val"><?= $total_appts ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- CHARTS ROW 2: Appointments by doctor + Slot availability -->
    <div class="charts-row2">
      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;">
          <div>
            <div class="card-title"><i class="fas fa-user-doctor"></i> Appointments by Doctor</div>
            <div class="card-sub">Top doctors by total appointment volume</div>
          </div>
        </div>
        <?php if (empty($doc_appt_counts)): ?>
          <div class="empty-state"><i class="fas fa-user-doctor"></i><p>No doctor-linked appointments yet</p></div>
        <?php else: ?>
          <div style="position:relative;width:100%;height:180px;">
            <canvas id="doctorChart"></canvas>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;">
          <div>
            <div class="card-title"><i class="fas fa-calendar-week"></i> Slot Availability</div>
            <div class="card-sub">Free vs booked slots for the selected doctor &amp; date</div>
          </div>
        </div>
        <?php if (!$show_slot_graph): ?>
          <div class="empty-state">
            <i class="fas fa-filter"></i>
            <p>Pick a Doctor and a Date in the filters below</p>
            <small>The free/booked slot graph appears here once both are selected.</small>
          </div>
        <?php elseif ($slot_total === 0): ?>
          <div class="empty-state">
            <i class="fas fa-calendar-xmark"></i>
            <p>No working schedule found for this doctor on <?= date("l", strtotime($f_date)) ?></p>
          </div>
        <?php else: ?>
          <div class="donut-wrap">
            <div style="position:relative;width:100px;height:100px;flex-shrink:0;">
              <canvas id="slotDonut"></canvas>
            </div>
            <div class="donut-stats">
              <div class="ds-row"><div class="ds-label"><div class="ds-dot" style="background:#10b981"></div>Free</div><div class="ds-val"><?= $slot_free ?></div></div>
              <div class="ds-row"><div class="ds-label"><div class="ds-dot" style="background:#ef4444"></div>Booked</div><div class="ds-val"><?= $slot_booked ?></div></div>
              <div class="ds-total"><div class="ds-total-label">Total Slots</div><div class="ds-total-val"><?= $slot_total ?></div></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="section-label"><i class="fas fa-filter"></i> Filter Appointments</div>

    <!-- FILTERS -->
    <form method="GET" class="filter-form">
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="all" <?= $f_status==='all'?'selected':'' ?>>All Statuses</option>
          <?php foreach ($statuses as $st): ?>
            <option value="<?= htmlspecialchars($st) ?>" <?= $f_status===$st?'selected':'' ?>><?= htmlspecialchars(ucfirst($st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Doctor</label>
        <select name="doctor" class="form-control">
          <option value="0">All Doctors</option>
          <?php foreach ($doctors as $d): ?>
            <option value="<?= $d['doctor_id'] ?>" <?= $f_doctor===(int)$d['doctor_id']?'selected':'' ?>><?= htmlspecialchars($d['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Date</label>
        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($f_date) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Search Patient</label>
        <input type="text" name="search" class="form-control" placeholder="Name or email…" value="<?= htmlspecialchars($f_search) ?>">
      </div>
      <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
      <a href="receptionistappointments.php" class="btn-clear"><i class="fas fa-rotate-left"></i> Clear</a>
    </form>

    <!-- TABLE -->
    <div class="table-card">
      <div class="table-head-row">
        <div class="table-title"><i class="fas fa-list"></i> Appointments</div>
        <div class="table-count"><?= count($appointments) ?> result<?= count($appointments) !== 1 ? 's' : '' ?> <?= count($appointments) === 200 ? '(showing first 200)' : '' ?></div>
      </div>

      <?php if (empty($appointments)): ?>
      <div class="empty-state">
        <i class="fas fa-calendar-xmark"></i>
        <p>No appointments found</p>
        <small>Try changing or clearing the filters above.</small>
      </div>
      <?php else: ?>
      <div class="tbl-wrap">
      <table>
        <thead>
          <tr>
            <th>Patient</th>
            <th>Doctor</th>
            <th>Date &amp; Time</th>
            <th>Type</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($appointments as $a):
            $ini = strtoupper(substr($a['patient_name'] ?? 'PA', 0, 2));
            $st  = $a['status'];
            $bc  = $badge_map[$st] ?? 'b-pending';
            $is_final = in_array($st, ['completed', 'cancelled'], true);
          ?>
          <tr>
            <td>
              <div class="pat-cell">
                <div class="pat-av"><?= htmlspecialchars($ini) ?></div>
                <div>
                  <div class="pat-name"><?= htmlspecialchars($a['patient_name']) ?></div>
                  <div class="pat-email"><?= htmlspecialchars($a['patient_email'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td style="color:var(--ink70)"><?= htmlspecialchars($a['doctor_name'] ?? '—') ?></td>
            <td style="color:var(--ink70)"><?= date('M d, Y · h:i A', strtotime($a['appointment_date'])) ?></td>
            <td>
              <?php if ($a['appointment_type'] === 'walk-in'): ?>
                <span class="badge b-walkin"><i class="fas fa-person-walking-arrow-right" style="font-size:8px"></i> Walk-in</span>
              <?php else: ?>
                <span style="color:var(--ink40);font-size:11px;">Scheduled</span>
              <?php endif; ?>
            </td>
            <td><span class="badge <?= $bc ?>"><?= htmlspecialchars(ucfirst($st)) ?></span></td>
            <td>
              <?php if (!$is_final): ?>
              <div class="action-group">
                <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this appointment as completed?');">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                  <input type="hidden" name="new_status" value="completed">
                  <input type="hidden" name="redirect_qs" value="<?= htmlspecialchars($current_qs) ?>">
                  <button type="submit" class="btn btn-complete"><i class="fas fa-check"></i> Complete</button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this appointment? The time slot will be freed up.');">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                  <input type="hidden" name="new_status" value="cancelled">
                  <input type="hidden" name="redirect_qs" value="<?= htmlspecialchars($current_qs) ?>">
                  <button type="submit" class="btn btn-cancel"><i class="fas fa-xmark"></i> Cancel</button>
                </form>
              </div>
              <?php else: ?>
                <span style="font-size:11px;color:var(--ink40);">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
Chart.defaults.color = 'rgba(12,45,63,0.45)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;

// Monthly trend
new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($m_labels) ?>,
    datasets: [{
      label: 'Appointments',
      data: <?= json_encode($m_totals) ?>,
      backgroundColor: 'rgba(14,165,233,0.52)',
      borderColor: '#0ea5e9',
      borderWidth: 1.5,
      borderRadius: 6,
      maxBarThickness: 36
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#0c2d3f', padding:9, cornerRadius:7 } },
    scales: {
      x: { grid:{ display:false } },
      y: { grid:{ color:'rgba(14,165,233,0.07)' }, beginAtZero:true, ticks:{ precision:0 } }
    }
  }
});

// Status breakdown donut
const statusLabels = <?= json_encode(array_map('ucfirst', array_keys($status_counts))) ?>;
const statusValues  = <?= json_encode(array_values($status_counts)) ?>;
const statusColors  = <?= json_encode(array_slice($status_palette, 0, max(count($status_counts), 1))) ?>;

if (statusValues.length) {
  new Chart(document.getElementById('statusDonut'), {
    type: 'doughnut',
    data: {
      labels: statusLabels,
      datasets: [{ data: statusValues, backgroundColor: statusColors, borderWidth: 0, hoverOffset: 5 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '72%',
      plugins: { legend: { display: false }, tooltip: { backgroundColor:'#0c2d3f', padding:9, cornerRadius:7 } }
    }
  });
}

// Appointments by doctor
const docChartEl = document.getElementById('doctorChart');
if (docChartEl) {
  new Chart(docChartEl, {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($doc_appt_counts, 'doc_name')) ?>,
      datasets: [{
        label: 'Appointments',
        data: <?= json_encode(array_map('intval', array_column($doc_appt_counts, 'cnt'))) ?>,
        backgroundColor: 'rgba(2,132,199,0.55)',
        borderColor: '#0284c7',
        borderWidth: 1.5,
        borderRadius: 6,
        maxBarThickness: 28
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true, maintainAspectRatio: false,
      plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#0c2d3f', padding:9, cornerRadius:7 } },
      scales: {
        x: { grid:{ color:'rgba(14,165,233,0.07)' }, beginAtZero:true, ticks:{ precision:0 } },
        y: { grid:{ display:false } }
      }
    }
  });
}

// Slot availability donut
const slotDonutEl = document.getElementById('slotDonut');
if (slotDonutEl) {
  new Chart(slotDonutEl, {
    type: 'doughnut',
    data: {
      labels: ['Free', 'Booked'],
      datasets: [{
        data: [<?= $slot_free ?>, <?= $slot_booked ?>],
        backgroundColor: ['#10b981', '#ef4444'],
        borderWidth: 0,
        hoverOffset: 5
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '72%',
      plugins: { legend: { display: false }, tooltip: { backgroundColor:'#0c2d3f', padding:9, cornerRadius:7 } }
    }
  });
}

// Auto-dismiss flash
const flash = document.querySelector('.flash');
if (flash) setTimeout(() => flash.style.display = 'none', 4000);
</script>
</body>
</html>