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

// ── HANDLE STATUS UPDATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['report_id'])) {
    $report_id = intval($_POST['report_id']);
    $action    = $_POST['action'];
    $new_status = match($action) {
        'complete' => 'completed',
        'pending'  => 'pending',
        default    => null
    };
    if ($new_status) {
        $stmt = $conn->prepare("UPDATE lab_reports SET status=? WHERE report_id=?");
        $stmt->bind_param("si", $new_status, $report_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: labdeptreports.php?msg=" . urlencode("Report status updated!"));
    exit();
}

// ── FETCH LAB HEAD INFO ──
$user = ['full_name' => 'Lab Head', 'profile_image' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($fn, $pi);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Lab Head', 'profile_image' => $pi ?: ''];
$stmt->close();

// ── STATS ──
$total_reports   = 0; $pending_reports = 0; $done_reports = 0;
$reports_list    = [];
$monthly_data    = []; $daily_data = []; $test_type_data = [];

$tableCheck = $conn->query("SHOW TABLES LIKE 'lab_reports'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $total_reports   = $conn->query("SELECT COUNT(*) FROM lab_reports")->fetch_row()[0] ?? 0;
    $pending_reports = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE status='pending'")->fetch_row()[0] ?? 0;
    $done_reports    = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE status='completed'")->fetch_row()[0] ?? 0;
    $today_reports   = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE DATE(uploaded_at)=CURDATE()")->fetch_row()[0] ?? 0;

    // Filter
    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $where  = "WHERE 1=1";
    if ($filter === 'pending')   $where .= " AND lr.status='pending'";
    if ($filter === 'completed') $where .= " AND lr.status='completed'";
    if ($search !== '') {
        $safe   = $conn->real_escape_string($search);
        $where .= " AND (u.full_name LIKE '%$safe%' OR lr.test_name LIKE '%$safe%')";
    }

    $res = $conn->query("SELECT lr.report_id, u.full_name as patient_name, u2.full_name as uploaded_by_name,
                                lr.test_name, lr.report_file, lr.status, lr.notes, lr.uploaded_at
                         FROM lab_reports lr
                         JOIN users u  ON lr.patient_id   = u.user_id
                         JOIN users u2 ON lr.uploaded_by  = u2.user_id
                         $where
                         ORDER BY lr.uploaded_at DESC");
    if ($res) while ($row = $res->fetch_assoc()) $reports_list[] = $row;

    // Monthly last 6 months
    $mres = $conn->query("SELECT DATE_FORMAT(uploaded_at,'%b') as month,
                                 COUNT(*) as total,
                                 SUM(status='completed') as done,
                                 SUM(status='pending') as pend
                          FROM lab_reports
                          WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                          GROUP BY MONTH(uploaded_at), DATE_FORMAT(uploaded_at,'%b')
                          ORDER BY MONTH(uploaded_at)");
    if ($mres) while ($row = $mres->fetch_assoc()) $monthly_data[] = $row;

    // Daily last 7 days
    $dres = $conn->query("SELECT DATE_FORMAT(uploaded_at,'%a') as day, COUNT(*) as total
                          FROM lab_reports
                          WHERE uploaded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          GROUP BY DATE(uploaded_at)
                          ORDER BY DATE(uploaded_at)");
    if ($dres) while ($row = $dres->fetch_assoc()) $daily_data[] = $row;

    // Test type breakdown
    $tres = $conn->query("SELECT test_name, COUNT(*) as total
                          FROM lab_reports
                          GROUP BY test_name
                          ORDER BY total DESC LIMIT 6");
    if ($tres) while ($row = $tres->fetch_assoc()) $test_type_data[] = $row;
} else {
    $filter = $_GET['filter'] ?? 'all';
    $search = '';
    $today_reports = 0;
}

$conn->close();

// Chart data
$months      = empty($monthly_data) ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data,'month');
$month_total = empty($monthly_data) ? [0,0,0,0,0,0] : array_column($monthly_data,'total');
$month_done  = empty($monthly_data) ? [0,0,0,0,0,0] : array_column($monthly_data,'done');
$month_pend  = empty($monthly_data) ? [0,0,0,0,0,0] : array_column($monthly_data,'pend');
$days_arr    = empty($daily_data)   ? ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] : array_column($daily_data,'day');
$day_counts  = empty($daily_data)   ? [0,0,0,0,0,0,0] : array_column($daily_data,'total');
$test_labels = empty($test_type_data) ? ['CBC','Blood Sugar','Urine','X-Ray','MRI','Other'] : array_column($test_type_data,'test_name');
$test_counts = empty($test_type_data) ? [0,0,0,0,0,0] : array_column($test_type_data,'total');

$initials = strtoupper(substr($user['full_name'], 0, 2));
$msg = $_GET['msg'] ?? '';
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lab Reports — SHAPMS</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:    #0d1117; --bg2: #161b27; --bg3: #1c2333;
  --border: rgba(255,255,255,0.07); --border2: rgba(255,255,255,0.12);
  --blue: #3b82f6; --blue-b: #60a5fa; --cyan: #06b6d4;
  --green: #10b981; --yellow: #f59e0b; --red: #ef4444; --purple: #8b5cf6;
  --white: #ffffff; --w90: rgba(255,255,255,0.90); --w70: rgba(255,255,255,0.70);
  --w40: rgba(255,255,255,0.40); --w08: rgba(255,255,255,0.08); --w04: rgba(255,255,255,0.04);
  --sw: 220px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--white);min-height:100vh;}
.layout{display:flex;min-height:100vh;}

/* SIDEBAR */
.sidebar{width:var(--sw);background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;}
.sidebar-logo{display:flex;align-items:center;gap:10px;padding:22px 18px 20px;border-bottom:1px solid var(--border);}
.logo-box{width:34px;height:34px;background:linear-gradient(135deg,var(--blue),var(--cyan));border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;color:white;flex-shrink:0;}
.logo-name{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:700;color:var(--white);line-height:1.1;}
.logo-sub{font-size:9px;color:var(--w40);letter-spacing:1.5px;text-transform:uppercase;}
.nav-section{font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--w40);padding:18px 18px 6px;}
.sidebar-nav{flex:1;overflow-y:auto;padding:6px 0;}
.sidebar-nav::-webkit-scrollbar{width:0;}
.sidebar-nav a{display:flex;align-items:center;gap:10px;padding:9px 18px;color:var(--w70);text-decoration:none;font-size:13px;font-weight:500;transition:all 0.15s;border-left:2px solid transparent;}
.sidebar-nav a i{width:16px;text-align:center;font-size:13px;}
.sidebar-nav a:hover{background:var(--w04);color:var(--white);}
.sidebar-nav a.active{background:rgba(59,130,246,0.12);color:var(--blue-b);border-left-color:var(--blue);font-weight:600;}
.sidebar-bottom{padding:14px 18px 20px;border-top:1px solid var(--border);}
.sidebar-bottom a{display:flex;align-items:center;gap:9px;color:#f87171;font-size:13px;font-weight:500;text-decoration:none;padding:8px 0;}

/* MAIN */
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 28px;height:58px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-breadcrumb{font-size:11px;color:var(--w40);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.topbar-title{font-family:'Space Grotesk',sans-serif;font-size:18px;font-weight:700;color:var(--white);}
.topbar-right{display:flex;align-items:center;gap:12px;}
.live-badge{display:flex;align-items:center;gap:6px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.25);color:#34d399;padding:5px 12px;border-radius:99px;font-size:11.5px;font-weight:700;}
.live-dot{width:6px;height:6px;background:#34d399;border-radius:50%;animation:lp 1.5s infinite;}
@keyframes lp{0%,100%{opacity:1;transform:scale(1);}50%{opacity:0.4;transform:scale(0.7);}}
.avatar-pill{display:flex;align-items:center;gap:8px;background:var(--w08);border:1px solid var(--border2);border-radius:99px;padding:5px 12px 5px 5px;cursor:pointer;}
.avatar-circle{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--cyan));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:white;}
.avatar-name{font-size:12px;font-weight:600;color:var(--w90);line-height:1.2;}
.avatar-role{font-size:9.5px;color:var(--w40);}

/* TICKER */
.ticker-bar{background:var(--bg3);border-bottom:1px solid var(--border);padding:0 28px;height:36px;display:flex;align-items:center;overflow:hidden;}
.ticker-track{display:flex;animation:tick 30s linear infinite;white-space:nowrap;}
.ticker-item{display:flex;align-items:center;gap:6px;padding:0 28px;font-size:11.5px;font-weight:500;color:var(--w70);}
.ticker-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;}
@keyframes tick{0%{transform:translateX(0);}100%{transform:translateX(-50%);}}

/* PAGE */
.page-body{padding:22px 28px 48px;display:flex;flex-direction:column;gap:20px;}

/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-top:2px solid var(--accent,var(--blue));border-radius:12px;padding:18px 16px 14px;display:flex;flex-direction:column;gap:8px;transition:transform 0.2s;}
.stat-card:hover{transform:translateY(-3px);}
.stat-icon-row{display:flex;align-items:center;justify-content:space-between;}
.stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.stat-val{font-family:'Space Grotesk',sans-serif;font-size:28px;font-weight:700;color:var(--white);line-height:1;}
.stat-label{font-size:10px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:0.8px;}
.stat-sub{font-size:10.5px;font-weight:600;}

/* SECTION LABEL */
.section-label{display:flex;align-items:center;gap:8px;font-size:10px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:1.5px;}
.section-label::before{content:'';width:12px;height:1px;background:var(--w40);}
.section-label::after{content:'';flex:1;height:1px;background:var(--border);}

/* CHARTS */
.charts-top{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.charts-bottom{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.chart-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px 22px;}
.chart-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;}
.chart-title{font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:600;color:var(--white);display:flex;align-items:center;gap:7px;}
.chart-title i{color:var(--blue-b);font-size:13px;}
.chart-sub{font-size:11px;color:var(--w40);margin-bottom:14px;}
.chart-pill{background:var(--w08);border:1px solid var(--border2);border-radius:99px;padding:3px 10px;font-size:10.5px;font-weight:600;color:var(--w70);white-space:nowrap;}
.legend-row{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap;}
.legend-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--w70);}
.legend-dot{width:8px;height:8px;border-radius:2px;flex-shrink:0;}

/* FILTER BAR */
.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap;}
.ftab{padding:6px 14px;border-radius:99px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.15s;border:1px solid var(--border2);color:var(--w70);}
.ftab:hover{background:var(--w08);color:var(--white);}
.ftab.active{background:var(--blue);border-color:var(--blue);color:white;}
.ftab-completed.active{background:var(--green);border-color:var(--green);color:white;}
.ftab-pending.active{background:var(--yellow);border-color:var(--yellow);color:#0d1117;}
.search-wrap{margin-left:auto;position:relative;}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--w40);font-size:12px;}
.search-wrap input{background:var(--bg3);border:1px solid var(--border2);border-radius:99px;padding:7px 14px 7px 32px;font-size:12.5px;color:var(--white);outline:none;font-family:'Inter',sans-serif;width:220px;transition:border-color 0.2s;}
.search-wrap input:focus{border-color:var(--blue);}
.search-wrap input::placeholder{color:var(--w40);}

/* TABLE */
.table-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;}
.table-hdr{display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;}
.table-title{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:600;color:var(--white);display:flex;align-items:center;gap:8px;}
.table-title i{color:var(--cyan);}
.table-count{font-size:11px;color:var(--w40);}
table{width:100%;border-collapse:collapse;}
th{text-align:left;font-size:9.5px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:0.8px;padding:0 16px 10px;border-bottom:1px solid var(--border);}
td{padding:12px 16px;font-size:13px;color:var(--w90);border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--w04);}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:600;}
.b-pending  {background:rgba(245,158,11,0.12);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);}
.b-completed{background:rgba(16,185,129,0.12);color:#34d399;border:1px solid rgba(16,185,129,0.2);}

.btn-action{padding:5px 12px;border-radius:99px;font-size:11px;font-weight:600;border:none;cursor:pointer;transition:all 0.15s;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:5px;}
.btn-complete{background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.25);}
.btn-complete:hover{background:#10b981;color:white;}
.btn-pending{background:rgba(245,158,11,0.12);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);}
.btn-pending:hover{background:#f59e0b;color:#0d1117;}
.btn-view{background:rgba(59,130,246,0.12);color:#60a5fa;border:1px solid rgba(59,130,246,0.2);}
.btn-view:hover{background:#3b82f6;color:white;}

.empty-state{text-align:center;padding:48px 16px;color:var(--w40);}
.empty-state i{font-size:36px;margin-bottom:12px;display:block;}
.empty-state h3{font-size:15px;font-weight:600;color:var(--w70);margin-bottom:6px;}

.toast{position:fixed;top:20px;right:24px;background:#10b981;color:white;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:999;display:flex;align-items:center;gap:8px;box-shadow:0 8px 24px rgba(0,0,0,0.3);animation:ti 0.3s ease;}
@keyframes ti{from{opacity:0;transform:translateY(-10px);}to{opacity:1;transform:translateY(0);}}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:16px;padding:28px;width:380px;box-shadow:0 20px 60px rgba(0,0,0,0.5);}
.modal h3{font-family:'Space Grotesk',sans-serif;font-size:17px;font-weight:700;margin-bottom:8px;}
.modal p{font-size:13px;color:var(--w70);margin-bottom:22px;line-height:1.5;}
.modal-btns{display:flex;gap:10px;justify-content:flex-end;}
.modal-cancel{padding:9px 20px;border-radius:99px;background:var(--w08);color:var(--w70);border:1px solid var(--border2);font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;}
.modal-confirm{padding:9px 20px;border-radius:99px;border:none;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;color:white;background:#10b981;}

@media(max-width:1100px){.charts-top{grid-template-columns:1fr;}.charts-bottom{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){.stats-row{grid-template-columns:repeat(2,1fr);}.charts-bottom{grid-template-columns:1fr;}}
@media(max-width:768px){
  .sidebar{width:56px;}
  .logo-name,.logo-sub,.nav-section,.sidebar-nav a span,.sidebar-bottom a span{display:none;}
  .sidebar-logo{padding:16px 10px;justify-content:center;}
  .sidebar-nav a{padding:11px;justify-content:center;}
  .main{margin-left:56px;}
  .page-body{padding:14px;}
}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-box"><i class="fas fa-flask"></i></div>
    <div><div class="logo-name">SHAPMS</div><div class="logo-sub">Laboratory</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="labdeptdashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="labdeptstaff.php"><i class="fas fa-users"></i><span>Lab Staff</span></a>
    <a href="labdeptreports.php" class="active"><i class="fas fa-file-medical-alt"></i><span>Lab Reports</span></a>
    <div class="nav-section">Account</div>
    <a href="labdeptprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
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
      <div class="topbar-breadcrumb">Dashboard / Lab Reports</div>
      <div class="topbar-title">Lab Reports Overview</div>
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

  <!-- Ticker -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php $tickers=[['#34d399','All Reports Monitored'],['#60a5fa','Real-Time Status Updates'],['#fbbf24','Pending Reports Need Review'],['#a78bfa','Report Analytics Active'],['#06b6d4','GLP Compliant Reporting'],['#34d399','All Reports Monitored'],['#60a5fa','Real-Time Status Updates'],['#fbbf24','Pending Reports Need Review'],['#a78bfa','Report Analytics Active'],['#06b6d4','GLP Compliant Reporting']];
      foreach($tickers as $t): ?>
        <div class="ticker-item"><div class="ticker-dot" style="background:<?= $t[0] ?>"></div><?= $t[1] ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="page-body">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card" style="--accent:#8b5cf6">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(139,92,246,0.12);color:#a78bfa"><i class="fas fa-file-medical-alt"></i></div>
        </div>
        <div class="stat-val"><?= $total_reports ?></div>
        <div class="stat-label">Total Reports</div>
        <div class="stat-sub" style="color:#a78bfa">All-time</div>
      </div>
      <div class="stat-card" style="--accent:#f59e0b">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(245,158,11,0.12);color:#fbbf24"><i class="fas fa-hourglass-half"></i></div>
        </div>
        <div class="stat-val"><?= $pending_reports ?></div>
        <div class="stat-label">Pending</div>
        <div class="stat-sub" style="color:#fbbf24">In queue</div>
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
      <div class="stat-card" style="--accent:#06b6d4">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(6,182,212,0.12);color:#22d3ee"><i class="fas fa-calendar-day"></i></div>
        </div>
        <div class="stat-val"><?= $today_reports ?></div>
        <div class="stat-label">Today</div>
        <div class="stat-sub" style="color:#22d3ee">Uploaded today</div>
      </div>
    </div>

    <!-- Analytics Label -->
    <div class="section-label"><i class="fas fa-chart-line"></i> Analytics Overview</div>

    <!-- Charts Top -->
    <div class="charts-top">
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-line"></i> Monthly Reports Trend</div>
            <div class="chart-sub">Completed vs Pending — last 6 months</div>
          </div>
          <div class="chart-pill">6 Months</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#10b981"></div> Completed</div>
          <div class="legend-item"><div class="legend-dot" style="background:#f59e0b"></div> Pending</div>
          <div class="legend-item"><div class="legend-dot" style="background:#3b82f6;opacity:0.5"></div> Total</div>
        </div>
        <canvas id="monthlyChart" height="110"></canvas>
      </div>

      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-circle-half-stroke"></i> Test Type Breakdown</div>
            <div class="chart-sub">Most common tests uploaded</div>
          </div>
          <div class="chart-pill">All Time</div>
        </div>
        <div class="legend-row">
          <?php foreach($test_labels as $tl): ?>
          <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div><?= htmlspecialchars($tl) ?></div>
          <?php endforeach; ?>
        </div>
        <canvas id="testTypeChart" height="160"></canvas>
      </div>
    </div>

    <!-- Charts Bottom -->
    <div class="charts-bottom">
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-area"></i> Daily Uploads</div>
            <div class="chart-sub">This week's report activity</div>
          </div>
          <div class="chart-pill">7 Days</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#8b5cf6"></div> Reports/day</div>
        </div>
        <canvas id="dailyChart" height="130"></canvas>
      </div>

      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-bar"></i> Report Status</div>
            <div class="chart-sub">Completed vs Pending snapshot</div>
          </div>
          <div class="chart-pill">Snapshot</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#10b981"></div> Completed</div>
          <div class="legend-item"><div class="legend-dot" style="background:#f59e0b"></div> Pending</div>
        </div>
        <canvas id="statusChart" height="130"></canvas>
      </div>

      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-gauge-high"></i> Completion Rate</div>
            <div class="chart-sub">Overall report completion %</div>
          </div>
          <div class="chart-pill">Live</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#06b6d4"></div> Rate %</div>
        </div>
        <canvas id="rateChart" height="130"></canvas>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
      <div class="filter-tabs">
        <a href="?filter=all"       class="ftab <?= $filter==='all'?'active':'' ?>">All (<?= $total_reports ?>)</a>
        <a href="?filter=pending"   class="ftab ftab-pending   <?= $filter==='pending'?'active':'' ?>">Pending (<?= $pending_reports ?>)</a>
        <a href="?filter=completed" class="ftab ftab-completed <?= $filter==='completed'?'active':'' ?>">Completed (<?= $done_reports ?>)</a>
      </div>
      <form method="GET" class="search-wrap">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <i class="fas fa-search"></i>
        <input type="text" name="search" placeholder="Search patient or test…" value="<?= htmlspecialchars($search) ?>">
      </form>
    </div>

    <!-- Reports Table -->
    <div class="table-card">
      <div class="table-hdr">
        <div class="table-title"><i class="fas fa-file-medical-alt"></i> Lab Reports</div>
        <div class="table-count"><?= count($reports_list) ?> result<?= count($reports_list)!==1?'s':'' ?></div>
      </div>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Patient</th>
            <th>Test Name</th>
            <th>Uploaded By</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($reports_list)): ?>
          <tr><td colspan="7">
            <div class="empty-state">
              <i class="fas fa-folder-open"></i>
              <h3>No reports found</h3>
              <p>No lab reports match your current filter.</p>
            </div>
          </td></tr>
          <?php else: foreach ($reports_list as $i => $r): ?>
          <tr>
            <td style="color:var(--w40);font-size:12px"><?= $i+1 ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($r['patient_name']) ?></td>
            <td style="color:var(--w70)"><?= htmlspecialchars($r['test_name']) ?></td>
            <td style="color:var(--w40);font-size:12px"><?= htmlspecialchars($r['uploaded_by_name']) ?></td>
            <td><span class="badge <?= $r['status']==='completed'?'b-completed':'b-pending' ?>"><?= ucfirst($r['status']) ?></span></td>
            <td style="color:var(--w40);font-size:12px"><?= date('M d, Y', strtotime($r['uploaded_at'])) ?></td>
            <td>
              <div style="display:flex;gap:6px;align-items:center;">
                <a href="uploads/<?= htmlspecialchars($r['report_file']) ?>" target="_blank" class="btn-action btn-view">
                  <i class="fas fa-eye"></i> View
                </a>
                <?php if ($r['status'] === 'pending'): ?>
                <button class="btn-action btn-complete" onclick="confirmAction(<?= $r['report_id'] ?>,'complete')">
                  <i class="fas fa-check"></i> Mark Done
                </button>
                <?php else: ?>
                <button class="btn-action btn-pending" onclick="confirmAction(<?= $r['report_id'] ?>,'pending')">
                  <i class="fas fa-rotate-left"></i> Reopen
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<?php if ($msg): ?>
<div class="toast" id="toast"><i class="fas fa-check-circle"></i><?= htmlspecialchars($msg) ?></div>
<script>setTimeout(()=>document.getElementById('toast').remove(),3500);</script>
<?php endif; ?>

<!-- MODAL -->
<div class="modal-overlay" id="modal">
  <div class="modal">
    <h3 id="modal-title">Confirm Action</h3>
    <p id="modal-body">Are you sure you want to update this report status?</p>
    <div class="modal-btns">
      <button class="modal-cancel" onclick="closeModal()">Cancel</button>
      <form method="POST" id="modal-form" style="display:inline">
        <input type="hidden" name="report_id" id="modal-report-id">
        <input type="hidden" name="action"    id="modal-action">
        <button type="submit" class="modal-confirm" id="modal-btn">Confirm</button>
      </form>
    </div>
  </div>
</div>

<script>
Chart.defaults.color = 'rgba(255,255,255,0.4)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;

const months     = <?= json_encode($months) ?>;
const monthDone  = <?= json_encode(array_map('intval', $month_done)) ?>;
const monthPend  = <?= json_encode(array_map('intval', $month_pend)) ?>;
const monthTotal = <?= json_encode(array_map('intval', $month_total)) ?>;
const daysArr    = <?= json_encode($days_arr) ?>;
const dayCounts  = <?= json_encode(array_map('intval', $day_counts)) ?>;
const testLabels = <?= json_encode($test_labels) ?>;
const testCounts = <?= json_encode(array_map('intval', $test_counts)) ?>;
const doneRep    = <?= intval($done_reports) ?>;
const pendRep    = <?= intval($pending_reports) ?>;
const totalRep   = <?= intval($total_reports) ?>;
const rateVal    = totalRep > 0 ? Math.round((doneRep / totalRep) * 100) : 0;

const gridColor = 'rgba(255,255,255,0.05)';
const tooltip   = { backgroundColor:'#1c2333', padding:10, cornerRadius:8 };

// Monthly stacked line
new Chart(document.getElementById('monthlyChart'), {
  type: 'line',
  data: {
    labels: months,
    datasets: [
      { label:'Completed', data:monthDone,  borderColor:'#10b981', backgroundColor:'rgba(16,185,129,0.10)', fill:true, tension:0.4, pointBackgroundColor:'#10b981', pointRadius:4, borderWidth:2 },
      { label:'Pending',   data:monthPend,  borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,0.08)', fill:true, tension:0.4, pointBackgroundColor:'#f59e0b', pointRadius:4, borderWidth:2 },
      { label:'Total',     data:monthTotal, borderColor:'rgba(59,130,246,0.4)', borderDash:[5,4], fill:false, tension:0.4, pointRadius:3, borderWidth:1.5 }
    ]
  },
  options: { responsive:true, plugins:{ legend:{display:false}, tooltip }, scales:{ x:{grid:{color:gridColor}}, y:{grid:{color:gridColor}, beginAtZero:true} } }
});

// Test Type Doughnut
new Chart(document.getElementById('testTypeChart'), {
  type: 'doughnut',
  data: {
    labels: testLabels.length ? testLabels : ['No Data'],
    datasets: [{ data: testCounts.length ? testCounts : [1], backgroundColor:['#3b82f6','#06b6d4','#10b981','#f59e0b','#8b5cf6','#ef4444'], borderWidth:0, hoverOffset:6 }]
  },
  options: { responsive:true, cutout:'65%', plugins:{ legend:{display:false}, tooltip } }
});

// Daily area
new Chart(document.getElementById('dailyChart'), {
  type: 'line',
  data: {
    labels: daysArr,
    datasets: [{ label:'Reports/day', data:dayCounts, borderColor:'#8b5cf6', backgroundColor:'rgba(139,92,246,0.10)', fill:true, tension:0.4, pointBackgroundColor:'#8b5cf6', pointRadius:4, borderWidth:2 }]
  },
  options: { responsive:true, plugins:{ legend:{display:false}, tooltip }, scales:{ x:{grid:{color:gridColor}}, y:{grid:{color:gridColor}, beginAtZero:true} } }
});

// Status bar
new Chart(document.getElementById('statusChart'), {
  type: 'bar',
  data: {
    labels: ['Reports'],
    datasets: [
      { label:'Completed', data:[doneRep], backgroundColor:'#10b981', borderRadius:6 },
      { label:'Pending',   data:[pendRep], backgroundColor:'#f59e0b', borderRadius:6 }
    ]
  },
  options: { responsive:true, plugins:{ legend:{display:false}, tooltip }, scales:{ x:{grid:{display:false}}, y:{grid:{color:gridColor}, beginAtZero:true} } }
});

// Completion rate doughnut
new Chart(document.getElementById('rateChart'), {
  type: 'doughnut',
  data: {
    labels: ['Completed','Remaining'],
    datasets: [{ data:[rateVal, 100-rateVal], backgroundColor:['#06b6d4','rgba(255,255,255,0.06)'], borderWidth:0, hoverOffset:4 }]
  },
  options: {
    responsive:true, cutout:'72%',
    plugins:{ legend:{display:false}, tooltip }
  },
  plugins:[{
    id:'centerText',
    afterDraw(chart){
      const {ctx,chartArea:{top,bottom,left,right}}=chart;
      const cx=(left+right)/2, cy=(top+bottom)/2;
      ctx.save();
      ctx.font='bold 22px Space Grotesk, Inter, sans-serif';
      ctx.fillStyle='#ffffff';
      ctx.textAlign='center';
      ctx.textBaseline='middle';
      ctx.fillText(rateVal+'%',cx,cy-6);
      ctx.font='10px Inter, sans-serif';
      ctx.fillStyle='rgba(255,255,255,0.4)';
      ctx.fillText('Completion',cx,cy+12);
      ctx.restore();
    }
  }]
});

// Modal
function confirmAction(id, action) {
  document.getElementById('modal-title').textContent = action==='complete' ? 'Mark as Completed' : 'Reopen Report';
  document.getElementById('modal-body').textContent  = action==='complete' ? 'Mark this report as completed?' : 'Reopen this report and set it back to pending?';
  document.getElementById('modal-report-id').value  = id;
  document.getElementById('modal-action').value     = action;
  document.getElementById('modal-btn').style.background = action==='complete' ? '#10b981' : '#f59e0b';
  document.getElementById('modal').classList.add('open');
}

function closeModal() { document.getElementById('modal').classList.remove('open'); }
document.getElementById('modal').addEventListener('click', e => { if(e.target===document.getElementById('modal')) closeModal(); });
</script>
</body>
</html>