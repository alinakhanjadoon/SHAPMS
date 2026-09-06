<?php
/* Timezone MUST be set before any date()/DateTime calculations below,
   otherwise hourly/daily/peak-hour stats are computed in the server's
   default timezone (often UTC) and will look "wrong". */
date_default_timezone_set("Asia/Karachi");

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ---------------- AUTH ---------------- */
if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

/* ---------------- DB ---------------- */
$conn = new mysqli("localhost", "root", "", "SHAPMS");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

/* ---------------- FETCH ACTIVITY LOGS ----------------
   Use activity_log's own `role` column (the role the user had AT THE
   TIME of the action) instead of the current users.role, falling back
   to the joined role only if the log row's own role is empty/null. */
$logs = $conn->query("
    SELECT 
        a.log_id,
        a.user_id,
        a.action,
        a.created_at,
        u.full_name,
        COALESCE(NULLIF(TRIM(a.role), ''), u.role, 'system') AS role
    FROM activity_log a
    LEFT JOIN users u ON u.user_id = a.user_id
    ORDER BY a.created_at DESC
");

if (!$logs) die("Query Error: " . $conn->error);

$log_data = $logs->fetch_all(MYSQLI_ASSOC);
$total_activities = count($log_data);

/* Role stats — normalize case so "Doctor" and "doctor" don't split into
   separate chart slices */
$role_stats = [];
foreach ($log_data as $row) {
    $role = strtolower(trim($row['role'] ?? 'system'));
    if ($role === '') $role = 'system';
    $role_stats[$role] = ($role_stats[$role] ?? 0) + 1;
}
$role_labels = array_map('ucfirst', array_keys($role_stats));
$role_counts = array_values($role_stats);

/* Hourly activity (0–23), now correctly using Asia/Karachi since
   timezone is set before this runs */
$hourly_counts = array_fill(0, 24, 0);
foreach ($log_data as $row) {
    $hour = (int)date('H', strtotime($row['created_at']));
    $hourly_counts[$hour]++;
}

/* Daily activity (last 7 calendar days).
   Bucketed by comparing Y-m-d date strings directly instead of
   DateTime::diff()->days, which measures elapsed hours and can
   misclassify an entry from "yesterday 11pm" into today's bucket. */
$daily_labels = [];
$day_keys = [];
for ($i = 6; $i >= 0; $i--) {
    $ts = strtotime("-$i days");
    $daily_labels[] = date('D, M j', $ts);
    $day_keys[] = date('Y-m-d', $ts);
}
$daily_counts = array_fill(0, 7, 0);
foreach ($log_data as $row) {
    $logDay = date('Y-m-d', strtotime($row['created_at']));
    $idx = array_search($logDay, $day_keys, true);
    if ($idx !== false) $daily_counts[$idx]++;
}

/* Insights */
$top_role   = !empty($role_labels) ? $role_labels[array_search(max($role_counts), $role_counts)] : 'N/A';
$top_count  = !empty($role_counts) ? max($role_counts) : 0;
$peak_hour  = max($hourly_counts) > 0 ? array_search(max($hourly_counts), $hourly_counts) : 'N/A';
$weekly_avg = $total_activities > 0 ? round($total_activities / 7) : 0;

/* Admin info */
$uid        = (int)$_SESSION['user_id'];
$admin      = $conn->query("SELECT full_name, profile_image FROM users WHERE user_id=$uid")->fetch_assoc();
$admin_name = $admin['full_name'] ?? 'Admin';
$profile    = $admin['profile_image'] ?? 'default-avatar.png';

$hour_now = (int)date('H');
$greeting = $hour_now < 12 ? "Good Morning" : ($hour_now < 17 ? "Good Afternoon" : "Good Evening");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Activity Logs — Zaman Medical</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fraunces:opsz,wght@9..144,300;9..144,400;9..144,700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root {
  --bg-mesh:    radial-gradient(ellipse at 20% 10%, #FFE4E4 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, #FFD6E0 0%, transparent 45%),
                radial-gradient(ellipse at 60% 20%, #FFECD2 0%, transparent 40%),
                #F5F0F8;
  --surface:    rgba(255,255,255,0.75);
  --surface-s:  rgba(255,255,255,0.55);
  --surface-b:  rgba(255,255,255,0.92);
  --border:     rgba(180,100,120,0.10);
  --border-s:   rgba(180,100,120,0.18);
  --red:        #C62A2A;
  --red-l:      #E03E3E;
  --red-xl:     #F06060;
  --red-2xl:    #FFB3B3;
  --grad-1: linear-gradient(145deg, #FF6B6B 0%, #C62A2A 100%);
  --grad-2: linear-gradient(145deg, #FF8FA3 0%, #D63864 100%);
  --grad-3: linear-gradient(145deg, #FFB347 0%, #E07020 100%);
  --grad-4: linear-gradient(145deg, #7EC8E3 0%, #2B6CB0 100%);
  --text-1:  #1A0A14;
  --text-2:  #5A3550;
  --text-3:  #9B7B90;
  --text-w:  rgba(255,255,255,0.95);
  --text-wm: rgba(255,255,255,0.72);
  --sw: 240px;
  --radius: 22px;
  --radius-s: 14px;
  --radius-pill: 100px;
  --shadow-card: 0 8px 32px rgba(180,60,80,0.12), 0 2px 8px rgba(180,60,80,0.06);
  --shadow-soft: 0 4px 20px rgba(0,0,0,0.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg-mesh);background-attachment:fixed;color:var(--text-1);min-height:100vh;overflow-x:hidden;}
::-webkit-scrollbar{width:5px;}
::-webkit-scrollbar-thumb{background:var(--red-2xl);border-radius:10px;}

/* SIDEBAR */
.sidebar{position:fixed;left:0;top:0;width:var(--sw);height:100vh;background:var(--surface-b);backdrop-filter:blur(24px) saturate(180%);border-right:1px solid var(--border-s);display:flex;flex-direction:column;z-index:200;box-shadow:4px 0 32px rgba(180,60,80,0.08);}
.sb-logo{padding:24px 20px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;}
.sb-logo-mark{width:40px;height:40px;border-radius:12px;background:var(--grad-1);display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;box-shadow:0 6px 20px rgba(198,42,42,0.35);flex-shrink:0;}
.sb-logo-name{font-family:'Fraunces',serif;font-size:17px;font-weight:700;color:var(--text-1);line-height:1.1;}
.sb-logo-tag{font-size:9.5px;font-weight:700;letter-spacing:0.16em;text-transform:uppercase;color:var(--red-xl);display:block;margin-top:2px;}
.sb-profile{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
.sb-profile img{width:38px;height:38px;border-radius:12px;object-fit:cover;border:2px solid var(--red-2xl);box-shadow:0 4px 12px rgba(198,42,42,0.2);}
.sb-profile-name{font-size:13px;font-weight:700;color:var(--text-1);line-height:1.2;}
.sb-profile-role{font-size:10px;color:var(--red-xl);font-weight:600;letter-spacing:0.08em;}
.sb-search{padding:14px 20px;border-bottom:1px solid var(--border);}
.sb-search-inner{display:flex;align-items:center;gap:8px;background:var(--surface-s);border:1px solid var(--border);border-radius:var(--radius-pill);padding:8px 14px;}
.sb-search-inner i{color:var(--text-3);font-size:12px;}
.sb-search-inner input{border:none;background:none;outline:none;font-family:'Plus Jakarta Sans',sans-serif;font-size:12.5px;color:var(--text-1);width:100%;}
.sb-nav{flex:1;padding:10px 12px;overflow-y:auto;}
.sb-section-label{padding:12px 10px 5px;font-size:9px;font-weight:700;letter-spacing:0.20em;text-transform:uppercase;color:var(--text-3);}
.sb-link{display:flex;align-items:center;gap:10px;padding:10px 12px;color:var(--text-2);text-decoration:none;font-size:13px;font-weight:500;border-radius:var(--radius-s);transition:all 0.2s;margin-bottom:2px;}
.sb-icon{width:32px;height:32px;border-radius:10px;background:transparent;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--text-3);flex-shrink:0;transition:all 0.2s;}
.sb-link:hover{background:rgba(198,42,42,0.07);color:var(--red);}
.sb-link:hover .sb-icon{background:rgba(198,42,42,0.12);color:var(--red);}
.sb-link.active{background:linear-gradient(135deg,rgba(198,42,42,0.12),rgba(224,62,62,0.06));color:var(--red);font-weight:700;box-shadow:inset 0 0 0 1px rgba(198,42,42,0.15);}
.sb-link.active .sb-icon{background:var(--grad-1);color:#fff;box-shadow:0 4px 12px rgba(198,42,42,0.30);}
.sb-quick{padding:14px 20px;border-top:1px solid var(--border);}
.sb-quick-title{font-size:9.5px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:var(--text-3);margin-bottom:10px;}
.sb-quick-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.sb-quick-btn{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 4px;border-radius:var(--radius-s);background:var(--surface-s);border:1px solid var(--border);text-decoration:none;transition:all 0.2s;}
.sb-quick-btn i{font-size:16px;color:var(--text-2);}
.sb-quick-btn span{font-size:9px;font-weight:600;color:var(--text-3);text-align:center;}
.sb-quick-btn:hover{background:#fff;box-shadow:var(--shadow-soft);transform:translateY(-2px);}
.sb-quick-btn:hover i{color:var(--red);}
.sb-footer{padding:14px 20px;border-top:1px solid var(--border);}
.sb-logout{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--radius-s);background:rgba(198,42,42,0.07);border:1px solid rgba(198,42,42,0.15);color:var(--red);text-decoration:none;font-size:13px;font-weight:600;transition:all 0.2s;}
.sb-logout:hover{background:rgba(198,42,42,0.14);transform:translateX(4px);}

/* MAIN */
.main{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column;}

/* TOPBAR */
.topbar{position:sticky;top:0;z-index:100;background:rgba(245,240,248,0.80);backdrop-filter:blur(20px) saturate(160%);border-bottom:1px solid var(--border-s);padding:0 32px;height:68px;display:flex;align-items:center;justify-content:space-between;gap:20px;}
.topbar-nav{display:flex;align-items:center;gap:4px;background:rgba(255,255,255,0.7);border:1px solid var(--border-s);border-radius:var(--radius-pill);padding:4px;}
.topbar-nav a{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:var(--radius-pill);font-size:12.5px;font-weight:600;color:var(--text-2);text-decoration:none;transition:all 0.2s;white-space:nowrap;}
.topbar-nav a:hover{color:var(--red);background:rgba(198,42,42,0.06);}
.topbar-nav a.active{background:var(--grad-1);color:#fff;box-shadow:0 4px 14px rgba(198,42,42,0.30);}
.topbar-right{display:flex;align-items:center;gap:10px;}
.tb-greeting{font-size:13px;font-weight:500;color:var(--text-2);}
.tb-greeting strong{color:var(--text-1);font-weight:700;}
.icon-btn{width:38px;height:38px;border-radius:var(--radius-s);background:rgba(255,255,255,0.8);border:1px solid var(--border-s);display:flex;align-items:center;justify-content:center;color:var(--text-2);font-size:14px;text-decoration:none;transition:all 0.2s;box-shadow:var(--shadow-soft);}
.icon-btn:hover{background:#fff;color:var(--red);box-shadow:var(--shadow-card);}

/* CONTENT */
.content{flex:1;padding:28px 32px 48px;display:flex;flex-direction:column;gap:28px;}
.page-title{font-family:'Fraunces',serif;font-size:32px;font-weight:700;color:var(--text-1);letter-spacing:-0.01em;}

/* FLOAT CARDS */
.float-cards-row{display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap;}
.float-card{border-radius:26px;padding:28px 24px;color:var(--text-w);position:relative;overflow:hidden;flex:1;min-width:180px;min-height:180px;display:flex;flex-direction:column;justify-content:space-between;transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),box-shadow 0.3s;cursor:default;}
.float-card:nth-child(1){background:var(--grad-1);transform:translateY(0px) rotate(-1.5deg);z-index:3;}
.float-card:nth-child(2){background:var(--grad-2);transform:translateY(-12px) rotate(0.5deg);z-index:2;}
.float-card:nth-child(3){background:var(--grad-3);transform:translateY(0px) rotate(1.5deg);z-index:1;}
.float-card:nth-child(4){background:var(--grad-4);transform:translateY(-8px) rotate(-0.8deg);z-index:0;}
.float-card:hover{transform:translateY(-16px) rotate(0deg) !important;box-shadow:0 28px 64px rgba(0,0,0,0.20) !important;z-index:10 !important;}
.float-card::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:radial-gradient(circle at 30% 30%,rgba(255,255,255,0.25) 0%,transparent 60%);pointer-events:none;}
.fc-icon-wrap{width:46px;height:46px;border-radius:14px;background:rgba(255,255,255,0.25);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;margin-bottom:auto;}
.fc-label{font-size:10px;font-weight:700;letter-spacing:0.18em;text-transform:uppercase;color:var(--text-wm);margin-top:16px;}
.fc-value{font-family:'Fraunces',serif;font-size:42px;font-weight:700;color:#fff;line-height:1;letter-spacing:-0.02em;}
.fc-sub{font-size:11px;color:var(--text-wm);margin-top:4px;}

/* LOWER GRID */
.lower-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.panel{background:var(--surface);backdrop-filter:blur(20px) saturate(160%);border:1px solid var(--border-s);border-radius:var(--radius);padding:26px;box-shadow:var(--shadow-card);position:relative;overflow:hidden;}
.panel::before{content:'';position:absolute;top:0;left:20%;right:20%;height:1.5px;background:linear-gradient(90deg,transparent,rgba(198,42,42,0.35),transparent);border-radius:100px;}
.panel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;}
.panel-title{font-size:15px;font-weight:700;color:var(--text-1);display:flex;align-items:center;gap:8px;}
.panel-title i{color:var(--red-xl);font-size:14px;}
.panel-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:var(--radius-pill);background:rgba(198,42,42,0.08);border:1px solid rgba(198,42,42,0.15);font-size:10px;font-weight:700;color:var(--red);}

/* TABLE */
.table-card{background:var(--surface);backdrop-filter:blur(20px) saturate(160%);border:1px solid var(--border-s);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;}
.table-header{padding:18px 24px;border-bottom:1px solid var(--border);background:rgba(198,42,42,0.04);display:flex;justify-content:space-between;align-items:center;}
.table-header h3{font-size:15px;font-weight:700;color:var(--text-1);display:flex;align-items:center;gap:8px;}
.record-count{font-size:11px;font-weight:600;background:rgba(255,255,255,0.7);padding:4px 12px;border-radius:100px;color:var(--text-2);}
.activity-table{width:100%;border-collapse:collapse;}
.activity-table thead th{text-align:left;padding:14px 20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-3);background:rgba(245,240,248,0.5);border-bottom:1px solid var(--border);}
.activity-table tbody td{padding:14px 20px;font-size:13px;color:var(--text-2);border-bottom:1px solid var(--border);}
.activity-table tbody tr:hover{background:rgba(198,42,42,0.04);}
.role-badge{display:inline-block;padding:4px 12px;border-radius:100px;font-size:10px;font-weight:700;background:rgba(198,42,42,0.1);color:var(--red);}
.role-doctor{background:rgba(59,130,246,0.1);color:#2563eb;}
.role-nurse{background:rgba(236,72,153,0.1);color:#db2777;}
.role-pharmacist{background:rgba(168,85,247,0.1);color:#9333ea;}
.role-patient{background:rgba(34,197,94,0.1);color:#16a34a;}
.role-receptionist{background:rgba(245,158,11,0.1);color:#d97706;}
.role-system{background:rgba(107,114,128,0.1);color:#4b5563;}
.action-preview{max-width:320px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.empty-state{text-align:center;padding:60px;color:var(--text-3);}
.empty-state i{font-size:48px;margin-bottom:16px;opacity:0.5;}

@keyframes riseUp{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}
@keyframes floatIn{from{opacity:0;transform:translateY(20px) scale(0.96);}to{opacity:1;transform:translateY(0) scale(1);}}
.page-title{animation:riseUp 0.4s ease both;}
.float-cards-row{animation:floatIn 0.5s 0.05s ease both;}
.table-card,.lower-grid{animation:riseUp 0.4s 0.1s ease both;}
@media(max-width:1200px){.lower-grid{grid-template-columns:1fr;}.float-cards-row{flex-wrap:wrap;}}
@media(max-width:900px){:root{--sw:200px;}.content{padding:20px;}.topbar-nav{display:none;}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sb-logo">
    <div class="sb-logo-mark"><i class="fa-solid fa-hospital"></i></div>
    <div>
      <div class="sb-logo-name">Zaman Medical</div>
      <span class="sb-logo-tag">Admin Portal</span>
    </div>
  </div>
  <div class="sb-profile">
    <img src="<?= htmlspecialchars($profile) ?>" onerror="this.src='default-avatar.png'" alt="Admin">
    <div>
      <div class="sb-profile-name"><?= htmlspecialchars($admin_name) ?></div>
      <div class="sb-profile-role">Super Admin</div>
    </div>
  </div>
  <div class="sb-search">
    <div class="sb-search-inner">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" placeholder="Search...">
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section-label">Navigation</div>
    <a href="admindashboard.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-table-columns"></i></span> Dashboard</a>
    <a href="adminreports.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-chart-line"></i></span> Reports</a>
    <a href="adminactivity.php" class="sb-link active"><span class="sb-icon"><i class="fa-solid fa-clock-rotate-left"></i></span> Activity</a>
    <div class="sb-section-label">Staff</div>
    <a href="adminstaffapproval.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-check"></i></span> Approval</a>
    <a href="admindoctors.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-doctor"></i></span> Doctors</a>
    <a href="adminnurses.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-nurse"></i></span> Nurses</a>
    <a href="adminpharmacists.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-pills"></i></span> Pharmacists</a>
    <a href="adminreceptionists.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-headset"></i></span> Receptionists</a>
    <div class="sb-section-label">Hospital</div>
    <a href="adminpatients.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-users"></i></span> Patients</a>
    <a href="adminappointments.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-calendar-check"></i></span> Appointments</a>
    <a href="adminprescriptions.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-file-medical"></i></span> Prescriptions</a>
    <a href="adminbeds.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-bed"></i></span> Beds</a>
    <div class="sb-section-label">Account</div>
    <a href="adminprofile.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-user-gear"></i></span> Profile</a>
    <a href="adminsettings.php" class="sb-link"><span class="sb-icon"><i class="fa-solid fa-gears"></i></span> Settings</a>
  </nav>
  <div class="sb-quick">
    <div class="sb-quick-title">Quick Menu</div>
    <div class="sb-quick-grid">
      <a href="adminpatients.php" class="sb-quick-btn"><i class="fa-solid fa-users" style="color:#C62A2A;"></i><span>Patients</span></a>
      <a href="adminappointments.php" class="sb-quick-btn"><i class="fa-solid fa-calendar-check" style="color:#D63864;"></i><span>Appts</span></a>
      <a href="adminbeds.php" class="sb-quick-btn"><i class="fa-solid fa-bed" style="color:#E07020;"></i><span>Beds</span></a>
      <a href="admindoctors.php" class="sb-quick-btn"><i class="fa-solid fa-user-doctor" style="color:#2B6CB0;"></i><span>Doctors</span></a>
      <a href="adminprescriptions.php" class="sb-quick-btn"><i class="fa-solid fa-file-medical" style="color:#7C3AED;"></i><span>Rx</span></a>
      <a href="adminreports.php" class="sb-quick-btn"><i class="fa-solid fa-chart-line" style="color:#059669;"></i><span>Reports</span></a>
    </div>
  </div>
  <div class="sb-footer">
    <a href="logout.php" class="sb-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <header class="topbar">
    <nav class="topbar-nav">
      <a href="admindashboard.php"><i class="fa-solid fa-table-columns"></i> Dashboard</a>
      <a href="adminappointments.php"><i class="fa-solid fa-calendar-check"></i> Appointments</a>
      <a href="adminpatients.php"><i class="fa-solid fa-users"></i> Patients</a>
      <a href="adminreports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
    </nav>
    <div class="topbar-right">
      <div class="tb-greeting"><?= $greeting ?>, <strong><?= htmlspecialchars(explode(' ', $admin_name)[0]) ?></strong> 👋</div>
      <a href="adminactivity.php" class="icon-btn"><i class="fa-solid fa-bell"></i></a>
      <a href="adminprofile.php" class="icon-btn"><i class="fa-solid fa-user-gear"></i></a>
    </div>
  </header>

  <main class="content">

    <div style="display:flex;align-items:baseline;justify-content:space-between;">
      <div>
        <div class="page-title">Activity Logs</div>
        <div style="font-size:13px;color:var(--text-3);margin-top:4px;"><?= date('l, d F Y') ?> &nbsp;·&nbsp; Audit trail & user actions</div>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="float-cards-row">
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-chart-simple"></i></div>
        <div><div class="fc-label">Total Activities</div><div class="fc-value"><?= number_format($total_activities) ?></div><div class="fc-sub">All events logged</div></div>
      </div>
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-users"></i></div>
        <div><div class="fc-label">Active Roles</div><div class="fc-value"><?= count($role_stats) ?></div><div class="fc-sub">Roles with activity</div></div>
      </div>
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-calendar-day"></i></div>
        <div><div class="fc-label">Today's Events</div><div class="fc-value"><?= $daily_counts[6] ?? 0 ?></div><div class="fc-sub">Last 24h</div></div>
      </div>
      <div class="float-card">
        <div class="fc-icon-wrap"><i class="fa-solid fa-chart-line"></i></div>
        <div><div class="fc-label">Avg Daily</div><div class="fc-value"><?= $weekly_avg ?></div><div class="fc-sub">per day (7d)</div></div>
      </div>
    </div>

    <!-- CHARTS -->
    <div class="lower-grid">

      <!-- Role Doughnut -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-solid fa-chart-pie"></i> Activity by Role</div>
          <span class="panel-badge">Distribution</span>
        </div>
        <canvas id="roleChart" height="200"></canvas>
      </div>

      <!-- Weekly Trend -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-solid fa-chart-line"></i> Last 7 Days Trend</div>
          <span class="panel-badge">Recent activity</span>
        </div>
        <canvas id="trendChart" height="200"></canvas>
      </div>

      <!-- Hourly -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-regular fa-clock"></i> Activity by Hour (24h)</div>
          <span class="panel-badge">Peak hours</span>
        </div>
        <canvas id="hourlyChart" height="200"></canvas>
      </div>

      <!-- Insights -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fa-solid fa-list-check"></i> Insights</div>
          <span class="panel-badge">Analytics</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:14px;">
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <span style="color:var(--text-3);">Most Active Role</span>
            <span style="font-weight:800;color:var(--red);"><?= htmlspecialchars($top_role) ?> (<?= $top_count ?> events)</span>
          </div>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <span style="color:var(--text-3);">Peak Hour</span>
            <span style="font-weight:800;color:#2B6CB0;"><?= $peak_hour !== 'N/A' ? str_pad($peak_hour, 2, '0', STR_PAD_LEFT).':00' : 'N/A' ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <span style="color:var(--text-3);">Weekly Average</span>
            <span style="font-weight:800;color:#D63864;"><?= $weekly_avg ?> / day</span>
          </div>
          <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:10px;">
            <span style="color:var(--text-3);">Roles Active</span>
            <span style="font-weight:800;color:#7C3AED;"><?= count($role_stats) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;">
            <span style="color:var(--text-3);">System Status</span>
            <span style="font-weight:800;color:#16a34a;"><i class="fa-solid fa-circle" style="font-size:8px;margin-right:6px;"></i>Operational</span>
          </div>
        </div>
      </div>

    </div>

    <!-- LOGS TABLE -->
    <div class="table-card">
      <div class="table-header">
        <h3><i class="fa-solid fa-clock-rotate-left"></i> All Activity Logs</h3>
        <div class="record-count"><?= $total_activities ?> Records</div>
      </div>
      <div style="overflow-x:auto;">
        <table class="activity-table">
          <thead>
            <tr>
              <th>#</th>
              <th>User</th>
              <th>Role</th>
              <th>Action</th>
              <th>Date & Time</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($log_data)): ?>
              <?php foreach ($log_data as $row): ?>
                <tr>
                  <td><?= $row['log_id'] ?></td>
                  <td><?= htmlspecialchars($row['full_name'] ?? 'Unknown') ?></td>
                  <td>
                    <?php $r = strtolower(trim($row['role'] ?? 'system')); if ($r === '') $r = 'system'; ?>
                    <span class="role-badge role-<?= htmlspecialchars($r) ?>"><?= ucfirst($r) ?></span>
                  </td>
                  <td class="action-preview"><?= htmlspecialchars($row['action'] ?? '—') ?></td>
                  <td><?= date('d M Y, h:i A', strtotime($row['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5">
                  <div class="empty-state">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <div>No activity logs found</div>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<!-- ══════════ CHART JS ══════════ -->
<script>
const chartDefaults = {
  plugins: { legend: { labels: { color: '#5A3550', font: { family: 'Plus Jakarta Sans', size: 12 } } } },
  scales: {
    x: { ticks: { color: '#9B7B90', font: { size: 11 } }, grid: { color: 'rgba(180,100,120,0.08)' } },
    y: { ticks: { color: '#9B7B90', font: { size: 11 } }, grid: { color: 'rgba(180,100,120,0.08)' }, beginAtZero: true }
  }
};

/* Role Doughnut */
new Chart(document.getElementById('roleChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($role_labels) ?>,
    datasets: [{
      data: <?= json_encode($role_counts) ?>,
      backgroundColor: ['#C62A2A','#D63864','#E07020','#2B6CB0','#7C3AED','#059669','#9B7B90'],
      borderWidth: 0,
      hoverOffset: 10
    }]
  },
  options: {
    cutout: '65%',
    plugins: { legend: { position: 'bottom', labels: { color: '#5A3550', font: { family: 'Plus Jakarta Sans', size: 11 }, padding: 16 } } }
  }
});

/* Weekly Trend Line */
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($daily_labels) ?>,
    datasets: [{
      label: 'Activities',
      data: <?= json_encode(array_values($daily_counts)) ?>,
      borderColor: '#C62A2A',
      backgroundColor: 'rgba(198,42,42,0.08)',
      borderWidth: 2.5,
      pointBackgroundColor: '#C62A2A',
      pointRadius: 4,
      fill: true,
      tension: 0.4
    }]
  },
  options: { ...chartDefaults, plugins: { legend: { display: false } } }
});

/* Hourly Bar */
new Chart(document.getElementById('hourlyChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($h) => str_pad($h,2,'0',STR_PAD_LEFT).':00', range(0,23))) ?>,
    datasets: [{
      label: 'Events',
      data: <?= json_encode(array_values($hourly_counts)) ?>,
      backgroundColor: 'rgba(198,42,42,0.7)',
      borderRadius: 6,
      borderSkipped: false
    }]
  },
  options: { ...chartDefaults, plugins: { legend: { display: false } } }
});
</script>

</body>
</html>