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

// Fetch dept head info
$user = ['full_name' => 'Reception Head', 'profile_image' => '', 'email' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image, email FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($full_name, $profile_image, $email);
if ($stmt->fetch()) {
    $user = ['full_name' => $full_name ?: 'Reception Head', 'profile_image' => $profile_image ?: '', 'email' => $email ?: ''];
}
$stmt->close();

// Stats — receptionist staff
$total_staff    = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist'")->fetch_row()[0] ?? 0;
$pending_staff  = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist' AND status='pending'")->fetch_row()[0] ?? 0;
$active_staff   = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist' AND status='active'")->fetch_row()[0] ?? 0;
$rejected_staff = $conn->query("SELECT COUNT(*) FROM users WHERE role='receptionist' AND status='rejected'")->fetch_row()[0] ?? 0;

// Appointments stats
$total_appts = $pending_appts = $today_appts = $completed_appts = 0;
$recent_appts = $monthly_data = [];

$tableCheck = $conn->query("SHOW TABLES LIKE 'appointments'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $total_appts     = $conn->query("SELECT COUNT(*) FROM appointments")->fetch_row()[0] ?? 0;
    $pending_appts   = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetch_row()[0] ?? 0;
    $today_appts     = $conn->query("SELECT COUNT(*) FROM appointments WHERE DATE(created_at)=CURDATE()")->fetch_row()[0] ?? 0;
    $completed_appts = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='completed'")->fetch_row()[0] ?? 0;

    $res = $conn->query("SELECT a.appointment_id, u.full_name as patient_name, a.status, a.created_at
                         FROM appointments a
                         JOIN users u ON a.patient_id = u.user_id
                         ORDER BY a.created_at DESC LIMIT 5");
    if ($res) while ($row = $res->fetch_assoc()) $recent_appts[] = $row;

    $mres = $conn->query("SELECT DATE_FORMAT(created_at,'%b') as month, COUNT(*) as total
                          FROM appointments
                          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                          GROUP BY MONTH(created_at), DATE_FORMAT(created_at,'%b')
                          ORDER BY MONTH(created_at)");
    if ($mres) while ($row = $mres->fetch_assoc()) $monthly_data[] = $row;
}

// Recent staff
$recent_staff = [];
$res2 = $conn->query("SELECT user_id, full_name, email, status, created_at FROM users WHERE role='receptionist' ORDER BY created_at DESC LIMIT 5");
if ($res2) while ($row = $res2->fetch_assoc()) $recent_staff[] = $row;

$conn->close();

$months       = empty($monthly_data) ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data, 'month');
$month_counts = empty($monthly_data) ? [0,0,0,0,0,0] : array_map('intval', array_column($monthly_data, 'total'));

$initials = strtoupper(substr($user['full_name'], 0, 2));
$profilePic = !empty($user['profile_image']) ? $user['profile_image'] : "uploads/default.png";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reception Department — SHAPMS</title>
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
.logo-sub { font-size: 9px; color: var(--sky-dark); letter-spacing: 1.2px; text-transform: uppercase; font-weight: 600; }
.nav-section { font-size: 9px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--ink40); padding: 16px 16px 6px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 4px 0; }
.sidebar-nav::-webkit-scrollbar { width: 0; }
.sidebar-nav a { display: flex; align-items: center; gap: 9px; padding: 9px 16px; color: var(--ink70); text-decoration: none; font-size: 12.5px; font-weight: 500; border-left: 2px solid transparent; transition: all 0.15s; }
.sidebar-nav a i { width: 15px; text-align: center; font-size: 13px; }
.sidebar-nav a:hover { background: var(--sky-pale); color: var(--sky-dark); }
.sidebar-nav a.active { background: var(--sky-pale); color: var(--sky-dark); border-left-color: var(--sky); font-weight: 600; }
.sidebar-bottom { padding: 14px 16px 18px; border-top: 1px solid var(--border); }
.sidebar-bottom a { display: flex; align-items: center; gap: 9px; color: #ef4444; font-size: 12.5px; font-weight: 600; text-decoration: none; }

/* ── MAIN ── */
.main { margin-left: var(--sw); flex: 1; display: flex; flex-direction: column; min-width: 0; overflow-x: hidden; width: calc(100% - var(--sw)); }

/* ── TOPBAR ── */
.topbar { background: var(--white); border-bottom: 1px solid var(--border); padding: 0 24px; height: 58px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-breadcrumb { font-size: 9.5px; color: var(--ink40); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; font-weight: 600; }
.topbar-title { font-size: 17px; font-weight: 700; color: var(--ink); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.live-badge { display: flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid rgba(16,185,129,0.25); color: #059669; padding: 5px 11px; border-radius: 99px; font-size: 10.5px; font-weight: 700; }
.live-dot { width: 5px; height: 5px; background: #10b981; border-radius: 50%; animation: lp 1.5s infinite; }
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
.page-body { padding: 22px 24px 44px; display: flex; flex-direction: column; gap: 20px; }

/* ── STAT CARDS ── */
.stats-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; }
.stat-card { border-radius: 14px; padding: 16px 16px 14px; position: relative; overflow: hidden; color: white; display: flex; flex-direction: column; justify-content: space-between; min-height: 120px; }
.stat-card::after { content: ''; position: absolute; right: -22px; bottom: -22px; width: 80px; height: 80px; border-radius: 50%; background: rgba(255,255,255,0.12); }
.stat-card.c1 { background: #0ea5e9; }
.stat-card.c2 { background: #38bdf8; }
.stat-card.c3 { background: #7dd3fc; color: #0c4a6e; }
.stat-card.c4 { background: #0284c7; }
.stat-card.c5 { background: #075985; }
.stat-card.c6 { background: #0369a1; }
.stat-icon { width: 30px; height: 30px; border-radius: 8px; background: rgba(255,255,255,0.22); display: flex; align-items: center; justify-content: center; font-size: 13px; position: relative; z-index: 2; }
.stat-label { font-size: 9px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; opacity: 0.9; margin-top: 12px; position: relative; z-index: 2; }
.stat-val { font-size: 28px; font-weight: 700; line-height: 1; margin-top: 3px; position: relative; z-index: 2; }
.stat-sub { font-size: 9.5px; opacity: 0.82; margin-top: 3px; position: relative; z-index: 2; }

/* ── SECTION LABEL ── */
.section-label { display: flex; align-items: center; gap: 8px; font-size: 9.5px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 1.2px; }
.section-label::before { content: ''; width: 12px; height: 2px; background: var(--sky); border-radius: 2px; }
.section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

/* ── CHART CARDS ── */
.charts-row { display: grid; grid-template-columns: 1.7fr 1fr; gap: 14px; }
.card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; }
.card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 4px; }
.card-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; }
.card-title i { color: var(--sky); font-size: 13px; }
.card-sub { font-size: 10.5px; color: var(--ink40); margin-bottom: 12px; }
.card-chip { background: var(--sky-pale); border: 1px solid var(--border2); border-radius: 99px; padding: 3px 10px; font-size: 9.5px; font-weight: 700; color: var(--sky-dark); }
.legend-row { display: flex; gap: 14px; margin-bottom: 10px; }
.legend-item { display: flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--ink70); }
.legend-dot { width: 8px; height: 8px; border-radius: 2px; }

/* ── DONUT SECTION ── */
.donut-wrap { display: flex; align-items: center; gap: 18px; padding-top: 6px; }
.donut-stats { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.ds-row { display: flex; align-items: center; justify-content: space-between; }
.ds-label { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--ink70); }
.ds-dot { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }
.ds-val { font-size: 13px; font-weight: 700; color: var(--ink); }
.ds-total { margin-top: 6px; padding-top: 8px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.ds-total-label { font-size: 10px; color: var(--ink40); }
.ds-total-val { font-size: 14px; font-weight: 700; color: var(--ink); }

/* ── TABLES ── */
.tables-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.table-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.table-title { font-size: 13px; font-weight: 700; color: var(--ink); display: flex; align-items: center; gap: 7px; }
.table-title i { color: var(--sky); font-size: 13px; }
.view-all { font-size: 11px; color: var(--sky-dark); text-decoration: none; font-weight: 700; display: flex; align-items: center; gap: 4px; }
.view-all:hover { opacity: 0.7; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; font-size: 9px; font-weight: 700; color: var(--ink40); text-transform: uppercase; letter-spacing: 0.6px; padding: 0 10px 9px; border-bottom: 1px solid var(--border); }
td { padding: 10px 10px; font-size: 12px; color: var(--ink); border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--sky-pale); }
.badge { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 99px; font-size: 9.5px; font-weight: 700; }
.b-pending   { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.b-active    { background: #d1fae5; color: #047857; border: 1px solid #a7f3d0; }
.b-completed { background: var(--sky-pale); color: var(--sky-dark); border: 1px solid var(--sky-pale2); }
.b-rejected  { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
.empty-state { text-align: center; padding: 28px 12px; color: var(--ink40); font-size: 12px; }
.empty-state i { font-size: 22px; margin-bottom: 8px; display: block; color: var(--sky-pale2); }

@media(max-width:1200px) {
  .stats-row { grid-template-columns: repeat(3,1fr); }
  .charts-row { grid-template-columns: 1fr; }
}
@media(max-width:900px) {
  .tables-row { grid-template-columns: 1fr; }
}
@media(max-width:768px) {
  :root { --sw: 56px; }
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span { display: none; }
  .sidebar-logo { padding: 16px 8px; justify-content: center; }
  .sidebar-nav a { padding: 11px; justify-content: center; }
  .page-body { padding: 14px; }
  .stats-row { grid-template-columns: repeat(2,1fr); }
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
    <a href="receptiondashboard.php" class="active"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="receptiondeptstaff.php"><i class="fas fa-users"></i><span>Reception Staff</span></a>
    <a href="receptiondeptappointments.php"><i class="fas fa-calendar-check"></i><span>Appointments</span></a>
    <div class="nav-section">Account</div>
    <a href="receptiondeptprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="receptiondeptchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
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
    <div class="topbar-breadcrumb">Dashboard / Home</div>
    <div class="topbar-title">Reception Department</div>
  </div>
  <div class="topbar-right">
    <div class="live-badge"><div class="live-dot"></div> LIVE</div>
    <div class="avatar-pill" onclick="document.getElementById('profileInput').click()" style="cursor:pointer;">
      <img id="profilePreview" src="<?= htmlspecialchars($profilePic) ?>" alt="Profile"
           style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1px solid var(--border2);">
      <div>
        <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="avatar-role">Dept. Head</div>
      </div>
    </div>
    <input type="file" id="profileInput" style="display:none;" accept="image/*">
  </div>
</header>

  <!-- TICKER -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php
      $tickers = ['Front Desk Active','Avg Wait Time: 6 min','Zero Missed Check-ins','All Systems Operational','Patient Flow Smooth','Queue Management Live'];
      foreach (array_merge($tickers, $tickers) as $t): ?>
        <div class="ticker-item"><div class="ticker-dot"></div><?= $t ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- PAGE BODY -->
  <div class="page-body">

    <!-- STAT CARDS -->
    <div class="stats-row">
      <div class="stat-card c1">
        <div class="stat-icon"><i class="fas fa-users"></i></div>
        <div>
          <div class="stat-label">Total Staff</div>
          <div class="stat-val"><?= $total_staff ?></div>
          <div class="stat-sub">Reception team</div>
        </div>
      </div>
      <div class="stat-card c2">
        <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
        <div>
          <div class="stat-label">Pending</div>
          <div class="stat-val"><?= $pending_staff ?></div>
          <div class="stat-sub">Needs attention</div>
        </div>
      </div>
      <div class="stat-card c3">
        <div class="stat-icon"><i class="fas fa-user-check"></i></div>
        <div>
          <div class="stat-label">Active Staff</div>
          <div class="stat-val"><?= $active_staff ?></div>
          <div class="stat-sub">Operational</div>
        </div>
      </div>
      <div class="stat-card c4">
        <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
        <div>
          <div class="stat-label">Total Appts</div>
          <div class="stat-val"><?= $total_appts ?></div>
          <div class="stat-sub">All-time</div>
        </div>
      </div>
      <div class="stat-card c5">
        <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
        <div>
          <div class="stat-label">Pending Appts</div>
          <div class="stat-val"><?= $pending_appts ?></div>
          <div class="stat-sub">In queue</div>
        </div>
      </div>
      <div class="stat-card c6">
        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
        <div>
          <div class="stat-label">Today</div>
          <div class="stat-val"><?= $today_appts ?></div>
          <div class="stat-sub">Booked today</div>
        </div>
      </div>
    </div>

    <div class="section-label"><i class="fas fa-chart-line"></i> Analytics Overview</div>

    <!-- CHARTS -->
    <div class="charts-row">

      <!-- Bar Chart -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><i class="fas fa-chart-bar"></i> Monthly Appointments</div>
            <div class="card-sub">Booking volume — last 6 months</div>
          </div>
          <div class="card-chip">6 Months</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#0ea5e9"></div> Appointments</div>
        </div>
        <div style="position:relative;width:100%;height:130px;">
          <canvas id="monthlyChart"></canvas>
        </div>
      </div>

      <!-- Donut Chart -->
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><i class="fas fa-circle-half-stroke"></i> Staff Status</div>
            <div class="card-sub">Distribution breakdown</div>
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

    <div class="section-label"><i class="fas fa-table"></i> Recent Activity</div>

    <!-- TABLES -->
    <div class="tables-row">

      <div class="card">
        <div class="table-header">
          <div class="table-title"><i class="fas fa-calendar-check"></i> Recent Appointments</div>
          <a href="receptiondeptappointments.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <table>
          <thead><tr><th>Patient</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <?php if (empty($recent_appts)): ?>
            <tr><td colspan="3"><div class="empty-state"><i class="fas fa-calendar-xmark"></i>No appointments yet</div></td></tr>
            <?php else: foreach ($recent_appts as $a): ?>
            <tr>
              <td><?= htmlspecialchars($a['patient_name']) ?></td>
              <td><span class="badge <?= $a['status']==='completed' ? 'b-completed' : 'b-pending' ?>"><?= ucfirst($a['status']) ?></span></td>
              <td style="color:var(--ink40);font-size:10.5px"><?= date('M d', strtotime($a['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="table-header">
          <div class="table-title"><i class="fas fa-users"></i> Recent Staff</div>
          <a href="receptiondeptstaff.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <table>
          <thead><tr><th>Name</th><th>Email</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (empty($recent_staff)): ?>
            <tr><td colspan="3"><div class="empty-state"><i class="fas fa-user-slash"></i>No staff registered yet</div></td></tr>
            <?php else: foreach ($recent_staff as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['full_name']) ?></td>
              <td style="color:var(--ink40);font-size:10.5px"><?= htmlspecialchars($s['email']) ?></td>
              <td>
                <span class="badge <?= $s['status']==='active' ? 'b-active' : ($s['status']==='pending' ? 'b-pending' : 'b-rejected') ?>">
                  <?= ucfirst($s['status']) ?>
                </span>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

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

// Bar chart
new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: months,
    datasets: [{
      label: 'Appointments',
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
      y: { grid: { color: 'rgba(14,165,233,0.07)' }, beginAtZero: true }
    }
  }
});

// Donut chart — compact 100×100
new Chart(document.getElementById('donutChart'), {
  type: 'doughnut',
  data: {
    labels: ['Active', 'Pending', 'Rejected'],
    datasets: [{
      data: [activeStaff || 1, pendingStaff, rejectedStaff],
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
</script>

<script>
document.getElementById('profileInput').addEventListener('change', function() {
  let file = this.files[0];
  if (!file) return;
  let formData = new FormData();
  formData.append("profile_image", file);
  fetch("upload_profile.php", {
    method: "POST",
    body: formData,
    credentials: "same-origin"
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      document.getElementById("profilePreview").src = data.url + "?t=" + new Date().getTime();
      alert("Profile photo updated successfully!");
    } else {
      alert(data.message || "Upload failed");
    }
  })
  .catch(() => alert("Upload error. Please try again."));
});
</script>

</body>
</html>