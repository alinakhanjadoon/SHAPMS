<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lab') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// ── FETCH STAFF INFO ──
$user = ['full_name' => 'Lab Technician', 'profile_image' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($fn, $pi);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Lab Technician', 'profile_image' => $pi ?: ''];
$stmt->close();

// ── STATS (only this technician's uploads) ──
$my_total     = 0; $my_pending = 0; $my_completed = 0; $my_today = 0;
$recent_reports = [];
$monthly_data   = [];
$test_type_data = [];

$uid = $_SESSION['user_id'];

$tableCheck = $conn->query("SHOW TABLES LIKE 'lab_reports'");
if ($tableCheck && $tableCheck->num_rows > 0) {

    $my_total     = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid")->fetch_row()[0] ?? 0;
    $my_pending   = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND status='pending'")->fetch_row()[0] ?? 0;
    $my_completed = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND status='completed'")->fetch_row()[0] ?? 0;
    $my_today     = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND DATE(uploaded_at)=CURDATE()")->fetch_row()[0] ?? 0;

    // Recent 8 reports by this technician
    $res = $conn->query("SELECT lr.report_id, u.full_name as patient_name,
                                lr.test_name, lr.status, lr.uploaded_at, lr.report_file
                         FROM lab_reports lr
                         JOIN users u ON lr.patient_id = u.user_id
                         WHERE lr.uploaded_by = $uid
                         ORDER BY lr.uploaded_at DESC
                         LIMIT 8");
    if ($res) while ($row = $res->fetch_assoc()) $recent_reports[] = $row;

    // Monthly uploads (last 6 months)
    $mres = $conn->query("SELECT DATE_FORMAT(uploaded_at,'%b') as month, COUNT(*) as total
                          FROM lab_reports
                          WHERE uploaded_by=$uid AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                          GROUP BY MONTH(uploaded_at), DATE_FORMAT(uploaded_at,'%b')
                          ORDER BY MONTH(uploaded_at)");
    if ($mres) while ($row = $mres->fetch_assoc()) $monthly_data[] = $row;

    // Test type breakdown for this technician
    $tres = $conn->query("SELECT test_name, COUNT(*) as total
                          FROM lab_reports
                          WHERE uploaded_by=$uid
                          GROUP BY test_name
                          ORDER BY total DESC LIMIT 5");
    if ($tres) while ($row = $tres->fetch_assoc()) $test_type_data[] = $row;
}

$conn->close();

// Chart arrays
$months     = empty($monthly_data)   ? ['Jan','Feb','Mar','Apr','May','Jun'] : array_column($monthly_data,'month');
$month_vals = empty($monthly_data)   ? [0,0,0,0,0,0]                        : array_column($monthly_data,'total');
$test_labels= empty($test_type_data) ? ['No Data']                          : array_column($test_type_data,'test_name');
$test_counts= empty($test_type_data) ? [1]                                  : array_column($test_type_data,'total');

$initials   = strtoupper(substr($user['full_name'], 0, 2));
$rate        = $my_total > 0 ? round(($my_completed / $my_total) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lab Staff Dashboard — SHAPMS</title>
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
.ticker-track{display:flex;animation:tick 28s linear infinite;white-space:nowrap;}
.ticker-item{display:flex;align-items:center;gap:6px;padding:0 28px;font-size:11.5px;font-weight:500;color:var(--w70);}
.ticker-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0;}
@keyframes tick{0%{transform:translateX(0);}100%{transform:translateX(-50%);}}

/* PAGE */
.page-body{padding:22px 28px 48px;display:flex;flex-direction:column;gap:20px;}

/* WELCOME BANNER */
.welcome-banner{background:linear-gradient(135deg,rgba(59,130,246,0.15),rgba(6,182,212,0.08));border:1px solid rgba(59,130,246,0.2);border-radius:16px;padding:22px 26px;display:flex;align-items:center;justify-content:space-between;gap:16px;}
.welcome-text h2{font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:700;color:var(--white);margin-bottom:4px;}
.welcome-text p{font-size:13px;color:var(--w70);line-height:1.5;}
.welcome-actions{display:flex;gap:10px;flex-shrink:0;}
.btn-primary{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:var(--blue);color:white;border-radius:99px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;font-family:'Inter',sans-serif;transition:background 0.15s;}
.btn-primary:hover{background:#2563eb;}
.btn-secondary{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:var(--w08);color:var(--w70);border-radius:99px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--border2);transition:all 0.15s;}
.btn-secondary:hover{background:var(--w04);color:var(--white);}

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

/* CHARTS ROW */
.charts-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.chart-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px 22px;}
.chart-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;}
.chart-title{font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:600;color:var(--white);display:flex;align-items:center;gap:7px;}
.chart-title i{color:var(--blue-b);font-size:13px;}
.chart-sub{font-size:11px;color:var(--w40);margin-bottom:14px;}
.chart-pill{background:var(--w08);border:1px solid var(--border2);border-radius:99px;padding:3px 10px;font-size:10.5px;font-weight:600;color:var(--w70);white-space:nowrap;}
.legend-row{display:flex;align-items:center;gap:14px;margin-bottom:14px;flex-wrap:wrap;}
.legend-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--w70);}
.legend-dot{width:8px;height:8px;border-radius:2px;flex-shrink:0;}

/* BOTTOM GRID */
.bottom-grid{display:grid;grid-template-columns:1fr 320px;gap:16px;}

/* TABLE */
.table-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;overflow:hidden;}
.table-hdr{display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;}
.table-title{font-family:'Space Grotesk',sans-serif;font-size:15px;font-weight:600;color:var(--white);display:flex;align-items:center;gap:8px;}
.table-title i{color:var(--cyan);}
.table-view-all{font-size:12px;color:var(--blue-b);text-decoration:none;font-weight:600;}
.table-view-all:hover{color:var(--white);}
table{width:100%;border-collapse:collapse;}
th{text-align:left;font-size:9.5px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:0.8px;padding:0 16px 10px;border-bottom:1px solid var(--border);}
td{padding:11px 16px;font-size:12.5px;color:var(--w90);border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--w04);}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:600;}
.b-pending  {background:rgba(245,158,11,0.12);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);}
.b-completed{background:rgba(16,185,129,0.12);color:#34d399;border:1px solid rgba(16,185,129,0.2);}
.btn-view{padding:4px 11px;border-radius:99px;font-size:11px;font-weight:600;background:rgba(59,130,246,0.12);color:#60a5fa;border:1px solid rgba(59,130,246,0.2);text-decoration:none;transition:all 0.15s;display:inline-flex;align-items:center;gap:4px;}
.btn-view:hover{background:#3b82f6;color:white;}

/* QUICK ACTIONS PANEL */
.quick-panel{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px 22px;display:flex;flex-direction:column;gap:14px;}
.quick-panel h3{font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:600;color:var(--white);display:flex;align-items:center;gap:7px;margin-bottom:4px;}
.quick-panel h3 i{color:var(--purple);font-size:13px;}
.quick-action{display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;text-decoration:none;transition:all 0.15s;cursor:pointer;}
.quick-action:hover{border-color:var(--border2);background:var(--w04);}
.qa-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.qa-label{font-size:13px;font-weight:600;color:var(--w90);line-height:1.2;}
.qa-sub{font-size:10.5px;color:var(--w40);margin-top:1px;}
.qa-arrow{margin-left:auto;color:var(--w40);font-size:11px;}

/* PROGRESS RING */
.ring-wrap{display:flex;flex-direction:column;align-items:center;gap:6px;padding:6px 0 2px;}
.ring-label{font-size:11px;color:var(--w40);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;}

.empty-state{text-align:center;padding:36px 16px;color:var(--w40);}
.empty-state i{font-size:32px;margin-bottom:10px;display:block;}
.empty-state p{font-size:12.5px;color:var(--w70);}

@media(max-width:1100px){.charts-row{grid-template-columns:1fr 1fr;}.bottom-grid{grid-template-columns:1fr;}}
@media(max-width:900px){.stats-row{grid-template-columns:repeat(2,1fr);}.charts-row{grid-template-columns:1fr;}.welcome-actions{display:none;}}
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
    <div><div class="logo-name">SHAPMS</div><div class="logo-sub">Lab Staff</div></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section">Main</div>
    <a href="labstaffdashboard.php" class="active"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="labstaffuploadreport.php"><i class="fas fa-upload"></i><span>Upload Report</span></a>
    <a href="labstaffreports.php"><i class="fas fa-file-medical-alt"></i><span>My Reports</span></a>
    <div class="nav-section">Account</div>
    <a href="labstaffprofile.php"><i class="fas fa-user-circle"></i><span>My Profile</span></a>
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
      <div class="topbar-breadcrumb">Lab Staff / Dashboard</div>
      <div class="topbar-title">My Dashboard</div>
    </div>
    <div class="topbar-right">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="avatar-pill">
        <div class="avatar-circle"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="avatar-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="avatar-role">Lab Technician</div>
        </div>
      </div>
    </div>
  </header>

  <!-- Ticker -->
  <div class="ticker-bar">
    <div class="ticker-track">
      <?php $tickers=[['#34d399','Reports Uploaded Successfully'],['#60a5fa','Patient Results Ready'],['#fbbf24','Pending Tests Awaiting Upload'],['#a78bfa','Lab System Active'],['#06b6d4','GLP Compliant Workflow'],['#34d399','Reports Uploaded Successfully'],['#60a5fa','Patient Results Ready'],['#fbbf24','Pending Tests Awaiting Upload'],['#a78bfa','Lab System Active'],['#06b6d4','GLP Compliant Workflow']];
      foreach($tickers as $t): ?>
        <div class="ticker-item"><div class="ticker-dot" style="background:<?= $t[0] ?>"></div><?= $t[1] ?></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="page-body">

    <!-- Welcome Banner -->
    <div class="welcome-banner">
      <div class="welcome-text">
        <h2>Welcome back, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?>! 👋</h2>
        <p>You have <strong style="color:#fbbf24"><?= $my_pending ?> pending report<?= $my_pending!=1?'s':'' ?></strong> awaiting review.
        <?= $my_today > 0 ? "You uploaded <strong style='color:#34d399'>$my_today report".($my_today!=1?'s':'')."</strong> today." : "No uploads yet today." ?></p>
      </div>
      <div class="welcome-actions">
        <a href="labstaffuploadreport.php" class="btn-primary"><i class="fas fa-upload"></i> Upload Report</a>
        <a href="labstaffreports.php" class="btn-secondary"><i class="fas fa-list"></i> View All</a>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card" style="--accent:#3b82f6">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(59,130,246,0.12);color:#60a5fa"><i class="fas fa-file-medical-alt"></i></div>
        </div>
        <div class="stat-val"><?= $my_total ?></div>
        <div class="stat-label">My Total Reports</div>
        <div class="stat-sub" style="color:#60a5fa">All-time uploads</div>
      </div>
      <div class="stat-card" style="--accent:#f59e0b">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(245,158,11,0.12);color:#fbbf24"><i class="fas fa-hourglass-half"></i></div>
        </div>
        <div class="stat-val"><?= $my_pending ?></div>
        <div class="stat-label">Pending Review</div>
        <div class="stat-sub" style="color:#fbbf24">Awaiting head approval</div>
      </div>
      <div class="stat-card" style="--accent:#10b981">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(16,185,129,0.12);color:#34d399"><i class="fas fa-circle-check"></i></div>
          <i class="fas fa-arrow-trend-up" style="color:#34d399;font-size:11px"></i>
        </div>
        <div class="stat-val"><?= $my_completed ?></div>
        <div class="stat-label">Completed</div>
        <div class="stat-sub" style="color:#34d399">Approved reports</div>
      </div>
      <div class="stat-card" style="--accent:#06b6d4">
        <div class="stat-icon-row">
          <div class="stat-icon" style="background:rgba(6,182,212,0.12);color:#22d3ee"><i class="fas fa-calendar-day"></i></div>
        </div>
        <div class="stat-val"><?= $my_today ?></div>
        <div class="stat-label">Today's Uploads</div>
        <div class="stat-sub" style="color:#22d3ee">Uploaded today</div>
      </div>
    </div>

    <!-- Analytics -->
    <div class="section-label"><i class="fas fa-chart-line"></i> My Activity Overview</div>

    <div class="charts-row">
      <!-- Monthly Uploads -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-line"></i> Monthly Uploads</div>
            <div class="chart-sub">My report uploads — last 6 months</div>
          </div>
          <div class="chart-pill">6 Months</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div> Reports Uploaded</div>
        </div>
        <canvas id="monthlyChart" height="130"></canvas>
      </div>

      <!-- Test Type -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-circle-half-stroke"></i> Tests I Handle</div>
            <div class="chart-sub">My most frequent test types</div>
          </div>
          <div class="chart-pill">All Time</div>
        </div>
        <div class="legend-row">
          <?php foreach(array_slice($test_labels,0,4) as $tl): ?>
          <div class="legend-item"><div class="legend-dot" style="background:#8b5cf6"></div><?= htmlspecialchars($tl) ?></div>
          <?php endforeach; ?>
        </div>
        <canvas id="testChart" height="130"></canvas>
      </div>

      <!-- Completion Rate -->
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-gauge-high"></i> Approval Rate</div>
            <div class="chart-sub">% of my reports marked completed</div>
          </div>
          <div class="chart-pill">Live</div>
        </div>
        <div class="legend-row">
          <div class="legend-item"><div class="legend-dot" style="background:#10b981"></div> Approved</div>
          <div class="legend-item"><div class="legend-dot" style="background:rgba(255,255,255,0.06)"></div> Pending</div>
        </div>
        <canvas id="rateChart" height="130"></canvas>
      </div>
    </div>

    <!-- Bottom: Recent Reports + Quick Actions -->
    <div class="section-label"><i class="fas fa-clock-rotate-left"></i> Recent Activity</div>

    <div class="bottom-grid">

      <!-- Recent Reports Table -->
      <div class="table-card">
        <div class="table-hdr">
          <div class="table-title"><i class="fas fa-file-medical-alt"></i> My Recent Reports</div>
          <a href="labstaffreports.php" class="table-view-all">View All <i class="fas fa-arrow-right" style="font-size:10px"></i></a>
        </div>
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Patient</th>
              <th>Test</th>
              <th>Status</th>
              <th>Date</th>
              <th>File</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent_reports)): ?>
            <tr><td colspan="6">
              <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <p>No reports uploaded yet.<br>Start by uploading your first report.</p>
              </div>
            </td></tr>
            <?php else: foreach($recent_reports as $i => $r): ?>
            <tr>
              <td style="color:var(--w40);font-size:11px"><?= $i+1 ?></td>
              <td style="font-weight:600;font-size:12.5px"><?= htmlspecialchars($r['patient_name']) ?></td>
              <td style="color:var(--w70)"><?= htmlspecialchars($r['test_name']) ?></td>
              <td><span class="badge <?= $r['status']==='completed'?'b-completed':'b-pending' ?>"><?= ucfirst($r['status']) ?></span></td>
              <td style="color:var(--w40);font-size:11px"><?= date('M d, Y', strtotime($r['uploaded_at'])) ?></td>
              <td>
                <a href="uploads/<?= htmlspecialchars($r['report_file']) ?>" target="_blank" class="btn-view">
                  <i class="fas fa-eye"></i> View
                </a>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Quick Actions Panel -->
      <div class="quick-panel">
        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>

        <a href="labstaffuploadreport.php" class="quick-action">
          <div class="qa-icon" style="background:rgba(59,130,246,0.12);color:#60a5fa"><i class="fas fa-upload"></i></div>
          <div>
            <div class="qa-label">Upload New Report</div>
            <div class="qa-sub">Add a test result for a patient</div>
          </div>
          <i class="fas fa-chevron-right qa-arrow"></i>
        </a>

        <a href="labstaffreports.php" class="quick-action">
          <div class="qa-icon" style="background:rgba(16,185,129,0.12);color:#34d399"><i class="fas fa-file-medical-alt"></i></div>
          <div>
            <div class="qa-label">View My Reports</div>
            <div class="qa-sub">Browse all reports you uploaded</div>
          </div>
          <i class="fas fa-chevron-right qa-arrow"></i>
        </a>

        <a href="labstaffreports.php?filter=pending" class="quick-action">
          <div class="qa-icon" style="background:rgba(245,158,11,0.12);color:#fbbf24"><i class="fas fa-hourglass-half"></i></div>
          <div>
            <div class="qa-label">Pending Reports</div>
            <div class="qa-sub"><?= $my_pending ?> report<?= $my_pending!=1?'s':'' ?> awaiting review</div>
          </div>
          <i class="fas fa-chevron-right qa-arrow"></i>
        </a>

        <a href="labstaffprofile.php" class="quick-action">
          <div class="qa-icon" style="background:rgba(139,92,246,0.12);color:#a78bfa"><i class="fas fa-user-circle"></i></div>
          <div>
            <div class="qa-label">My Profile</div>
            <div class="qa-sub">View and update your info</div>
          </div>
          <i class="fas fa-chevron-right qa-arrow"></i>
        </a>

        <a href="patientchangepassword.php" class="quick-action">
          <div class="qa-icon" style="background:rgba(6,182,212,0.12);color:#22d3ee"><i class="fas fa-lock"></i></div>
          <div>
            <div class="qa-label">Change Password</div>
            <div class="qa-sub">Update your login credentials</div>
          </div>
          <i class="fas fa-chevron-right qa-arrow"></i>
        </a>
      </div>

    </div><!-- /bottom-grid -->
  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
Chart.defaults.color = 'rgba(255,255,255,0.4)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;

const gridColor = 'rgba(255,255,255,0.05)';
const tooltip   = { backgroundColor:'#1c2333', padding:10, cornerRadius:8 };

const months    = <?= json_encode($months) ?>;
const monthVals = <?= json_encode(array_map('intval', $month_vals)) ?>;
const testLabels= <?= json_encode($test_labels) ?>;
const testCounts= <?= json_encode(array_map('intval', $test_counts)) ?>;
const rateVal   = <?= intval($rate) ?>;

// Monthly bar chart
new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: months,
    datasets: [{
      label: 'Reports', data: monthVals,
      backgroundColor: 'rgba(59,130,246,0.5)',
      borderColor: '#3b82f6',
      borderWidth: 1.5,
      borderRadius: 6
    }]
  },
  options: { responsive:true, plugins:{ legend:{display:false}, tooltip }, scales:{ x:{grid:{color:gridColor}}, y:{grid:{color:gridColor}, beginAtZero:true} } }
});

// Test type doughnut
new Chart(document.getElementById('testChart'), {
  type: 'doughnut',
  data: {
    labels: testLabels,
    datasets: [{ data: testCounts, backgroundColor:['#8b5cf6','#3b82f6','#06b6d4','#10b981','#f59e0b'], borderWidth:0, hoverOffset:6 }]
  },
  options: { responsive:true, cutout:'62%', plugins:{ legend:{display:false}, tooltip } }
});

// Approval rate doughnut
new Chart(document.getElementById('rateChart'), {
  type: 'doughnut',
  data: {
    labels: ['Approved','Pending'],
    datasets: [{ data:[rateVal, 100-rateVal], backgroundColor:['#10b981','rgba(255,255,255,0.06)'], borderWidth:0, hoverOffset:4 }]
  },
  options: { responsive:true, cutout:'72%', plugins:{ legend:{display:false}, tooltip } },
  plugins:[{
    id:'centerText',
    afterDraw(chart){
      const {ctx,chartArea:{top,bottom,left,right}}=chart;
      const cx=(left+right)/2, cy=(top+bottom)/2;
      ctx.save();
      ctx.font='bold 22px Space Grotesk, Inter, sans-serif';
      ctx.fillStyle='#ffffff'; ctx.textAlign='center'; ctx.textBaseline='middle';
      ctx.fillText(rateVal+'%',cx,cy-6);
      ctx.font='10px Inter, sans-serif';
      ctx.fillStyle='rgba(255,255,255,0.4)';
      ctx.fillText('Approved',cx,cy+12);
      ctx.restore();
    }
  }]
});
</script>
</body>
</html>