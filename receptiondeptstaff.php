<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'department_head') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// ── ACTIONS: Approve / Reject / Delete ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $target_id = intval($_POST['user_id']);
    $action    = $_POST['action'];

    if ($action === 'approve') {
    $stmt = $conn->prepare("UPDATE users SET status='active' WHERE user_id=?");
    $stmt->bind_param("i", $target_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Receptionist approved (' . $affected . ' row updated).'];
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE users SET status='rejected' WHERE user_id=? AND role='receptionist'");
        $stmt->bind_param("i", $target_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Receptionist request rejected.'];
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=? AND role='receptionist'");
        $stmt->bind_param("i", $target_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Receptionist removed from system.'];
    } elseif ($action === 'reactivate') {
        $stmt = $conn->prepare("UPDATE users SET status='active' WHERE user_id=? AND role='receptionist'");
        $stmt->bind_param("i", $target_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Receptionist reactivated successfully.'];
    }

    header("Location: receptiondeptstaff.php");
    exit();
}

// ── Flash message ──
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── Current user ──
$user = ['full_name' => 'Reception Head', 'profile_image' => '', 'email' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image, email FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($full_name, $profile_image, $email);
if ($stmt->fetch()) {
    $user = ['full_name' => $full_name ?: 'Reception Head', 'profile_image' => $profile_image ?: '', 'email' => $email ?: ''];
}
$stmt->close();

// ── Filter ──
$filter = isset($_GET['filter']) && in_array($_GET['filter'], ['all','pending','active','rejected']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ── Stats ──
$total_staff    = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist'")->fetch_row()[0] ?? 0;
$pending_staff  = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist' AND status='pending'")->fetch_row()[0] ?? 0;
$active_staff   = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist' AND status='active'")->fetch_row()[0] ?? 0;
$rejected_staff = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist' AND status='rejected'")->fetch_row()[0] ?? 0;

// ── Monthly registrations (last 6 months) ──
$monthly_data = [];
$mres = $conn->query("SELECT DATE_FORMAT(created_at,'%b') as month, COUNT(*) as total
                      FROM users WHERE role='receptionist'
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                      GROUP BY MONTH(created_at), DATE_FORMAT(created_at,'%b')
                      ORDER BY MONTH(created_at)");
if ($mres) while ($row = $mres->fetch_assoc()) $monthly_data[] = $row;
$months       = empty($monthly_data) ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data, 'month');
$month_counts = empty($monthly_data) ? [0,0,0,0,0,0] : array_map('intval', array_column($monthly_data, 'total'));

// ── Staff list ──
$where = "WHERE role='receptionist'";
$params = [];
$types  = '';

if ($filter !== 'all') {
    $where  .= " AND status=?";
    $params[] = $filter;
    $types   .= 's';
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where   .= " AND (full_name LIKE ? OR email LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$staff_list = [];
$stmt2 = $conn->prepare("SELECT user_id, full_name, email, status, created_at, profile_image FROM users $where ORDER BY
    CASE status WHEN 'pending' THEN 1 WHEN 'active' THEN 2 ELSE 3 END, created_at DESC");
if (!empty($params)) {
    $stmt2->bind_param($types, ...$params);
}
$stmt2->execute();
$res2 = $stmt2->get_result();
while ($row = $res2->fetch_assoc()) $staff_list[] = $row;
$stmt2->close();

$conn->close();

$initials = strtoupper(substr($user['full_name'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reception Staff — SHAPMS</title>
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
  --sw:        200px;
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; font-size: 13px; overflow-x: hidden; width: 100%; }
.layout { display: flex; min-height: 100vh; width: 100%; max-width: 100vw; overflow: hidden; }

/* ── SIDEBAR ── */
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

/* ── MAIN ── */
.main { margin-left: var(--sw); flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-x: hidden; width: calc(100% - var(--sw)); }
/* ── TOPBAR ── */
.topbar { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 24px; height: 58px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-breadcrumb { font-size: 9.5px; color: var(--ink40); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; font-weight: 600; }
.topbar-title { font-size: 17px; font-weight: 700; color: var(--ink); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.live-badge { display: flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid rgba(16,185,129,0.25); color: #059669; padding: 5px 11px; border-radius: 99px; font-size: 10.5px; font-weight: 700; }
.live-dot { width: 5px; height: 5px; background: var(--green); border-radius: 50%; animation: lp 1.5s infinite; }
@keyframes lp { 0%,100% { opacity:1; } 50% { opacity:0.4; } }
.avatar-pill { display: flex; align-items: center; gap: 8px; background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 5px 12px 5px 5px; }
.avatar-circle { width: 28px; height: 28px; border-radius: 50%; background: var(--sky); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: white; }
.avatar-name { font-size: 11.5px; font-weight: 700; color: var(--ink); line-height: 1.2; }
.avatar-role { font-size: 9.5px; color: var(--sky-dark); font-weight: 600; }

/* ── TICKER ── */
.ticker-bar { background: var(--sky-pale); border-bottom: 1px solid var(--border); padding: 0 24px; height: 34px; display: flex; align-items: center; overflow: hidden; }
.ticker-track { display: flex; animation: tick 28s linear infinite; white-space: nowrap; }
.ticker-item { display: flex; align-items: center; gap: 6px; padding: 0 24px; font-size: 10.5px; font-weight: 600; color: var(--sky-dark); }
.ticker-dot { width: 4px; height: 4px; border-radius: 50%; background: var(--sky); }
@keyframes tick { 0% { transform: translateX(0); } 100% { transform: translateX(-50%); } }

/* ── PAGE BODY ── */
.page-body { padding: 22px 24px 50px; display: flex; flex-direction: column; gap: 20px; }

/* ── FLASH ── */
.flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 12.5px; font-weight: 600; }
.flash-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.flash-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.flash-danger  { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* ── STAT CARDS ── */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.stat-card { border-radius: 14px; padding: 16px 18px 14px; position: relative; overflow: hidden; color: white; min-height: 110px; display: flex; flex-direction: column; justify-content: space-between; }
.stat-card::after { content: ''; position: absolute; right: -20px; bottom: -20px; width: 80px; height: 80px; border-radius: 50%; background: rgba(255,255,255,0.12); }
.stat-card.c1 { background: #0ea5e9; }
.stat-card.c2 { background: #38bdf8; }
.stat-card.c3 { background: #0284c7; }
.stat-card.c4 { background: #075985; }
.stat-icon { width: 30px; height: 30px; border-radius: 8px; background: rgba(255,255,255,0.22); display: flex; align-items: center; justify-content: center; font-size: 13px; position: relative; z-index: 2; }
.stat-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.9; margin-top: 10px; position: relative; z-index: 2; }
.stat-val   { font-size: 28px; font-weight: 700; line-height: 1; margin-top: 2px; position: relative; z-index: 2; }
.stat-sub   { font-size: 9.5px; opacity: 0.82; margin-top: 2px; position: relative; z-index: 2; }

/* ── SECTION LABEL ── */
.section-label { display: flex; align-items: center; gap: 8px; font-size: 9.5px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 1.2px; }
.section-label::before { content: ''; width: 12px; height: 2px; background: var(--sky); border-radius: 2px; }
.section-label::after  { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ── CHARTS ROW ── */
.charts-row { display: grid; grid-template-columns: 1.6fr 1fr; gap: 14px; }
.card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; }
.card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 4px; }
.card-title  { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; }
.card-title i { color: var(--sky); font-size: 13px; }
.card-sub    { font-size: 10.5px; color: var(--ink40); margin-bottom: 12px; }
.card-chip   { background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 3px 10px; font-size: 9.5px; font-weight: 700; color: var(--sky-dark); }
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

/* ── TOOLBAR ── */
.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.filter-tabs { display: flex; gap: 4px; background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: 4px; }
.filter-tab { padding: 5px 14px; border-radius: 7px; font-size: 11.5px; font-weight: 600; color: var(--ink70); cursor: pointer; text-decoration: none; transition: all 0.15s; white-space: nowrap; }
.filter-tab:hover  { background: var(--sky-pale); color: var(--sky-dark); }
.filter-tab.active { background: var(--sky); color: white; }
.filter-tab .tab-count { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,0.25); font-size: 9px; margin-left: 5px; }
.filter-tab:not(.active) .tab-count { background: var(--sky-pale); color: var(--sky-dark); }
.search-wrap { flex: 1; min-width: 200px; position: relative; }
.search-wrap i { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--ink40); font-size: 12px; }
.search-wrap input { width: 100%; padding: 8px 12px 8px 32px; border: 1px solid var(--border); border-radius: 9px; font-size: 12.5px; color: var(--ink); background: var(--white); outline: none; font-family: inherit; }
.search-wrap input:focus { border-color: var(--sky); box-shadow: 0 0 0 3px rgba(14,165,233,0.1); }
.search-wrap input::placeholder { color: var(--ink40); }

/* ── STAFF TABLE ── */
.table-card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.table-head-row { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px 14px; border-bottom: 1px solid var(--border); }
.table-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; }
.table-title i { color: var(--sky); font-size: 13px; }
.table-count { font-size: 11px; color: var(--ink40); font-weight: 600; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 9px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.6px; padding: 10px 16px; border-bottom: 1px solid var(--border); background: #fafcff; }
td { padding: 12px 16px; font-size: 12.5px; color: var(--ink); border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f8fcff; }

/* ── BADGES ── */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
.b-pending  { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.b-active   { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
.b-rejected { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

/* ── AVATAR in table ── */
.staff-avatar { display: flex; align-items: center; gap: 10px; }
.staff-av-circle { width: 32px; height: 32px; border-radius: 50%; background: var(--sky-pale); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--sky-dark); flex-shrink: 0; }
.staff-name  { font-size: 12.5px; font-weight: 600; color: var(--ink); }
.staff-email { font-size: 10.5px; color: var(--ink40); margin-top: 1px; }

/* ── ACTION BUTTONS ── */
.action-group { display: flex; gap: 5px; flex-wrap: wrap; }
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: 7px; font-size: 11px; font-weight: 600; cursor: pointer; border: none; font-family: inherit; transition: all 0.15s; text-decoration: none; white-space: nowrap; }
.btn-approve    { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
.btn-approve:hover { background: #a7f3d0; }
.btn-reject     { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.btn-reject:hover  { background: #fde68a; }
.btn-reactivate { background: var(--sky-pale); color: var(--sky-dark); border: 1px solid var(--sky-pale2); }
.btn-reactivate:hover { background: var(--sky-pale2); }
.btn-delete     { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.btn-delete:hover  { background: #fecaca; }

/* ── EMPTY STATE ── */
.empty-state { text-align: center; padding: 48px 20px; color: var(--ink40); }
.empty-state i { font-size: 32px; margin-bottom: 12px; display: block; color: var(--sky-pale2); }
.empty-state p { font-size: 13px; margin-bottom: 4px; }
.empty-state small { font-size: 11.5px; }

/* ── PENDING ALERT ── */
.pending-alert { display: flex; align-items: center; gap: 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 16px; }
.pending-alert i { color: var(--yellow); font-size: 16px; flex-shrink: 0; }
.pending-alert-text { font-size: 12.5px; color: #92400e; font-weight: 600; }
.pending-alert-sub  { font-size: 11px; color: #b45309; margin-top: 1px; }

@media(max-width:1100px) {
  .stats-row { grid-template-columns: repeat(2,1fr); }
  .charts-row { grid-template-columns: 1fr; }
}
@media(max-width:768px) {
  :root { --sw: 56px; }
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span { display: none; }
  .sidebar-logo { padding: 16px 8px; justify-content: center; }
  .sidebar-nav a { padding: 11px; justify-content: center; }
  .page-body { padding: 14px; }
  .stats-row { grid-template-columns: repeat(2,1fr); }
  .toolbar { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-headset"></i></div>
    <div>
      <div class="logo-name">Zaman Medical Center</div>
      <div class="logo-sub">Reception</div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="receptionistDHdashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="receptiondeptstaff.php" class="active"><i class="fas fa-users"></i><span>Reception Staff</span></a>
    <a href="receptiondeptappointments.php"><i class="fas fa-calendar-check"></i><span>Appointments</span></a>
    <div class="nav-section">Account</div>
    <a href="receptiondeptprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="patientchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
  </nav>
  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">

  <!-- TOPBAR -->
  <header class="topbar">
    <div>
      <div class="topbar-breadcrumb">Staff / Management</div>
      <div class="topbar-title">Reception Staff</div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="avatar-pill">
        <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="avatar-role">Dept. Head</div>
        </div>
      </div>
    </div>
  </header>

  <!-- TICKER -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php
      $tickers = ['Staff Requests Need Review','Approve to Grant Login Access','Pending Receptionists Awaiting Approval','Manage Your Reception Team','Rejected Staff Cannot Log In'];
      foreach (array_merge($tickers, $tickers) as $t): ?>
        <div class="ticker-item"><div class="ticker-dot"></div><?= $t ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- PAGE BODY -->
  <div class="page-body">

    <!-- Flash -->
    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
      <i class="fas <?= $flash['type']==='success'?'fa-circle-check':($flash['type']==='warning'?'fa-triangle-exclamation':'fa-circle-xmark') ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Pending Alert -->
    <?php if ($pending_staff > 0): ?>
    <div class="pending-alert">
      <i class="fas fa-bell"></i>
      <div>
        <div class="pending-alert-text"><?= $pending_staff ?> receptionist<?= $pending_staff > 1 ? 's' : '' ?> waiting for approval</div>
        <div class="pending-alert-sub">Pending receptionists cannot log in until you approve their request.</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="stat-card c1">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div>
          <div class="stat-label">Total Staff</div>
          <div class="stat-val"><?= $total_staff ?></div>
          <div class="stat-sub">All receptionists</div>
        </div>
      </div>
      <div class="stat-card c2">
        <div class="stat-icon"><i class="fas fa-clock"></i></div>
        <div>
          <div class="stat-label">Pending Approval</div>
          <div class="stat-val"><?= $pending_staff ?></div>
          <div class="stat-sub">Awaiting your decision</div>
        </div>
      </div>
      <div class="stat-card c3">
        <div class="stat-icon"><i class="fas fa-user-check"></i></div>
        <div>
          <div class="stat-label">Active</div>
          <div class="stat-val"><?= $active_staff ?></div>
          <div class="stat-sub">Can log in now</div>
        </div>
      </div>
      <div class="stat-card c4">
        <div class="stat-icon"><i class="fas fa-user-xmark"></i></div>
        <div>
          <div class="stat-label">Rejected</div>
          <div class="stat-val"><?= $rejected_staff ?></div>
          <div class="stat-sub">Access denied</div>
        </div>
      </div>
    </div>

    <div class="section-label"><i class="fas fa-chart-bar"></i> Analytics</div>

    <!-- CHARTS -->
    <div class="charts-row">
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><i class="fas fa-chart-bar"></i> Monthly Registrations</div>
            <div class="card-sub">New receptionist sign-ups — last 6 months</div>
          </div>
          <div class="card-chip">6 Months</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#0ea5e9"></div> Registrations</div>
        </div>
        <div style="position:relative;width:100%;height:130px;">
          <canvas id="monthlyChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><i class="fas fa-circle-half-stroke"></i> Status Breakdown</div>
            <div class="card-sub">Current staff distribution</div>
          </div>
          <div class="card-chip">Live</div>
        </div>
        <div class="donut-wrap">
          <div style="position:relative;width:100px;height:100px;flex-shrink:0;">
            <canvas id="donutChart"></canvas>
          </div>
          <div class="donut-stats">
            <div class="ds-row">
              <div class="ds-label"><div class="ds-dot" style="background:#10b981"></div>Active</div>
              <div class="ds-val"><?= $active_staff ?></div>
            </div>
            <div class="ds-row">
              <div class="ds-label"><div class="ds-dot" style="background:#f59e0b"></div>Pending</div>
              <div class="ds-val"><?= $pending_staff ?></div>
            </div>
            <div class="ds-row">
              <div class="ds-label"><div class="ds-dot" style="background:#ef4444"></div>Rejected</div>
              <div class="ds-val"><?= $rejected_staff ?></div>
            </div>
            <div class="ds-total">
              <div class="ds-total-label">Total</div>
              <div class="ds-total-val"><?= $total_staff ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="section-label"><i class="fas fa-list"></i> Staff Directory</div>

    <!-- TOOLBAR -->
    <div class="toolbar">
      <div class="filter-tabs">
        <a href="?filter=all<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-tab <?= $filter==='all' ? 'active' : '' ?>">
          All <span class="tab-count"><?= $total_staff ?></span>
        </a>
        <a href="?filter=pending<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-tab <?= $filter==='pending' ? 'active' : '' ?>">
          Pending <span class="tab-count"><?= $pending_staff ?></span>
        </a>
        <a href="?filter=active<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-tab <?= $filter==='active' ? 'active' : '' ?>">
          Active <span class="tab-count"><?= $active_staff ?></span>
        </a>
        <a href="?filter=rejected<?= $search ? '&search='.urlencode($search) : '' ?>" class="filter-tab <?= $filter==='rejected' ? 'active' : '' ?>">
          Rejected <span class="tab-count"><?= $rejected_staff ?></span>
        </a>
      </div>
      <form method="GET" class="search-wrap" style="display:flex;">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <i class="fas fa-magnifying-glass"></i>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email…">
      </form>
    </div>

    <!-- STAFF TABLE -->
    <div class="table-card">
      <div class="table-head-row">
        <div class="table-title"><i class="fas fa-users"></i> Receptionists</div>
        <div class="table-count"><?= count($staff_list) ?> result<?= count($staff_list) !== 1 ? 's' : '' ?></div>
      </div>

      <?php if (empty($staff_list)): ?>
      <div class="empty-state">
        <i class="fas fa-user-slash"></i>
        <p>No receptionists found</p>
        <small>Try changing the filter or search term.</small>
      </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Receptionist</th>
            <th>Joined</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staff_list as $s):
            $ini = strtoupper(substr($s['full_name'], 0, 2));
          ?>
          <tr>
            <td>
              <div class="staff-avatar">
                <div class="staff-av-circle"><?= htmlspecialchars($ini) ?></div>
                <div>
                  <div class="staff-name"><?= htmlspecialchars($s['full_name']) ?></div>
                  <div class="staff-email"><?= htmlspecialchars($s['email']) ?></div>
                </div>
              </div>
            </td>
            <td style="color:var(--ink40);font-size:11px"><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
            <td>
              <?php if ($s['status'] === 'pending'): ?>
                <span class="badge b-pending"><i class="fas fa-clock" style="font-size:8px"></i> Pending</span>
              <?php elseif ($s['status'] === 'active'): ?>
                <span class="badge b-active"><i class="fas fa-circle-check" style="font-size:8px"></i> Active</span>
              <?php else: ?>
                <span class="badge b-rejected"><i class="fas fa-ban" style="font-size:8px"></i> Rejected</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="action-group">

                <?php if ($s['status'] === 'pending'): ?>
                  <!-- Approve -->
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Approve <?= htmlspecialchars($s['full_name']) ?>? They will be able to log in.');">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                    <button type="submit" class="btn btn-approve"><i class="fas fa-check"></i> Approve</button>
                  </form>
                  <!-- Reject -->
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Reject <?= htmlspecialchars($s['full_name']) ?>? They will not be able to log in.');">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                    <button type="submit" class="btn btn-reject"><i class="fas fa-xmark"></i> Reject</button>
                  </form>

                <?php elseif ($s['status'] === 'active'): ?>
                  <!-- Reject (revoke access) -->
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Revoke login access for <?= htmlspecialchars($s['full_name']) ?>?');">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                    <button type="submit" class="btn btn-reject"><i class="fas fa-user-slash"></i> Revoke</button>
                  </form>

                <?php elseif ($s['status'] === 'rejected'): ?>
                  <!-- Reactivate -->
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Reactivate <?= htmlspecialchars($s['full_name']) ?>?');">
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                    <button type="submit" class="btn btn-reactivate"><i class="fas fa-rotate-left"></i> Reactivate</button>
                  </form>
                <?php endif; ?>

                <!-- Delete (always visible) -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete <?= htmlspecialchars($s['full_name']) ?>? This cannot be undone.');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
                  <button type="submit" class="btn btn-delete"><i class="fas fa-trash"></i></button>
                </form>

              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
Chart.defaults.color = 'rgba(12,45,63,0.45)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;

const months      = <?= json_encode($months) ?>;
const monthCounts = <?= json_encode($month_counts) ?>;
const activeStaff   = <?= intval($active_staff) ?>;
const pendingStaff  = <?= intval($pending_staff) ?>;
const rejectedStaff = <?= intval($rejected_staff) ?>;

// Bar chart — monthly registrations
new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: months,
    datasets: [{
      label: 'Registrations',
      data: monthCounts,
      backgroundColor: 'rgba(14,165,233,0.52)',
      borderColor: '#0ea5e9',
      borderWidth: 1.5,
      borderRadius: 6,
      maxBarThickness: 36
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: { backgroundColor: '#0c2d3f', padding: 9, cornerRadius: 7 }
    },
    scales: {
      x: { grid: { display: false } },
      y: { grid: { color: 'rgba(14,165,233,0.07)' }, beginAtZero: true, ticks: { precision: 0 } }
    }
  }
});

// Donut chart — staff status
new Chart(document.getElementById('donutChart'), {
  type: 'doughnut',
  data: {
    labels: ['Active', 'Pending', 'Rejected'],
    datasets: [{
      data: [activeStaff || 0.1, pendingStaff, rejectedStaff],
      backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
      borderWidth: 0,
      hoverOffset: 5
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '72%',
    plugins: {
      legend: { display: false },
      tooltip: { backgroundColor: '#0c2d3f', padding: 9, cornerRadius: 7 }
    }
  }
});

// Auto-dismiss flash after 4s
const flash = document.querySelector('.flash');
if (flash) setTimeout(() => flash.style.display = 'none', 4000);
</script>
</body>
</html>