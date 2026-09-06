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

$uid = $_SESSION['user_id'];

// ── FETCH STAFF INFO ──
$user = ['full_name' => 'Lab Technician', 'profile_image' => ''];
$stmt = $conn->prepare("SELECT full_name, profile_image FROM users WHERE user_id=?");
$stmt->bind_param("i", $uid);
$stmt->execute();
$stmt->bind_result($fn, $pi);
if ($stmt->fetch()) $user = ['full_name' => $fn ?: 'Lab Technician', 'profile_image' => $pi ?: ''];
$stmt->close();

// ── FILTER / SEARCH ──
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where = "WHERE lr.uploaded_by = $uid";
if ($filter === 'pending')   $where .= " AND lr.status='pending'";
if ($filter === 'completed') $where .= " AND lr.status='completed'";
if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $where .= " AND (u.full_name LIKE '%$safe%' OR lr.test_name LIKE '%$safe%')";
}

// ── COUNTS (always full, unfiltered by search, scoped to this tech) ──
$my_total     = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid")->fetch_row()[0] ?? 0;
$my_pending   = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND status='pending'")->fetch_row()[0] ?? 0;
$my_completed = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND status='completed'")->fetch_row()[0] ?? 0;
$my_today     = $conn->query("SELECT COUNT(*) FROM lab_reports WHERE uploaded_by=$uid AND DATE(uploaded_at)=CURDATE()")->fetch_row()[0] ?? 0;

// ── REPORT LIST (filtered) ──
$reports = [];
$res = $conn->query("SELECT lr.report_id, u.full_name as patient_name, lr.test_name, lr.status, lr.uploaded_at, lr.report_file
                     FROM lab_reports lr
                     JOIN users u ON lr.patient_id = u.user_id
                     $where
                     ORDER BY lr.uploaded_at DESC");
if ($res) while ($row = $res->fetch_assoc()) $reports[] = $row;

// ── CHART DATA: last 14 days upload trend ──
$daily_data = [];
$dres = $conn->query("SELECT DATE_FORMAT(uploaded_at,'%b %d') as day, COUNT(*) as total
                      FROM lab_reports
                      WHERE uploaded_by=$uid AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                      GROUP BY DATE(uploaded_at), DATE_FORMAT(uploaded_at,'%b %d')
                      ORDER BY DATE(uploaded_at)");
if ($dres) while ($row = $dres->fetch_assoc()) $daily_data[] = $row;

// ── CHART DATA: test type breakdown ──
$test_type_data = [];
$tres = $conn->query("SELECT test_name, COUNT(*) as total FROM lab_reports WHERE uploaded_by=$uid GROUP BY test_name ORDER BY total DESC LIMIT 6");
if ($tres) while ($row = $tres->fetch_assoc()) $test_type_data[] = $row;

$conn->close();

$days_arr  = empty($daily_data) ? [] : array_column($daily_data, 'day');
$day_vals  = empty($daily_data) ? [] : array_map('intval', array_column($daily_data, 'total'));
$test_labels = empty($test_type_data) ? ['No Data'] : array_column($test_type_data, 'test_name');
$test_counts = empty($test_type_data) ? [1] : array_map('intval', array_column($test_type_data, 'total'));

$initials = strtoupper(substr($user['full_name'], 0, 2));
$rate = $my_total > 0 ? round(($my_completed / $my_total) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Reports — SHAPMS</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --bg:#0d1117; --bg2:#161b27; --bg3:#1c2333;
  --border:rgba(255,255,255,0.07); --border2:rgba(255,255,255,0.12);
  --blue:#3b82f6; --blue-b:#60a5fa; --cyan:#06b6d4;
  --green:#10b981; --yellow:#f59e0b; --red:#ef4444; --purple:#8b5cf6;
  --white:#ffffff; --w90:rgba(255,255,255,0.90); --w70:rgba(255,255,255,0.70);
  --w40:rgba(255,255,255,0.40); --w08:rgba(255,255,255,0.08); --w04:rgba(255,255,255,0.04);
  --sw:220px;
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

/* PAGE */
.page-body{padding:22px 28px 48px;display:flex;flex-direction:column;gap:20px;}

/* STATS */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-top:2px solid var(--accent,var(--blue));border-radius:12px;padding:18px 16px 14px;display:flex;flex-direction:column;gap:8px;transition:transform 0.2s;}
.stat-card:hover{transform:translateY(-3px);}
.stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.stat-val{font-family:'Space Grotesk',sans-serif;font-size:28px;font-weight:700;color:var(--white);line-height:1;}
.stat-label{font-size:10px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:0.8px;}
.stat-sub{font-size:10.5px;font-weight:600;}

/* CHARTS */
.section-label{display:flex;align-items:center;gap:8px;font-size:10px;font-weight:700;color:var(--w40);text-transform:uppercase;letter-spacing:1.5px;}
.section-label::before{content:'';width:12px;height:1px;background:var(--w40);}
.section-label::after{content:'';flex:1;height:1px;background:var(--border);}
.charts-row{display:grid;grid-template-columns:2fr 1fr;gap:16px;}
.chart-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px 22px;}
.chart-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px;}
.chart-title{font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:600;color:var(--white);display:flex;align-items:center;gap:7px;}
.chart-title i{color:var(--blue-b);font-size:13px;}
.chart-sub{font-size:11px;color:var(--w40);margin-bottom:14px;}
.chart-pill{background:var(--w08);border:1px solid var(--border2);border-radius:99px;padding:3px 10px;font-size:10.5px;font-weight:600;color:var(--w70);white-space:nowrap;display:flex;align-items:center;gap:5px;}
.pulse-dot{width:6px;height:6px;background:#34d399;border-radius:50%;animation:lp 1.5s infinite;}

/* FILTER BAR */
.filter-bar{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.filter-tabs{display:flex;gap:6px;flex-wrap:wrap;}
.ftab{padding:6px 14px;border-radius:99px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:all 0.15s;border:1px solid var(--border2);color:var(--w70);}
.ftab:hover{background:var(--w08);color:var(--white);}
.ftab.active{background:var(--blue);border-color:var(--blue);color:white;}
.ftab-pending.active{background:var(--yellow);border-color:var(--yellow);color:#0d1117;}
.ftab-completed.active{background:var(--green);border-color:var(--green);color:white;}
.search-wrap{margin-left:auto;position:relative;}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--w40);font-size:12px;}
.search-wrap input{background:var(--bg3);border:1px solid var(--border2);border-radius:99px;padding:7px 14px 7px 32px;font-size:12.5px;color:var(--white);outline:none;font-family:'Inter',sans-serif;width:220px;}
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
td{padding:12px 16px;font-size:12.5px;color:var(--w90);border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:var(--w04);}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:10.5px;font-weight:600;}
.b-pending{background:rgba(245,158,11,0.12);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);}
.b-completed{background:rgba(16,185,129,0.12);color:#34d399;border:1px solid rgba(16,185,129,0.2);}
.btn-view{padding:4px 11px;border-radius:99px;font-size:11px;font-weight:600;background:rgba(59,130,246,0.12);color:#60a5fa;border:1px solid rgba(59,130,246,0.2);text-decoration:none;transition:all 0.15s;display:inline-flex;align-items:center;gap:4px;}
.btn-view:hover{background:#3b82f6;color:white;}

.empty-state{text-align:center;padding:48px 16px;color:var(--w40);}
.empty-state i{font-size:36px;margin-bottom:12px;display:block;}
.empty-state h3{font-size:15px;font-weight:600;color:var(--w70);margin-bottom:6px;}
.empty-state p{font-size:12.5px;}

@media(max-width:1100px){.charts-row{grid-template-columns:1fr;}}
@media(max-width:900px){.stats-row{grid-template-columns:repeat(2,1fr);}}
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
    <a href="labstaffdashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <a href="labstaffuploadreport.php"><i class="fas fa-upload"></i><span>Upload Report</span></a>
    <a href="labstaffreports.php" class="active"><i class="fas fa-file-medical-alt"></i><span>My Reports</span></a>
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
      <div class="topbar-breadcrumb">Lab Staff / My Reports</div>
      <div class="topbar-title">My Reports</div>
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

  <div class="page-body">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card" style="--accent:#3b82f6">
        <div class="stat-icon" style="background:rgba(59,130,246,0.12);color:#60a5fa"><i class="fas fa-file-medical-alt"></i></div>
        <div class="stat-val" id="statTotal"><?= $my_total ?></div>
        <div class="stat-label">Total Reports</div>
        <div class="stat-sub" style="color:#60a5fa">All-time uploads</div>
      </div>
      <div class="stat-card" style="--accent:#f59e0b">
        <div class="stat-icon" style="background:rgba(245,158,11,0.12);color:#fbbf24"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-val" id="statPending"><?= $my_pending ?></div>
        <div class="stat-label">Pending Review</div>
        <div class="stat-sub" style="color:#fbbf24">Awaiting approval</div>
      </div>
      <div class="stat-card" style="--accent:#10b981">
        <div class="stat-icon" style="background:rgba(16,185,129,0.12);color:#34d399"><i class="fas fa-circle-check"></i></div>
        <div class="stat-val" id="statCompleted"><?= $my_completed ?></div>
        <div class="stat-label">Completed</div>
        <div class="stat-sub" style="color:#34d399">Approved</div>
      </div>
      <div class="stat-card" style="--accent:#06b6d4">
        <div class="stat-icon" style="background:rgba(6,182,212,0.12);color:#22d3ee"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-val" id="statToday"><?= $my_today ?></div>
        <div class="stat-label">Today's Uploads</div>
        <div class="stat-sub" style="color:#22d3ee">Uploaded today</div>
      </div>
    </div>

    <!-- Analytics -->
    <div class="section-label"><i class="fas fa-chart-line"></i> My Activity</div>

    <div class="charts-row">
      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-chart-area"></i> Upload Trend</div>
            <div class="chart-sub">My uploads — last 14 days</div>
          </div>
          <div class="chart-pill"><div class="pulse-dot"></div> Live · refreshes every 15s</div>
        </div>
        <canvas id="trendChart" height="110"></canvas>
      </div>

      <div class="chart-card">
        <div class="chart-header">
          <div>
            <div class="chart-title"><i class="fas fa-circle-half-stroke"></i> Test Types</div>
            <div class="chart-sub">My most frequent tests</div>
          </div>
        </div>
        <canvas id="testChart" height="160"></canvas>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <div class="filter-tabs">
        <a href="?filter=all" class="ftab <?= $filter==='all'?'active':'' ?>">All (<?= $my_total ?>)</a>
        <a href="?filter=pending" class="ftab ftab-pending <?= $filter==='pending'?'active':'' ?>">Pending (<?= $my_pending ?>)</a>
        <a href="?filter=completed" class="ftab ftab-completed <?= $filter==='completed'?'active':'' ?>">Completed (<?= $my_completed ?>)</a>
      </div>
      <form method="GET" class="search-wrap">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <i class="fas fa-search"></i>
        <input type="text" name="search" placeholder="Search patient or test…" value="<?= htmlspecialchars($search) ?>">
      </form>
    </div>

    <!-- Table -->
    <div class="table-card">
      <div class="table-hdr">
        <div class="table-title"><i class="fas fa-file-medical-alt"></i> Report List</div>
        <div class="table-count"><?= count($reports) ?> result<?= count($reports)!==1?'s':'' ?></div>
      </div>
      <table>
        <thead>
          <tr><th>#</th><th>Patient</th><th>Test</th><th>Status</th><th>Date</th><th>File</th></tr>
        </thead>
        <tbody>
          <?php if (empty($reports)): ?>
          <tr><td colspan="6">
            <div class="empty-state">
              <i class="fas fa-folder-open"></i>
              <h3>No reports found</h3>
              <p>No reports match your current filter.</p>
            </div>
          </td></tr>
          <?php else: foreach ($reports as $i => $r): ?>
          <tr>
            <td style="color:var(--w40);font-size:11px"><?= $i+1 ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($r['patient_name']) ?></td>
            <td style="color:var(--w70)"><?= htmlspecialchars($r['test_name']) ?></td>
            <td><span class="badge <?= $r['status']==='completed'?'b-completed':'b-pending' ?>"><?= ucfirst($r['status']) ?></span></td>
            <td style="color:var(--w40);font-size:11.5px"><?= date('M d, Y', strtotime($r['uploaded_at'])) ?></td>
            <td>
              <a href="uploads/<?= htmlspecialchars($r['report_file']) ?>" target="_blank" class="btn-view"><i class="fas fa-eye"></i> View</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /page-body -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
Chart.defaults.color = 'rgba(255,255,255,0.4)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 10;
const gridColor = 'rgba(255,255,255,0.05)';
const tooltip = { backgroundColor:'#1c2333', padding:10, cornerRadius:8 };

let trendChart, testChart;

function renderTrend(days, vals) {
  if (trendChart) { trendChart.data.labels = days; trendChart.data.datasets[0].data = vals; trendChart.update(); return; }
  trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: { labels: days, datasets: [{
      label: 'Uploads', data: vals,
      borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.12)',
      fill: true, tension: 0.4, pointBackgroundColor: '#3b82f6', pointRadius: 3, borderWidth: 2
    }]},
    options: { responsive:true, plugins:{legend:{display:false}, tooltip}, scales:{x:{grid:{color:gridColor}}, y:{grid:{color:gridColor}, beginAtZero:true}} }
  });
}

function renderTest(labels, counts) {
  if (testChart) { testChart.data.labels = labels; testChart.data.datasets[0].data = counts; testChart.update(); return; }
  testChart = new Chart(document.getElementById('testChart'), {
    type: 'doughnut',
    data: { labels, datasets: [{ data: counts, backgroundColor:['#8b5cf6','#3b82f6','#06b6d4','#10b981','#f59e0b','#ef4444'], borderWidth:0, hoverOffset:6 }] },
    options: { responsive:true, cutout:'62%', plugins:{legend:{display:true, position:'bottom', labels:{boxWidth:8, font:{size:10}}}, tooltip} }
  });
}

// Initial render from PHP data
renderTrend(<?= json_encode($days_arr) ?>, <?= json_encode($day_vals) ?>);
renderTest(<?= json_encode($test_labels) ?>, <?= json_encode($test_counts) ?>);

// ── REAL-TIME POLLING ──
// Fetches fresh stats + chart data every 15s from labstaffreports_data.php (lightweight JSON endpoint)
async function refreshLiveData() {
  try {
    const res = await fetch('labstaffreports_data.php');
    if (!res.ok) return;
    const data = await res.json();

    document.getElementById('statTotal').textContent = data.my_total;
    document.getElementById('statPending').textContent = data.my_pending;
    document.getElementById('statCompleted').textContent = data.my_completed;
    document.getElementById('statToday').textContent = data.my_today;

    renderTrend(data.days, data.day_vals);
    renderTest(data.test_labels, data.test_counts);
  } catch (e) {
    console.error('Live refresh failed:', e);
  }
}
setInterval(refreshLiveData, 15000);
</script>
</body>
</html>