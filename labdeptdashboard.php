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

// Fetch lab head info
$user = ['full_name' => 'Lab Head', 'profile_image' => '', 'email' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image, email FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($full_name, $profile_image, $email);
if ($stmt->fetch()) {
    $user = ['full_name' => $full_name ?: 'Lab Head', 'profile_image' => $profile_image ?: '', 'email' => $email ?: ''];
}
$stmt->close();

// Stats
$total_staff    = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab'")->fetch_row()[0] ?? 0;
$pending_staff  = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab' AND status='pending'")->fetch_row()[0] ?? 0;
$active_staff   = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab' AND status='active'")->fetch_row()[0] ?? 0;
$rejected_staff = $conn->query("SELECT COUNT(*) FROM users WHERE role='lab' AND status='rejected'")->fetch_row()[0] ?? 0;

$total_reports = $pending_reports = $done_reports = 0;
$recent_reports = $monthly_data = [];

$tableCheck = $conn->query("SHOW TABLES LIKE 'lab_reports'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $total_reports   = $conn->query("SELECT COUNT(*) FROM lab_reports")->fetch_row()[0] ?? 0;
    $pending_reports = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE status='pending'")->fetch_row()[0] ?? 0;
    $done_reports    = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE status='completed'")->fetch_row()[0] ?? 0;

    $res = $conn->query("SELECT lr.report_id, u.full_name as patient_name, lr.test_name, lr.status, lr.uploaded_at
                         FROM lab_reports lr
                         JOIN users u ON lr.patient_id = u.user_id
                         ORDER BY lr.uploaded_at DESC LIMIT 5");
    if ($res) while ($row = $res->fetch_assoc()) $recent_reports[] = $row;

    // Monthly trend last 6 months
    $mres = $conn->query("SELECT DATE_FORMAT(uploaded_at,'%b') as month, COUNT(*) as total
                          FROM lab_reports
                          WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                          GROUP BY MONTH(uploaded_at), DATE_FORMAT(uploaded_at,'%b')
                          ORDER BY MONTH(uploaded_at)");
    if ($mres) while ($row = $mres->fetch_assoc()) $monthly_data[] = $row;

    // Daily last 7 days
    $daily_data = [];
    $dres = $conn->query("SELECT DATE_FORMAT(uploaded_at,'%a') as day, COUNT(*) as total
                          FROM lab_reports
                          WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          GROUP BY DATE(uploaded_at), DATE_FORMAT(uploaded_at,'%a')
                          ORDER BY DATE(uploaded_at)");
    if ($dres) while ($row = $dres->fetch_assoc()) $daily_data[] = $row;
}

// Recent staff
$recent_staff = [];
$res2 = $conn->query("SELECT user_id, full_name, email, status, created_at FROM users WHERE role='lab' ORDER BY created_at DESC LIMIT 5");
if ($res2) while ($row = $res2->fetch_assoc()) $recent_staff[] = $row;

$conn->close();

// Chart data
$months = empty($monthly_data) ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data, 'month');
$month_counts = empty($monthly_data) ? [0,0,0,0,0,0] : array_column($monthly_data, 'total');
$days_arr = empty($daily_data) ? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] : array_column($daily_data, 'day');
$day_counts = empty($daily_data) ? [0,0,0,0,0,0,0] : array_column($daily_data, 'total');

$initials = strtoupper(substr($user['full_name'], 0, 2));
$first_name = explode(' ', $user['full_name'])[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lab Department — SHAPMS</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:           #0d1117;
  --bg2:          #161b27;
  --bg3:          #1c2333;
  --bg4:          #212840;
  --border:       rgba(255,255,255,0.07);
  --border2:      rgba(255,255,255,0.12);
  --blue:         #3b82f6;
  --blue-bright:  #60a5fa;
  --cyan:         #06b6d4;
  --purple:       #8b5cf6;
  --green:        #10b981;
  --yellow:       #f59e0b;
  --red:          #ef4444;
  --white:        #ffffff;
  --w90:          rgba(255,255,255,0.90);
  --w70:          rgba(255,255,255,0.70);
  --w40:          rgba(255,255,255,0.40);
  --w15:          rgba(255,255,255,0.15);
  --w08:          rgba(255,255,255,0.08);
  --w04:          rgba(255,255,255,0.04);
  --sidebar-w:    220px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--white);
  min-height: 100vh;
  overflow-x: hidden;
}

/* ── LAYOUT ── */
.layout { display: flex; min-height: 100vh; }

/* ── SIDEBAR ── */
.sidebar {
  width: var(--sidebar-w);
  background: var(--bg2);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 100;
}

.sidebar-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 22px 18px 20px;
  border-bottom: 1px solid var(--border);
}

.logo-box {
  width: 34px; height: 34px;
  background: linear-gradient(135deg, var(--blue), var(--cyan));
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; color: white; flex-shrink: 0;
}

.logo-name {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 15px; font-weight: 700; color: var(--white);
  line-height: 1.1;
}

.logo-sub {
  font-size: 9px; color: var(--w40);
  letter-spacing: 1.5px; text-transform: uppercase;
}

.nav-section {
  font-size: 9px; font-weight: 700;
  letter-spacing: 1.5px; text-transform: uppercase;
  color: var(--w40); padding: 18px 18px 6px;
}

.sidebar-nav { flex: 1; overflow-y: auto; padding: 6px 0; }
.sidebar-nav::-webkit-scrollbar { width: 0; }

.sidebar-nav a {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 18px;
  color: var(--w70); text-decoration: none;
  font-size: 13px; font-weight: 500;
  transition: all 0.15s;
  border-left: 2px solid transparent;
}

.sidebar-nav a i { width: 16px; text-align: center; font-size: 13px; }
.sidebar-nav a:hover { background: var(--w04); color: var(--white); }
.sidebar-nav a.active {
  background: rgba(59,130,246,0.12);
  color: var(--blue-bright);
  border-left-color: var(--blue);
  font-weight: 600;
}

.sidebar-bottom {
  padding: 14px 18px 20px;
  border-top: 1px solid var(--border);
}

.sidebar-bottom a {
  display: flex; align-items: center; gap: 9px;
  color: #f87171; font-size: 13px; font-weight: 500;
  text-decoration: none; padding: 8px 0;
}
.sidebar-bottom a:hover { opacity: 0.7; }

/* ── MAIN ── */
.main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; }

/* ── TOPBAR ── */
.topbar {
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  height: 58px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 50;
}

.topbar-breadcrumb {
  font-size: 11px; color: var(--w40);
  text-transform: uppercase; letter-spacing: 1px;
  margin-bottom: 2px;
}

.topbar-title {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 18px; font-weight: 700; color: var(--white);
}

.topbar-right { display: flex; align-items: center; gap: 12px; }

.live-badge {
  display: flex; align-items: center; gap: 6px;
  background: rgba(16,185,129,0.12);
  border: 1px solid rgba(16,185,129,0.25);
  color: #34d399; padding: 5px 12px;
  border-radius: 99px; font-size: 11.5px; font-weight: 700;
  letter-spacing: 0.5px;
}

.live-dot {
  width: 6px; height: 6px; background: #34d399;
  border-radius: 50%; animation: livepulse 1.5s infinite;
}

@keyframes livepulse {
  0%,100%{ opacity:1; transform:scale(1); }
  50%{ opacity:0.4; transform:scale(0.7); }
}

.avatar-pill {
  display: flex; align-items: center; gap: 8px;
  background: var(--w08); border: 1px solid var(--border2);
  border-radius: 99px; padding: 5px 12px 5px 5px; cursor: pointer;
}

.avatar-circle {
  width: 28px; height: 28px; border-radius: 50%;
  background: linear-gradient(135deg, var(--blue), var(--cyan));
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; color: white;
}

.avatar-info { display: flex; flex-direction: column; gap: 0; }
.avatar-name { font-size: 12px; font-weight: 600; color: var(--w90); line-height: 1.2; }
.avatar-role { font-size: 9.5px; color: var(--w40); }

/* ── TICKER ── */
.ticker-bar {
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
  padding: 0 28px;
  height: 36px;
  display: flex; align-items: center;
  overflow: hidden;
  position: relative;
}

.ticker-track {
  display: flex; gap: 0;
  animation: ticker 30s linear infinite;
  white-space: nowrap;
}

.ticker-item {
  display: flex; align-items: center; gap: 6px;
  padding: 0 28px;
  font-size: 11.5px; font-weight: 500; color: var(--w70);
}

.ticker-dot {
  width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0;
}

@keyframes ticker {
  0%   { transform: translateX(0); }
  100% { transform: translateX(-50%); }
}

/* ── PAGE BODY ── */
.page-body { padding: 22px 28px 48px; display: flex; flex-direction: column; gap: 22px; }

/* ── STAT CARDS ── */
.stats-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; }

.stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-top: 2px solid var(--accent, var(--blue));
  border-radius: 12px;
  padding: 18px 16px 14px;
  display: flex; flex-direction: column; gap: 8px;
  transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.3); }

.stat-icon-row { display: flex; align-items: center; justify-content: space-between; }

.stat-icon {
  width: 36px; height: 36px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
}

.stat-val {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 28px; font-weight: 700; color: var(--white); line-height: 1;
}

.stat-label {
  font-size: 10px; font-weight: 700; color: var(--w40);
  text-transform: uppercase; letter-spacing: 0.8px;
}

.stat-sub {
  font-size: 10.5px; font-weight: 600;
}

/* ── SECTION LABEL ── */
.section-label {
  display: flex; align-items: center; gap: 8px;
  font-size: 10px; font-weight: 700;
  color: var(--w40); text-transform: uppercase; letter-spacing: 1.5px;
  margin-bottom: 14px;
}
.section-label::before { content:''; width:12px; height:1px; background: var(--w40); }
.section-label::after  { content:''; flex:1; height:1px; background: var(--border); }

/* ── CHARTS AREA ── */
.charts-top { display: grid; grid-template-columns: 1fr 340px; gap: 16px; }
.charts-bottom { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

.chart-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 20px 22px;
}

.chart-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  margin-bottom: 4px;
}

.chart-title {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 14px; font-weight: 600; color: var(--white);
  display: flex; align-items: center; gap: 7px;
}

.chart-title i { color: var(--blue-bright); font-size: 13px; }

.chart-sub { font-size: 11px; color: var(--w40); margin-bottom: 16px; }

.chart-pill {
  background: var(--w08); border: 1px solid var(--border2);
  border-radius: 99px; padding: 3px 10px;
  font-size: 10.5px; font-weight: 600; color: var(--w70);
  white-space: nowrap;
}

.legend-row {
  display: flex; align-items: center; gap: 14px;
  margin-bottom: 14px; flex-wrap: wrap;
}

.legend-item {
  display: flex; align-items: center; gap: 5px;
  font-size: 11px; color: var(--w70);
}

.legend-dot { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }

/* ── TABLES ROW ── */
.tables-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.table-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 20px 22px;
  overflow: hidden;
}

.table-hdr {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}

.table-title {
  font-family: 'Space Grotesk', sans-serif;
  font-size: 14px; font-weight: 600; color: var(--white);
  display: flex; align-items: center; gap: 7px;
}
.table-title i { color: var(--cyan); font-size: 13px; }

.view-all {
  font-size: 11px; color: var(--blue-bright);
  text-decoration: none; font-weight: 600;
  display: flex; align-items: center; gap: 4px;
}
.view-all:hover { opacity: 0.7; }

table { width: 100%; border-collapse: collapse; }

th {
  text-align: left;
  font-size: 9.5px; font-weight: 700; color: var(--w40);
  text-transform: uppercase; letter-spacing: 0.8px;
  padding: 0 10px 9px;
  border-bottom: 1px solid var(--border);
}

td {
  padding: 10px 10px;
  font-size: 12.5px; color: var(--w90);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}

tr:last-child td { border-bottom: none; }
tr:hover td { background: var(--w04); }

/* ── BADGES ── */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 9px; border-radius: 99px;
  font-size: 10.5px; font-weight: 600;
}
.b-pending   { background: rgba(245,158,11,0.12); color: #fbbf24; border:1px solid rgba(245,158,11,0.2); }
.b-active    { background: rgba(16,185,129,0.12);  color: #34d399; border:1px solid rgba(16,185,129,0.2); }
.b-completed { background: rgba(59,130,246,0.12);  color: #60a5fa; border:1px solid rgba(59,130,246,0.2); }
.b-rejected  { background: rgba(239,68,68,0.12);   color: #f87171; border:1px solid rgba(239,68,68,0.2); }

/* ── EMPTY STATE ── */
.empty-state {
  text-align: center; padding: 28px 16px;
  color: var(--w40); font-size: 12.5px;
}
.empty-state i { font-size: 24px; margin-bottom: 8px; display: block; }

/* ── RESPONSIVE ── */
@media (max-width:1200px){
  .stats-row { grid-template-columns: repeat(3,1fr); }
  .charts-top { grid-template-columns: 1fr; }
  .charts-bottom { grid-template-columns: 1fr 1fr; }
}
@media (max-width:900px){
  .tables-row { grid-template-columns: 1fr; }
  .charts-bottom { grid-template-columns: 1fr; }
}
@media (max-width:768px){
  .sidebar { width: 56px; }
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span { display:none; }
  .sidebar-logo { padding:16px 10px; justify-content:center; }
  .sidebar-nav a { padding:11px; justify-content:center; }
  .main { margin-left:56px; }
  .page-body { padding:14px; }
  .stats-row { grid-template-columns: repeat(2,1fr); }
}
</style>
</head>
<body>
<div class="layout">

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-flask"></i></div>
    <div>
      <div class="logo-name">SHAPMS</div>
      <div class="logo-sub">Laboratory</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="labdeptdashboard.php" class="active"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="labdeptstaff.php"><i class="fas fa-users"></i><span>Lab Staff</span></a>
    <a href="labdeptreports.php"><i class="fas fa-file-medical-alt"></i><span>Lab Reports</span></a>
    <div class="nav-section">Account</div>
    <a href="labdeptprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
    <a href="patientchangepassword.php"><i class="fas fa-lock"></i><span>Change Password</span></a>
  </nav>

  <div class="sidebar-bottom">
    <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div>
      <div class="topbar-breadcrumb">Dashboard / Home</div>
      <div class="topbar-title">Laboratory Department</div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="avatar-pill">
        <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        <div class="avatar-info">
          <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="avatar-role">Dept. Head</div>
        </div>
      </div>
    </div>
  </header>

  <!-- Ticker -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php
      $tickers = [
        ['color'=>'#34d399','text'=>'24/7 Lab Monitoring Active'],
        ['color'=>'#60a5fa','text'=>'Avg Turnaround: 4 hrs'],
        ['color'=>'#a78bfa','text'=>'Zero Critical Errors This Month'],
        ['color'=>'#34d399','text'=>'All Systems Operational'],
        ['color'=>'#fbbf24','text'=>'ISO 17025 Accredited'],
        ['color'=>'#06b6d4','text'=>'GLP Compliant Facility'],
        ['color'=>'#34d399','text'=>'24/7 Lab Monitoring Active'],
        ['color'=>'#60a5fa','text'=>'Avg Turnaround: 4 hrs'],
        ['color'=>'#a78bfa','text'=>'Zero Critical Errors This Month'],
        ['color'=>'#34d399','text'=>'All Systems Operational'],
        ['color'=>'#fbbf24','text'=>'ISO 17025 Accredited'],
        ['color'=>'#06b6d4','text'=>'GLP Compliant Facility'],
      ];
      foreach($tickers as $t): ?>
        <div class="ticker-item">
          <div class="ticker-dot" style="background:<?= $t['color'] ?>"></div>
          <?= $t['text'] ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="page-body">

    <!-- Stats Row -->
    <div class="stats-row">
      <div class="stat-card" style="--accent:#3b82f6">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(59,130,246,0.12);color:#60a5fa"><i class="fas fa-users"></i></div>
          <i class="fas fa-arrow-trend-up" style="color:#60a5fa;font-size:11px"></i>
        </div>
        <div class="stat-val"><?= $total_staff ?></div>
        <div class="stat-label">Total Staff</div>
        <div class="stat-sub" style="color:#60a5fa">↑ Active workforce</div>
      </div>
      <div class="stat-card" style="--accent:#f59e0b">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(245,158,11,0.12);color:#fbbf24"><i class="fas fa-user-clock"></i></div>
        </div>
        <div class="stat-val"><?= $pending_staff ?></div>
        <div class="stat-label">Pending Approvals</div>
        <div class="stat-sub" style="color:#fbbf24">Needs attention</div>
      </div>
      <div class="stat-card" style="--accent:#10b981">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(16,185,129,0.12);color:#34d399"><i class="fas fa-user-check"></i></div>
          <i class="fas fa-arrow-trend-up" style="color:#34d399;font-size:11px"></i>
        </div>
        <div class="stat-val"><?= $active_staff ?></div>
        <div class="stat-label">Active Staff</div>
        <div class="stat-sub" style="color:#34d399">↑ Operational</div>
      </div>
      <div class="stat-card" style="--accent:#8b5cf6">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(139,92,246,0.12);color:#a78bfa"><i class="fas fa-file-medical-alt"></i></div>
        </div>
        <div class="stat-val"><?= $total_reports ?></div>
        <div class="stat-label">Total Reports</div>
        <div class="stat-sub" style="color:#a78bfa">All-time</div>
      </div>
      <div class="stat-card" style="--accent:#ef4444">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(239,68,68,0.12);color:#f87171"><i class="fas fa-hourglass-half"></i></div>
        </div>
        <div class="stat-val"><?= $pending_reports ?></div>
        <div class="stat-label">Pending Reports</div>
        <div class="stat-sub" style="color:#f87171">In queue</div>
      </div>
      <div class="stat-card" style="--accent:#10b981">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(16,185,129,0.12);color:#34d399"><i class="fas fa-circle-check"></i></div>
          <i class="fas fa-arrow-trend-up" style="color:#34d399;font-size:11px"></i>
        </div>
        <div class="stat-val"><?= $done_reports ?></div>
        <div class="stat-label">Completed</div>
        <div class="stat-sub" style="color:#34d399">↑ Done</div>
      </div>
    </div>

    <!-- Analytics Label -->
    <div class="section-label"><i class="fas fa-chart-line"></i> Analytics Overview</div>

    <!-- Charts Top Row -->
    <div class="charts-top">

      <!-- Monthly Trend -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-line"></i> Monthly Reports Trend</div>
            <div class="chart-sub">Report volume — last 6 months</div>
          </div>
          <div class="chart-pill">6 Months</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div> Reports</div>
          <div class="legend-item"><div class="legend-dot" style="background:rgba(255,255,255,0.3);border:1px dashed rgba(255,255,255,0.4)"></div> Target</div>
        </div>
        <canvas id="monthlyChart" height="110"></canvas>
      </div>

      <!-- Staff Donut -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-circle-half-stroke"></i> Staff Status</div>
            <div class="chart-sub">Distribution breakdown</div>
          </div>
          <div class="chart-pill">Live</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#10b981"></div> Active</div>
          <div class="legend-item"><div class="legend-dot" style="background:#f59e0b"></div> Pending</div>
          <div class="legend-item"><div class="legend-dot" style="background:#ef4444"></div> Rejected</div>
        </div>
        <canvas id="donutChart" height="160"></canvas>
      </div>
    </div>

    <!-- Charts Bottom Row -->
    <div class="charts-bottom">

      <!-- Report Status Bar -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-bar"></i> Report Status</div>
            <div class="chart-sub">Completed vs pending</div>
          </div>
          <div class="chart-pill">Snapshot</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#06b6d4"></div> Done</div>
          <div class="legend-item"><div class="legend-dot" style="background:#f59e0b"></div> Pending</div>
        </div>
        <canvas id="statusChart" height="130"></canvas>
      </div>

      <!-- Daily Reports -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-area"></i> Daily Reports</div>
            <div class="chart-sub">This week's activity</div>
          </div>
          <div class="chart-pill">7 Days</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#8b5cf6"></div> Reports/day</div>
        </div>
        <canvas id="dailyChart" height="130"></canvas>
      </div>

      <!-- Radar -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-star"></i> Lab Activity</div>
            <div class="chart-sub">Multi-metric overview</div>
          </div>
          <div class="chart-pill">Radar</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#60a5fa"></div> Performance %</div>
        </div>
        <canvas id="radarChart" height="130"></canvas>
      </div>
    </div>

    <!-- Tables -->
    <div class="tables-row">

      <!-- Recent Reports -->
      <div class="table-card">
        <div class="table-hdr">
          <div class="table-title"><i class="fas fa-file-alt"></i> Recent Reports</div>
          <a href="labdeptreports.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <table>
          <thead>
            <tr><th>Patient</th><th>Test</th><th>Status</th><th>Date</th></tr>
          </thead>
          <tbody>
            <?php if (empty($recent_reports)): ?>
            <tr><td colspan="4"><div class="empty-state"><i class="fas fa-folder-open"></i>No reports yet</div></td></tr>
            <?php else: foreach ($recent_reports as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['patient_name']) ?></td>
              <td style="color:var(--w70)"><?= htmlspecialchars($r['test_name']) ?></td>
              <td><span class="badge <?= $r['status']==='completed'?'b-completed':'b-pending' ?>"><?= ucfirst($r['status']) ?></span></td>
              <td style="color:var(--w40);font-size:11.5px"><?= date('M d', strtotime($r['uploaded_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Recent Staff -->
      <div class="table-card">
        <div class="table-hdr">
          <div class="table-title"><i class="fas fa-users"></i> Recent Staff</div>
          <a href="labdeptstaff.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        <table>
          <thead>
            <tr><th>Name</th><th>Email</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php if (empty($recent_staff)): ?>
            <tr><td colspan="3"><div class="empty-state"><i class="fas fa-user-slash"></i>No staff registered yet</div></td></tr>
            <?php else: foreach ($recent_staff as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['full_name']) ?></td>
              <td style="color:var(--w40);font-size:11.5px"><?= htmlspecialchars($s['email']) ?></td>
              <td>
                <span class="badge <?= $s['status']==='active'?'b-active':($s['status']==='pending'?'b-pending':'b-rejected') ?>">
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
Chart.defaults.color = 'rgba(255,255,255,0.4)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;

const months      = <?= json_encode($months) ?>;
const monthCounts = <?= json_encode(array_map('intval', $month_counts)) ?>;
const daysArr     = <?= json_encode($days_arr) ?>;
const dayCounts   = <?= json_encode(array_map('intval', $day_counts)) ?>;
const activeStaff  = <?= intval($active_staff) ?>;
const pendingStaff = <?= intval($pending_staff) ?>;
const rejectedStaff= <?= intval($rejected_staff) ?>;
const doneRep    = <?= intval($done_reports) ?>;
const pendingRep = <?= intval($pending_reports) ?>;

// Monthly line chart
new Chart(document.getElementById('monthlyChart'), {
  type: 'line',
  data: {
    labels: months,
    datasets: [
      {
        label: 'Reports',
        data: monthCounts,
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59,130,246,0.12)',
        fill: true,
        tension: 0.4,
        pointBackgroundColor: '#3b82f6',
        pointRadius: 4,
        borderWidth: 2
      },
      {
        label: 'Target',
        data: monthCounts.map(v => Math.round(v * 0.85)),
        borderColor: 'rgba(255,255,255,0.25)',
        borderDash: [5,4],
        fill: false,
        tension: 0.4,
        pointRadius: 3,
        borderWidth: 1.5
      }
    ]
  },
  options: {
    responsive: true,
    plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#1c2333', padding:10, cornerRadius:8 } },
    scales: {
      x: { grid:{ color:'rgba(255,255,255,0.05)' } },
      y: { grid:{ color:'rgba(255,255,255,0.05)' }, beginAtZero:true }
    }
  }
});

// Donut chart
new Chart(document.getElementById('donutChart'), {
  type: 'doughnut',
  data: {
    labels: ['Active','Pending','Rejected'],
    datasets: [{
      data: [activeStaff || 1, pendingStaff, rejectedStaff],
      backgroundColor: ['#10b981','#f59e0b','#ef4444'],
      borderWidth: 0,
      hoverOffset: 6
    }]
  },
  options: {
    responsive: true,
    cutout: '68%',
    plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#1c2333', padding:10, cornerRadius:8 } }
  }
});

// Status bar chart
new Chart(document.getElementById('statusChart'), {
  type: 'bar',
  data: {
    labels: ['Reports'],
    datasets: [
      { label:'Done',    data:[doneRep],    backgroundColor:'#06b6d4', borderRadius:6 },
      { label:'Pending', data:[pendingRep], backgroundColor:'#f59e0b', borderRadius:6 }
    ]
  },
  options: {
    responsive: true,
    plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#1c2333', padding:10, cornerRadius:8 } },
    scales: {
      x: { grid:{ display:false } },
      y: { grid:{ color:'rgba(255,255,255,0.05)' }, beginAtZero:true }
    }
  }
});

// Daily area chart
new Chart(document.getElementById('dailyChart'), {
  type: 'line',
  data: {
    labels: daysArr,
    datasets: [{
      label: 'Reports/day',
      data: dayCounts,
      borderColor: '#8b5cf6',
      backgroundColor: 'rgba(139,92,246,0.10)',
      fill: true,
      tension: 0.4,
      pointBackgroundColor: '#8b5cf6',
      pointRadius: 4,
      borderWidth: 2
    }]
  },
  options: {
    responsive: true,
    plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#1c2333', padding:10, cornerRadius:8 } },
    scales: {
      x: { grid:{ color:'rgba(255,255,255,0.05)' } },
      y: { grid:{ color:'rgba(255,255,255,0.05)' }, beginAtZero:true }
    }
  }
});

// Radar chart
new Chart(document.getElementById('radarChart'), {
  type: 'radar',
  data: {
    labels: ['Reports','Tests','Staff','Active','Pending'],
    datasets: [{
      label: 'Performance %',
      data: [
        Math.min(doneRep * 2, 100),
        Math.min((doneRep + pendingRep) * 2, 100),
        Math.min(activeStaff * 10, 100),
        Math.min(activeStaff * 10, 100),
        Math.max(100 - pendingStaff * 10, 10)
      ],
      backgroundColor: 'rgba(96,165,250,0.12)',
      borderColor: '#60a5fa',
      pointBackgroundColor: '#60a5fa',
      borderWidth: 1.5,
      pointRadius: 3
    }]
  },
  options: {
    responsive: true,
    plugins: { legend:{ display:false }, tooltip:{ backgroundColor:'#1c2333', padding:10, cornerRadius:8 } },
    scales: {
      r: {
        grid: { color:'rgba(255,255,255,0.08)' },
        angleLines: { color:'rgba(255,255,255,0.08)' },
        ticks: { display:false },
        pointLabels: { font:{ size:9 }, color:'rgba(255,255,255,0.5)' }
      }
    }
  }
});
</script>
</body>
</html>